<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Capability\CapabilityState;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * A small, obviously-fake dataset so the intelligence screens have something to
 * draw. NOT fixtures for a test, and NOT a substitute for real data.
 *
 * WHY THIS EXISTS. Decision Intelligence and KASBA Explorer were both correct
 * and both blank: tenant 7 holds 0 decisions, 0 recommendations and 0 capability
 * assessments, so a bar chart drew three zero-height bars and the capability
 * list rendered its empty state. Neither screen can be restyled, reviewed or
 * demonstrated against nothing.
 *
 * ─── SAFETY ────────────────────────────────────────────────────────────────
 * This database is SHARED WITH THE INSTITUTE ERP. Three rules follow from that
 * and none of them is optional:
 *
 *   1. WRITES ONLY TO hpbrain_* TABLES. Nothing here touches tbluser,
 *      hrms_departments or institute_detail. Real people and real units are
 *      READ, to attach assessments to ids that actually exist, and never
 *      written.
 *
 *   2. EVERY ROW IS MARKED. created_by / assessed_by / decided_by is
 *      self::MARKER on every row this seeder writes, so the whole dataset can
 *      be removed with one predicate per table — see purge() and the artisan
 *      command in the class footer. Demo data you cannot find again is demo
 *      data that becomes permanent by accident.
 *
 *   3. IDS ARE DETERMINISTIC. Every id is a UUID v5 derived from the tenant and
 *      a stable key, so running this twice updates in place rather than
 *      producing a second copy. This is the same reasoning
 *      ProcessLoopEvents::handleOutcomeRecorded applies to learnings: a replay
 *      must collide with itself.
 *
 * ─── USAGE ─────────────────────────────────────────────────────────────────
 *   php artisan db:seed --class=DemoTenantSeeder            # tenant 7
 *   DEMO_TENANT=3 php artisan db:seed --class=DemoTenantSeeder
 *
 * To remove everything it wrote:
 *   php artisan tinker --execute="(new Database\Seeders\DemoTenantSeeder)->purge('7');"
 */
final class DemoTenantSeeder extends Seeder
{
    /**
     * Stamped on every row. The single predicate that makes this reversible —
     * do not reuse it for anything that is not disposable.
     */
    public const MARKER = 'demo-seeder';

    /** Namespace for UUID v5 derivation. Fixed, so ids are stable across runs. */
    private const NS = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    /**
     * Recommendation categories. Chosen to be four DISTINCT values because the
     * Category × Executor table on Decision Intelligence has one row per
     * category — one category would render a single row and prove nothing about
     * the layout.
     */
    private const CATEGORIES = ['capability_gap', 'process', 'risk', 'staffing'];

    /** Executor types — the columns of that same table. */
    private const EXECUTORS = ['human', 'agent', 'system'];

    public function run(): void
    {
        $tenant = (string) (env('DEMO_TENANT') ?: '7');

        $this->command?->info("Seeding demo intelligence for tenant {$tenant}…");

        $decisions = $this->seedDecisionChain($tenant);
        $cells = $this->seedCapabilityAssessments($tenant);

        $this->command?->info("  recommendations + decisions: {$decisions}");
        $this->command?->info("  capability assessments:      {$cells}");
        $this->command?->info('  every row carries created_by/assessed_by/decided_by = '.self::MARKER);
    }

