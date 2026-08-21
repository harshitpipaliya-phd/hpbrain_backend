<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Domain\Universal\EntityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE definition of "how many departments" and "how many people", for every
 * screen that publishes either number.
 *
 * WHAT THIS REPLACES. Three screens counted the same two things three different
 * ways, and disagreed:
 *
 *   - The Organization overview read WorkspaceController::homeMetrics, which
 *     counted OrganizationUnit rows with `status = 1 AND deleted_at IS NULL`
 *     and Person rows with `status = 1 AND deleted_at IS NULL`.
 *   - The Departments screen counted the length of the department LIST, which
 *     DepartmentController::index returns without the status filter — so an
 *     inactive unit was invisible to the overview and counted by the screen.
 *   - The same screen's "People" tile summed each unit's twin.personCount, and
 *     on a school tenant the twin substitutes feeIntelligence.students for it.
 *     That tile therefore published a STUDENT count under the word "People",
 *     against an overview whose "People" meant ERP staff. On Sunrise those two
 *     numbers differ by three orders of magnitude.
 *
 * None of that was fixable by changing a displayed number, because the numbers
 * were answers to three different questions. This class asks one question, in
 * SQL, once, and every caller publishes its answer.
 *
 * FOUR DIFFERENT POPULATIONS, NAMED. The second defect this class exists to fix
 * is not a wrong number, it is an unlabelled one. Lions' overview read
 * "People 1 · Departments 0 · Imported records 398,831" beside a People screen
 * reading "Students 7,445", and every one of those figures was correct. They
 * count four different things, and the overview published one of them under the
 * bare word "People" while never mentioning the other, so it read as a
 * contradiction. Each is defined here, once, and every screen publishes all of
 * them together:
 *
 *   people      STAFF, from the ERP roster the tenant maps Person to
 *               (tbluser). Active, not deleted. One row per employee. This is
 *               the ONLY thing that may be called People, and on Lions it is
 *               genuinely 1 — a school that has entered one staff account.
 *
 *   departments UNITS, from the table the tenant maps OrganizationUnit to
 *               (hrms_departments), narrowed by DepartmentVisibilityScope.
 *               Lions' single row is soft-deleted, so 0 is the truth. A class
 *               or a subject is NOT a department and never becomes one.
 *
 *   students    Children, from hpbrain_students — the projection
 *               StudentProjectionBuilder collapses imported academic and fee
 *               rows into, one row per enrolment number. NEVER added to
 *               `people`: an imported academic row is a student record, not a
 *               member of staff, and counting it as one would invent thousands
 *               of employees no HR system has.
 *
 *   records     Rows of imported source data in hpbrain_operational_records —
 *               receipts and mark entries, not entities. 398,831 of them
 *               describe 7,445 children; both numbers are right and they are
 *               not comparable.
 *
 * WHY EACH IS COUNTED ONCE. `people` and `departments` are keyed by the ERP's
 * own primary key. `students` is keyed by UNIQUE (tenant_id, student_ref), so
 * the same child appearing in both the fee file and the results file is one
 * row. `records` is keyed by UNIQUE (tenant_id, dataset, natural_key), so a
 * re-imported or overlapping file cannot inflate it. Verified on the live data:
 * every tenant's record count equals its distinct (dataset, natural_key) count.
 *
 * DEFINITIONS OF THE SUB-FIELDS:
 *
 *   departments.total     units that exist        (deleted_at IS NULL)
 *   departments.active    ... and are switched on (status = 1)
 *   departments.inactive  total - active
 *   people.total          staff that exist and are active (status = 1,
 *                         deleted_at IS NULL)
 *   people.withoutUnit    ... with no unit assigned
 *   perUnit               unit id => headcount, over exactly the people
 *                         counted above, so the parts sum to the whole
 *   students.total        distinct enrolment numbers held for this tenant
 *   students.inBothFiles  present in the academic AND the fee dataset
 *   records.total         imported source rows
 *
 * NOT ONE ROW CROSSES THE WIRE. Everything is COUNT/SUM/GROUP BY, so this stays
 * flat on a tenant with 200,000 records; the largest result set is one row per
 * department. Memoised per (tenant) for the life of the request, because the
 * overview asks for it and so does everything the overview calls.
 */
