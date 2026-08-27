<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * What a permanent deletion would destroy, in the order it would do it.
 *
 * Produced before anything is written and returned by the preview endpoint, so
 * an administrator can see the row counts per table and per tier before typing
 * an organization's name to confirm. The same object is what the executor
 * walks, so what is previewed and what runs cannot drift apart.
 */
final class TenantDeletionPlan
{
    /**
     * @param  array<int, TenantTable>  $tables  in deletion order
     * @param  array<int, string>  $missingReferences
     * @param  array<int, TenantDependentRows>  $dependents  transitively-owned
     *         junction rows, deepest first. Deleted BEFORE $tables, because
     *         every one of them is a foreign key pointing at a row $tables
     *         removes.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $organizationName,
        public readonly array $tables,
        public readonly array $missingReferences = [],
        public readonly array $dependents = [],
    ) {
    }

    /**
     * Transitively-owned rows in a given tier.
     *
     * A junction hanging off an LMS table is LMS data and is acknowledged as
     * such; it does not become Brain-owned by having no tenant column.
     *
     * @return array<int, TenantDependentRows>
     */
    public function dependentsInTier(string $tier): array
    {
        return array_values(array_filter(
            $this->dependents,
            static fn (TenantDependentRows $d): bool => $d->tier === $tier,
        ));
    }

    public function dependentRowCount(): int
    {
        return array_sum(array_map(
            static fn (TenantDependentRows $d): int => $d->rows,
            $this->dependents,
        ));
    }

    /** @return array<int, TenantTable> */
    public function tier(string $tier): array
    {
        return array_values(array_filter(
            $this->tables,
            static fn (TenantTable $t): bool => $t->tier === $tier,
        ));
    }

    /** @return array<int, TenantTable> */
    public function sourceSystemTables(): array
    {
        return $this->tier(TenantTable::TIER_SOURCE_SYSTEM);
    }

    public function rowsInTier(string $tier): int
    {
        return array_sum(array_map(
            static fn (TenantTable $t): int => $t->rows ?? 0,
            $this->tier($tier),
        )) + array_sum(array_map(
            static fn (TenantDependentRows $d): int => $d->rows,
            $this->dependentsInTier($tier),
        ));
    }

    public function totalRows(): int
    {
        return array_sum(array_map(
            static fn (TenantTable $t): int => $t->rows ?? 0,
            $this->tables,
        )) + $this->dependentRowCount();
    }

    /**
     * The plan without the source-system tier, for a caller who has not
     * acknowledged it.
     */
    public function withoutSourceSystem(): self
    {
        return new self(
            $this->tenantId,
            $this->organizationName,
            array_values(array_filter(
                $this->tables,
                static fn (TenantTable $t): bool => $t->tier !== TenantTable::TIER_SOURCE_SYSTEM,
            )),
            $this->missingReferences,
            // Dropped alongside their parents. A junction row whose parent is
            // being preserved must be preserved too, or the deletion leaves a
            // reference pointing at a row that is still there.
            array_values(array_filter(
                $this->dependents,
                static fn (TenantDependentRows $d): bool => $d->tier !== TenantTable::TIER_SOURCE_SYSTEM,
            )),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenantId'         => $this->tenantId,
            'organizationName' => $this->organizationName,
            'totals'           => [
                'rows'         => $this->totalRows(),
                'tables'       => count($this->tables),
                'brain'        => $this->rowsInTier(TenantTable::TIER_BRAIN),
                'identity'     => $this->rowsInTier(TenantTable::TIER_IDENTITY),
                'sourceSystem' => $this->rowsInTier(TenantTable::TIER_SOURCE_SYSTEM),
            ],
            'tables' => array_map(static fn (TenantTable $t) => $t->toArray(), $this->tables),
            // Reported apart from `tables` because they are a different kind of
            // thing: rows in a table this tenant does not own, scoped by a
            // foreign key into one it does.
            'dependents' => array_map(static fn (TenantDependentRows $d) => $d->toArray(), $this->dependents),
            // Named rather than counted: an administrator reviewing a deletion
            // needs to know WHICH expected table is absent, because "the
            // migration has not run" and "this tenant genuinely has none" lead
            // to opposite decisions.
            'missingReferences' => $this->missingReferences,
        ];
    }
}