    /** Stable id for a logical row. */
    private function id(string $tenant, string $key): string
    {
        return Uuid::uuid5(self::NS, "demo:{$tenant}:{$key}")->toString();
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * Signal → ReasoningStep → Recommendation → Decision.
     *
     * The chain is built in full rather than inserting decisions alone, because
     * decisionIntelligence() derives BOTH the latency figure and the category
     * axis by joining decisions to recommendations. Orphan decisions would
     * render a pipeline and leave the other two panels empty — which is the
     * state this seeder exists to escape.
     *
     * @return int decisions written
     */
    private function seedDecisionChain(string $tenant): int
    {
        // Attach to real signals where they exist. A reasoning step with a
        // dangling signal_id would be exactly the orphan the Intelligence
        // Engine's ReferentialIntegrity analyzer is designed to complain about,
        // and seeding one to demo a screen would be self-defeating.
        $signalIds = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenant)->orderBy('created_date')->limit(8)->pluck('id')->all();

        if ($signalIds === []) {
            $this->command?->warn('  no signals for this tenant — reasoning steps will carry a null signal_id');
        }

        $now = self::now();
        $written = 0;

        // 12 recommendations across 4 categories; 9 of them decided. The
        // undecided 3 are deliberate: a pipeline with nothing pending looks
        // like a system nobody is waiting on.
        foreach (range(0, 11) as $i) {
            $category = self::CATEGORIES[$i % count(self::CATEGORIES)];
            $stepId   = $this->id($tenant, "step:{$i}");
            $recId    = $this->id($tenant, "rec:{$i}");

            // Recommendation timestamps are staggered backwards so the decision
            // that follows each one produces a non-zero, varied latency. A
            // constant offset would yield a single average and hide whether the
            // figure is being computed at all.
            $recAt = date('Y-m-d H:i:s', strtotime($now.' -'.(30 - $i).' days'));

            $this->upsert('hpbrain_reasoning_steps', $stepId, [
                'id'               => $stepId,
                'tenant_id'        => $tenant,
                'signal_id'        => $signalIds === [] ? null : $signalIds[$i % count($signalIds)],
                'step_order'       => 1,
                'description'      => "[demo] Reasoning behind recommendation {$i}",
                'confidence_score' => round(0.55 + ($i % 5) * 0.08, 4),
                'created_by'       => self::MARKER,
                'created_date'     => $recAt,
            ]);

            $this->upsert('hpbrain_recommendations', $recId, [
                'id'                => $recId,
                'tenant_id'         => $tenant,
                'reasoning_step_id' => $stepId,
                'category'          => $category,
                'title'             => '[demo] '.$this->recommendationTitle($category, $i),
                'description'       => 'Seeded by DemoTenantSeeder so the analytics screens have data to render.',
                'priority'          => ['low', 'medium', 'high', 'critical'][$i % 4],
                'confidence'        => round(0.50 + ($i % 6) * 0.07, 4),
                'impact'            => ['low', 'medium', 'high'][$i % 3],
                'status'            => $i < 9 ? 'accepted' : 'pending',
                'created_by'        => self::MARKER,
                'created_date'      => $recAt,
                'updated_date'      => $recAt,
            ]);

            if ($i >= 9) {
                continue;   // left pending on purpose
            }

            // Statuses spread across all three pipeline buckets so the bar
            // chart has three non-zero bars rather than one.
            $status = match ($i % 3) {
                0 => 'approved',
                1 => 'rejected',
                default => 'proposed',
            };

            $decidedAt = date('Y-m-d H:i:s', strtotime($recAt.' +'.(6 + $i * 3).' hours'));
            $decId = $this->id($tenant, "dec:{$i}");

            $this->upsert('hpbrain_decisions', $decId, [
                'id'                => $decId,
                'tenant_id'         => $tenant,
                'recommendation_id' => $recId,
                'decided_by'        => self::MARKER,
                'executor_type'     => self::EXECUTORS[$i % count(self::EXECUTORS)],
                'rationale'         => '[demo] Recorded by DemoTenantSeeder.',
                'status'            => $status,
                'confidence'        => round(0.60 + ($i % 4) * 0.09, 4),
                'created_date'      => $decidedAt,
            ]);

            $written++;
        }

        return $written;
    }

    private function recommendationTitle(string $category, int $i): string
    {
        return match ($category) {
            'capability_gap' => "Close the field-installation skill gap in zone {$i}",
            'process'        => "Shorten the escalation path for repeat faults ({$i})",
            'risk'           => "Mitigate single-person dependency on capability {$i}",
            default          => "Rebalance staffing against demand in unit {$i}",
        };
    }

