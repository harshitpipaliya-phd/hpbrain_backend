<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Intelligence\OrganizationDataProfiler;
use App\Domain\Universal\EntityResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Every table a tenant owns rows in, discovered from the live schema and
 * classified by who owns them.
 *
 * WHY THIS IS DISCOVERED AND NOT WRITTEN DOWN. The two lists this codebase
 * already keeps — EntityResolver's mappings and
 * OrganizationDataProfiler::LOOP_TABLES — describe READ paths, and between them
 * they name 25 tables. The live database has 331 tables carrying a tenant
 * column, 102 of them hpbrain_-owned. Driving a deletion from either list would
 * leave 83 Brain tables behind, including hpbrain_refresh_tokens and
 * hpbrain_entity_mappings — which are two of the three things keeping a
 * "deleted" organization able to sign in. LOOP_TABLES excludes them on purpose:
 * its docblock says identity and config tables are deliberately absent because
 * counting them would inflate completeness figures. That is right for
 * profiling and catastrophic for deletion.
 *
 * So the schema is the source of truth, and the two curated lists are used as
 * an ASSERTION instead: every table they name must turn up in the discovered
 * set, and missingReferences() reports it if one does not. That catches drift
 * in both directions without either list being load-bearing.
 *
 * FOUR TIERS, AND THE BOUNDARY BETWEEN THEM IS THE WHOLE SAFETY ARGUMENT.
 * See TenantTable. Briefly: hpbrain_ tables are destroyed, the ERP records that
 * constitute the organization and its logins are destroyed by tenant scope,
 * other applications' tables need an explicit acknowledgement, and anything
 * without a tenant column is never touched.
 *
 * RESERVED TENANT IDS ARE REFUSED OUTRIGHT. hpbrain_signal_rules,
 * hpbrain_industries and hpbrain_industry_templates hold rows under tenant_id
 * 'platform', and hpbrain_prompt_templates under '*'. Those are the shipped
 * rule definitions every tenant reads. Tenant scoping already protects them
 * from a delete aimed at organization 8, but nothing else would stop a caller
 * aiming a delete AT 'platform', so that is rejected before any query runs.
 */
final class TenantOwnedTables
{
    /**
     * Tenant ids that address platform-wide rows rather than an organization.
     *
     * Verified present in the live database: 'platform' in hpbrain_signal_rules,
     * hpbrain_industries and hpbrain_industry_templates; '*' in
     * hpbrain_prompt_templates.
     */
    public const RESERVED_TENANT_IDS = ['platform', '*', 'global', 'system', 'all', 'default'];

    /**
     * Columns that scope a row to a tenant, in the order they are preferred.
     *
     * tenant_id is the Brain's own convention; sub_institute_id is the institute
     * ERP's. A table carrying both is scoped by tenant_id, which is the Brain's.
     */
    private const TENANT_COLUMNS = ['tenant_id', 'sub_institute_id'];

    /**
     * Tables whose PRIMARY KEY is the tenant id rather than a column named after
     * it. A discovery pass keyed on column names cannot see these, and both
     * matter: hpbrain_tenants is the Brain's tenant registry, and school_setup
     * is the actual tenant root that roughly sixty ERP tables foreign-key into
     * as sub_institute_id.
     *
     * @var array<string, string> table => primary key column holding the tenant id
     */
    private const TENANT_KEYED_TABLES = [
        'hpbrain_tenants' => 'id',
        'school_setup'    => 'id',
    ];

    /**
     * Tables that are never touched even though they carry a tenant-shaped
     * column, because destroying them would damage the installation rather than
     * the tenant.
     *
     * @var array<int, string>
     */
    private const NEVER_DELETE = [
        'hpbrain_schema_migrations',
        'migrations',
    ];

    /**
     * How far below a plan table transitively-owned junction rows are followed.
     *
     * Three covers every chain in this schema (the deepest observed is two) and
     * bounds both the walk and the nesting of the scoping subquery. A chain
     * deeper than this is not silently truncated into a partial delete: the
     * foreign key still refuses and the transaction still rolls back, which is
     * the safe direction to fail in.
     */
    private const MAX_DEPENDENT_DEPTH = 3;

    public function __construct(
        private readonly EntityResolver $resolver,
    ) {
    }