final class FoundationCounts
{
    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly DepartmentVisibilityScope $visibility,
        private readonly OrganizationStructureService $structure,
    ) {
    }

    /**
     * @return array{
     *   departments: array{total: int, active: int, inactive: int, withoutParent: int, supported: bool},
     *   people: array{total: int, withoutUnit: int, withoutProfile: int, inVisibleUnits: int, supported: bool},
     *   students: array{total: int, inBothFiles: int, academicOnly: int, feesOnly: int, supported: bool},
     *   records: array{total: int},
     *   perUnit: array<string, int>
     * }
     */
    public function forTenant(string $tenant): array
    {
        if (isset($this->memo[$tenant])) {
            return $this->memo[$tenant];
        }

        $perUnit = $this->peoplePerUnit($tenant);
        $people = $this->people($tenant);
        $people['inVisibleUnits'] = array_sum($perUnit);

        return $this->memo[$tenant] = [
            'departments' => $this->departments($tenant),
            'people' => $people,
            'students' => $this->students($tenant),
            'records' => $this->records($tenant),
            'perUnit' => $perUnit,
        ];
    }

    /**
     * Children this organization holds records for.
     *
     * ONE ROW PER ENROLMENT NUMBER, guaranteed by UNIQUE (tenant_id,
     * student_ref) on hpbrain_students — so a child who appears in both the fee
     * register and the results export is one student, not two, and re-running
     * `students:rebuild` cannot inflate the figure. Nothing is counted from the
     * raw record table here for exactly that reason: 398,831 imported rows
     * describe 7,445 children.
     *
     * `supported` is false where the projection table does not exist, which is
     * the SQLite suite unless a test builds it. False means "this organization
     * has no student data of this kind", and a caller must render that
     * differently from a real zero.
     *
     * @return array{total: int, inBothFiles: int, academicOnly: int, feesOnly: int, supported: bool}
     */
    private function students(string $tenant): array
    {
        if (! Schema::hasTable('hpbrain_students')) {
            return ['total' => 0, 'inBothFiles' => 0, 'academicOnly' => 0, 'feesOnly' => 0, 'supported' => false];
        }

        // One pass, four tallies — the cohort split is what makes the total
        // explicable ("7,445 = 1,911 in both files + the rest").
        $row = DB::table('hpbrain_students')
            ->where('tenant_id', $tenant)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN in_academic = 1 AND in_fees = 1 THEN 1 ELSE 0 END) AS both_files')
            ->selectRaw('SUM(CASE WHEN in_academic = 1 AND in_fees = 0 THEN 1 ELSE 0 END) AS academic_only')
            ->selectRaw('SUM(CASE WHEN in_fees = 1 AND in_academic = 0 THEN 1 ELSE 0 END) AS fees_only')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'inBothFiles' => (int) ($row->both_files ?? 0),
            'academicOnly' => (int) ($row->academic_only ?? 0),
            'feesOnly' => (int) ($row->fees_only ?? 0),
            'supported' => true,
        ];
    }

    /**
     * Imported source rows — receipts and mark entries, not entities.
     *
     * Deliberately beside the entity counts rather than mixed into them, so a
     * screen can say "398,831 rows describing 7,445 students" instead of
     * publishing the larger number next to "People 1" and leaving a reader to
     * conclude the two disagree.
     *
     * @return array{total: int}
     */
    private function records(string $tenant): array
    {
        if (! Schema::hasTable('hpbrain_operational_records')) {
            return ['total' => 0];
        }

        // A plain COUNT is already the count of DISTINCT logical records: the
        // table carries UNIQUE (tenant_id, dataset, natural_key), so a duplicate
        // cannot be stored in the first place. Verified against the live data —
        // every tenant's row count equals its distinct-business-key count.
        return ['total' => DB::table('hpbrain_operational_records')->where('tenant_id', $tenant)->count()];
    }

    /**
     * The two headline numbers, in the shape a UI tile wants.
     *
     * @return array{departments: int, departmentsTotal: int, people: int}
     */
    public function headline(string $tenant): array
    {
        $counts = $this->forTenant($tenant);

        return [
            'departments' => $counts['departments']['active'],
            'departmentsTotal' => $counts['departments']['total'],
            'people' => $counts['people']['total'],
        ];
    }

    /**
     * DELEGATED, NOT COMPUTED. This method used to run its own COUNT over the
     * mapped OrganizationUnit table, which made it the fourth place in the
     * application with an opinion about how many departments an organization
     * has — and on a school with no HR units it answered zero while the
     * Departments screen listed four teaching sections.
     * OrganizationStructureService owns the definition now; this reshapes its
     * answer into the tile-friendly shape callers already consume.
     *
     * @return array{total: int, active: int, inactive: int, withoutParent: int, source: string, supported: bool}
     */
    private function departments(string $tenant): array
    {
        $structure = $this->structure->forTenant($tenant);

        return [
            'total' => $structure['total'],
            'active' => $structure['active'],
            'inactive' => $structure['inactive'],
            'withoutParent' => count(array_filter(
                $structure['departments'],
                fn (array $d) => ($d['parentId'] ?? null) === null,
            )),
            // Published so a screen can say what these units ARE — units of the
            // connected HR system, or teaching sections derived from imported
            // data. A count without its provenance is what let a student count
            // and a staff count share a label.
            'source' => $structure['source'],
            // An unmapped entity is an absent one, not an empty one. False means
            // "this source system has no such concept" rather than "it has none".
            'supported' => $this->resolver->has($tenant, 'OrganizationUnit'),
        ];
    }

    /**
     * @return array{total: int, withoutUnit: int, withoutProfile: int, supported: bool}
     */
    private function people(string $tenant): array
    {
        if (! $this->resolver->has($tenant, 'Person')) {
            return ['total' => 0, 'withoutUnit' => 0, 'withoutProfile' => 0, 'supported' => false];
        }

        $person = $this->resolver->resolve($tenant, 'Person');
        $unitColumn = $person->has('unit') ? $person->field('unit') : null;
        $profileColumn = $person->has('profile') ? $person->field('profile') : null;

        $query = DB::table($person->table)
            ->where($person->tenantKey, $tenant)
            ->selectRaw('COUNT(*) AS total');

        $this->activeSourceRows($query, $person);

        if ($person->has('status')) {
            $query->where($person->field('status'), 1);
        }

        $query = $unitColumn === null
            ? $query->selectRaw('0 AS no_unit')
            : $query->selectRaw("SUM(CASE WHEN {$unitColumn} IS NULL OR {$unitColumn} = 0 THEN 1 ELSE 0 END) AS no_unit");

        $query = $profileColumn === null
            ? $query->selectRaw('0 AS no_profile')
            : $query->selectRaw("SUM(CASE WHEN {$profileColumn} IS NULL OR {$profileColumn} = 0 THEN 1 ELSE 0 END) AS no_profile");

        $row = $query->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'withoutUnit' => (int) ($row->no_unit ?? 0),
            'withoutProfile' => (int) ($row->no_profile ?? 0),
            'supported' => true,
        ];
    }

    /**
     * Headcount per VISIBLE unit, over exactly the people counted by people().
     *
     * GROUPED IN SQL, not by loading every person and grouping in PHP — which is
     * what the Departments screen did: a full download of the tenant's people
     * list plus one twin request per department, N+1 against a remote database.
     *
     * Restricted to the units the Departments screen can actually show, so the
     * bars on that screen and the rows beneath them are the same population.
     * The parts therefore need not sum to people.total — some staff sit in units
     * this organization does not display — and people.inVisibleUnits publishes
     * that difference rather than leaving a reader to discover it.
     *
     * @return array<string, int>
     */
    private function peoplePerUnit(string $tenant): array
    {
        if (! $this->resolver->has($tenant, 'Person')) {
            return [];
        }

        $person = $this->resolver->resolve($tenant, 'Person');

        if (! $person->has('unit')) {
            return [];
        }

        $unitColumn = $person->field('unit');

        $query = DB::table($person->table)
            ->where($person->tenantKey, $tenant)
            ->whereNotNull($unitColumn)
            ->where($unitColumn, '!=', 0)
            ->groupBy($unitColumn)
            ->selectRaw("{$unitColumn} AS unit_id, COUNT(*) AS n");

        $this->activeSourceRows($query, $person);

        if ($person->has('status')) {
            $query->where($person->field('status'), 1);
        }

        /*
          whereIn over the visible unit ids rather than a join to the unit table.
          A join would need the visibility predicates qualified — `is_calculated`
          and `created_at` exist on BOTH tables in this ERP, so an unqualified
          `where('created_at', '>=', ...)` inside a join is ambiguous SQL. The id
          list is one row per department (tens, not thousands), so materialising
          it is cheaper than getting that qualification subtly wrong.
        */
        if ($this->resolver->has($tenant, 'OrganizationUnit')) {
            $visible = $this->visibility->visibleIds(
                $this->resolver->resolve($tenant, 'OrganizationUnit'),
                $tenant,
            );

            if ($visible === []) {
                return [];
            }

            $query->whereIn($unitColumn, $visible);
        }

        $out = [];

        foreach ($query->get() as $row) {
            $out[(string) $row->unit_id] = (int) $row->n;
        }

        return $out;
    }

    private function activeSourceRows(\Illuminate\Database\Query\Builder $query, \App\Domain\Universal\ResolvedSource $source): void
    {
        if ($source->has('deletedAt')) {
            $query->whereNull($source->field('deletedAt'));
        }
    }
}
