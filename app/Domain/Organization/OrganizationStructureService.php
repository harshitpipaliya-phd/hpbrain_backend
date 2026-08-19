<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Domain\School\AcademicSections;
use App\Domain\Universal\EntityResolver;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE ANSWER to "what are this organization's departments".
 *
 * WHY THIS EXISTS. Five places computed a department count and they did not
 * agree. WorkspaceController::homeMetrics, AnalyticsController::organizationReport
 * and OrganizationController::dataQuality each ran their own COUNT over the
 * mapped OrganizationUnit table; DepartmentController::index ran a fourth with a
 * visibility filter the others lacked; and the Departments screen had since
 * grown a fifth notion — the teaching sections a school is actually organised
 * by. Lions ended up showing 4 departments on the Departments screen and 0 on
 * the Organization overview, both of them honestly derived and neither of them
 * reconcilable with the other.
 *
 * Every one of those callers now asks this class. There is no second query.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE RULE, and it is universal — no tenant is named anywhere in this file.
 *
 *   1. An organization's departments are the units its CONNECTED SOURCE SYSTEM
 *      records, narrowed by DepartmentVisibilityScope (which drops ERP template
 *      rows and superseded cohorts — see that class for why). If there is at
 *      least one, those are the departments. Full stop.
 *
 *   2. Where the source system records NONE and the organization is
 *      dataset-backed, its departments are the teaching sections derived from
 *      the standards its students are recorded in
 *      (App\Domain\School\AcademicSections). An organization is structured even
 *      when its HR system has not been filled in, and reporting zero for a
 *      school with four teaching sections and seven thousand children is
 *      technically true and practically useless.
 *
 *   3. Otherwise there are none, and every screen says zero.
 *
 * WHAT MAKES THIS UNIVERSAL RATHER THAN A SPECIAL CASE. Rule 2 fires on a
 * PROPERTY of the data — no HR units, and a student projection that yields
 * sections — not on an identity. An organization that later imports its HR
 * departments moves to rule 1 automatically and nothing here changes; one that
 * has never imported students stays on rule 3 and sees an honest zero. A new
 * tenant created tomorrow is classified by the same three questions.
 *
 * EVERY DEPARTMENT CARRIES ITS PROVENANCE. `source` is 'hr' or 'academic', and
 * `memberType` is 'staff' or 'students' to match. Nothing downstream has to
 * guess which population a headcount describes, and no screen can print a
 * student count under a staff label — the mistake that produced the last round
 * of contradictions.
 *
 * TENANT SCOPE IS NOT OPTIONAL. Both branches filter on the tenant key before
 * anything else, and the tenant reaches this class from the caller's auth token
 * rather than from a URL. There is no code path that returns another
 * organization's units.
 *
 * NOTHING HERE IS STORED. Both branches are read-side derivations over data the
 * organization already owns; no row is created, and an organization's HR tables
 * are never written to in order to make a count look better.
 */
final class OrganizationStructureService
{
    public const SOURCE_HR = 'hr';

    public const SOURCE_ACADEMIC = 'academic';

    public const SOURCE_NONE = 'none';

