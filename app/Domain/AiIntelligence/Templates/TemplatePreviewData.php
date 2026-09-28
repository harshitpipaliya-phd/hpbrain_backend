<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Templates;

use App\Domain\AiIntelligence\Reports\ModuleDataSourceCatalog;
use App\Domain\AiIntelligence\Support\AiIntelligenceScope;
use App\Domain\AiIntelligence\Support\OrganisationProfile;
use App\Domain\AiIntelligence\Support\TenantFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The values Preview substitutes into a template, read from this tenant's own
 * HP Brain records.
 *
 * G2G previews against its competency taxonomy. HP Brain's analogue is the
 * capability model (hpbrain_capabilities) — it describes the organisation's shape
 * and names no person — plus the tenant-level figures of the intelligence loop.
 *
 * There is no invented fallback. A tenant with no capabilities yet gets
 * `records = "none listed"` and `data_source = "none"`, which is exactly what a
 * runtime caller would send, so the author sees how the prompt reads against an
 * empty tenant instead of against made-up rows.
 */
final class TemplatePreviewData
{
    private const ROWS = 3;

    public function __construct(
        private readonly TenantFacts $facts,
        private readonly OrganisationProfile $organisation,
        private readonly TemplateModuleCatalog $modules,
        private readonly ModuleDataSourceCatalog $sources,
    ) {
    }

    /** @return array<string, string> */
    public function forTenant(string $tenantId, ?string $moduleKey = null): array
    {
        $facts = $this->facts->all($tenantId);

        // A named AI Stack module previews against ITS OWN records — the first rows of
        // its own read-only data source — so a Signals prompt is never previewed
        // against capability rows. The centralized console (no module, or the shared
        // key) keeps the capability-model preview below.
        if ($moduleKey !== null && $moduleKey !== '' && $moduleKey !== TemplateModuleCatalog::SHARED
            && $this->sources->forModule($moduleKey) !== []) {
            return $this->forModule($tenantId, $moduleKey, $facts);
        }

        try {
            [$rows, $total] = $this->capabilities($tenantId);
        } catch (Throwable) {
            [$rows, $total] = [[], null];
        }

        $shown = count($rows);

        $metrics = [];

        foreach ([
            'Capabilities' => $facts['capability_count'],
            'Open signals' => $facts['open_signals'],
            'Open cases' => $facts['open_cases'],
            'Pending recommendations' => $facts['pending_recommendations'],
            'Evidence records' => $facts['evidence_count'],
            'Knowledge assets' => $facts['knowledge_asset_count'],
        ] as $label => $value) {
            if ($value !== null) {
                $metrics[] = "{$label}: {$value}";
            }
        }

        $moduleLabel = $moduleKey !== null && $moduleKey !== '' && $moduleKey !== TemplateModuleCatalog::SHARED
            ? $this->modules->label($moduleKey, $tenantId)
            : 'Capabilities';

        return [
            'records' => $shown === 0 ? 'none listed' : implode("\n", $rows),
            'metrics' => $metrics === [] ? 'none reported' : implode('; ', $metrics),
            'record_count' => (string) ($total ?? 0),
            'rows_shown' => (string) $shown,
            'is_partial' => ($total ?? 0) > $shown ? 'yes' : 'no',
            'page_title' => 'Capability model',
            'page_type' => 'list',
            'filters' => 'none',
            'search_query' => '',
            'data_source' => $shown === 0 ? 'none' : 'your organisation’s capability records',
            'module' => $moduleLabel,
            'entity_label' => '',
            'organisation_name' => (string) ($this->organisation->name($tenantId) ?? ''),
            'open_signals' => $this->stringify($facts['open_signals']),
            'open_cases' => $this->stringify($facts['open_cases']),
            'pending_recommendations' => $this->stringify($facts['pending_recommendations']),
            'evidence_count' => $this->stringify($facts['evidence_count']),
            'decision_count' => $this->stringify($facts['decision_count']),
            'capability_count' => $this->stringify($facts['capability_count']),
            'knowledge_asset_count' => $this->stringify($facts['knowledge_asset_count']),
        ];
    }