    /**
     * Classify every table in the database against one tenant.
     *
     * @return array<int, TenantTable>
     */
    public function classify(string $tenantId): array
    {
        $identity = $this->identityTables($tenantId);
        $selfRefs = $this->selfReferencingColumns();

        $out = [];

        foreach ($this->tables() as $table) {
            if (in_array($table, self::NEVER_DELETE, true)) {
                continue;
            }

            $column = $this->tenantColumn($table);

            if ($column === null) {
                // No tenant column and not tenant-keyed: global by construction.
                // Not reported — the database is full of ERP lookup tables and
                // listing all of them as "preserved" would bury the tables that
                // are preserved for an interesting reason.
                continue;
            }

            $tier = $this->tierFor($table, $identity);

            $out[] = new TenantTable(
                table: $table,
                tenantColumn: $column,
                tier: $tier,
                selfReferenceColumn: $selfRefs[$table] ?? null,
            );
        }

        return $out;
    }

    /**
     * The same classification, with this tenant's row count on every table and
     * empty ones dropped.
     *
     * COUNTED ONE TABLE AT A TIME AND THAT IS DELIBERATE. The obvious
     * optimisation is a UNION ALL across every table, which is what
     * OrganizationDataProfiler does for its 19 loop tables. It is not safe here:
     * this set is discovered rather than curated, so a single table the caller's
     * database types differently — sub_institute_id is BIGINT on the ERP and
     * VARCHAR(36) on hpbrain_ — collapses the entire union and would report a
     * tenant as holding nothing. A wrong zero here means data silently left
     * behind, so each count stands or falls alone.
     *
     * @param  array<int, TenantTable>  $tables
     * @return array<int, TenantTable>
     */
    public function withCounts(string $tenantId, array $tables): array
    {
        $out = [];

        foreach ($tables as $table) {
            try {
                $rows = (int) DB::table($table->table)
                    ->where($table->tenantColumn, $tenantId)
                    ->count();
            } catch (Throwable) {
                // A table that cannot be counted is reported rather than
                // skipped: it is still going to be deleted from, and a caller
                // reviewing the plan should see it.
                $out[] = $table->withRows(0);

                continue;
            }

            if ($rows > 0) {
                $out[] = $table->withRows($rows);
            }
        }

        return $out;
    }

    /**
     * Deletion order: dependents strictly before the rows they depend on.
     *
     * Computed from the live foreign keys, because 41 of the 42 foreign keys on
     * hpbrain_ tables are ON DELETE RESTRICT — the database refuses rather than
     * cascades, so a plausible-looking alphabetical sweep fails partway through
     * with hpbrain_case_evidence still pointing at hpbrain_cases. There are also
     * ZERO foreign keys referencing institute_detail, so nothing about this
     * order can be inferred from the organization row itself.
     *
     * On a driver that reports no foreign keys (SQLite in the test suite) the
     * input order is returned unchanged, which is correct: with no constraints
     * declared there is nothing to violate.
     *
     * @param  array<int, TenantTable>  $tables
     * @return array<int, TenantTable>
     */
    public function inDeletionOrder(array $tables): array
    {
        $names = [];
        foreach ($tables as $t) {
            $names[$t->table] = $t;
        }

        // dependents[B] = tables that reference B, so they must go first.
        $dependents = [];

        foreach ($this->foreignKeys() as [$from, $to]) {
            if ($from === $to || ! isset($names[$from], $names[$to])) {
                continue;
            }

            $dependents[$to][$from] = true;
        }

        $ordered = [];
        $state   = [];

        $visit = function (string $table) use (&$visit, &$ordered, &$state, $dependents, $names): void {
            // 'open' means we re-entered a cycle. Emitting here rather than
            // recursing forever is the only option a cycle leaves, and the
            // deletes still run inside one transaction, so a genuine constraint
            // problem rolls the whole thing back rather than half-deleting.
            if (isset($state[$table])) {
                return;
            }

            $state[$table] = 'open';

            foreach (array_keys($dependents[$table] ?? []) as $dependent) {
                $visit($dependent);
            }

            $state[$table] = 'done';
            $ordered[] = $names[$table];
        };

        foreach (array_keys($names) as $table) {
            $visit($table);
        }

        return $ordered;
    }

