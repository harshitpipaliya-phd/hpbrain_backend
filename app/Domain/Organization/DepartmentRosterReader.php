<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use Illuminate\Support\Facades\DB;

/**
 * WHO IS ON EACH UNIT'S ROSTER, UNDER THE NAME THE RECORDS USE.
 *
 * One definition, because three things depend on it agreeing with itself: owner
 * attribution decides which unit a record belongs to, the roster panel decides
 * which person a record belongs to, and the nightly snapshot decides what
 * yesterday's score was. A name assembled two ways would attribute a record to a
 * department while denying it to everyone in that department, and the daily delta
 * would report movement that never happened.
 *
 * THE NAME IS `firstName` PLUS `lastName`, EXACTLY AS PersonProfileService BUILDS
 * IT. That is the string the operational records' `owner_name` column actually
 * carries on this ERP, and matching it is exact — no prefix, no fuzzy distance,
 * no initials. Two people called "R Patel" are not one person.
 */
final class DepartmentRosterReader
{
    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly DepartmentVisibilityScope $visibility,
        private readonly DepartmentWorkAttribution $attribution,
    ) {
    }

    /**
     * Every visible unit's roster, as owner names.
     *
     * ONE QUERY FOR THE ORGANIZATION. Owner attribution needs every unit's names
     * at once — see DepartmentWorkAttribution on why a rank cannot be built from
     * units measured two different ways — and asking per unit would be the N+1
     * this codebase forbids on the path a reader takes to open one department.
     *
     * @return array<string, array<int, string>>  unit id => full names
     */
    public function forTenant(string $tenant): array
    {
        try {
            $person = $this->resolver->resolve($tenant, 'Person');
            $unit = $this->resolver->resolve($tenant, 'OrganizationUnit');
        } catch (\Throwable) {
            return [];
        }

        if (! $person->has('unit') || ! $person->has('firstName')) {
            return [];
        }

        $visible = $this->visibility->visibleIds($unit, $tenant);

        if ($visible === []) {
            return [];
        }

        $query = DB::table($person->table)
            ->where($person->tenantKey, $tenant)
            ->whereIn($person->field('unit'), $visible);

        $this->activeRows($query, $person);

        $columns = [$person->field('unit'), $person->field('firstName')];

        if ($person->has('lastName')) {
            $columns[] = $person->field('lastName');
        }

        $out = [];

        foreach ($query->get($columns) as $raw) {
            $row = (array) $raw;
            $unitId = (string) ($row[$person->field('unit')] ?? '');

            // '0' is this ERP's "no department", not a department called zero.
            if ($unitId === '' || $unitId === '0') {
                continue;
            }

            $name = $this->displayName($row, $person);

            if ($name !== null) {
                $out[$unitId][] = $name;
            }
        }

        return $out;
    }

    /**
     * Owner-attributed work for every unit, ready for the scoring engine.
     *
     * @return array<string, array<string, mixed>>  unit id => attribution
     */
    public function ownerWorkFor(string $tenant, bool $fresh = false): array
    {
        return $this->attribution->forTenant($tenant, $this->forTenant($tenant), $fresh)['departments'] ?? [];
    }

    /**
     * The name a record would carry for this person, or null if they have none.
     *
     * @param  array<string, mixed>  $row
     */
    public function displayName(array $row, ResolvedSource $person): ?string
    {
        $first = (string) ($row[$person->field('firstName')] ?? '');
        $last = $person->has('lastName') ? (string) ($row[$person->field('lastName')] ?? '') : '';

        $name = trim($first.' '.$last);

        return $name === '' ? null : $name;
    }

    /** Only rows the source system still considers live. */
    public function activeRows(\Illuminate\Database\Query\Builder $query, ResolvedSource $source): void
    {
        if ($source->has('status')) {
            $query->where($source->field('status'), 1);
        }

        if ($source->has('deletedAt')) {
            $query->whereNull($source->field('deletedAt'));
        }
    }
}
