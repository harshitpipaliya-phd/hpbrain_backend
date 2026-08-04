<?php

declare(strict_types=1);

namespace App\Domain\Universal;

use Illuminate\Support\Facades\DB;

/**
 * Resolves universal entity names to the tables a given tenant keeps them in.
 *
 * The Brain's own vocabulary is fixed — Organization, OrganizationUnit, Person,
 * Position — while the tables underneath vary per customer. A school keeps
 * people in tbluser; a hospital will keep them somewhere else entirely. Every
 * query in the application used to name the school's tables directly, which is
 * why onboarding a second industry meant editing code. This class is the seam
 * that makes it configuration instead.
 *
 * IT FAILS CLOSED, AND THAT IS THE MOST IMPORTANT THING ABOUT IT.
 *
 * There is no default, no fallback, no "if unmapped, assume tbluser". A tenant
 * with no mapping for an entity gets an exception. The tempting alternative —
 * defaulting to the school's tables so nothing breaks during migration — would
 * mean a hospital tenant silently reading a school's employee rows and
 * presenting them as its own. That is a cross-tenant leak, and it would arrive
 * disguised as a display bug rather than as a security incident. A thrown
 * exception is the only failure mode that cannot be mistaken for data.
 *
 * CACHING IS PER REQUEST, NEVER PER PROCESS. Mappings are configuration and
 * change while the application is running; a process-lifetime cache would serve
 * a stale table name until the worker recycled. The container binding in
 * UniversalServiceProvider is scoped(), so the instance and its cache are
 * discarded at the end of each request. Within a request the cache is keyed by
 * tenant, so resolving four entities for two tenants is two queries, not eight.
 */
final class EntityResolver
{
    /**
     * Universal fields that bind the source rather than describe it. Both are
     * mandatory on every mapped entity.
     */
    public const FIELD_ID = 'id';

    public const FIELD_TENANT_KEY = 'tenantKey';

    /**
     * The vocabulary the Brain reasons in.
     *
     * The first four are the entities intelligence is produced about. The last
     * two are satellite records the ERP keeps alongside them — an organization's
     * legal details, a person's role profile — which need resolving because the
     * code reads and writes them, but which nothing reasons over directly.
     */
    public const ENTITIES = [
        'Organization',
        'OrganizationUnit',
        'Person',
        'Position',
        'OrganizationProfile',
        'PersonProfile',
    ];

    /** @var array<string, array<string, ResolvedSource>> tenantId => entity => source */
    private array $cache = [];

    /** @var array<string, true> tenants whose mappings have been loaded */
    private array $loaded = [];

    public function resolve(string $tenantId, string $entity): ResolvedSource
    {
        $this->load($tenantId);

        if (! isset($this->cache[$tenantId][$entity])) {
            throw UnsupportedEntityException::forEntity(
                $tenantId,
                $entity,
                array_keys($this->cache[$tenantId] ?? []),
            );
        }

        return $this->cache[$tenantId][$entity];
    }

    /**
     * Whether an entity is mapped, without throwing.
     *
     * For callers deciding whether to offer a feature at all. It is NOT for
     * swapping in a default when the answer is false — that is the fallback
     * this class exists to prevent.
     */
    public function has(string $tenantId, string $entity): bool
    {
        $this->load($tenantId);

        return isset($this->cache[$tenantId][$entity]);
    }

    /** @return array<int, string> */
    public function mappedEntities(string $tenantId): array
    {
        $this->load($tenantId);

        $names = array_keys($this->cache[$tenantId] ?? []);
        sort($names);

        return $names;
    }