    /**
     * Rows the plan cannot see, but which the plan's own deletes trip over.
     *
     * THE BUG THIS CLOSES. classify() finds tables by looking for a tenant
     * column, which is the right way to find everything a tenant owns DIRECTLY.
     * It is blind to a table that owns nothing itself and exists only to join
     * two rows together, and inDeletionOrder() is blind to it a second time
     * because it only orders edges whose BOTH ends were found. hp_erp's
     * content_mapping_type is exactly that table — id, content_id,
     * mapping_type_id, mapping_value_id, no tenant column anywhere — and its
     * content_id foreign key into the tenant-scoped content_master is
     * ON DELETE NO ACTION. The sweep deleted content_master's rows, InnoDB
     * refused because junction rows still referenced them, and the whole
     * transaction unwound: the organization survived its own deletion.
     *
     * OWNERSHIP IS DERIVED, NOT ASSUMED. A row qualifies only when its foreign
     * key points at a row this tenant owns, so the junction table is filtered
     * rather than emptied — see scopedDependentQuery().
     *
     * Walked breadth-first so a junction hanging off a junction is found too,
     * and returned deepest-first, which is the order the deletes must run in.
     *
     * @param  array<int, TenantTable>  $planTables
     * @return array<int, TenantDependentRows>
     */
    public function dependentRows(array $planTables): array
    {
        $plan = [];
        foreach ($planTables as $t) {
            $plan[$t->table] = $t;
        }

        /** @var array<string, array<int, array{0:string,1:string,2:string}>> parent => [child, childCol, parentCol] */
        $children = [];
        foreach ($this->foreignKeyColumns() as [$child, $childCol, $parent, $parentCol]) {
            if ($child === $parent) {
                continue; // self-references are nulled by TenantPurgeService
            }

            $children[$parent][] = [$child, $childCol, $parentCol];
        }

        $out   = [];
        $seen  = [];
        $queue = [];

        foreach ($plan as $table => $planTable) {
            $queue[] = [$table, $planTable, []];
        }

        while ($queue !== []) {
            [$parent, $root, $path] = array_shift($queue);

            if (count($path) >= self::MAX_DEPENDENT_DEPTH) {
                continue;
            }

            foreach ($children[$parent] ?? [] as [$child, $childCol, $parentCol]) {
                // Already destroyed by the plan's own tenant-scoped sweep.
                if (isset($plan[$child]) || in_array($child, self::NEVER_DELETE, true)) {
                    continue;
                }

                // A dependent that HAS a tenant column is not transitively
                // owned — it names its own owner. It is either in the plan
                // (handled above) or holds no rows for this tenant at all, and
                // any row of it referencing this tenant belongs to a DIFFERENT
                // organization. Those are conflicts, not collateral:
                // crossTenantConflicts() reports them and the purge refuses.
                if ($this->tenantColumn($child) !== null) {
                    continue;
                }

                $key = $child.'|'.$childCol.'|'.$parent.'|'.$parentCol;

                if (isset($seen[$key])) {
                    continue;
                }

                // A cycle would put the dependent table inside its own scoping
                // subquery, and MySQL refuses that outright — error 1093, "you
                // can't specify target table for update in FROM clause". The
                // constraint would be left unsatisfied and the transaction
                // would roll back, which is precisely the failure mode being
                // fixed, so the chain stops rather than being built.
                if ($child === $root->table || $child === $parent || $this->pathTouches($path, $child)) {
                    continue;
                }

                $seen[$key] = true;

                $next = array_merge(
                    [['table' => $parent, 'column' => $childCol, 'parentColumn' => $parentCol]],
                    $path,
                );

                $mode = TenantDependentRows::modeFor($childCol);

                $out[] = new TenantDependentRows(
                    table: $child,
                    column: $childCol,
                    path: $next,
                    rootTable: $root->table,
                    rootTenantColumn: $root->tenantColumn,
                    tier: $root->tier,
                    mode: $mode,
                );

                // Only an OWNERSHIP edge is followed further. A row that merely
                // records who edited it is not this tenant's, so nothing hanging
                // off it is either — walking through it would turn one shared
                // lookup row into a path to somebody else's data.
                if ($mode === TenantDependentRows::MODE_DELETE) {
                    $queue[] = [$child, $root, $next];
                }
            }
        }

        // Deepest first: a junction under a junction must go before the
        // junction it hangs off, or it simply becomes the next blocker.
        usort($out, static fn (TenantDependentRows $a, TenantDependentRows $b): int => $b->depth() <=> $a->depth());

        return $out;
    }

