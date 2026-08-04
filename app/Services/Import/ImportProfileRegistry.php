<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;

/**
 * Resolves config/import_profiles.php into ImportProfile objects and finds the
 * workbook each one refers to.
 *
 * Also mirrors every profile into hpbrain_entity_mappings. That table already
 * models exactly this — source_system / source_entity / source_field ->
 * universal_entity / universal_field — and shipped with no writer, so it has
 * been dead weight since it was created. Publishing profiles into it means the
 * existing EntityMappingController can list and inspect the mapping through the
 * API that was built for it, which is what "reuse existing repositories" asks
 * for. The config file stays the source of truth; the table is a projection.
 */
final class ImportProfileRegistry
{
    /** @var array<string, array<string, ImportProfile>>|null */
    private ?array $profiles = null;

    /**
     * Every profile for one organization slug, keyed by profile name.
     *
     * @return array<string, ImportProfile>
     */
    public function forOrganization(string $orgSlug): array
    {
        $this->load();

        return $this->profiles[$orgSlug] ?? [];
    }

    public function find(string $orgSlug, string $profileKey): ImportProfile
    {
        $profiles = $this->forOrganization($orgSlug);

        if (! isset($profiles[$profileKey])) {
            $known = implode(', ', array_keys($profiles)) ?: '(none)';

            throw new ImportConfigurationException(
                "No import profile '{$profileKey}' for organization '{$orgSlug}'. Known profiles: {$known}"
            );
        }

        return $profiles[$profileKey];
    }

    /** @return array<int, string> */
    public function organizations(): array
    {
        $this->load();

        return array_keys($this->profiles);
    }

    /**
     * Absolute path to the workbook a profile refers to.
     *
     * Globs are resolved to the MOST RECENTLY MODIFIED match, not the
     * alphabetically first. 'Complain 2025-26 R2.xlsx' and a later
     * 'Complain 2025-26 R3.xlsx' sort in the order you would want by luck;
     * 'R10' would not. Modification time always picks the newest export.
     */
    public function resolveFile(ImportProfile $profile, ?string $baseDirectory = null): ?string
    {
        $directory = $baseDirectory ?? storage_path('imports/'.$profile->orgSlug);
        $pattern   = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$profile->filePattern();

        $matches = glob($pattern) ?: [];
        $matches = array_values(array_filter($matches, 'is_file'));

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $matches[0];
    }

    /**
     * The universal fields an OperationalRecord entity binds, and the columns
     * of hpbrain_operational_records behind them.
     *
     * 'id' and 'tenantKey' are not optional decoration. EntityResolver::load()
     * requires both on EVERY mapped entity and throws if either is absent —
     * and it throws while building the map for the whole tenant, so one
     * incomplete entity takes down resolution for every other one too.
     */
    private const OPERATIONAL_FIELDS = [
        'id'          => 'id',
        'tenantKey'   => 'tenant_id',
        'natural_key' => 'natural_key',
        'occurredAt'  => 'occurred_at',
        'closedAt'    => 'closed_at',
        'status'      => 'status',
        'category'    => 'category',
        'subCategory' => 'sub_category',
        'owner'       => 'owner_name',
        'supervisor'  => 'supervisor_name',
        'zone'        => 'zone',
        'area'        => 'area',
        'subjectRef'  => 'subject_ref',
        'metricValue' => 'metric_value',
        'quantity'    => 'quantity',
    ];