    /**
     * Every tenant that maps a given entity, resolved.
     *
     * For the one operation that has no tenant to scope by: LOGIN. A person
     * offers an email address and nothing else, so there is no tenant context to
     * resolve against — the tenant is what the lookup is trying to establish.
     *
     * The honest answer is to search every tenant that has the entity mapped and
     * let the matching row identify the tenant, which is what this supports.
     * Callers should group the results by source table so tenants sharing one
     * ERP cost a single query rather than one apiece.
     *
     * @return array<string, ResolvedSource> tenantId => source
     */
    public function everyTenantWith(string $entity): array
    {
        $tenantIds = DB::table('hpbrain_entity_mappings')
            ->where('universal_entity', $entity)
            ->where('is_active', 1)
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->all();

        $out = [];

        foreach ($tenantIds as $tenantId) {
            $tenantId = (string) $tenantId;

            // A tenant listed here always resolves, unless its mapping is
            // incomplete — in which case resolve() throws, which is correct:
            // a half-configured tenant must not silently drop out of a login
            // search and leave its people unable to sign in with no explanation.
            $out[$tenantId] = $this->resolve($tenantId, $entity);
        }

        return $out;
    }

    /**
     * Drop cached mappings. Called after a mapping is written, so the change is
     * visible within the same request that made it.
     */
    public function flush(?string $tenantId = null): void
    {
        if ($tenantId === null) {
            $this->cache = [];
            $this->loaded = [];

            return;
        }

        unset($this->cache[$tenantId], $this->loaded[$tenantId]);
    }

    /**
     * One query per tenant per request, assembled into ResolvedSource objects.
     */
    private function load(string $tenantId): void
    {
        if (isset($this->loaded[$tenantId])) {
            return;
        }

        $rows = DB::table('hpbrain_entity_mappings')
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->orderBy('universal_entity')
            ->orderBy('universal_field')
            ->get();

        /** @var array<string, array{system: string, tables: array<string, true>, fields: array<string, array>}> $byEntity */
        $byEntity = [];

        foreach ($rows as $row) {
            $entity = (string) $row->universal_entity;
            $field = (string) $row->universal_field;

            $byEntity[$entity] ??= ['system' => (string) $row->source_system, 'tables' => [], 'fields' => []];
            $byEntity[$entity]['tables'][(string) $row->source_entity] = true;

            $byEntity[$entity]['fields'][$field] = [
                'column'      => (string) $row->source_field,
                'type'        => (string) ($row->mapping_type ?: 'direct'),
                'expression'  => $this->decodeExpression($row->transform_expression ?? null),
                'lookupTable' => ($row->lookup_table ?? null) !== null && $row->lookup_table !== ''
                    ? (string) $row->lookup_table
                    : null,
            ];
        }

        $resolved = [];

        foreach ($byEntity as $entity => $spec) {
            $tables = array_keys($spec['tables']);

            // Two tables claiming one entity is a configuration error with no
            // safe resolution: picking either binds every subsequent query to an
            // arbitrary winner, and the wrong winner reads the wrong rows.
            if (count($tables) > 1) {
                throw UnsupportedEntityException::ambiguous($tenantId, $entity, $tables);
            }

            foreach ([self::FIELD_ID, self::FIELD_TENANT_KEY] as $required) {
                if (! isset($spec['fields'][$required])) {
                    throw UnsupportedEntityException::incomplete($tenantId, $entity, $required);
                }
            }

            $resolved[$entity] = new ResolvedSource(
                tenantId: $tenantId,
                entity: $entity,
                table: $tables[0],
                sourceSystem: $spec['system'],
                tenantKey: $spec['fields'][self::FIELD_TENANT_KEY]['column'],
                primaryKey: $spec['fields'][self::FIELD_ID]['column'],
                fields: $spec['fields'],
            );
        }

        $this->cache[$tenantId] = $resolved;
        $this->loaded[$tenantId] = true;
    }

    /**
     * transform_expression is stored as JSON describing the transformation, not
     * as SQL.
     *
     * The column is TEXT and its contents are supplied by whoever configures the
     * tenant, so treating it as an expression to interpolate into a query would
     * hand that person the query planner. It is decoded to a structure here and
     * left for the caller to apply in PHP. A value that does not parse as JSON
     * is returned verbatim rather than nulled — the stored text is then not what
     * this class expects, and destroying the only copy of it would be worse than
     * surfacing it.
     */
    private function decodeExpression(mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }
}