    /** @var array<string, array<string, mixed>> memoised for the life of the request */
    private array $memo = [];

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly DepartmentVisibilityScope $visibility,
        private readonly AcademicSections $sections,
    ) {
    }

    /**
     * This organization's departments, whatever they are derived from.
     *
     * @return array{
     *   source: string,
     *   memberType: string,
     *   departments: array<int, array<string, mixed>>,
     *   total: int,
     *   active: int,
     *   inactive: int,
     *   membersInDepartments: int,
     *   membersOutside: int
     * }
     */
    public function forTenant(string $tenant): array
    {
        return $this->memo[$tenant] ??= $this->derive($tenant);
    }

    /**
     * The number every screen must publish.
     *
     * Active units, because that is what "how many departments does this
     * organization have" means to a reader — an archived unit is not one the
     * organization runs. total() is available beside it for the screens that
     * distinguish them.
     */
    public function departmentCount(string $tenant): int
    {
        return $this->forTenant($tenant)['active'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDepartmentsForOrganization(string $tenant): array
    {
        return $this->forTenant($tenant)['departments'];
    }

    /**
     * Members per department id, over exactly the departments above.
     *
     * The members are STAFF when the departments came from the HR system and
     * STUDENTS when they came from the academic sections; memberType() says
     * which. They are never added together and never relabelled.
     *
     * @return array<string, int>
     */
    public function getPeopleCountByDepartment(string $tenant): array
    {
        $out = [];

        foreach ($this->forTenant($tenant)['departments'] as $department) {
            $out[(string) $department['id']] = (int) $department['members'];
        }

        return $out;
    }

    /** 'staff' | 'students' | 'none' — what getPeopleCountByDepartment counts. */
    public function memberType(string $tenant): string
    {
        return $this->forTenant($tenant)['memberType'];
    }

    /** 'hr' | 'academic' | 'none' — where the departments came from. */
    public function source(string $tenant): string
    {
        return $this->forTenant($tenant)['source'];
    }

    /**
     * Whether the departments are real units of a connected source system.
     *
     * The one question callers legitimately need the answer to: a data-quality
     * report may only raise "department has no head" against a unit that HAS a
     * head field, and a derived teaching section has none.
     */
    public function isSourceSystemBacked(string $tenant): bool
    {
        return $this->forTenant($tenant)['source'] === self::SOURCE_HR;
    }

    /**
     * @return array<string, mixed>
     */
    private function derive(string $tenant): array
    {
        $hr = $this->fromSourceSystem($tenant);

        // Rule 1. Present takes precedence, always — an organization that
        // records its own units is never told what its structure is.
        if ($hr['total'] > 0) {
            return $hr;
        }

        // Rule 2.
        $academic = $this->fromAcademicSections($tenant);

        if ($academic['total'] > 0) {
            return $academic;
        }

        // Rule 3.
        return [
            'source' => self::SOURCE_NONE,
            'memberType' => 'none',
            'departments' => [],
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'membersInDepartments' => 0,
            'membersOutside' => 0,
        ];
    }

    /**
     * Units the connected source system records, and their staff.
     *
     * @return array<string, mixed>
     */
    private function fromSourceSystem(string $tenant): array
    {
        if (! $this->resolver->has($tenant, 'OrganizationUnit')) {
            return ['source' => self::SOURCE_HR, 'memberType' => 'staff', 'departments' => [],
                'total' => 0, 'active' => 0, 'inactive' => 0, 'membersInDepartments' => 0, 'membersOutside' => 0];
        }

        $unit = $this->resolver->resolve($tenant, 'OrganizationUnit');

        $query = DB::table($unit->table)
            ->where($unit->tenantKey, $tenant)
            ->whereNull('deleted_at')
            ->orderBy($unit->primaryKey);

        // The same filter the Departments list applies. Applying it here is the
        // whole point of the class: the count and the list are one query shape.
        $this->visibility->apply($query, $unit, $tenant);

        $rows = $query->get();
        $perUnit = $this->staffPerUnit($tenant, $rows->pluck($unit->primaryKey)->map(fn ($id) => (string) $id)->all());

        $statusColumn = $unit->has('status') ? $unit->field('status') : null;
        $parentColumn = $unit->has('parent') ? $unit->field('parent') : null;

        $departments = $rows->map(function ($row) use ($unit, $statusColumn, $parentColumn, $perUnit) {
            $id = (string) $row->{$unit->primaryKey};

            return [
                'id' => $id,
                'name' => (string) ($row->{$unit->field('name')} ?? ''),
                'source' => self::SOURCE_HR,
                'status' => $statusColumn === null || (int) $row->{$statusColumn} === 1 ? 'active' : 'inactive',
                'parentId' => $parentColumn !== null && ! empty($row->{$parentColumn}) ? (string) $row->{$parentColumn} : null,
                // Null, not zero: this ERP has no department-head column, and
                // an absent field is not an empty one.
                'headId' => $unit->has('head') && ! empty($row->{$unit->field('head')})
                    ? (string) $row->{$unit->field('head')}
                    : null,
                'members' => $perUnit[$id] ?? 0,
                'memberType' => 'staff',
                'standards' => null,
                'updatedDate' => $row->updated_at ?? null,
            ];
        })->values()->all();

        $active = count(array_filter($departments, fn ($d) => $d['status'] === 'active'));
        $inDepartments = array_sum(array_column($departments, 'members'));

        return [
            'source' => self::SOURCE_HR,
            'memberType' => 'staff',
            'departments' => $departments,
            'total' => count($departments),
            'active' => $active,
            'inactive' => count($departments) - $active,
            'membersInDepartments' => $inDepartments,
            'membersOutside' => max(0, $this->activeStaffCount($tenant) - $inDepartments),
        ];
    }

    /**
     * Teaching sections, and their students.
     *
     * @return array<string, mixed>
     */
    private function fromAcademicSections(string $tenant): array
    {
        $derived = $this->sections->forTenant($tenant);

        $departments = array_map(fn (array $section): array => [
            'id' => $section['id'],
            'name' => $section['name'],
            'source' => self::SOURCE_ACADEMIC,
            'status' => 'active',
            'parentId' => null,
            // A derived section has no head, and cannot: there is no person
            // record behind it. Null says "not recorded", which is the truth.
            'headId' => null,
            'members' => $section['students'],
            'memberType' => 'students',
            'standards' => $section['standards'],
            'updatedDate' => null,
            // Carried through so a screen can show a section without a second
            // request; ignored by callers that only want the count.
            'academicRecords' => $section['academicRecords'],
            'feeRecords' => $section['feeRecords'],
            'feesCollected' => $section['feesCollected'],
            'averagePercentage' => $section['averagePercentage'],
        ], $derived['sections']);

        $inDepartments = array_sum(array_column($departments, 'members'));

        return [
            'source' => self::SOURCE_ACADEMIC,
            'memberType' => 'students',
            'departments' => $departments,
            'total' => count($departments),
            'active' => count($departments),
            'inactive' => 0,
            'membersInDepartments' => $inDepartments,
            // Students whose recorded standard no section can read. Published,
            // so the parts and the whole always reconcile.
            'membersOutside' => (int) ($derived['totals']['unplaced'] ?? 0),
        ];
    }

    /**
     * Active staff per unit id — one GROUP BY, restricted to the units passed.
     *
     * @param  array<int, string>  $unitIds
     * @return array<string, int>
     */
    private function staffPerUnit(string $tenant, array $unitIds): array
    {
        if ($unitIds === [] || ! $this->resolver->has($tenant, 'Person')) {
            return [];
        }

        $person = $this->resolver->resolve($tenant, 'Person');

        if (! $person->has('unit')) {
            return [];
        }

        $unitColumn = $person->field('unit');

        $query = DB::table($person->table)
            ->where($person->tenantKey, $tenant)
            ->whereNull('deleted_at')
            ->whereIn($unitColumn, $unitIds)
            ->groupBy($unitColumn)
            ->selectRaw("{$unitColumn} AS unit_id, COUNT(*) AS n");

        if ($person->has('status')) {
            $query->where($person->field('status'), 1);
        }

        $out = [];

        foreach ($query->get() as $row) {
            $out[(string) $row->unit_id] = (int) $row->n;
        }

        return $out;
    }

    private function activeStaffCount(string $tenant): int
    {
        if (! $this->resolver->has($tenant, 'Person')) {
            return 0;
        }

        $person = $this->resolver->resolve($tenant, 'Person');

        $query = DB::table($person->table)
            ->where($person->tenantKey, $tenant)
            ->whereNull('deleted_at');

        if ($person->has('status')) {
            $query->where($person->field('status'), 1);
        }

        return $query->count();
    }
}