    /**
     * Preview values from one module's own data source (ModuleDataSourceCatalog),
     * read for this tenant exactly as the module's Knowledge Base "Check" reads it.
     * An unavailable or empty source gives "none listed" / "none" — never other rows.
     *
     * @param  array<string, int|null>  $facts
     * @return array<string, string>
     */
    private function forModule(string $tenantId, string $moduleKey, array $facts): array
    {
        $source = $this->sources->forModule($moduleKey)[0];
        $rows = [];
        $total = 0;
        $available = false;

        try {
            $result = $this->sources->run($source['name'], new AiIntelligenceScope($tenantId, '', ''), ['limit' => self::ROWS]);
            $available = (bool) $result['available'];
            $rows = $available ? $result['rows'] : [];
            $total = $available ? (int) $result['total'] : 0;
        } catch (Throwable) {
            // A preview never fails the editor: an unreadable source previews as empty.
        }

        $labels = array_column($source['columns'], 'label', 'key');
        $lines = array_map(fn (array $row) => $this->describeRow($row, $labels), $rows);
        $shown = count($lines);

        $metrics = [$source['label'] . ': ' . ($available ? $total : 'not available')];

        foreach ([
            'Open signals' => $facts['open_signals'] ?? null,
            'Open cases' => $facts['open_cases'] ?? null,
            'Pending recommendations' => $facts['pending_recommendations'] ?? null,
        ] as $label => $value) {
            if ($value !== null) {
                $metrics[] = "{$label}: {$value}";
            }
        }

        return [
            'records' => $shown === 0 ? 'none listed' : implode("\n", $lines),
            'metrics' => implode('; ', $metrics),
            'record_count' => (string) $total,
            'rows_shown' => (string) $shown,
            'is_partial' => $total > $shown ? 'yes' : 'no',
            'page_title' => (string) $source['label'],
            'page_type' => 'list',
            'filters' => 'none',
            'search_query' => '',
            'data_source' => $shown === 0 ? 'none' : 'the module',
            'module' => $this->modules->label($moduleKey, $tenantId),
            'entity_label' => '',
            'organisation_name' => (string) ($this->organisation->name($tenantId) ?? ''),
            'open_signals' => $this->stringify($facts['open_signals'] ?? null),
            'open_cases' => $this->stringify($facts['open_cases'] ?? null),
            'pending_recommendations' => $this->stringify($facts['pending_recommendations'] ?? null),
            'evidence_count' => $this->stringify($facts['evidence_count'] ?? null),
            'decision_count' => $this->stringify($facts['decision_count'] ?? null),
            'capability_count' => $this->stringify($facts['capability_count'] ?? null),
            'knowledge_asset_count' => $this->stringify($facts['knowledge_asset_count'] ?? null),
        ];
    }

    /**
     * `- Label: value, Label: value` — the populated, non-identifier fields of one row.
     *
     * @param  array<string, mixed>   $row
     * @param  array<string, string>  $labels
     */
    private function describeRow(array $row, array $labels): string
    {
        $fields = [];

        foreach ($row as $key => $value) {
            if ($value === null || trim((string) $value) === '' || str_ends_with((string) $key, '_id')) {
                continue;
            }

            $fields[] = ($labels[$key] ?? $key) . ': ' . mb_strimwidth(trim((string) $value), 0, 120, '…');

            if (count($fields) === 6) {
                break;
            }
        }

        return $fields === [] ? '- (a record with no populated fields)' : '- ' . implode(', ', $fields);
    }

    /** @return array{0: array<int, string>, 1: ?int} */
    private function capabilities(string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_capabilities')) {
            return [[], null];
        }

        $base = DB::table('hpbrain_capabilities')
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'archived');

        $total = (int) (clone $base)->count();

        $rows = (clone $base)->orderBy('name')->limit(self::ROWS)->get();

        return [$rows->map(fn ($row) => $this->describe($row))->all(), $total];
    }

    /** `- Name (code: …, category: …, criticality: …)`, populated fields only. */
    private function describe(object $row): string
    {
        $fields = [];

        foreach (['capability_code' => 'code', 'category' => 'category', 'capability_type' => 'type', 'criticality' => 'criticality'] as $column => $label) {
            $value = trim((string) ($row->{$column} ?? ''));

            if ($value !== '') {
                $fields[] = "{$label}: {$value}";
            }
        }

        $name = trim((string) ($row->name ?? '')) ?: 'Unnamed capability';

        return $fields === [] ? "- {$name}" : sprintf('- %s (%s)', $name, implode(', ', $fields));
    }

    private function stringify(?int $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
