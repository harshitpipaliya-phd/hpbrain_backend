<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Capability\DemandService;
use App\Domain\Metrics\SnapshotWriter;
use App\Domain\Organization\DepartmentIntelligenceMetrics;
use App\Domain\Organization\DepartmentProfile;
use App\Domain\Organization\DepartmentRosterReader;
use App\Domain\Organization\DepartmentVerdict;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One row per metric per tenant per day.
 *
 * Everything the Brain reported was instantaneous: "42 open signals", never "42,
 * down from 61". Without a series no screen can show MOVEMENT, and an
 * Intelligence Score of 62 cannot be told from a coin-flip that landed on 62
 * twice running.
 *
 * IDEMPOTENT WITHIN A DAY. Re-running overwrites the day's rows rather than
 * appending, so a retried cron does not double-count. See SnapshotWriter for why
 * that cannot be left to the unique index.
 *
 * NULL IS WRITTEN, NOT ZERO. Every rate below goes through writeRate(), which
 * stores NULL when the denominator is zero. A reuse rate of null is not a reuse
 * rate of zero.
 */
final class SnapshotMetrics extends Command
{
    protected $signature = 'brain:snapshot {--tenant= : Snapshot one tenant instead of all}
                                           {--date= : Snapshot as of a date (Y-m-d), default today}';

    protected $description = 'Write today\'s metric snapshot for every tenant';

    public function __construct(private readonly DemandService $demand)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = (string) ($this->option('date')
            ?: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d'));

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('--date must be Y-m-d.');

            return self::FAILURE;
        }

        $writer = new SnapshotWriter($date);
        $tenants = $this->tenants();

