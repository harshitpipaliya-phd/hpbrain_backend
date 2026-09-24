<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE INTELLIGENCE LOOP, POPULATED FOR ONE ORGANIZATION.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS FOR
 *
 * Fiber Valley has 225,103 imported operational records and 963 staff — real
 * data, already loaded — and almost nothing in the tables the intelligence loop
 * reads. Capability, decisions, executions, outcomes, learnings and knowledge
 * were all empty, so every screen downstream of them showed an explained
 * absence. The absences were honest; they were also the whole product.
 *
 * This seeds the loop ON TOP OF the real data, so the demonstrated intelligence
 * is derived from records that actually exist: signals name real departments,
 * evidence cites real record counts, and the decisions and executions follow
 * from them.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHAT IT WILL NOT DO
 *
 * NO OPERATIONAL RECORD IS WRITTEN, and no person, department or imported row
 * is created or altered. Those are the authoritative tables — the ones the
 * organization's own systems populate — and inventing rows in them would make
 * every figure on every screen a fiction rather than a demonstration. This
 * writes only to the derived layer: what the Brain concluded, never what the
 * business did.
 *
 * Every row is marked. `created_by` is the DEMO_ACTOR constant on every table
 * that has the column, so one indexed query finds everything this seeder ever
 * wrote and a later cleanup can remove exactly that.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * IDEMPOTENT BY CONSTRUCTION
 *
 * Every id is DETERMINISTIC — a namespaced hash of the tenant and a stable key,
 * never a random uuid — so re-running updates the same rows instead of adding a
 * second set. That is the difference between a seeder you can run on a live
 * demo database and one you can run once.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * TENANT ISOLATION
 *
 * The tenant is resolved from the organization register by name, and every
 * insert carries it. Nothing here can touch another organization: there is no
 * code path that writes a row without the resolved tenant id.
 *
 * Run:  php artisan db:seed --class=FiberValleyDemoSeeder
 */
final class FiberValleyDemoSeeder extends Seeder
{
    /**
     * Stamped into `created_by` on every row this seeder writes.
     *
     * The marker is deliberately a value, not a schema change: these tables
     * already carry an actor column, and adding an `is_demo` flag to fifteen
     * tables to record something one existing column can say would be a
     * migration for no gain.
     *
     * DISTINCT FROM `demo-seeder`, WHICH DemoTenantSeeder ALREADY USES. Sharing
     * that string made the two seeders' rows indistinguishable by marker, and a
     * cleanup that deleted "the demo rows" on a tenant deleted the other
     * seeder's as well. The prefix is the whole fix: a marker that identifies
     * the writer, not merely the fact that a row is demonstration data.
     */
    private const DEMO_ACTOR = 'fv-demo-seeder';

    /** Names the organization is registered under. */
    private const ORG_NAMES = ['Fiber Valley', 'FiberValley'];

    private string $tenant = '';

    /** @var array<string, string> department id => name */
    private array $units = [];

    /** @var array<string, array<string, mixed>> department id => live metrics */
    private array $metrics = [];

    private string $now = '';

    public function run(): void
    {
        $this->now = now()->format('Y-m-d H:i:s');

        if (! $this->resolveTenant()) {
            $this->command?->error('Fiber Valley is not registered in this database. Run FiberValleySeeder first.');

            return;
        }

        $this->command?->info("Fiber Valley resolved to tenant {$this->tenant}.");

        $this->loadUnits();

        if ($this->units === []) {
            $this->command?->error('Fiber Valley has no departments on its register; nothing to attribute intelligence to.');

            return;
        }

        $this->loadLiveMetrics();

        $capabilities = $this->seedCapabilities();
        $this->seedCapabilityAssignments($capabilities);
        $signals = $this->seedSignals();
        $evidence = $this->seedEvidence($signals);
        $cases = $this->seedCases($signals, $evidence);
        // ESOs first: hpbrain_recommendations.eso_id is a real foreign key, and
        // Invariant 3 has actionable recommendations naming one.
        $esos = $this->seedEsoDefinitions();
        $recommendations = $this->seedRecommendations($signals);
        $decisions = $this->seedDecisions($recommendations);
        $this->seedMeasurementPlans($decisions);
        $executions = $this->seedExecutions($decisions, $esos);
        $outcomes = $this->seedOutcomes($decisions, $executions);
        $this->seedLearnings($outcomes);
        $this->seedKnowledge($capabilities);
        $this->seedPolicies();

        $this->report();
    }

    /* ====================================================================== */
    /*  RESOLUTION                                                            */
    /* ====================================================================== */

    private function resolveTenant(): bool
    {
        if (! Schema::hasTable('institute_detail')) {
            return false;
        }

        /*
          THE NAME IS NOT THE IDENTITY.

          This register holds TWO rows named "Fiber Valley" — sub_institute_id 7
          (code FIBER-VALLEY) and 1000018 (code FV) — and only one of them is the
          tenant the departments, roster and imported records actually live
          under. Taking the first name match seeded an organization that has no
          departments, which is a silent failure: the seeder reports success and
          every screen stays empty.

          So the candidates are ranked by what they HOLD. Duplicate registrations
          are common wherever an organization has been onboarded twice, and this
          is the only rule that survives one.
        */
        $candidates = DB::table('institute_detail')
            ->whereIn('organization_name', self::ORG_NAMES)
            ->pluck('sub_institute_id')
            ->map(static fn ($v) => (string) $v)
            ->all();

        if ($candidates === []) {
            return false;
        }

        $best = null;
        $bestWeight = -1;

        foreach ($candidates as $candidate) {
            $units = DB::table('hrms_departments')
                ->where('sub_institute_id', $candidate)
                ->whereNull('deleted_at')
                ->count();

            $records = Schema::hasTable('hpbrain_operational_records')
                ? DB::table('hpbrain_operational_records')->where('tenant_id', $candidate)->count()
                : 0;

            /*
              IMPORTED RECORDS DOMINATE THE RANKING.

              Both registrations carry departments, so a department count alone
              cannot separate them — it picked the wrong one. Imported volume
              can: an organization that has connected its data has hundreds of
              thousands of records, and a stub registration has none. Departments
              break the tie where neither has imported anything yet.
            */
            $weight = ($records * 1000) + $units;

            if ($weight > $bestWeight) {
                $bestWeight = $weight;
                $best = $candidate;
            }
        }

        $this->tenant = (string) $best;

        return $this->tenant !== '';
    }

