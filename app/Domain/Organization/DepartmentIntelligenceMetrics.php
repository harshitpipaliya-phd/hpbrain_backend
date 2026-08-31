<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Domain\Operations\OperationalIntelligence;
use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EVERY DEPARTMENT'S MEASURABLE FACTS, FOR THE WHOLE TENANT, IN ONE PASS.
 *
 * WHAT THIS REPLACES, AND WHY IT HAD TO. The Departments screen scored each unit
 * by calling `GET /departments/{t}/{id}/twin` once per department:
 *
 *     Promise.allSettled(departments.map((d) => deptApi.getTwin(tenant, d.id)))
 *
 * On Fiber Valley that is 13 HTTP round trips, and each twin runs six queries of
 * its own — people, capability assignments, capability proficiency, signals,
 * decisions, and an audit-log scan — so opening a list of 13 departments cost
 * roughly 78 queries and 13 request/response latencies before the first card
 * could show a number. It is the textbook N+1, moved up a layer where a database
 * profiler cannot see it.
 *
 * Everything below is COUNT / SUM / GROUP BY, keyed by department, for all
 * departments at once: eight queries for an organization of any size. No row of
 * operational data enters PHP, and the largest result set is one row per
 * department.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * `support` IS THE POINT OF THIS CLASS, NOT THE COUNTS.
 *
 * A count of zero is two completely different statements and the caller cannot
 * tell them apart from the number alone:
 *
 *   "this tenant records capability assessments, and this department has none"
 *       → a real finding about the department. Score it.
 *
 *   "this tenant has never recorded a capability assessment anywhere"
 *       → nothing has been measured. Scoring it 0 invents a failing grade for
 *         every department in the organization.
 *
 * So each family of facts is published with a `support` flag saying whether the
 * ORGANIZATION carries that kind of data at all. The scoring layer drops any
 * dimension whose support is false instead of feeding it in as a zero. This is
 * the same rule the Organization Intelligence engine already applies to its loop
 * dimensions, applied to departments.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OPERATIONAL RECORDS ARE HERE NOW, AND THE ATTRIBUTION IS THE SOURCE'S OWN.
 *
 * This class used to leave `hpbrain_operational_records` out entirely, and said
 * why: the table carried no department key, so a person could be attached to a
 * record only by exact string match on `subject_ref`, `owner_name` or
 * `supervisor_name` — an unindexed join of a quarter of a million records
 * against a thousand people, on a list page, producing an activity figure that
 * would be confidently wrong for exactly the people whose names are recorded
 * inconsistently. That was the right call for what the schema then held.
 *
 * The schema changed. Every record this product ingests carries the owning unit
 * the SOURCE EXPORT named, and that value now lives in an indexed
 * `department_label` column rather than inside a JSON blob — see the
 * 2026_08_30 migration and `operations:backfill-departments`. So the join is
 * gone: attribution is a GROUP BY on a column the source system populated, not
 * a name match this code invented.
 *
 * AND IT IS NOT COMPUTED HERE. OperationalIntelligence already produces those
 * figures for every unit in one pass and caches them against a fingerprint of
 * the records; this class reads them. Running the grouping again on a list page
 * would be both a second scan and a second definition of "this department's
 * completion rate" — and two definitions of one number is the defect this class
 * was written to remove.
 *
 * NAME MATCHING STILL HAPPENS IN EXACTLY ONE PLACE, and it is bounded: the
 * label the source wrote ("Help Desk") has to be reconciled with the register's
 * own row for that unit, which is at most a few dozen names compared
 * case-insensitively in PHP. A label that matches no registered unit is
 * reported at organization level and attributed to none — never silently
 * dropped, and never forced onto the closest-looking department.
 */