        if ($tenants === []) {
            $this->warn('No tenants have entity mappings — nothing to snapshot.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenantId) {
            try {
                $this->snapshotTenant($writer, $tenantId);
                $this->line("  {$tenantId}: written");
            } catch (\Throwable $e) {
                // One tenant's broken configuration must not cost every other
                // tenant its day of history — the gap would be permanent.
                $this->warn("  {$tenantId}: skipped — ".$e->getMessage());
            }
        }

        $this->info('brain:snapshot complete for '.$date.' ('.count($tenants).' tenants).');

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function tenants(): array
    {
        if ($one = $this->option('tenant')) {
            return [(string) $one];
        }

        return DB::table('hpbrain_entity_mappings')
            ->where('is_active', 1)
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    private function snapshotTenant(SnapshotWriter $writer, string $tenantId): void
    {
        $this->snapshotSignals($writer, $tenantId);
        $this->snapshotScoreComponents($writer, $tenantId);
        $this->snapshotEvidence($writer, $tenantId);
        $this->snapshotMemory($writer, $tenantId);
        $this->snapshotCapabilities($writer, $tenantId);
        $this->snapshotDecisionLatency($writer, $tenantId);
        $this->snapshotDepartmentHealth($writer, $tenantId);
    }

    /**
     * Each department's health score, one row per unit per day.
     *
     * WHY THIS IS HERE AND NOT ON THE SCREEN. The department intelligence screen
     * opens with "down 4 points since the last refresh", and that sentence needs
     * two measurements. Computing it on the read path would mean either writing
     * on a GET — so the delta would depend on who opened the page and when — or
     * inventing a baseline, which is the one thing this product does not do. The
     * scheduled snapshot is the only honest source of movement, and until it has
     * run twice the screen says so rather than showing a zero.
     *
     * NULL WHERE THE UNIT CANNOT BE SCORED, never zero: an unscorable department
     * and a department scoring zero are opposite findings, and a series that
     * converted one into the other would draw a flat line along the bottom of the
     * chart and present it as a measurement.
     *
     * Scored on the SAME BASIS the screen uses — owner attribution included, for
     * every unit at once — so a delta compares like with like. A snapshot taken on
     * the label basis against a screen reading the owner basis would report
     * movement that never happened.
     */
    private function snapshotDepartmentHealth(SnapshotWriter $w, string $tenantId): void
    {
        $metrics = app(DepartmentIntelligenceMetrics::class)->forTenant($tenantId);
        $departments = array_keys($metrics['departments'] ?? []);

        if ($departments === []) {
            return;
        }

        $profiles = app(DepartmentProfile::class);
        $ownerWork = app(DepartmentRosterReader::class)->ownerWorkFor($tenantId);

        foreach ($departments as $departmentId) {
            $profile = $profiles->forDepartment($tenantId, (string) $departmentId, $ownerWork);

            if ($profile === null) {
                continue;
            }

            $w->write(
                $tenantId,
                DepartmentVerdict::HEALTH_METRIC,
                $profile['score'] === null ? null : (float) $profile['score'],
                dimensionKey: (string) $departmentId,
                sampleN: (int) ($profile['measuredCount'] ?? 0),
            );
        }
    }

    private function snapshotSignals(SnapshotWriter $w, string $t): void
    {
        $open = DB::table('hpbrain_signals')->where('tenant_id', $t)
            ->whereNotIn('status', ['resolved', 'closed', 'dismissed']);

        $w->write($t, 'signals.open', (float) (clone $open)->count());
        $w->write($t, 'signals.high', (float) (clone $open)
            ->whereIn('severity', ['high', 'critical'])->count());

        $w->write($t, 'recommendations.pending', (float) DB::table('hpbrain_recommendations')
            ->where('tenant_id', $t)->whereIn('status', ['pending', 'proposed'])->count());
    }

    /**
     * The four components of the Intelligence Score, snapshotted separately.
     *
     * The composite alone cannot tell you WHICH component moved, which is the
     * only thing that makes it actionable. Each is 0-1 here; the API renders
     * them 0-100.
     */
    private function snapshotScoreComponents(SnapshotWriter $w, string $t): void
    {
        $decisions = DB::table('hpbrain_decisions')->where('tenant_id', $t)->get();
        $accepted = $decisions->filter(
            fn ($d) => in_array(strtolower((string) $d->status), ['approved', 'accepted'], true)
        )->count();

        $w->writeRate($t, 'score.decisionAcceptance', $accepted, $decisions->count());

        $outcomes = DB::table('hpbrain_outcomes')->where('tenant_id', $t)->get();
        $successful = $outcomes->filter(
            fn ($o) => in_array(strtolower((string) ($o->result ?? '')), ['success', 'succeeded'], true)
        )->count();

        $w->writeRate($t, 'score.recommendationAccuracy', $successful, $outcomes->count());

        $evidence = DB::table('hpbrain_evidence')->where('tenant_id', $t);
        $meanConfidence = (clone $evidence)->avg('confidence');
        $evidenceCount = (clone $evidence)->count();

        $w->write(
            $t,
            'score.evidenceQuality',
            $meanConfidence === null ? null : round((float) $meanConfidence, 4),
            sampleN: $evidenceCount,
        );

        $risks = DB::table('hpbrain_risks')->where('tenant_id', $t);
        $total = (clone $risks)->count();
        $openRisks = (clone $risks)->whereRaw('LOWER(status) <> ?', ['mitigated'])->count();

        $w->writeRate($t, 'score.riskCoverage', $total - $openRisks, $total);
    }

    private function snapshotEvidence(SnapshotWriter $w, string $t): void
    {
        $evidence = DB::table('hpbrain_evidence')->where('tenant_id', $t);
        $mean = (clone $evidence)->avg('confidence');

        $w->write(
            $t,
            'evidence.meanConfidence',
            $mean === null ? null : round((float) $mean, 4),
            sampleN: (clone $evidence)->count(),
        );
    }

    /**
     * Memory reuse — the flywheel metric.
     *
     * Two rates, because the Brain keeps memory in two places and they answer
     * different questions:
     *
     *   memory.reusableRate — the share of learnings marked reusable at all.
     *                         "Did we write anything worth keeping?"
     *   memory.reuseRate    — the share of knowledge assets actually retrieved
     *                         at least once. "Did anyone come back for it?"
     *
     * Both are NULL on a tenant with nothing to divide by. A flywheel that has
     * not started turning is not a flywheel turning at zero, and rendering it as
     * zero would make an empty organization look like a failing one.
     */
    private function snapshotMemory(SnapshotWriter $w, string $t): void
    {
        $learnings = DB::table('hpbrain_learnings')->where('tenant_id', $t);
        $total = (clone $learnings)->count();
        $reusable = (clone $learnings)->where('reusable', 1)->count();

        $w->write($t, 'memory.learnings', (float) $total);
        $w->writeRate($t, 'memory.reusableRate', $reusable, $total);

        // hpbrain_knowledge_assets is the retrieval side. Guarded because it is
        // the one table in this command a tenant can legitimately not have yet.
        try {
            $assets = DB::table('hpbrain_knowledge_assets')->where('tenant_id', $t);

            $w->writeRate(
                $t,
                'memory.reuseRate',
                (clone $assets)->where('reuse_count', '>', 0)->count(),
                (clone $assets)->count(),
            );
        } catch (\Throwable) {
            // No table, no series. Writing 0 would be worse than writing nothing.
        }
    }

    /**
     * Coverage and deficit per capability — one row per capability, carried on
     * dimension_key.
     */
    private function snapshotCapabilities(SnapshotWriter $w, string $t): void
    {
        foreach ($this->demand->perCapability($t) as $row) {
            $capabilityId = $row['capabilityId'];

            $w->write($t, 'capability.coverage', $row['coverage'], $capabilityId,
                sampleN: $row['headcount']);

            // NULL when either side is unknown. "40 short" and "never measured"
            // are different claims and must not share a rendering.
            $w->write($t, 'capability.deficit', $row['deficit'], $capabilityId,
                sampleN: $row['assessedCount']);
        }
    }

    /**
     * Median decision latency per category, in hours.
     *
     * Median rather than mean: one decision left open over a holiday drags a
     * mean into meaninglessness, and latency distributions are routinely skewed
     * that way.
     */
    private function snapshotDecisionLatency(SnapshotWriter $w, string $t): void
    {
        $rows = DB::table('hpbrain_decisions as d')
            ->join('hpbrain_recommendations as r', function ($j) use ($t) {
                $j->on('r.id', '=', 'd.recommendation_id')->where('r.tenant_id', '=', $t);
            })
            ->where('d.tenant_id', $t)
            ->whereNotNull('d.created_date')
            ->whereNotNull('r.created_date')
            ->get(['r.category', 'r.created_date as from_date', 'd.created_date as to_date']);

        $byCategory = [];

        foreach ($rows as $row) {
            $from = strtotime((string) $row->from_date);
            $to = strtotime((string) $row->to_date);

            if ($from === false || $to === false) {
                continue;
            }

            $byCategory[(string) ($row->category ?? 'uncategorised')][] = ($to - $from) / 3600;
        }

        foreach ($byCategory as $category => $hours) {
            sort($hours);
            $count = count($hours);
            $middle = (int) floor($count / 2);

            $median = $count % 2 === 1
                ? $hours[$middle]
                : ($hours[$middle - 1] + $hours[$middle]) / 2;

            $w->write($t, 'decision.latencyMedianHours', round($median, 4), $category, sampleN: $count);
        }
    }
}