    private function loadUnits(): void
    {
        $this->units = DB::table('hrms_departments')
            ->where('sub_institute_id', $this->tenant)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('department', 'id')
            ->map(static fn ($n) => (string) $n)
            ->all();

        $this->units = array_combine(
            array_map('strval', array_keys($this->units)),
            array_values($this->units),
        );
    }

    /**
     * The real numbers each unit already has.
     *
     * The seeded intelligence CITES these. A signal that says "Sales carries
     * 95,288 records" is checkable against the operational store on the same
     * screen; one that says "Sales is busy" is decoration. Two grouped queries,
     * not one per department.
     */
    private function loadLiveMetrics(): void
    {
        $people = DB::table('tbluser')
            ->where('sub_institute_id', $this->tenant)
            ->whereNull('deleted_at')
            ->whereNotNull('department_id')
            ->groupBy('department_id')
            ->selectRaw('department_id, COUNT(*) AS n')
            ->pluck('n', 'department_id')
            ->all();

        $work = [];

        if (Schema::hasTable('hpbrain_operational_records') && Schema::hasColumn('hpbrain_operational_records', 'department_label')) {
            $work = DB::table('hpbrain_operational_records')
                ->where('tenant_id', $this->tenant)
                ->whereNotNull('department_label')
                ->groupBy('department_label')
                ->selectRaw('department_label, COUNT(*) AS n')
                ->pluck('n', 'department_label')
                ->all();
        }

        foreach ($this->units as $id => $name) {
            $this->metrics[$id] = [
                'name' => $name,
                'people' => (int) ($people[$id] ?? 0),
                // Matched on the unit's own name, exactly as the aggregate does.
                'records' => (int) ($work[$name] ?? 0),
            ];
        }
    }

    /* ====================================================================== */
    /*  DETERMINISTIC IDS                                                     */
    /* ====================================================================== */