final class DepartmentIntelligenceMetrics
{
    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly FoundationCounts $foundation,
        private readonly DepartmentVisibilityScope $visibility,
        private readonly OperationalIntelligence $operations,
    ) {
    }

    /**
     * @return array{
     *   departments: array<string, array<string, mixed>>,
     *   support: array<string, bool>,
     *   tenant: array<string, mixed>
     * }
     */
    public function forTenant(string $tenant): array
    {
        /*
          NO INSTANCE-LEVEL MEMO HERE, DELIBERATELY.

          The first version cached the result on the object. It measurably
          served a stale picture: a department added between two calls did not
          appear, because the instance outlived the request that built it. That
          is the same trap the Organization Intelligence provider documents when
          it binds its profiler `scoped()` and never `singleton()` — a cached
          answer that survives a write is worse than no cache, because nothing
          about the response says it is old.

          The expensive half is memoised where it can be invalidated:
          FoundationCounts holds the per-request headcount cache. Everything
          below is eight aggregate queries, which is cheap enough to simply run.
        */
        $unit = $this->resolver->resolve($tenant, 'OrganizationUnit');
        $person = $this->resolver->resolve($tenant, 'Person');

        $unitIds = $this->visibleUnitIds($tenant, $unit);

        // The headcount every other screen publishes. Read, never re-derived:
        // a department page that counts its own people and an organization page
        // that counts them centrally will disagree, and a reader has no way to
        // tell which one is real.
        $perUnit = $this->foundation->forTenant($tenant)['perUnit'];

        $completeness = $this->peopleCompleteness($tenant, $person);
        $capability = $this->capability($tenant, $person);
        $signals = $this->signals($tenant);
        $evidence = $this->evidence($tenant);
        $cases = $this->cases($tenant);
        $decisions = $this->decisions($tenant, $person);
        $activity = $this->activity($tenant, $person);
        $operational = $this->operationalActivity($tenant, $unit, $unitIds);

        $departments = [];

        foreach ($unitIds as $id) {
            $people = (int) ($perUnit[$id] ?? 0);
            $complete = $completeness['perDepartment'][$id] ?? [];
            $caps = $capability['perDepartment'][$id] ?? ['assessedPeople' => 0, 'capabilities' => 0, 'levelSum' => 0.0, 'levelCount' => 0];
            $sig = $signals['perDepartment'][$id] ?? ['total' => 0, 'open' => 0, 'openHigh' => 0, 'resolved' => 0];
            $dec = $decisions['perDepartment'][$id] ?? ['total' => 0, 'approved' => 0, 'withOutcome' => 0];
            $act = $activity['perDepartment'][$id] ?? ['total' => 0, 'recent' => 0];
            $ops = $operational['perDepartment'][$id] ?? null;
            $counterpart = $operational['counterparts'][$id] ?? null;

            $departments[$id] = [
                'people' => $people,
                // Three independent completeness probes rather than one, because
                // "incomplete" is not one condition: a roster can carry every
                // job title and no contact detail, and the remedy differs.
                /*
                  NULL WHERE THE PROBE COULD NOT RUN — never 0.

                  MEASURED ON LIVE DATA. Fiber Valley's roster does not map a
                  `position` field at all, so this probe never executed and the
                  first version published `peopleWithRole: 0` for all five of its
                  departments. The client then averaged that 0 into record
                  completeness and marked every department down for a column the
                  source system does not have — the exact "absence scored as
                  failure" defect this whole class exists to prevent, reproduced
                  one layer up. A probe that did not run says null, and the
                  scoring layer averages only the probes that did.
                */
                'peopleWithRole' => $complete['withRole'] ?? null,
                'peopleWithContact' => $complete['withContact'] ?? null,
                'peopleWithReference' => $complete['withReference'] ?? null,

                /*
                  A LABEL CARRYING THIS UNIT'S WORK THAT THE REGISTER DOES NOT
                  CLAIM.

                  Null for almost every organization. It is populated only where
                  the source system holds two rows for one real unit — the
                  workforce on one, the imported work booked against the other —
                  which is the case on this ERP and the reason a department with
                  111 people can show no work at all. Reported, never merged:
                  attribution stays as the source states it.
                */
                'unclaimedWork' => $counterpart,

                'capabilityAssessedPeople' => (int) $caps['assessedPeople'],
                'capabilityCount' => (int) $caps['capabilities'],
                'capabilityAverageLevel' => $caps['levelCount'] > 0
                    ? round($caps['levelSum'] / $caps['levelCount'], 2)
                    : null,

                'signalsTotal' => (int) $sig['total'],
                'signalsOpen' => (int) $sig['open'],
                'signalsOpenHigh' => (int) $sig['openHigh'],
                'signalsResolved' => (int) $sig['resolved'],

                'evidenceCount' => (int) ($evidence['perDepartment'][$id] ?? 0),

                'casesTotal' => (int) ($cases['perDepartment'][$id]['total'] ?? 0),
                'casesOpen' => (int) ($cases['perDepartment'][$id]['open'] ?? 0),

                'decisionCount' => (int) $dec['total'],
                'decisionsApproved' => (int) $dec['approved'],
                'decisionsWithOutcome' => (int) $dec['withOutcome'],

                'activityTotal' => (int) $act['total'],
                'activityRecent' => (int) $act['recent'],

                /*
                  THE UNIT'S ACTUAL WORK, from the imported operational records.

                  NULL, NOT ZERO, WHERE THE ORGANIZATION RECORDS NO OWNING UNIT.
                  `support.operational` says whether this organization's imports
                  name a department at all; where they do not, every field here
                  is null and the scoring layer drops the dimension instead of
                  marking every unit down for a column the source lacks. That is
                  the same rule the capability and completeness probes follow,
                  and the reason the whole class publishes `support`.

                  A unit that IS named by the imports but has no records is a
                  real zero and is reported as one — that is a finding about the
                  unit, not about the data.
                */
                'operationalRecords' => $ops === null ? null : (int) $ops['records'],
                'operationalCompleted' => $ops === null ? null : (int) $ops['completed'],
                'operationalCancelled' => $ops === null ? null : (int) $ops['cancelled'],
                'operationalBacklog' => $ops === null ? null : (int) $ops['backlog'],
                'operationalCompletionRate' => $ops === null ? null : $ops['completionRate'],
                'operationalShare' => $ops === null ? null : $ops['share'],
                'operationalDatasets' => $ops === null ? null : (int) $ops['datasets'],
                'operationalPrimaryDataset' => $ops['primaryDataset'] ?? null,
                'operationalDatasetBreakdown' => $ops['datasetBreakdown'] ?? [],
                'operationalCancellationRate' => $ops['cancellationRate'] ?? null,
                'operationalClassified' => $ops === null ? null : (int) $ops['classifiedRecords'],
                'operationalTurnaroundHours' => $ops['averageTurnaroundHours'] ?? null,
                'operationalTurnaroundMeasured' => (int) ($ops['turnaroundMeasured'] ?? 0),
                'operationalTrend' => $ops['trend'] ?? [],
                'operationalMomentum' => $ops['momentum'] ?? null,
                'operationalRank' => $ops['activityRank'] ?? null,
                'operationalRankOf' => $ops['activityOf'] ?? null,
            ];
        }

        return [
            'departments' => $departments,
            /*
              Whether the ORGANIZATION carries each kind of data at all. False
              means "not measured here", and the scoring layer must drop the
              dimension rather than score it zero. See the class comment.
            */
            'support' => [
                'capability' => $capability['supported'],
                'signals' => $signals['supported'],
                'evidence' => $evidence['supported'],
                'cases' => $cases['supported'],
                'decisions' => $decisions['supported'],
                'activity' => $activity['supported'],
                'operational' => $operational['supported'],
                // Which completeness probes this roster can answer at all.
                'completenessProbes' => $completeness['probes'],
            ],
            'tenant' => [
                'departments' => count($unitIds),
                'people' => array_sum(array_map(static fn ($id) => (int) ($perUnit[$id] ?? 0), $unitIds)),
                'signalsTotal' => $signals['total'],
                'evidenceTotal' => $evidence['total'],
                'casesTotal' => $cases['total'],
                'capabilityAssignments' => $capability['assignments'],
                'operationalRecords' => $operational['total'],
                'operationalAttributed' => $operational['attributed'],
                'operationalUnattributed' => $operational['unattributed'],
                'operationalReason' => $operational['reason'],
            ],
        ];
    }

    /**
     * The units this caller is allowed to see, in the same scope `index()` uses.
     *
     * Same table, same soft-delete rule, same visibility scope — so a department
     * that the list shows always has metrics and one it hides never does. A
     * second, subtly different scope here is how a screen ends up scoring a unit
     * the user cannot open.
     *
     * @return list<string>
     */
    private function visibleUnitIds(string $tenant, ResolvedSource $unit): array
    {
        $query = DB::table($unit->table)
            ->where($unit->tenantKey, $tenant)
            ->orderBy($unit->primaryKey);

        if ($unit->has('deletedAt')) {
            $query->whereNull($unit->field('deletedAt'));
        }

        $this->visibility->apply($query, $unit, $tenant);

        return $query->pluck($unit->primaryKey)->map(static fn ($v) => (string) $v)->all();
    }

    /**
     * How completely each unit's people are recorded — one grouped pass.
     *
     * NOT A JUDGEMENT ON THE PEOPLE. This measures the ORGANIZATION'S RECORD of
     * them: a department whose staff have no job title recorded cannot be
     * reasoned about, whatever those staff actually do. It is the one dimension
     * that is measurable for every tenant, because it needs nothing but the
     * roster the tenant already maps Person to.
     *
     * @return array{perDepartment: array<string, array<string, int>>, probes: list<string>}
     */
    private function peopleCompleteness(string $tenant, ResolvedSource $person): array
    {
        if (! $person->has('unit')) {
            return ['perDepartment' => [], 'probes' => []];
        }

        $unitColumn = $person->field('unit');

        $query = DB::table($person->table)
            ->where($person->tenantKey, $tenant)
            ->where($person->field('status'), 1)
            ->whereNotNull($unitColumn)
            ->groupBy($unitColumn)
            ->select($unitColumn . ' as unit_id');

        if ($person->has('deletedAt')) {
            $query->whereNull($person->field('deletedAt'));
        }

        // Each probe is added only when the source system actually has the
        // column. A tenant whose roster carries no email must not be told its
        // contact coverage is 0%; the probe simply does not run.
        // `position` is the job-title reference on the mapped roster — the
        // Person entity has no free-text designation, so probing for one would
        // silently never run and report every tenant as having no role data.
        $probes = array_filter([
            $this->countNonEmpty($query, $person, 'position', 'with_role') ? 'withRole' : null,
            $this->countNonEmpty($query, $person, 'email', 'with_contact') ? 'withContact' : null,
            $this->countNonEmpty($query, $person, 'externalRef', 'with_reference') ? 'withReference' : null,
        ]);

        $columns = ['withRole' => 'with_role', 'withContact' => 'with_contact', 'withReference' => 'with_reference'];
        $out = [];

        foreach ($query->get() as $row) {
            $counts = [];

            // Only the probes that actually ran get a key. A department missing
            // from a probe's result set is a real 0 for that probe; a probe that
            // never ran is absent, and the caller publishes null for it.
            foreach ($probes as $probe) {
                $counts[$probe] = (int) ($row->{$columns[$probe]} ?? 0);
            }

            $out[(string) $row->unit_id] = $counts;
        }

        return ['perDepartment' => $out, 'probes' => array_values($probes)];
    }

    /**
     * Adds `SUM(CASE WHEN <col> IS NOT NULL AND <col> <> '' THEN 1 ELSE 0 END)`,
     * and reports whether it could — a caller must publish null, not 0, for a
     * probe whose column this tenant's source system does not have.
     *
     * The empty-string half matters more than the NULL half: an ERP export
     * writes '' far more often than NULL, and counting '' as a populated field
     * is how a roster with no job titles reports 100% role coverage.
     */
    private function countNonEmpty(Builder $query, ResolvedSource $source, string $field, string $alias): bool
    {
        if (! $source->has($field)) {
            return false;
        }

        $column = $source->field($field);
        $query->selectRaw("SUM(CASE WHEN {$column} IS NOT NULL AND {$column} <> '' THEN 1 ELSE 0 END) AS {$alias}");

        return true;
    }

    /**
     * Capability assessments reaching each department, by either route.
     *
     * TWO ROUTES, ONE TOTAL. An assignment targets a Department directly, or it
     * targets a Person who belongs to one. Both are real and the screen shows
     * their sum, so both are collected — the per-person route through a join on
     * the roster rather than by pulling person ids into PHP and sending back an
     * `IN (...)` of 768 values.
     *
     * @return array{perDepartment: array<string, array<string, mixed>>, supported: bool, assignments: int}
     */
    private function capability(string $tenant, ResolvedSource $person): array
    {
        $blank = ['perDepartment' => [], 'supported' => false, 'assignments' => 0];

        if (! Schema::hasTable('hpbrain_capability_assignments') || ! Schema::hasTable('hpbrain_capability_proficiency')) {
            return $blank;
        }

        $assignments = (int) DB::table('hpbrain_capability_assignments')->where('tenant_id', $tenant)->count();

        if ($assignments === 0) {
            return $blank;
        }

        $per = [];

        // A proficiency row is what makes an assignment an ASSESSMENT. An
        // assignment with no proficiency means "this capability is expected
        // here", which is not evidence of the level anyone holds, so the level
        // average is taken over assessed rows only.
        $levelExpression = $this->averageOfDimensions();

        $direct = DB::table('hpbrain_capability_assignments as a')
            ->join('hpbrain_capability_proficiency as p', function ($join) use ($tenant) {
                $join->on('p.assignment_id', '=', 'a.id')->where('p.tenant_id', '=', $tenant);
            })
            ->where('a.tenant_id', $tenant)
            ->where('a.target_type', 'Department')
            ->groupBy('a.target_id')
            ->selectRaw('a.target_id AS department_id')
            ->selectRaw('COUNT(DISTINCT a.capability_id) AS capabilities')
            ->selectRaw('COUNT(*) AS assessed')
            ->selectRaw("SUM({$levelExpression}) AS level_sum")
            ->selectRaw("SUM(CASE WHEN {$levelExpression} IS NULL THEN 0 ELSE 1 END) AS level_count")
            ->get();

        foreach ($direct as $row) {
            $per[(string) $row->department_id] = [
                'assessedPeople' => 0,
                'capabilities' => (int) $row->capabilities,
                'levelSum' => (float) ($row->level_sum ?? 0),
                'levelCount' => (int) ($row->level_count ?? 0),
            ];
        }

        if ($person->has('unit')) {
            $viaPerson = DB::table('hpbrain_capability_assignments as a')
                ->join('hpbrain_capability_proficiency as p', function ($join) use ($tenant) {
                    $join->on('p.assignment_id', '=', 'a.id')->where('p.tenant_id', '=', $tenant);
                })
                ->join($person->table . ' as person', function ($join) use ($person, $tenant) {
                    $join->on(DB::raw('CAST(person.' . $person->primaryKey . ' AS CHAR)'), '=', DB::raw('CAST(a.target_id AS CHAR)'))
                        ->where('person.' . $person->tenantKey, '=', $tenant);
                })
                ->where('a.tenant_id', $tenant)
                ->where('a.target_type', 'Person')
                ->whereNotNull('person.' . $person->field('unit'))
                ->groupBy('person.' . $person->field('unit'))
                ->selectRaw('person.' . $person->field('unit') . ' AS department_id')
                ->selectRaw('COUNT(DISTINCT a.capability_id) AS capabilities')
                ->selectRaw('COUNT(DISTINCT a.target_id) AS assessed_people')
                ->selectRaw("SUM({$levelExpression}) AS level_sum")
                ->selectRaw("SUM(CASE WHEN {$levelExpression} IS NULL THEN 0 ELSE 1 END) AS level_count")
                ->get();

            foreach ($viaPerson as $row) {
                $id = (string) $row->department_id;
                $existing = $per[$id] ?? ['assessedPeople' => 0, 'capabilities' => 0, 'levelSum' => 0.0, 'levelCount' => 0];

                $per[$id] = [
                    'assessedPeople' => $existing['assessedPeople'] + (int) $row->assessed_people,
                    // Capabilities reached by the two routes may overlap; the
                    // larger of the two is the honest floor, and claiming their
                    // sum would double-count a capability assigned both ways.
                    'capabilities' => max($existing['capabilities'], (int) $row->capabilities),
                    'levelSum' => $existing['levelSum'] + (float) ($row->level_sum ?? 0),
                    'levelCount' => $existing['levelCount'] + (int) ($row->level_count ?? 0),
                ];
            }
        }

        return ['perDepartment' => $per, 'supported' => true, 'assignments' => $assignments];
    }

    /**
     * The KASBA average for one proficiency row, in SQL.
     *
     * A dimension left NULL is UNRATED, not a zero — averaging it in as 0 would
     * drag a person assessed only on Knowledge down to a fifth of their real
     * level. So the divisor counts the rated dimensions, and a row with none
     * yields NULL and drops out of the aggregate entirely.
     */
    private function averageOfDimensions(): string
    {
        $dimensions = ['p.knowledge_level', 'p.ability_level', 'p.skill_level', 'p.behaviour_level', 'p.attitude_level'];

        $sum = implode(' + ', array_map(static fn ($d) => "COALESCE({$d}, 0)", $dimensions));
        $count = implode(' + ', array_map(static fn ($d) => "CASE WHEN {$d} IS NULL THEN 0 ELSE 1 END", $dimensions));

        return "(CASE WHEN ({$count}) = 0 THEN NULL ELSE ({$sum}) * 1.0 / ({$count}) END)";
    }

    /**
     * Signals per department, split by whether they are still open.
     *
     * `supported` is true when this tenant attributes ANY signal to ANY
     * department. That distinction is what lets the screen say "no open signals"
     * as a finding on a clean department, while staying silent about a tenant
     * whose signals carry no department at all — where the same zero would mean
     * nothing was ever looked at.
     *
     * @return array{perDepartment: array<string, array<string, int>>, supported: bool, total: int}
     */
    private function signals(string $tenant): array
    {
        if (! Schema::hasTable('hpbrain_signals') || ! Schema::hasColumn('hpbrain_signals', 'department_id')) {
            return ['perDepartment' => [], 'supported' => false, 'total' => 0];
        }

        $rows = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenant)
            ->whereNotNull('department_id')
            ->where('department_id', '<>', '')
            ->groupBy('department_id')
            ->selectRaw('department_id')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN LOWER(status) IN ('resolved','closed','dismissed') THEN 0 ELSE 1 END) AS open_count")
            ->selectRaw("SUM(CASE WHEN LOWER(status) IN ('resolved','closed','dismissed') THEN 1 ELSE 0 END) AS resolved_count")
            ->selectRaw("SUM(CASE WHEN LOWER(status) NOT IN ('resolved','closed','dismissed') AND LOWER(severity) IN ('high','critical') THEN 1 ELSE 0 END) AS open_high")
            ->get();

        $per = [];
        $total = 0;

        foreach ($rows as $row) {
            $per[(string) $row->department_id] = [
                'total' => (int) $row->total,
                'open' => (int) $row->open_count,
                'openHigh' => (int) $row->open_high,
                'resolved' => (int) $row->resolved_count,
            ];
            $total += (int) $row->total;
        }

        return ['perDepartment' => $per, 'supported' => $total > 0, 'total' => $total];
    }

    /**
     * Evidence reaching a department THROUGH the signal it supports.
     *
     * Evidence carries no department of its own — `hpbrain_evidence.signal_id`
     * is its only route to one. That is a real limitation and it is reflected
     * honestly: evidence attached to a signal with no department is counted in
     * the tenant total and against no department.
     *
     * @return array{perDepartment: array<string, int>, supported: bool, total: int}
     */
    private function evidence(string $tenant): array
    {
        if (! Schema::hasTable('hpbrain_evidence') || ! Schema::hasTable('hpbrain_signals')) {
            return ['perDepartment' => [], 'supported' => false, 'total' => 0];
        }

        $total = (int) DB::table('hpbrain_evidence')->where('tenant_id', $tenant)->count();

        if ($total === 0) {
            return ['perDepartment' => [], 'supported' => false, 'total' => 0];
        }

        $rows = DB::table('hpbrain_evidence as e')
            ->join('hpbrain_signals as s', function ($join) use ($tenant) {
                $join->on('s.id', '=', 'e.signal_id')->where('s.tenant_id', '=', $tenant);
            })
            ->where('e.tenant_id', $tenant)
            ->whereNotNull('s.department_id')
            ->where('s.department_id', '<>', '')
            ->groupBy('s.department_id')
            ->selectRaw('s.department_id AS department_id')
            ->selectRaw('COUNT(*) AS total')
            ->get();

        $per = [];

        foreach ($rows as $row) {
            $per[(string) $row->department_id] = (int) $row->total;
        }

        return ['perDepartment' => $per, 'supported' => true, 'total' => $total];
    }

    /**
     * Cases reaching a department through their signal, split by open/closed.
     *
     * @return array{perDepartment: array<string, array<string, int>>, supported: bool, total: int}
     */
    private function cases(string $tenant): array
    {
        if (! Schema::hasTable('hpbrain_cases') || ! Schema::hasTable('hpbrain_signals')) {
            return ['perDepartment' => [], 'supported' => false, 'total' => 0];
        }

        $total = (int) DB::table('hpbrain_cases')->where('tenant_id', $tenant)->count();

        if ($total === 0) {
            return ['perDepartment' => [], 'supported' => false, 'total' => 0];
        }

        $rows = DB::table('hpbrain_cases as c')
            ->join('hpbrain_signals as s', function ($join) use ($tenant) {
                $join->on('s.id', '=', 'c.signal_id')->where('s.tenant_id', '=', $tenant);
            })
            ->where('c.tenant_id', $tenant)
            ->whereNotNull('s.department_id')
            ->where('s.department_id', '<>', '')
            ->groupBy('s.department_id')
            ->selectRaw('s.department_id AS department_id')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN LOWER(c.status) IN ('closed','resolved','dismissed') THEN 0 ELSE 1 END) AS open_count")
            ->get();

        $per = [];

        foreach ($rows as $row) {
            $per[(string) $row->department_id] = [
                'total' => (int) $row->total,
                'open' => (int) $row->open_count,
            ];
        }

        return ['perDepartment' => $per, 'supported' => true, 'total' => $total];
    }

    /**
     * Decisions made by each department's people, and how many were approved.
     *
     * `withOutcome` is counted separately from `total` because a decision still
     * in flight is not evidence of decision quality either way. An approval rate
     * is published over decided decisions only, or not at all.
     *
     * @return array{perDepartment: array<string, array<string, int>>, supported: bool}
     */
    private function decisions(string $tenant, ResolvedSource $person): array
    {
        if (! Schema::hasTable('hpbrain_decisions') || ! $person->has('unit')) {
            return ['perDepartment' => [], 'supported' => false];
        }

        $total = (int) DB::table('hpbrain_decisions')->where('tenant_id', $tenant)->count();

        if ($total === 0) {
            return ['perDepartment' => [], 'supported' => false];
        }

        $decided = "LOWER(d.status) IN ('approved','accepted','rejected','declined')";
        $approved = "LOWER(d.status) IN ('approved','accepted')";

        $rows = DB::table('hpbrain_decisions as d')
            ->join($person->table . ' as person', function ($join) use ($person, $tenant) {
                $join->on(DB::raw('CAST(person.' . $person->primaryKey . ' AS CHAR)'), '=', DB::raw('CAST(d.decided_by AS CHAR)'))
                    ->where('person.' . $person->tenantKey, '=', $tenant);
            })
            ->where('d.tenant_id', $tenant)
            ->whereNotNull('person.' . $person->field('unit'))
            ->groupBy('person.' . $person->field('unit'))
            ->selectRaw('person.' . $person->field('unit') . ' AS department_id')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN {$approved} THEN 1 ELSE 0 END) AS approved_count")
            ->selectRaw("SUM(CASE WHEN {$decided} THEN 1 ELSE 0 END) AS decided_count")
            ->get();

        $per = [];

        foreach ($rows as $row) {
            $per[(string) $row->department_id] = [
                'total' => (int) $row->total,
                'approved' => (int) $row->approved_count,
                'withOutcome' => (int) $row->decided_count,
            ];
        }

        return ['perDepartment' => $per, 'supported' => true];
    }

    /**
     * Recorded activity: audit events against the unit, or by someone in it.
     *
     * NAMED FOR WHAT IT IS. This is the system's own record of things happening,
     * not the organization's operational throughput — see the class comment on
     * why operational records cannot be attributed to a department cheaply or
     * honestly. A screen that calls this "operational activity" would be
     * overclaiming; it is labelled "recorded activity" throughout.
     *
     * @return array{perDepartment: array<string, array<string, int>>, supported: bool}
     */
    private function activity(string $tenant, ResolvedSource $person): array
    {
        if (! Schema::hasTable('hpbrain_audit_logs')) {
            return ['perDepartment' => [], 'supported' => false];
        }

        $total = (int) DB::table('hpbrain_audit_logs')->where('tenant_id', $tenant)->count();

        if ($total === 0) {
            return ['perDepartment' => [], 'supported' => false];
        }

        $cutoff = now()->subDays(30)->format('Y-m-d H:i:s');
        $per = [];

        // Route 1 — events whose subject IS the department.
        $direct = DB::table('hpbrain_audit_logs')
            ->where('tenant_id', $tenant)
            ->where('entity_type', 'Department')
            ->groupBy('entity_id')
            ->selectRaw('entity_id AS department_id')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS recent', [$cutoff])
            ->get();

        foreach ($direct as $row) {
            $per[(string) $row->department_id] = [
                'total' => (int) $row->total,
                'recent' => (int) $row->recent,
            ];
        }

        // Route 2 — events performed by someone who belongs to the department.
        if ($person->has('unit')) {
            $byActor = DB::table('hpbrain_audit_logs as l')
                ->join($person->table . ' as person', function ($join) use ($person, $tenant) {
                    $join->on(DB::raw('CAST(person.' . $person->primaryKey . ' AS CHAR)'), '=', DB::raw('CAST(l.actor_id AS CHAR)'))
                        ->where('person.' . $person->tenantKey, '=', $tenant);
                })
                ->where('l.tenant_id', $tenant)
                ->whereNotNull('person.' . $person->field('unit'))
                ->groupBy('person.' . $person->field('unit'))
                ->selectRaw('person.' . $person->field('unit') . ' AS department_id')
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('SUM(CASE WHEN l.created_at >= ? THEN 1 ELSE 0 END) AS recent', [$cutoff])
                ->get();

            foreach ($byActor as $row) {
                $id = (string) $row->department_id;
                $existing = $per[$id] ?? ['total' => 0, 'recent' => 0];

                $per[$id] = [
                    'total' => $existing['total'] + (int) $row->total,
                    'recent' => $existing['recent'] + (int) $row->recent,
                ];
            }
        }

        return ['perDepartment' => $per, 'supported' => true];
    }

    /**
     * What each unit actually did — read from the cached operational aggregate,
     * not computed here.
     *
     * WHY THIS DELEGATES INSTEAD OF QUERYING.
     *
     * The obvious implementation is one GROUP BY on (department_label, status),
     * and it is the wrong thing to put on a LIST PAGE. That grouping is covered
     * by an index only where the 2026_08_30 migration has run; where it has not,
     * it falls back to the clustered index — which is where every record's JSON
     * payload lives — and becomes a half-gigabyte scan on the path a user takes
     * to see a list of thirteen departments. This class exists because that
     * screen used to cost 78 queries; replacing them with one scan would not be
     * an improvement.
     *
     * OperationalIntelligence already computes exactly these figures for every
     * unit in one pass, and caches them against a fingerprint of the records, so
     * reading them here is free between imports and identical to what the
     * Organization screen shows. A second implementation would be a second
     * definition of "this department's completion rate", and the two would
     * eventually disagree — which is the defect this whole class was written to
     * remove, one layer down.
     *
     * THE ONLY WORK DONE HERE IS RECONCILING A NAME TO A UNIT ID. The aggregate
     * is keyed by the label the source export wrote ("Help Desk"); this screen
     * is keyed by the register's primary key. Matching is case- and
     * punctuation-insensitive on the whole name and nothing else — no prefix
     * match, no closest-match, no fuzzy distance, because "Sales" and
     * "Sales - FVCPL" are two separate rows on this register and are two
     * different units. A label matching no unit is counted in `unattributed` and
     * reported at organization level rather than pushed onto whichever unit
     * looked nearest.
     *
     * @param  list<string>  $unitIds
     * @return array{
     *   perDepartment: array<string, array<string, mixed>>,
     *   supported: bool,
     *   total: int,
     *   attributed: int,
     *   unattributed: int,
     *   reason: string|null
     * }
     */
    private function operationalActivity(string $tenant, ResolvedSource $unit, array $unitIds): array
    {
        $blank = [
            'perDepartment' => [],
            'supported' => false,
            'total' => 0,
            'attributed' => 0,
            'unattributed' => 0,
            'reason' => 'This installation does not attribute operational records to an owning unit.',
        ];

        if (! Schema::hasTable('hpbrain_operational_records') || ! Schema::hasColumn('hpbrain_operational_records', 'department_label')) {
            return $blank;
        }

        if (! $unit->has('name')) {
            return array_merge($blank, [
                'reason' => 'This organization\'s unit register carries no name field, so a source label cannot be reconciled to a unit.',
            ]);
        }

        $aggregate = $this->operations->forTenant($tenant);
        $units = $aggregate['departments'] ?? [];

        if ($units === []) {
            return array_merge($blank, [
                'reason' => (string) ($aggregate['support']['reasons']['department']
                    ?? 'No imported record for this organization names an owning unit, so operational work cannot be attributed to a department.'),
            ]);
        }

        // The register's own names, normalised, so each source label is one
        // array lookup rather than a comparison against every unit.
        $byName = [];
        $displayNames = [];

        foreach (DB::table($unit->table)
            ->where($unit->tenantKey, $tenant)
            ->whereIn($unit->primaryKey, $unitIds)
            ->pluck($unit->field('name'), $unit->primaryKey) as $id => $name) {
            $key = $this->normaliseUnitName((string) $name);

            if ($key !== '') {
                $byName[$key] = (string) $id;
                $displayNames[(string) $id] = (string) $name;
            }
        }

        $perDepartment = [];
        $unmatched = [];
        $total = 0;
        $attributed = 0;

        foreach ($units as $entry) {
            $records = (int) $entry['records'];
            $total += $records;

            $id = $byName[$this->normaliseUnitName((string) $entry['label'])] ?? null;

            if ($id === null) {
                $unmatched[] = $entry;
                continue;
            }

            $attributed += $records;

            $perDepartment[$id] = [
                'records' => $records,
                'completed' => (int) $entry['completed'],
                'cancelled' => (int) $entry['cancelled'],
                'backlog' => (int) $entry['backlog'],
                'classified' => (int) $entry['classified'],
                'datasets' => (int) $entry['datasets'],
                // Forwarded from the cached operational aggregate rather than
                // recomputed: it already holds the trend, momentum, turnaround
                // and dataset mix per unit, and a second pass over 200k+ rows
                // to rebuild them here would be the whole cost of the screen.
                'primaryDataset' => $entry['primaryDataset'] ?? null,
                'datasetBreakdown' => $entry['datasetBreakdown'] ?? [],
                'cancellationRate' => $entry['cancellationRate'] ?? null,
                'averageTurnaroundHours' => $entry['averageTurnaroundHours'] ?? null,
                'turnaroundMeasured' => (int) ($entry['turnaroundMeasured'] ?? 0),
                'trend' => $entry['trend'] ?? [],
                'momentum' => $entry['momentum'] ?? null,
                'activityRank' => $entry['rank'] ?? null,
                'activityOf' => $entry['of'] ?? null,
                'classifiedRecords' => (int) ($entry['classified'] ?? 0),
                // Published as the aggregate computed it, including its null:
                // a unit with too few classified records has no rate, and
                // manufacturing one here would defeat the floor.
                'completionRate' => $entry['completionRate'],
                /*
                  RE-BASED ON WHAT REACHED THE REGISTER, not on the aggregate's
                  organization-wide denominator. The aggregate's `share` counts
                  every labelled record including those naming units this screen
                  cannot see; a department page showing "37% of the
                  organization's work" against a total the reader cannot get back
                  to is worse than no figure. Filled in below, once the
                  attributed total is known.
                */
                'share' => null,
            ];
        }

        /*
          THE SPLIT REGISTER, NAMED.

          This ERP carries TWO rows for the same real unit — one the workforce is
          assigned to, one the work is booked against. "CST - FVCPL" holds 111
          people and no records; "CST" holds 47,693 records and nobody. Both
          halves look broken on screen and neither says why.

          This does NOT merge them. Attribution stays exactly as the source
          states it: a screen that quietly moved 47,693 records onto a unit the
          source never named would be inventing the organization's structure.
          It reports the pairing as an OBSERVATION against the staffed row —
          here is another unit on your own register, whose name is this one's
          name plus a suffix, carrying the work this one has none of.

          The test is deliberately narrow: one normalised name must be the other
          plus a whole extra word. "Sales" pairs with "Sales - FVCPL"; it does
          not pair with "Salesforce", and two unrelated units never pair.
        */
        $counterparts = [];

        foreach ($byName as $name => $id) {
            // Only a unit with no work of its own has work to explain.
            if (isset($perDepartment[$id])) {
                continue;
            }

            foreach ($byName as $otherName => $otherId) {
                if ($otherId === $id || ! isset($perDepartment[$otherId])) {
                    continue;
                }

                $isExtension = str_starts_with($name, $otherName.' ') || str_starts_with($otherName, $name.' ');

                if (! $isExtension) {
                    continue;
                }

                // A unit paired with two candidates has an ambiguity the reader
                // must resolve; the largest is kept and the rest are dropped
                // rather than a pairing being invented from a tie.
                if (($counterparts[$id]['records'] ?? -1) < $perDepartment[$otherId]['records']) {
                    $counterparts[$id] = [
                        'unitId' => (string) $otherId,
                        'label' => (string) ($displayNames[$otherId] ?? $otherName),
                        'records' => (int) $perDepartment[$otherId]['records'],
                        'completed' => (int) $perDepartment[$otherId]['completed'],
                        'backlog' => (int) $perDepartment[$otherId]['backlog'],
                    ];
                }
            }
        }

        foreach ($perDepartment as $id => $entry) {
            $perDepartment[$id]['share'] = $attributed > 0
                ? round($entry['records'] / $attributed, 4)
                : null;
        }

        return [
            'perDepartment' => $perDepartment,
            'counterparts' => $counterparts,
            'supported' => $attributed > 0,
            'total' => $total,
            'attributed' => $attributed,
            'unattributed' => $total - $attributed,
            'reason' => $attributed > 0
                ? null
                : 'Imported records name owning units, but none of those names matches a unit on this organization\'s register.',
        ];
    }

    /**
     * A unit name reduced to what two spellings of the same unit share.
     *
     * Case and punctuation only. Deliberately NOT a fuzzy match: "Sales" and
     * "Sales - FVCPL" are two separate rows on this register and are two
     * different units, so anything that collapsed them would merge one unit's
     * work into another's and there would be no way to tell from the screen.
     */
    private function normaliseUnitName(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $spaced = preg_replace('/[^a-z0-9]+/u', ' ', $lower) ?? $lower;

        return trim(preg_replace('/\s+/', ' ', $spaced) ?? $spaced);
    }
}
