<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Intelligence\OrganizationDataProfiler;
use App\Domain\Universal\EntityResolver;
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