    /**
     * A stable id for a logical row.
     *
     * Shaped like a uuid because every id column here is VARCHAR(36) and the
     * rest of the system reads them as uuids, but derived from the tenant and a
     * caller-supplied key so the same logical row always lands on the same id.
     * That is what makes re-running this an update rather than a duplicate.
     */
    private function id(string $kind, string $key): string
    {
        $hash = substr(hash('sha256', $this->tenant.'|'.$kind.'|'.$key), 0, 32);

        return implode('-', [
            substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4),
            substr($hash, 16, 4), substr($hash, 20, 12),
        ]);
    }

    /**
     * Insert or update by primary key, scoped to this tenant.
     *
     * @param  array<string, mixed>  $row
     */
    private function put(string $table, array $row): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $columns = Schema::getColumnListing($table);
        $row = array_intersect_key($row, array_flip($columns));

        $exists = DB::table($table)
            ->where('id', $row['id'])
            ->where('tenant_id', $this->tenant)
            ->exists();

        if ($exists) {
            // The id and tenant are the identity; never rewrite them.
            DB::table($table)
                ->where('id', $row['id'])
                ->where('tenant_id', $this->tenant)
                ->update(array_diff_key($row, array_flip(['id', 'tenant_id', 'created_date'])));

            return;
        }

        DB::table($table)->insert($row);
    }

    /** A date `$daysAgo` before now, for spreading records across a timeline. */
    private function ago(int $daysAgo, int $hour = 9): string
    {
        return now()->subDays($daysAgo)->setTime($hour, ($daysAgo * 7) % 60)->format('Y-m-d H:i:s');
    }

    /**
     * The units this organization actually staffs, largest first.
     *
     * Intelligence is attached to the units a reader will open. Seeding a
     * signal against a unit with nobody in it would put the demonstration on
     * the page least likely to be looked at.
     *
     * @return array<int, string> department ids
     */
    private function staffedUnits(?int $limit = null): array
    {
        $rows = $this->metrics;
        uasort($rows, static fn ($a, $b) => ($b['people'] + $b['records']) <=> ($a['people'] + $a['records']));

        // array_keys returns ints for numeric keys, and every id column here is
        // a string. Casting once at the source keeps the rest honest.
        $ordered = array_map('strval', array_keys($rows));

        return $limit === null ? $ordered : array_slice($ordered, 0, $limit);
    }

    /* ====================================================================== */
    /*  CAPABILITIES                                                          */
    /* ====================================================================== */

    /**
     * A capability taxonomy for a field-services operator.
     *
     * Generic on purpose: these are the capabilities any organization running
     * field work and a service desk needs, so the taxonomy reads as a real one
     * rather than as a list invented to fill a screen.
     *
     * @return array<int, array<string, mixed>>
     */
    private function seedCapabilities(): array
    {
        $catalogue = [
            ['Network Operations', 'technical', 'high', 4, 'Planning, monitoring and restoring the physical and logical network.'],
            ['Field Coordination', 'operational', 'high', 3, 'Dispatching, sequencing and closing field work against commitments.'],
            ['Incident Management', 'operational', 'critical', 4, 'Detecting, triaging and resolving service-affecting incidents.'],
            ['Customer Service', 'service', 'high', 2, 'Handling customer contact and resolving issues at first line.'],
            ['Technical Support', 'technical', 'high', 3, 'Diagnosing faults reported by customers and field teams.'],
            ['Operational Planning', 'management', 'high', 3, 'Forecasting demand and allocating capacity across units.'],
            ['Quality Management', 'management', 'medium', 3, 'Verifying that completed work meets the recorded standard.'],
            ['Data Analysis', 'analytical', 'medium', 3, 'Turning operational records into decisions.'],
            ['Team Leadership', 'management', 'high', 3, 'Directing a unit, its workload and its development.'],
            ['Workforce Planning', 'management', 'medium', 4, 'Matching staffing to committed and forecast work.'],
        ];

        $out = [];

        foreach ($catalogue as $i => [$name, $category, $criticality, $difficulty, $description]) {
            $id = $this->id('capability', $name);

            $this->put('hpbrain_capabilities', [
                'id' => $id,
                'tenant_id' => $this->tenant,
                'org_id' => $this->tenant,
                'org_unit_id' => null,
                'capability_code' => 'CAP-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => $name,
                'description' => $description,
                'category' => $category,
                'capability_type' => 'organizational',
                'difficulty' => $difficulty,
                'criticality' => $criticality,
                'version' => 1,
                'status' => 'active',
                // The five KASBA blocks. Constrained to valid JSON by the
                // schema, and read by the KASBA explorer — an empty {} would
                // satisfy the constraint and leave that screen blank.
                'knowledge' => json_encode(['descriptors' => ["What {$name} requires the unit to know."]]),
                'ability' => json_encode(['descriptors' => ["What {$name} requires the unit to be able to do."]]),
                'skill' => json_encode(['descriptors' => ["The practised technique {$name} depends on."]]),
                'behaviour' => json_encode(['descriptors' => ["How {$name} shows up in day-to-day work."]]),
                'attitude' => json_encode(['descriptors' => ["The disposition {$name} depends on."]]),
                'created_by' => self::DEMO_ACTOR,
                'created_date' => $this->ago(180 - $i),
                'updated_date' => $this->now,
            ]);

            $out[] = ['id' => $id, 'name' => $name, 'index' => $i];
        }

        return $out;
    }

    /**
     * Capabilities assigned to departments, and assessed at varying levels.
     *
     * THE VARIATION IS THE POINT. A demonstration where every unit assesses at
     * the same level proves nothing about the model — the screen exists to show
     * which units are strong and which have gaps, and it can only do that if the
     * seeded assessments actually differ. The spread is deterministic, derived
     * from the unit and capability, so it is stable across runs rather than
     * random noise that changes every time the demo is shown.
     *
     * @param  array<int, array<string, mixed>>  $capabilities
     */
    private function seedCapabilityAssignments(array $capabilities): void
    {
        $units = $this->staffedUnits();

        foreach ($units as $u => $unitId) {
            // Each unit carries a different slice of the taxonomy: a service
            // desk and a splicing crew do not need the same capabilities.
            $slice = array_slice($capabilities, ($u * 2) % 6, 5);

            foreach ($slice as $c) {
                $assignmentId = $this->id('assignment', $unitId.'|'.$c['name']);

                $this->put('hpbrain_capability_assignments', [
                    'id' => $assignmentId,
                    'tenant_id' => $this->tenant,
                    'capability_id' => $c['id'],
                    'target_type' => 'Department',
                    'target_id' => $unitId,
                    'assigned_by' => self::DEMO_ACTOR,
                    'assigned_date' => $this->ago(120 - $u),
                    'status' => 'active',
                ]);

                // A repeatable 1-5 spread that differs by unit AND capability,
                // so no two units read alike and no unit is uniformly strong.
                $seed = crc32($unitId.$c['name']);
                $base = 2 + ($seed % 3);
                $level = static fn (int $offset): int => max(1, min(5, $base + (($seed >> ($offset * 3)) % 3) - 1));

                $this->put('hpbrain_capability_proficiency', [
                    'id' => $this->id('proficiency', $assignmentId),
                    'tenant_id' => $this->tenant,
                    'assignment_id' => $assignmentId,
                    'knowledge_level' => $level(1),
                    'ability_level' => $level(2),
                    'skill_level' => $level(3),
                    'behaviour_level' => $level(4),
                    'attitude_level' => $level(5),
                    'capability_state' => $base >= 4 ? 'proven' : ($base >= 3 ? 'developing' : 'claimed'),
                    'evidence_confidence' => round(0.55 + (($seed % 40) / 100), 2),
                    'assessed_by' => self::DEMO_ACTOR,
                    'assessed_date' => $this->ago(max(1, 60 - $u)),
                    'created_date' => $this->ago(max(1, 60 - $u)),
                ]);
            }

            $this->assessPeople($unitId, $slice, $u);
        }
    }

    /**
     * Capability assessed against actual people in the unit.
     *
     * WHY BOTH ROUTES. An assignment can target a Department or a Person, and
     * the metrics service counts them separately: the department route says the
     * capability is EXPECTED here, the person route says somebody has been
     * ASSESSED against it. Coverage — the share of the team assessed — needs the
     * second, so seeding only the first left capability coverage at zero and the
     * heatmap empty.
     *
     * Bounded to a sample per unit rather than the whole roster. Assessing all
     * 768 people in one unit would be both slow and untrue to how organizations
     * actually assess.
     *
     * @param  array<int, array<string, mixed>>  $capabilities
     */
    private function assessPeople(string $unitId, array $capabilities, int $u): void
    {
        $people = DB::table('tbluser')
            ->where('sub_institute_id', $this->tenant)
            ->where('department_id', $unitId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(6)
            ->pluck('id')
            ->all();

        foreach ($people as $i => $personId) {
            // A different capability per person, so the unit reads as a team
            // with a spread of strengths rather than one repeated assessment.
            $c = $capabilities[$i % max(1, count($capabilities))];
            $assignmentId = $this->id('assignment', 'person|'.$personId.'|'.$c['name']);

            $this->put('hpbrain_capability_assignments', [
                'id' => $assignmentId,
                'tenant_id' => $this->tenant,
                'capability_id' => $c['id'],
                'target_type' => 'Person',
                'target_id' => (string) $personId,
                'assigned_by' => self::DEMO_ACTOR,
                'assigned_date' => $this->ago(max(1, 100 - $u)),
                'status' => 'active',
            ]);

            $seed = crc32((string) $personId.$c['name']);
            $base = 2 + ($seed % 4);
            $level = static fn (int $offset): int => max(1, min(5, $base + (($seed >> ($offset * 3)) % 3) - 1));

            $this->put('hpbrain_capability_proficiency', [
                'id' => $this->id('proficiency', $assignmentId),
                'tenant_id' => $this->tenant,
                'assignment_id' => $assignmentId,
                'knowledge_level' => $level(1),
                'ability_level' => $level(2),
                'skill_level' => $level(3),
                'behaviour_level' => $level(4),
                'attitude_level' => $level(5),
                'capability_state' => $base >= 4 ? 'proven' : ($base >= 3 ? 'developing' : 'claimed'),
                'evidence_confidence' => round(0.55 + (($seed % 40) / 100), 2),
                'assessed_by' => self::DEMO_ACTOR,
                'assessed_date' => $this->ago(max(1, 45 - $u)),
                'created_date' => $this->ago(max(1, 45 - $u)),
            ]);
        }
    }

    /* ====================================================================== */
    /*  SIGNALS                                                               */
    /* ====================================================================== */

    /**
     * Signals about conditions this organization's own data exhibits.
     *
     * EACH ONE NAMES A DEPARTMENT. The department screens report signal health
     * as unmeasurable when signals carry no `department_id`, which is exactly
     * what the five pre-existing rows do — so seeding attributed signals is what
     * turns that dimension on across the whole organization.
     *
     * @return array<int, array<string, mixed>>
     */
    private function seedSignals(): array
    {
        $units = $this->staffedUnits();
        $out = [];

        $templates = [
            ['workload_concentration', 'high', 'critical', 'Workload concentrated in a single unit'],
            ['service_response_degradation', 'high', 'high', 'Service response time degrading'],
            ['capability_gap', 'medium', 'high', 'Capability gap against assigned work'],
            ['recurring_operational_issue', 'medium', 'medium', 'Recurring operational issue detected'],
            ['capacity_imbalance', 'medium', 'high', 'Capacity imbalance across peer units'],
            ['record_quality', 'low', 'medium', 'Incomplete records limiting measurement'],
            ['activity_increase', 'low', 'medium', 'Unusual increase in recorded activity'],
            ['inactive_assignment', 'medium', 'medium', 'Assigned staff with no recorded activity'],
        ];

        foreach ($units as $i => $unitId) {
            $m = $this->metrics[$unitId];
            [$rule, $priority, $severity, $title] = $templates[$i % count($templates)];

            // Two signals per unit, at different ages and states, so the queues
            // downstream have something to be open and something to be closed.
            foreach ([0, 1] as $variant) {
                $key = $unitId.'|'.$rule.'|'.$variant;
                $id = $this->id('signal', $key);
                $age = 3 + ($i * 4) + ($variant * 21);

                $status = $variant === 0
                    ? ($i % 3 === 0 ? 'investigating' : 'new')
                    : 'resolved';

                $this->put('hpbrain_signals', [
                    'id' => $id,
                    'tenant_id' => $this->tenant,
                    'dedupe_key' => substr(hash('sha256', $key), 0, 64),
                    'org_id' => $this->tenant,
                    'source' => 'operational-records',
                    'classification' => $rule,
                    'rule_key' => $rule,
                    'priority' => $priority,
                    'severity' => $variant === 0 ? $severity : 'low',
                    'confidence' => round(0.62 + ((crc32($key) % 30) / 100), 2),
                    'related_entity_type' => 'Department',
                    'related_entity_id' => $unitId,
                    'department_id' => $unitId,
                    'status' => $status,
                    'metadata' => json_encode([
                        'title' => $title.' — '.$m['name'],
                        'description' => $this->signalDescription($rule, $m),
                        'department' => $m['name'],
                        'observedPeople' => $m['people'],
                        'observedRecords' => $m['records'],
                        'recommendedAction' => $this->signalAction($rule, $m),
                        'demo' => true,
                    ]),
                    'created_by' => self::DEMO_ACTOR,
                    'created_date' => $this->ago($age),
                    'updated_date' => $this->ago(max(1, $age - 2)),
                ]);

                $out[] = ['id' => $id, 'unit' => $unitId, 'rule' => $rule, 'status' => $status, 'metrics' => $m, 'age' => $age];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $m */
    private function signalDescription(string $rule, array $m): string
    {
        $people = number_format((int) $m['people']);
        $records = number_format((int) $m['records']);
        $name = $m['name'];

        return match ($rule) {
            'workload_concentration' => "{$name} carries {$records} imported operational records against {$people} recorded staff, a concentration well above the organization's median unit.",
            'service_response_degradation' => "Closure times on {$name} work have lengthened across recent periods while intake has held steady.",
            'capability_gap' => "{$name} holds assessed capability below the level its assigned work implies, with no proven assessment on at least one critical capability.",
            'recurring_operational_issue' => "A repeating category accounts for a disproportionate share of {$name}'s {$records} records, suggesting a cause that is not being addressed.",
            'capacity_imbalance' => "{$name} runs at a materially different records-per-person load than its peer units on the same register.",
            'record_quality' => "A material share of {$name}'s roster is missing at least one core field, which limits every measure that depends on it.",
            'activity_increase' => "Recorded activity on {$name} has risen against the previous comparable period without a matching change in staffing.",
            default => "{$people} staff are assigned to {$name} with no recorded activity attributed to them in the current window.",
        };
    }

    /** @param array<string, mixed> $m */
    private function signalAction(string $rule, array $m): string
    {
        return match ($rule) {
            'workload_concentration' => 'Review workload distribution against peer units and rebalance the highest-volume category.',
            'service_response_degradation' => 'Sample the slowest-closing records and identify the step where time is being lost.',
            'capability_gap' => 'Assess the unit against its critical capabilities and schedule development where the gap is proven.',
            'recurring_operational_issue' => 'Group the recurring category by root cause and address the largest contributor.',
            'capacity_imbalance' => 'Compare records-per-person across peer units and move committed work to the lighter unit.',
            'record_quality' => 'Complete the missing roster fields so dependent measures become available.',
            'activity_increase' => 'Confirm whether the increase is demand or duplication before adding capacity.',
            default => 'Confirm whether the assignment is current, or retire it.',
        };
    }

    /* ====================================================================== */
    /*  EVIDENCE                                                              */
    /* ====================================================================== */

    /**
     * Evidence supporting each signal, citing figures that are checkable.
     *
     * Every item points at a real count from the operational store or the
     * roster, so a reader who distrusts a signal can verify it on the same
     * screen. That traceability is the difference between evidence and a label.
     *
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<int, array<string, mixed>>
     */
    private function seedEvidence(array $signals): array
    {
        $out = [];

        foreach ($signals as $s) {
            $m = $s['metrics'];

            $items = [
                ['operational-records', 'aggregate', "{$m['name']} is named by ".number_format((int) $m['records'])." imported operational records for this organization."],
                ['hr-roster', 'roster', number_format((int) $m['people'])." staff are assigned to {$m['name']} on the source register."],
                ['derived-metric', 'calculation', 'Records per person for this unit is '.($m['people'] > 0 ? round($m['records'] / max(1, $m['people']), 1) : 'not computable without a headcount').'.'],
            ];

            foreach ($items as $i => [$source, $type, $content]) {
                $id = $this->id('evidence', $s['id'].'|'.$i);

                $this->put('hpbrain_evidence', [
                    'id' => $id,
                    'tenant_id' => $this->tenant,
                    'signal_id' => $s['id'],
                    'source' => $source,
                    'evidence_type' => $type,
                    // The column carries a json_valid CHECK, so the sentence is
                    // wrapped rather than stored bare. `statement` is the field
                    // the evidence readers already display.
                    'content' => json_encode(['statement' => $content, 'department' => $m['name']]),
                    'provenance' => json_encode([
                        'derivedFrom' => $source,
                        'department' => $m['name'],
                        'method' => 'SQL aggregation over this organization\'s own records',
                        'demo' => true,
                    ]),
                    'confidence' => round(0.70 + ((crc32($id) % 25) / 100), 2),
                    'hash' => substr(hash('sha256', $content), 0, 64),
                    'version' => 1,
                    'status' => 'grounded',
                    'created_by' => self::DEMO_ACTOR,
                    'created_date' => $this->ago(max(1, (int) $s['age'] - 1)),
                    'observed_date' => $this->ago((int) $s['age']),
                ]);

                $out[] = ['id' => $id, 'signal' => $s['id']];
            }
        }

        return $out;
    }

    /* ====================================================================== */
    /*  CASES                                                                 */
    /* ====================================================================== */

    /**
     * Investigations over the signals worth investigating.
     *
     * Opened only for signals that are not already resolved: a case against a
     * closed finding is the kind of detail that makes a demonstration read as
     * generated rather than observed.
     *
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<int, array<string, mixed>>  $evidence
     * @return array<int, array<string, mixed>>
     */
    private function seedCases(array $signals, array $evidence): array
    {
        $byStatus = ['investigating', 'recommended', 'approved', 'resolved'];
        $out = [];
        $n = 0;

        foreach ($signals as $s) {
            if ($s['status'] === 'resolved') {
                continue;
            }

            $m = $s['metrics'];
            $id = $this->id('case', $s['id']);
            $status = $byStatus[$n % count($byStatus)];

            $this->put('hpbrain_cases', [
                'id' => $id,
                'tenant_id' => $this->tenant,
                'signal_id' => $s['id'],
                'title' => $this->caseTitle($s['rule'], $m),
                'description' => $this->signalDescription($s['rule'], $m)
                    .' Investigating whether this is a distribution problem, a capacity problem, or a recording problem, because the remedy differs for each.',
                'status' => $status,
                'created_by' => self::DEMO_ACTOR,
                'created_date' => $this->ago(max(1, (int) $s['age'] - 2)),
                'updated_date' => $this->now,
            ]);

            // The join rows that make the case traceable to what opened it.
            $this->link('hpbrain_case_signals', [
                'tenant_id' => $this->tenant, 'case_id' => $id, 'signal_id' => $s['id'],
                'role' => 'primary', 'linked_by' => self::DEMO_ACTOR, 'linked_date' => $this->now,
            ], ['case_id' => $id, 'signal_id' => $s['id']]);

            foreach ($evidence as $e) {
                if ($e['signal'] !== $s['id']) {
                    continue;
                }

                $this->link('hpbrain_case_evidence', [
                    'tenant_id' => $this->tenant, 'case_id' => $id, 'evidence_id' => $e['id'], 'linked_date' => $this->now,
                ], ['case_id' => $id, 'evidence_id' => $e['id']]);
            }

            $out[] = ['id' => $id, 'signal' => $s['id'], 'status' => $status, 'metrics' => $m, 'rule' => $s['rule'], 'age' => $s['age']];
            $n++;
        }

        return $out;
    }

    /** @param array<string, mixed> $m */
    private function caseTitle(string $rule, array $m): string
    {
        return match ($rule) {
            'workload_concentration' => "{$m['name']} workload concentration requires a capacity review",
            'service_response_degradation' => "Closure times on {$m['name']} are lengthening",
            'capability_gap' => "{$m['name']} capability does not cover its assigned work",
            'recurring_operational_issue' => "A recurring category dominates {$m['name']} volume",
            'capacity_imbalance' => "{$m['name']} load differs materially from its peers",
            'record_quality' => "Incomplete records on {$m['name']} limit measurement",
            'activity_increase' => "Activity on {$m['name']} rose without a staffing change",
            default => "Assigned staff on {$m['name']} show no recorded activity",
        };
    }

    /**
     * A join row with no surrogate key, matched on its natural key.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $key
     */
    private function link(string $table, array $row, array $key): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $exists = DB::table($table)->where('tenant_id', $this->tenant)->where($key)->exists();

        if (! $exists) {
            DB::table($table)->insert($row);
        }
    }

    /* ====================================================================== */
    /*  RECOMMENDATIONS → DECISIONS → EXECUTIONS → OUTCOMES → LEARNINGS       */
    /* ====================================================================== */

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<int, array<string, mixed>>
     */
    private function seedRecommendations(array $signals): array
    {
        $out = [];
        $n = 0;

        foreach ($signals as $s) {
            if ($s['status'] === 'resolved') {
                continue;
            }

            $m = $s['metrics'];
            $id = $this->id('recommendation', $s['id']);

            $this->put('hpbrain_recommendations', [
                'id' => $id,
                'tenant_id' => $this->tenant,
                'category' => $n % 3 === 0 ? 'intervene' : 'monitor',
                'title' => $this->signalAction($s['rule'], $m),
                'description' => 'Derived from '.$m['name'].', which carries '.number_format((int) $m['records']).' records against '.number_format((int) $m['people']).' staff.',
                'priority' => $n % 3 === 0 ? 'high' : 'medium',
                'confidence' => round(0.6 + ((crc32($id) % 30) / 100), 2),
                'impact' => $n % 3 === 0 ? 'high' : 'medium',
                'cost' => 'low',
                'risk' => 'low',
                'dependencies' => json_encode([]),
                'status' => 'open',
                'urgency' => $n % 4 === 0 ? 'high' : 'normal',
                // Invariant 3: an actionable category must name something
                // runnable. The ESO ids are seeded below and are deterministic,
                // so this binding resolves.
                'eso_id' => $n % 3 === 0 ? $this->id('eso', 'workload-rebalance') : null,
                'created_by' => self::DEMO_ACTOR,
                'created_date' => $this->ago(max(1, (int) $s['age'] - 2)),
                'updated_date' => $this->now,
            ]);

            $out[] = ['id' => $id, 'signal' => $s['id'], 'metrics' => $m, 'age' => $s['age'], 'index' => $n];
            $n++;
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $recommendations
     * @return array<int, array<string, mixed>>
     */
    private function seedDecisions(array $recommendations): array
    {
        $out = [];
        $states = ['approved', 'approved', 'pending', 'approved', 'rejected'];

        foreach ($recommendations as $r) {
            $m = $r['metrics'];
            $id = $this->id('decision', $r['id']);
            $status = $states[$r['index'] % count($states)];

            $this->put('hpbrain_decisions', [
                'id' => $id,
                'tenant_id' => $this->tenant,
                'recommendation_id' => $r['id'],
                'decided_by' => self::DEMO_ACTOR,
                'executor_type' => 'human',
                'rationale' => "Evidence shows {$m['name']} at ".number_format((int) $m['records'])
                    ." records against ".number_format((int) $m['people'])
                    .' staff. Acting on the largest contributing category is the change with the clearest measurable effect.',
                'alternatives_considered' => json_encode([
                    'Add headcount to the unit — rejected: the imbalance is in distribution, not total capacity.',
                    'Take no action and re-measure next period — held as the comparison case.',
                ]),
                'status' => $status,
                'confidence' => round(0.65 + ((crc32($id) % 25) / 100), 2),
                'explanation' => 'Chosen because it is reversible, measurable within one period, and does not require new staff.',
                'approved_by' => $status === 'approved' ? self::DEMO_ACTOR : null,
                'approved_date' => $status === 'approved' ? $this->ago(max(1, (int) $r['age'] - 3)) : null,
                'created_date' => $this->ago(max(2, (int) $r['age'] - 3)),
            ]);

            $out[] = ['id' => $id, 'status' => $status, 'metrics' => $m, 'age' => $r['age'], 'index' => $r['index']];
        }

        return $out;
    }

    /**
     * Invariant 4: an execution needs a plan that PRE-DATES it.
     *
     * @param  array<int, array<string, mixed>>  $decisions
     */
    private function seedMeasurementPlans(array $decisions): void
    {
        foreach ($decisions as $d) {
            if ($d['status'] !== 'approved') {
                continue;
            }

            $this->put('hpbrain_measurement_plans', [
                'id' => $this->id('plan', $d['id']),
                'tenant_id' => $this->tenant,
                'decision_id' => $d['id'],
                'baseline_metric' => 'records_per_person',
                'baseline_value' => $d['metrics']['people'] > 0
                    ? round($d['metrics']['records'] / max(1, $d['metrics']['people']), 2)
                    : 0,
                'target_value' => $d['metrics']['people'] > 0
                    ? round(($d['metrics']['records'] / max(1, $d['metrics']['people'])) * 0.85, 2)
                    : 0,
                'metric_unit' => 'records/person',
                'measurement_window_days' => 30,
                'owner_id' => self::DEMO_ACTOR,
                'created_by' => self::DEMO_ACTOR,
                // Deliberately older than the execution it authorises.
                'created_date' => $this->ago(max(3, (int) $d['age'] - 4)),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function seedEsoDefinitions(): array
    {
        $definitions = [
            ['workload-rebalance', 'Rebalance unit workload', 'PERFORM', 'Move committed work from an overloaded unit to a peer with capacity.'],
            ['capability-assessment', 'Assess unit capability', 'ASSESS', 'Assess a unit against its critical capabilities and record the result.'],
            ['record-completion', 'Complete roster records', 'PERFORM', 'Fill the missing roster fields that block dependent measures.'],
            ['recurrence-review', 'Review recurring category', 'DECIDE', 'Group a recurring category by root cause and decide the largest fix.'],
        ];

        $out = [];

        foreach ($definitions as $i => [$code, $name, $objective, $description]) {
            $id = $this->id('eso', $code);

            $this->put('hpbrain_eso_definitions', [
                'id' => $id,
                'tenant_id' => $this->tenant,
                'org_id' => $this->tenant,
                'eso_code' => strtoupper(str_replace('-', '_', $code)),
                'name' => $name,
                'version' => 1,
                'status' => 'published',
                'owner' => self::DEMO_ACTOR,
                'provenance' => 'authored',
                'kasba_node_type' => 'Capability',
                'is_cognitive_primitive' => false,
                'trigger_description' => $description,
                'applicable_contexts' => json_encode(['field-operations', 'service-desk']),
                'gap_types' => json_encode([$i === 1 ? 'Capability' : 'Capacity']),
                'objective' => $objective,
                'inputs' => json_encode([['name' => 'departmentId', 'type' => 'string']]),
                'outputs' => json_encode([['name' => 'outcome', 'type' => 'string']]),
                'procedure_steps' => json_encode([
                    ['stepId' => 's1', 'order' => 1, 'executorClass' => 'human', 'method' => 'Review the unit\'s current distribution.', 'expectedArtifact' => 'A written review.'],
                    ['stepId' => 's2', 'order' => 2, 'executorClass' => 'human', 'method' => 'Apply the change and record it.', 'expectedArtifact' => 'A change record.'],
                ]),
                'allowed_executor_classes' => json_encode(['human']),
                'trust_level' => 'approve',
                'gotchas' => json_encode([[
                    'gotchaId' => 'g1', 'kind' => 'failure-mode',
                    'description' => 'Rebalancing without checking capability moves work to a unit that cannot do it.',
                ]]),
                'assessment' => json_encode(['evaluator' => 'human', 'masteryThreshold' => 0.8]),
                'evidence_hooks' => json_encode(['mustLog' => ['executor', 'context', 'artifacts', 'duration']]),
                'created_by' => self::DEMO_ACTOR,
                'created_date' => $this->ago(150 - $i),
                'updated_date' => $this->now,
            ]);

            $out[] = $id;
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $decisions
     * @param  array<int, string>  $esos
     * @return array<int, array<string, mixed>>
     */
    private function seedExecutions(array $decisions, array $esos): array
    {
        $out = [];
        $states = ['completed', 'completed', 'running', 'completed', 'failed'];

        foreach ($decisions as $d) {
            if ($d['status'] !== 'approved' || $esos === []) {
                continue;
            }

            $id = $this->id('execution', $d['id']);
            $status = $states[$d['index'] % count($states)];
            $eso = $esos[$d['index'] % count($esos)];

            $this->put('hpbrain_eso_executions', [
                'id' => $id,
                'tenant_id' => $this->tenant,
                'eso_id' => $eso,
                'eso_definition_id' => $eso,
                'decision_id' => $d['id'],
                'status' => $status,
                'executed_by' => self::DEMO_ACTOR,
                'executor_type' => 'human',
                'input' => json_encode([
                    'departmentId' => null,
                    'baselineMetric' => 'records_per_person',
                    'measurementWindowDays' => 30,
                ]),
                // json_valid CHECK: an empty object, never null.
                'output' => $status === 'completed'
                    ? json_encode(['result' => 'applied', 'note' => 'Change applied and recorded against the measurement plan.'])
                    : json_encode([]),
                'error' => $status === 'failed' ? 'Blocked: the receiving unit had no assessed capability for the moved work.' : null,
                'started_date' => $this->ago(max(1, (int) $d['age'] - 5)),
                'completed_date' => in_array($status, ['completed', 'failed'], true) ? $this->ago(max(1, (int) $d['age'] - 6)) : null,
                'created_date' => $this->ago(max(1, (int) $d['age'] - 5)),
            ]);

            $out[] = ['id' => $id, 'decision' => $d['id'], 'status' => $status, 'metrics' => $d['metrics'], 'age' => $d['age'], 'index' => $d['index']];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $decisions
     * @param  array<int, array<string, mixed>>  $executions
     * @return array<int, array<string, mixed>>
     */
    private function seedOutcomes(array $decisions, array $executions): array
    {
        $out = [];

        foreach ($executions as $e) {
            if ($e['status'] !== 'completed') {
                continue;
            }

            $m = $e['metrics'];
            $before = $m['people'] > 0 ? round($m['records'] / max(1, $m['people']), 2) : 0;
            $after = round($before * 0.86, 2);
            $id = $this->id('outcome', $e['id']);

            $this->put('hpbrain_outcomes', [
                'id' => $id,
                'tenant_id' => $this->tenant,
                'decision_id' => $e['decision'],
                'result' => 'improved',
                'metrics' => json_encode([
                    'baseline' => $before,
                    'observed' => $after,
                    'unit' => 'records/person',
                    'changePercent' => $before > 0 ? round((($after - $before) / $before) * 100, 1) : 0,
                ]),
                'kpis' => json_encode(['records_per_person' => $after]),
                'evidence_ids' => json_encode([]),
                'feedback' => "Load on {$m['name']} fell against the plan's baseline within the measurement window.",
                'confidence' => 0.78,
                'created_by' => self::DEMO_ACTOR,
                'created_date' => $this->ago(max(1, (int) $e['age'] - 7)),
            ]);

            $out[] = ['id' => $id, 'metrics' => $m, 'age' => $e['age'], 'index' => $e['index']];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $outcomes
     */
    private function seedLearnings(array $outcomes): void
    {
        foreach ($outcomes as $o) {
            $m = $o['metrics'];

            $this->put('hpbrain_learnings', [
                'id' => $this->id('learning', $o['id']),
                'tenant_id' => $this->tenant,
                'outcome_id' => $o['id'],
                'pattern' => 'workload-redistribution-improves-load',
                'description' => "Redistributing the highest-volume category away from {$m['name']} reduced records per person without adding staff. "
                    .'The same pattern is worth trying wherever a unit\'s load is materially above its peers.',
                'domain' => 'operations',
                'confidence' => 0.74,
                'reusable' => 1,
                'created_by' => self::DEMO_ACTOR,
                'created_date' => $this->ago(max(1, (int) $o['age'] - 8)),
            ]);
        }
    }

    /* ====================================================================== */
    /*  KNOWLEDGE AND AUTOMATION                                              */
    /* ====================================================================== */

    /**
     * @param  array<int, array<string, mixed>>  $capabilities
     */
    private function seedKnowledge(array $capabilities): void
    {
        $units = $this->staffedUnits(6);
        $capIds = array_column($capabilities, 'id');

        $articles = [
            ['Workload concentration pattern', 'pattern', 'A unit whose records-per-person materially exceeds its peers is usually carrying a category that belongs elsewhere, not working slower. Check distribution before capacity.'],
            ['Reading the split register', 'reference', 'This ERP carries two rows per unit — one the workforce is assigned to, one the work is booked against. Measures that look empty on the staffed row are usually recorded on its sibling.'],
            ['What makes a capability measurable', 'reference', 'A capability becomes measurable when it is assigned to a unit and at least one assessment is recorded against it. Assignment alone leaves coverage undefined.'],
            ['Turnaround as a service measure', 'method', 'Turnaround is measured only over records carrying both an opened and a closed timestamp. Where too few do, the measure is withheld rather than estimated.'],
            ['Rebalancing without capability checks', 'lesson', 'Moving work to a unit with capacity but no assessed capability produced a failed execution. Check the receiving unit\'s assessment first.'],
            ['Evidence before conclusion', 'principle', 'Every published figure traces to a count over imported records. A conclusion with no evidence row behind it is an assertion.'],
        ];

        foreach ($articles as $i => [$title, $category, $content]) {
            $this->put('hpbrain_knowledge_assets', [
                'id' => $this->id('knowledge', $title),
                'tenant_id' => $this->tenant,
                'title' => $title,
                'category' => $category,
                'content' => $content,
                'tags' => json_encode(['operations', 'intelligence', $category]),
                'confidence' => round(0.7 + ($i * 0.03), 2),
                'department_id' => $units[$i % max(1, count($units))] ?? null,
                'related_capability_ids' => json_encode(array_slice($capIds, $i % 4, 2)),
                'related_person_ids' => json_encode([]),
                'reuse_count' => 1 + ($i * 2),
                'status' => 'published',
                'created_by' => self::DEMO_ACTOR,
                'created_date' => $this->ago(90 - ($i * 6)),
                'updated_date' => $this->now,
            ]);
        }
    }

    private function seedPolicies(): void
    {
        $policies = [
            ['Field work autonomy', 'active', 'approve', 'Field execution may be proposed automatically but requires human approval before it runs.'],
            ['Capability gate on redistribution', 'active', 'approve', 'Work may not be redistributed to a unit with no assessed capability for it.'],
            ['Measurement before execution', 'active', 'suggest', 'No execution starts without a measurement plan that pre-dates it.'],
            ['Draft: autonomous triage', 'draft', 'observe', 'Automatic triage of low-severity signals, pending review.'],
        ];

        foreach ($policies as $i => [$name, $status, $trust, $description]) {
            $this->put('hpbrain_policies', [
                'id' => $this->id('policy', $name),
                'tenant_id' => $this->tenant,
                'name' => $name,
                'scope' => 'organization',
                'allowed_executor_classes' => json_encode(['human']),
                'trust_levels' => json_encode([['executorRef' => 'human', 'trustLevel' => $trust]]),
                'routing_criteria' => json_encode(['severity' => ['high', 'critical']]),
                'escalation_path' => json_encode(['unit-lead', 'operations-manager']),
                'status' => $status,
                'policy_type' => 'execution',
                'rules' => json_encode([['condition' => 'severity >= high', 'action' => 'require_approval', 'description' => $description]]),
                'version' => 1,
                'created_by' => self::DEMO_ACTOR,
                'created_date' => $this->ago(120 - ($i * 10)),
                'updated_date' => $this->now,
            ]);
        }
    }

    /* ====================================================================== */
    /*  REPORT                                                                */
    /* ====================================================================== */

    private function report(): void
    {
        $tables = [
            'hpbrain_capabilities', 'hpbrain_capability_assignments', 'hpbrain_capability_proficiency',
            'hpbrain_signals', 'hpbrain_evidence', 'hpbrain_cases',
            'hpbrain_recommendations', 'hpbrain_decisions', 'hpbrain_measurement_plans',
            'hpbrain_eso_definitions', 'hpbrain_eso_executions',
            'hpbrain_outcomes', 'hpbrain_learnings', 'hpbrain_knowledge_assets', 'hpbrain_policies',
        ];

        foreach ($tables as $t) {
            if (! Schema::hasTable($t)) {
                continue;
            }

            $n = DB::table($t)->where('tenant_id', $this->tenant)->count();
            $this->command?->line(sprintf('  %-38s %d', $t, $n));
        }
    }
}