    /**
     * CapabilityAssignment → CapabilityProficiency, which is what the heatmap
     * endpoint actually reads.
     *
     * Assignments target DEPARTMENTS rather than people. Both are supported by
     * heatmapCells(), but a Person target makes the endpoint resolve that
     * person's unit through EntityResolver against the live ERP, and a demo
     * dataset has no business depending on the shape of somebody's employee
     * record. A Department target sets departmentId directly.
     *
     * @return int proficiency rows written
     */
    private function seedCapabilityAssessments(string $tenant): int
    {
        $capabilities = DB::table('hpbrain_capabilities')
            ->where('tenant_id', $tenant)->orderBy('name')->limit(8)->pluck('id')->all();

        if ($capabilities === []) {
            $this->command?->warn('  no capabilities for this tenant — skipping KASBA seed');

            return 0;
        }

        // Real unit ids, read from wherever this tenant keeps them. Inventing
        // department ids would put the heatmap's departmentId out of step with
        // every other screen that resolves the same unit.
        $unitIds = [];

        try {
            $unit = app(\App\Domain\Universal\EntityResolver::class)->resolve($tenant, 'OrganizationUnit');
            $unitIds = DB::table($unit->table)
                ->where($unit->tenantKey, $tenant)->whereNull('deleted_at')
                ->limit(4)->pluck($unit->primaryKey)->map(fn ($v) => (string) $v)->all();
        } catch (\Throwable $e) {
            $this->command?->warn('  OrganizationUnit unmapped — assessments will carry a null department');
        }

        if ($unitIds === []) {
            $unitIds = [null];
        }

        $now = self::now();
        $written = 0;

        foreach ($capabilities as $ci => $capabilityId) {
            // Two units per capability, so a capability appears more than once
            // in the heatmap and the per-cell aggregation is actually exercised.
            foreach (array_slice($unitIds, $ci % 2, 2) as $ui => $unitId) {
                if ($unitId === null) {
                    continue;
                }

                $key = "kasba:{$ci}:{$ui}";
                $assignmentId = $this->id($tenant, $key);

                $this->upsert('hpbrain_capability_assignments', $assignmentId, [
                    'id'            => $assignmentId,
                    'tenant_id'     => $tenant,
                    'capability_id' => $capabilityId,
                    'target_type'   => 'Department',
                    'target_id'     => $unitId,
                    'assigned_by'   => self::MARKER,
                    'assigned_date' => $now,
                    'status'        => 'active',
                ]);

                // Levels spread across the 1–5 scale so levelColor() in
                // KasbaExplorer resolves to all three of its bands (crit < 2.5,
                // warn < 4, good >= 4) rather than painting every card one hue.
                $base = 1.5 + (($ci * 2 + $ui) % 8) * 0.45;

                // State is NOT uniformly 'Demonstrated'. The screen's whole
                // argument is that a level without a state lets a self-assertion
                // read as a measurement, so the demo data has to contain the
                // weak states too — including Unknown.
                $state = [
                    CapabilityState::DEMONSTRATED,
                    CapabilityState::ASSESSED,
                    CapabilityState::INFERRED,
                    CapabilityState::ASSERTED,
                    CapabilityState::UNKNOWN,
                ][($ci + $ui) % 5];

                $proficiencyId = $this->id($tenant, "prof:{$key}");

                $this->upsert('hpbrain_capability_proficiency', $proficiencyId, [
                    'id'                  => $proficiencyId,
                    'tenant_id'           => $tenant,
                    'assignment_id'       => $assignmentId,
                    'knowledge_level'     => round(min(5, $base + 0.3), 2),
                    'ability_level'       => round(min(5, $base), 2),
                    'skill_level'         => round(min(5, $base + 0.15), 2),
                    'behaviour_level'     => round(min(5, $base - 0.2), 2),
                    'attitude_level'      => round(min(5, $base + 0.45), 2),
                    'capability_state'    => $state,
                    'state_source'        => self::MARKER,
                    'evidence_confidence' => round(0.45 + (($ci + $ui) % 5) * 0.11, 4),
                    'assessed_by'         => self::MARKER,
                    'assessed_date'       => $now,
                    'created_date'        => $now,
                ]);

                $written++;
            }
        }

        return $written;
    }

    /**
     * Insert, or update in place if this seeder already wrote the row.
     *
     * Guarded on the MARKER: if an id collides with a row this seeder did NOT
     * write, it is left alone. A demo seeder must never overwrite real data,
     * and a UUID v5 collision with a real row — however unlikely — is not worth
     * the risk of finding out the hard way.
     *
     * @param  array<string, mixed>  $row
     */
    private function upsert(string $table, string $id, array $row): void
    {
        $existing = DB::table($table)->where('id', $id)->first();

        if ($existing === null) {
            DB::table($table)->insert($row);

            return;
        }

        $ownerColumn = collect(['created_by', 'assessed_by', 'assigned_by', 'decided_by'])
            ->first(fn (string $c) => property_exists($existing, $c));

        if ($ownerColumn === null || ($existing->{$ownerColumn} ?? null) !== self::MARKER) {
            return;   // not ours — leave it exactly as it is
        }

        DB::table($table)->where('id', $id)->update($row);
    }

    /**
     * Remove everything this seeder wrote for a tenant, children first.
     *
     * @return array<string, int> table => rows deleted
     */
    public function purge(string $tenant): array
    {
        $plan = [
            'hpbrain_capability_proficiency'  => 'assessed_by',
            'hpbrain_capability_assignments'  => 'assigned_by',
            'hpbrain_decisions'               => 'decided_by',
            'hpbrain_recommendations'         => 'created_by',
            'hpbrain_reasoning_steps'         => 'created_by',
        ];

        $deleted = [];

        foreach ($plan as $table => $column) {
            $deleted[$table] = DB::table($table)
                ->where('tenant_id', $tenant)
                ->where($column, self::MARKER)
                ->delete();
        }

        return $deleted;
    }
}