    /**
     * Whether a table already appears in a dependency chain.
     *
     * @param  array<int, array{table: string, column: string, parentColumn: string}>  $path
     */
    private function pathTouches(array $path, string $table): bool
    {
        foreach ($path as $step) {
            if ($step['table'] === $table) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same list with this tenant's row count on each, empties dropped.
     *
     * @param  array<int, TenantDependentRows>  $dependents
     * @return array<int, TenantDependentRows>
     */
    public function dependentRowsWithCounts(string $tenantId, array $dependents): array
    {
        $out = [];

        foreach ($dependents as $dependent) {
            try {
                $rows = (int) $this->scopedDependentQuery($tenantId, $dependent)->count();
            } catch (Throwable) {
                // Counted one at a time and skipped on failure for the same
                // reason withCounts() does it: a type mismatch on one join must
                // not take the whole plan down with it.
                continue;
            }

            if ($rows > 0) {
                $out[] = $dependent->withRows($rows);
            }
        }

        return $out;
    }

    /**
     * Exactly the rows of a dependent table this tenant owns, selected by
     * following the foreign key back to a row the plan deletes.
     *
     * THE NESTING IS THE SAFETY PROPERTY. The innermost WHERE is the tenant
     * scope, so a junction row pointing at another organization's parent is
     * never selected. On the live database content_mapping_type holds 56 rows
     * and this matches the 7 that belong to Fiber Valley.
     */
    public function scopedDependentQuery(string $tenantId, TenantDependentRows $dependent): Builder
    {
        $build = function (array $path) use (&$build, $tenantId, $dependent): callable {
            $step = $path[0];
            $rest = array_slice($path, 1);

            return function ($q) use ($step, $rest, $build, $tenantId, $dependent): void {
                $q->select($step['parentColumn'])->from($step['table']);

                if ($rest === []) {
                    $q->where($dependent->rootTenantColumn, $tenantId);

                    return;
                }

                $q->whereNotNull($rest[0]['column'])->whereIn($rest[0]['column'], $build($rest));
            };
        };

        return DB::table($dependent->table)
            ->whereNotNull($dependent->column)
            ->whereIn($dependent->column, $build($dependent->path));
    }

    /**
     * Dependent tables that DO declare an owner and hold rows belonging to
     * someone else which point at this tenant's rows.
     *
     * These must stop the deletion rather than be swept up by it. Deleting them
     * would destroy another organization's data purely to let this deletion
     * through; leaving them makes the foreign key refuse, which is the very
     * rollback this work exists to eliminate. So they are found first and
     * reported by name, before anything is deleted.
     *
     * @param  array<int, TenantTable>  $planTables
     * @return array<int, array<string, mixed>>
     */
    public function crossTenantConflicts(string $tenantId, array $planTables): array
    {
        $plan = [];
        foreach ($planTables as $t) {
            $plan[$t->table] = $t;
        }

        $conflicts = [];
        $seen      = [];

        foreach ($this->foreignKeyColumns() as [$child, $childCol, $parent, $parentCol]) {
            if ($child === $parent || isset($plan[$child]) || ! isset($plan[$parent])) {
                continue;
            }

            $childTenantColumn = $this->tenantColumn($child);

            if ($childTenantColumn === null) {
                continue; // transitively owned — handled by dependentRows()
            }

            $key = $child.'|'.$childCol;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            try {
                $rows = (int) DB::table($child)
                    ->whereNotNull($childCol)
                    // NULL is included deliberately. `!= '7'` is false for NULL
                    // in SQL, so a row whose owner is unrecorded would slip
                    // past this check and then break the delete from inside the
                    // transaction — the exact rollback being fixed. A row that
                    // references this tenant and declines to say who owns it is
                    // not provably ours, so it is reported rather than deleted.
                    ->where(function ($w) use ($childTenantColumn, $tenantId): void {
                        $w->where($childTenantColumn, '!=', $tenantId)
                            ->orWhereNull($childTenantColumn);
                    })
                    ->whereIn($childCol, function ($q) use ($parent, $parentCol, $plan, $tenantId): void {
                        $q->select($parentCol)->from($parent)->where($plan[$parent]->tenantColumn, $tenantId);
                    })
                    ->count();
            } catch (Throwable) {
                // A comparison this driver cannot make — the BIGINT/VARCHAR
                // tenant-id split this class already documents — is not
                // evidence of a conflict, so it is not reported as one.
                continue;
            }

            if ($rows > 0) {
                $conflicts[] = [
                    'table'  => $child,
                    'column' => $childCol,
                    'via'    => $parent,
                    'rows'   => $rows,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Tables named by the two curated lists that discovery did not find.
     *
     * A cross-check, not a source. An entry here means either the migration has
     * not run or a curated list has gone stale, and both are worth saying out
     * loud before anyone destroys an organization on the strength of this plan.
     *
     * @param  array<int, TenantTable>  $tables
     * @return array<int, string>
     */
    public function missingReferences(string $tenantId, array $tables): array
    {
        $found = array_flip(array_map(static fn (TenantTable $t) => $t->table, $tables));
        $expected = array_keys(OrganizationDataProfiler::LOOP_TABLES);

        foreach ($this->identityTables($tenantId) as $table) {
            $expected[] = $table;
        }

        $missing = [];

        foreach (array_unique($expected) as $table) {
            if (! isset($found[$table])) {
                $missing[] = $table;
            }
        }

        sort($missing);

        return $missing;
    }

    /**
     * The ERP tables this tenant's identity and organization records live in,
     * taken from EntityResolver — the same mechanism every tenant-scoped read in
     * the application already trusts, so a tenant on different tables gets its
     * own tables deleted rather than the institute ERP's.
     *
     * @return array<int, string>
     */
    public function identityTables(string $tenantId): array
    {
        // school_setup is the tenant root and belongs here. hpbrain_tenants is
        // also tenant-keyed but is Brain-owned, so it is left to fall through to
        // the hpbrain_ rule and be classified as TIER_BRAIN.
        $tables = ['school_setup'];

        foreach (EntityResolver::ENTITIES as $entity) {
            try {
                if (! $this->resolver->has($tenantId, $entity)) {
                    continue;
                }

                $tables[] = $this->resolver->resolve($tenantId, $entity)->table;
            } catch (Throwable) {
                // An unmapped or half-mapped entity contributes nothing. It is
                // surfaced by missingReferences() rather than thrown here, so a
                // tenant whose mappings are already partly gone can still be
                // cleaned up.
                continue;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Whether this tenant id addresses platform-wide rows rather than an
     * organization.
     */
    public static function isReserved(string $tenantId): bool
    {
        return $tenantId === ''
            || in_array(strtolower($tenantId), self::RESERVED_TENANT_IDS, true);
    }

    /* ─────────────────────────── schema introspection ─────────────────────────── */

    /** @param array<int, string> $identity */
    private function tierFor(string $table, array $identity): string
    {
        if (in_array($table, $identity, true)) {
            return TenantTable::TIER_IDENTITY;
        }

        if (str_starts_with($table, 'hpbrain_')) {
            return TenantTable::TIER_BRAIN;
        }

        return TenantTable::TIER_SOURCE_SYSTEM;
    }

    private function tenantColumn(string $table): ?string
    {
        if (isset(self::TENANT_KEYED_TABLES[$table])) {
            return self::TENANT_KEYED_TABLES[$table];
        }

        $columns = $this->columns($table);

        foreach (self::TENANT_COLUMNS as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /** @var array<int, string>|null */
    private ?array $tableCache = null;

    /** @return array<int, string> */
    private function tables(): array
    {
        if ($this->tableCache !== null) {
            return $this->tableCache;
        }

        $names = Schema::getTableListing();

        // Some drivers qualify the name with the schema; the deletes address
        // bare table names, so both must agree.
        $names = array_map(
            static fn (string $n): string => str_contains($n, '.') ? substr($n, (int) strrpos($n, '.') + 1) : $n,
            $names,
        );

        sort($names);

        return $this->tableCache = $names;
    }

    /** @var array<string, array<string, true>> */
    private array $columnCache = [];

    /** @return array<string, true> */
    private function columns(string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }

        try {
            $columns = array_flip(Schema::getColumnListing($table));
        } catch (Throwable) {
            $columns = [];
        }

        return $this->columnCache[$table] = array_map(static fn () => true, $columns);
    }

    /**
     * Every foreign key in the database as [dependent table, referenced table].
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function foreignKeys(): array
    {
        try {
            $driver = DB::connection()->getDriverName();

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $rows = DB::select(
                    'SELECT TABLE_NAME AS dependent, REFERENCED_TABLE_NAME AS referenced
                       FROM information_schema.KEY_COLUMN_USAGE
                      WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                    [DB::connection()->getDatabaseName()],
                );

                return array_map(
                    static fn ($r): array => [(string) $r->dependent, (string) $r->referenced],
                    $rows,
                );
            }

            $out = [];

            foreach ($this->tables() as $table) {
                foreach (Schema::getForeignKeys($table) as $fk) {
                    $referenced = $fk['foreign_table'] ?? null;

                    if (is_string($referenced) && $referenced !== '') {
                        $out[] = [$table, $referenced];
                    }
                }
            }

            return $out;
        } catch (Throwable) {
            // No foreign-key metadata available. The caller falls back to the
            // order it already has, and the transaction still protects it.
            return [];
        }
    }

    /**
     * Every foreign key as [dependent, dependentColumn, referenced, referencedColumn].
     *
     * foreignKeys() above answers "which table depends on which", which is all
     * an ordering needs. Following ownership THROUGH a foreign key needs the
     * columns as well, so this is the same introspection kept at full width.
     * Memoised because both dependentRows() and crossTenantConflicts() walk it.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private function foreignKeyColumns(): array
    {
        if ($this->fkColumnCache !== null) {
            return $this->fkColumnCache;
        }

        try {
            $driver = DB::connection()->getDriverName();

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $rows = DB::select(
                    'SELECT TABLE_NAME AS dependent, COLUMN_NAME AS dependent_column,
                            REFERENCED_TABLE_NAME AS referenced, REFERENCED_COLUMN_NAME AS referenced_column
                       FROM information_schema.KEY_COLUMN_USAGE
                      WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                    [DB::connection()->getDatabaseName()],
                );

                return $this->fkColumnCache = array_map(
                    static fn ($r): array => [
                        (string) $r->dependent,
                        (string) $r->dependent_column,
                        (string) $r->referenced,
                        (string) $r->referenced_column,
                    ],
                    $rows,
                );
            }

            $out = [];

            foreach ($this->tables() as $table) {
                foreach (Schema::getForeignKeys($table) as $fk) {
                    $referenced = $fk['foreign_table'] ?? null;
                    $columns    = $fk['columns'] ?? [];
                    $foreign    = $fk['foreign_columns'] ?? [];

                    // Composite keys are not followed. A partial scope on one
                    // column of a two-column key would select rows this tenant
                    // does not own, and that is the one mistake this whole
                    // class is written to avoid.
                    if (! is_string($referenced) || $referenced === '' || count($columns) !== 1 || count($foreign) !== 1) {
                        continue;
                    }

                    $out[] = [$table, (string) $columns[0], $referenced, (string) $foreign[0]];
                }
            }

            return $this->fkColumnCache = $out;
        } catch (Throwable) {
            // No foreign-key metadata available (SQLite in the suite). With no
            // constraints declared there is nothing to trip over and nothing to
            // follow, so an empty graph is the correct answer rather than a
            // degraded one.
            return $this->fkColumnCache = [];
        }
    }

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}>|null */
    private ?array $fkColumnCache = null;

    /**
     * Columns that point at their own table — hpbrain_capability_tasks.
     * parent_task_id, hpbrain_policies.previous_version_id and
     * hpbrain_prompt_templates.previous_version_id in this schema.
     *
     * They matter because their foreign keys are ON DELETE RESTRICT, so a single
     * `DELETE ... WHERE tenant_id = ?` removing both a parent row and its child
     * can fail depending on the order the engine happens to visit rows in.
     * TenantPurgeService nulls these first.
     *
     * @return array<string, string> table => column
     */
    private function selfReferencingColumns(): array
    {
        try {
            $driver = DB::connection()->getDriverName();

            if ($driver !== 'mysql' && $driver !== 'mariadb') {
                return [];
            }

            $rows = DB::select(
                'SELECT TABLE_NAME AS t, COLUMN_NAME AS c
                   FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = TABLE_NAME',
                [DB::connection()->getDatabaseName()],
            );

            $out = [];

            foreach ($rows as $row) {
                $out[(string) $row->t] = (string) $row->c;
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}