    /**
     * Project a profile into hpbrain_entity_mappings, so it is visible through
     * GET /api/v1/entity-mappings/{tenant} and resolvable by EntityResolver.
     *
     * THIS TABLE IS NOT A NOTEBOOK. It is the vocabulary layer every repository
     * reads through, and the resolver holds two rules that a documentation-only
     * writer violates immediately:
     *
     *   1. Every entity needs an 'id' and a 'tenantKey' row. This used to write
     *      exactly one row per profile, carrying 'natural_key' — so the entity
     *      was declared and unusable, and resolve() threw for the whole tenant.
     *      Every ERP rule then failed and was counted as "not triggered".
     *
     *   2. source_entity IS THE TABLE NAME. The resolver reads it as the table
     *      to query (EntityResolver::load(), `table: $tables[0]`). Writing the
     *      profile key there — 'complaints' — pointed the entity at a table
     *      that does not exist.
     *
     * PERSON IS DELIBERATELY NOT PUBLISHED. The roster loads into tbluser,
     * which EntityMappingSeeder already maps. Publishing a second source_entity
     * for Person makes the entity AMBIGUOUS, which the resolver treats as
     * unresolvable — correctly, since guessing binds every later read to an
     * arbitrary table. The roster needs no mapping of its own: it writes into
     * the tables the existing one already describes.
     */
    public function publishMappings(string $tenantId, ImportProfile $profile, string $actor = 'system'): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        if ($profile->loader() === 'erp_roster') {
            // Remove anything an earlier build published here. Left in place it
            // is not inert — it is the second table that makes Person ambiguous.
            DB::table('hpbrain_entity_mappings')
                ->where('tenant_id', $tenantId)
                ->where('source_system', $profile->orgSlug)
                ->where('universal_entity', 'Person')
                ->delete();

            return;
        }

        $universalEntity = 'OperationalRecord:'.$profile->dataset();

        $fieldMap = $profile->map();

        if ($profile->isMatrix()) {
            $matrix = $profile->matrix();
            $fieldMap = [
                (string) ($matrix['entity_to'] ?? 'owner_name') => '<column headers>',
                (string) ($matrix['value_to'] ?? 'status')      => '<cell values>',
                'occurred_at'                                    => (string) $matrix['row_key'],
            ];
        }

        // The profile's own shape, kept on the natural_key row so the workbook
        // it came from is still visible through the mappings endpoint.
        $provenance = json_encode([
            'profile'    => $profile->key,
            'sheet'      => $profile->sheet(),
            'header_row' => $profile->headerRow(),
            'shape'      => $profile->shape(),
            'fields'     => $fieldMap,
        ], JSON_UNESCAPED_SLASHES);

        foreach (self::OPERATIONAL_FIELDS as $universalField => $column) {
            $row = [
                'source_system'        => $profile->orgSlug,
                // The TABLE, not the profile key — see the docblock.
                'source_entity'        => 'hpbrain_operational_records',
                'source_field'         => $column,
                'universal_entity'     => $universalEntity,
                'universal_field'      => $universalField,
                'mapping_type'         => 'direct',
                'transform_expression' => $universalField === 'natural_key' ? $provenance : null,
                'lookup_table'         => 'hpbrain_operational_records',
                'is_active'            => true,
                'updated_date'         => $now,
            ];

            // Keyed the way the table's UNIQUE index is since
            // 2026_08_03_000100_entity_mappings_field_unique_key:
            // (tenant_id, universal_entity, universal_field). The previous
            // lookup keyed on (tenant, system, source_entity), which under the
            // current index would have collided on the second field written.
            $existing = DB::table('hpbrain_entity_mappings')
                ->where('tenant_id', $tenantId)
                ->where('universal_entity', $universalEntity)
                ->where('universal_field', $universalField)
                ->first();

            if ($existing) {
                DB::table('hpbrain_entity_mappings')->where('id', $existing->id)->update($row);

                continue;
            }

            DB::table('hpbrain_entity_mappings')->insert($row + [
                'id'           => \Ramsey\Uuid\Uuid::uuid4()->toString(),
                'tenant_id'    => $tenantId,
                'created_by'   => $actor,
                'created_date' => $now,
            ]);
        }
    }

    private function load(): void
    {
        if ($this->profiles !== null) {
            return;
        }

        $raw = (array) config('import_profiles', []);
        $built = [];

        foreach ($raw as $orgSlug => $profiles) {
            foreach ((array) $profiles as $key => $config) {
                $built[$orgSlug][$key] = ImportProfile::fromConfig(
                    (string) $orgSlug,
                    (string) $key,
                    (array) $config
                );
            }
        }

        $this->profiles = $built;
    }
}
