<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Operations\OperationalIntelligence;
use App\Domain\Universal\EntityResolver;
use App\Repositories\DataSourceRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIVE SEPARATE ANSWERS TO "HOW MUCH OF THIS TENANT'S DATA DOES THE BRAIN
 * ACTUALLY SEE", NEVER COLLAPSED INTO ONE PERCENTAGE.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * REUSED, NOT RECOMPUTED
 *
 * Every number below already exists somewhere in the codebase before this
 * class runs. Field presence per dataset is OperationalIntelligence's own
 * probe set (`fields.timeline`, `fields.status`, …) — the same numbers
 * OrganizationScorecard::dataCoverage() already folds into one weighted
 * score. Entity mapping is EntityResolver's own registry. Import row counts
 * are hpbrain_import_jobs, written by the ingestion pipeline itself. This
 * class queries none of the underlying tables OperationalIntelligence already
 * reads — it takes that engine's own payload and re-groups it under five
 * headings, plus reads the two tables (hpbrain_import_jobs,
 * hpbrain_data_sources) nothing upstream already exposes as a dashboard.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHY FIVE, AND WHY THEY DO NOT COLLAPSE INTO ONE
 *
 *   SOURCE DISCOVERY asks: does the Brain know where to look at all? A
 *   universal entity can be fully mapped with zero rows ever imported.
 *
 *   RECORD PROCESSING asks: of what was actually submitted to an import job,
 *   how much did the pipeline get through? A stalled or partially-failed
 *   job is a processing gap even when the source is perfectly mapped.
 *
 *   FIELD COMPLETENESS asks: of the rows that landed, how many of this
 *   engine's recognised fields are populated? A fully-processed dataset can
 *   still carry no status column.
 *
 *   DATA QUALITY asks a different question again: of the rows an import job
 *   actually processed, how many were clean versus errored or flagged as a
 *   duplicate? A dataset can be 100% field-complete and still be half
 *   duplicate rows from a re-run that was never deduplicated.
 *
 *   FRESHNESS asks how recently each of the first four answers was last true.
 *   A well-mapped, fully-processed, field-complete, clean dataset that has
 *   not received a row in eight months is not the same finding as one
 *   updated an hour ago, even though the first four numbers agree.
 *
 * Conflating any two of these is exactly how a dashboard ends up claiming
 * 100% coverage for a tenant whose only connected source has not synced
 * since onboarding — which this class is built not to do.
 */
final class DataCoverageService
{
    /** A configured source with no sync in this many days is reported stale. */
    private const STALE_AFTER_DAYS = 7;

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly OperationalIntelligence $operations,
        private readonly DataSourceRepository $sources,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forTenant(string $tenantId, bool $fresh = false): array
    {
        $ops = $this->operations->forTenant($tenantId, $fresh);
        $configuredSources = $this->sources->list($tenantId, false);
        $recordProcessing = $this->recordProcessing($tenantId);

        return [
            'tenantId' => $tenantId,
            'computedAt' => gmdate('c'),
            'sourceDiscovery' => $this->sourceDiscovery($tenantId, $configuredSources),
            'recordProcessing' => $recordProcessing,
            'fieldCompleteness' => $this->fieldCompleteness($ops),
            'dataQuality' => $this->dataQuality($recordProcessing),
            'freshness' => $this->freshness($ops, $configuredSources),
            'definitions' => [
                'sourceDiscovery' => 'Universal entities this tenant has mapped, and sources configured in the source registry. Connection status only — a mapped entity may still carry zero imported rows.',
                'recordProcessing' => 'Of the rows submitted across every import job this tenant has run, the share the pipeline actually processed, and how that processed set split into successes, failures and duplicates.',
                'fieldCompleteness' => 'For each connected operational dataset, the share of this engine\'s recognised fields (timeline, status, owner, category, …) that carry a value on at least one row.',
                'dataQuality' => 'Of the rows an import job actually processed (not of what was merely submitted), the share that were clean versus errored or treated as a duplicate.',
                'freshness' => 'How long ago each dataset last received a row, and how long ago each configured source last completed a sync.',
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $configuredSources
     * @return array<string, mixed>
     */
    private function sourceDiscovery(string $tenantId, array $configuredSources): array
    {
        $mapped = $this->resolver->mappedEntities($tenantId);
        $unmapped = array_values(array_diff(EntityResolver::ENTITIES, $mapped));
        $total = count(EntityResolver::ENTITIES);

        $active = array_values(array_filter($configuredSources, fn (array $s): bool => (bool) ($s['is_active'] ?? false)));

        return [
            'universalEntities' => [
                'total' => $total,
                'mapped' => count($mapped),
                'mappedEntities' => $mapped,
                'unmappedEntities' => $unmapped,
                'coveragePct' => $total > 0 ? round(count($mapped) / $total * 100, 1) : null,
            ],
            'configuredSources' => array_map(fn (array $s): array => [
                'sourceKey' => $s['source_key'] ?? null,
                'displayName' => $s['display_name'] ?? null,
                'sourceType' => $s['source_type'] ?? null,
                'universalEntity' => $s['universal_entity'] ?? null,
                'isActive' => (bool) ($s['is_active'] ?? false),
                'lastSyncedAt' => $s['last_synced_at'] ?? null,
            ], $configuredSources),
            'configuredSourceCount' => count($configuredSources),
            'activeSourceCount' => count($active),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordProcessing(string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_import_jobs')) {
            return ['supported' => false, 'reason' => 'No import job table exists on this installation.'];
        }

        $rows = DB::table('hpbrain_import_jobs')->where('tenant_id', $tenantId)->get();

        if ($rows->isEmpty()) {
            return ['supported' => false, 'reason' => 'No import job has been run for this tenant yet.', 'byStatus' => []];
        }

        $totalRows = (int) $rows->sum('total_rows');
        $processedRows = (int) $rows->sum('processed_rows');
        $successCount = (int) $rows->sum('success_count');
        $errorCount = (int) $rows->sum('error_count');
        $duplicateCount = (int) $rows->sum('duplicate_count');

        $lastSuccessful = $rows
            ->filter(fn ($r) => in_array((string) $r->status, ['completed', 'completed_with_errors'], true) && $r->completed_date !== null)
            ->sortByDesc('completed_date')
            ->first();

        $exclusionSamples = [];
        foreach ($rows as $row) {
            $report = json_decode((string) ($row->error_report ?? '[]'), true);
            if (! is_array($report)) {
                continue;
            }

            foreach (array_slice($report, 0, 3) as $entry) {
                $exclusionSamples[] = is_array($entry) ? (string) ($entry['message'] ?? json_encode($entry)) : (string) $entry;
            }
        }

        return [
            'supported' => true,
            'totalJobs' => $rows->count(),
            'byStatus' => $rows->groupBy('status')->map->count()->all(),
            'totalRowsSubmitted' => $totalRows,
            'rowsProcessed' => $processedRows,
            'rowsSucceeded' => $successCount,
            'rowsFailed' => $errorCount,
            'rowsDuplicate' => $duplicateCount,
            'processingCoveragePct' => $totalRows > 0 ? round($processedRows / $totalRows * 100, 1) : null,
            'lastSuccessfulImportAt' => $lastSuccessful->completed_date ?? null,
            'exclusionSamples' => array_slice(array_values(array_unique($exclusionSamples)), 0, 10),
        ];
    }

    /**
     * @param  array<string, mixed>  $ops
     * @return array<string, mixed>
     */
    private function fieldCompleteness(array $ops): array
    {
        if (($ops['support']['records'] ?? false) !== true) {
            return ['supported' => false, 'reason' => 'No operational records exist to measure field completeness over.', 'datasets' => []];
        }

        $datasets = [];

        foreach ((array) ($ops['datasets'] ?? []) as $d) {
            $fields = (array) ($d['fields'] ?? []);
            $present = count(array_filter($fields, fn ($v): bool => $v === true));
            $total = count($fields);

            $datasets[] = [
                'dataset' => $d['dataset'] ?? null,
                'label' => $d['label'] ?? null,
                'records' => $d['records'] ?? 0,
                'fieldsPresent' => $present,
                'fieldsTotal' => $total,
                'completenessPct' => $total > 0 ? round($present / $total * 100, 1) : null,
                'missingFields' => array_keys(array_filter($fields, fn ($v): bool => $v === false)),
            ];
        }

        $withScore = array_values(array_filter($datasets, fn (array $d): bool => $d['completenessPct'] !== null));

        return [
            'supported' => true,
            'overallPct' => $withScore === []
                ? null
                : round(array_sum(array_column($withScore, 'completenessPct')) / count($withScore), 1),
            'datasets' => $datasets,
        ];
    }

    /**
     * @param  array<string, mixed>  $recordProcessing
     * @return array<string, mixed>
     */
    private function dataQuality(array $recordProcessing): array
    {
        if (($recordProcessing['supported'] ?? false) !== true) {
            return ['supported' => false, 'reason' => $recordProcessing['reason'] ?? 'No processed rows exist to assess quality from.'];
        }

        $processed = (int) $recordProcessing['rowsProcessed'];

        return [
            'supported' => $processed > 0,
            'reason' => $processed > 0 ? null : 'Import jobs exist for this tenant but none has processed a row yet.',
            'cleanRatePct' => $processed > 0 ? round($recordProcessing['rowsSucceeded'] / $processed * 100, 1) : null,
            'errorRatePct' => $processed > 0 ? round($recordProcessing['rowsFailed'] / $processed * 100, 1) : null,
            'duplicateRatePct' => $processed > 0 ? round($recordProcessing['rowsDuplicate'] / $processed * 100, 1) : null,
            'exclusionSamples' => $recordProcessing['exclusionSamples'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $ops
     * @param  array<int, array<string, mixed>>  $configuredSources
     * @return array<string, mixed>
     */
    private function freshness(array $ops, array $configuredSources): array
    {
        $datasets = [];

        foreach ((array) ($ops['datasets'] ?? []) as $d) {
            $lastIngested = $d['lastIngestedAt'] ?? null;

            $datasets[] = [
                'dataset' => $d['dataset'] ?? null,
                'label' => $d['label'] ?? null,
                'earliest' => $d['earliest'] ?? null,
                'latest' => $d['latest'] ?? null,
                'lastIngestedAt' => $lastIngested,
                'spanDays' => $d['spanDays'] ?? null,
                'daysSinceLastIngest' => $this->daysSince($lastIngested),
            ];
        }

        $sources = array_map(fn (array $s): array => [
            'sourceKey' => $s['source_key'] ?? null,
            'lastSyncedAt' => $s['last_synced_at'] ?? null,
            'daysSinceLastSync' => $this->daysSince($s['last_synced_at'] ?? null),
            'isStale' => $this->isStale($s['last_synced_at'] ?? null),
        ], $configuredSources);

        return ['datasets' => $datasets, 'configuredSources' => $sources, 'staleAfterDays' => self::STALE_AFTER_DAYS];
    }

    private function daysSince(?string $timestamp): ?int
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        $parsed = strtotime($timestamp);

        return $parsed === false ? null : (int) floor((time() - $parsed) / 86400);
    }

    private function isStale(?string $lastSyncedAt): ?bool
    {
        $days = $this->daysSince($lastSyncedAt);

        return $days === null ? null : $days > self::STALE_AFTER_DAYS;
    }
}
