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
     * Project the declared mappings into hpbrain_entity_mappings for this
     * tenant, so they are visible through GET /api/v1/entity-mappings/{tenant}.
     *
     * Idempotent: the table's UNIQUE (tenant_id, source_system, source_entity)
     * means one row per (org, profile), refreshed on each import rather than
     * accumulating a new row per run.
     */
    public function publishMappings(string $tenantId, ImportProfile $profile, string $actor = 'system'): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $universalEntity = $profile->loader() === 'erp_roster'
            ? 'Person'
            : 'OperationalRecord:'.$profile->dataset();

        // The mapping table holds one source_field/universal_field pair per
        // row, but its UNIQUE key is (tenant, system, entity) — so it can only
        // physically store one row per profile. The full field map therefore
        // goes into transform_expression as JSON, and source_field/
        // universal_field carry the natural key, which is the single most
        // useful pair to see at a glance.
        $fieldMap = $profile->map();

        if ($profile->isMatrix()) {
            $matrix = $profile->matrix();
            $fieldMap = [
                (string) ($matrix['entity_to'] ?? 'owner_name') => '<column headers>',
                (string) ($matrix['value_to'] ?? 'status')      => '<cell values>',
                'occurred_at'                                    => (string) $matrix['row_key'],
            ];
        }

        $keyColumn = $profile->keyColumns()[0]
            ?? (string) ($profile->matrix()['row_key'] ?? 'natural_key');

        $row = [
            'source_system'        => $profile->orgSlug,
            'source_entity'        => $profile->key,
            'source_field'         => $keyColumn,
            'universal_entity'     => $universalEntity,
            'universal_field'      => 'natural_key',
            'mapping_type'         => $profile->isMatrix() ? 'unpivot' : 'direct',
            'transform_expression' => json_encode([
                'sheet'      => $profile->sheet(),
                'header_row' => $profile->headerRow(),
                'shape'      => $profile->shape(),
                'fields'     => $fieldMap,
            ], JSON_UNESCAPED_SLASHES),
            'lookup_table'         => $profile->loader() === 'erp_roster' ? 'tbluser' : 'hpbrain_operational_records',
            'is_active'            => true,
            'updated_date'         => $now,
        ];

        $existing = DB::table('hpbrain_entity_mappings')
            ->where('tenant_id', $tenantId)
            ->where('source_system', $profile->orgSlug)
            ->where('source_entity', $profile->key)
            ->first();

        if ($existing) {
            DB::table('hpbrain_entity_mappings')->where('id', $existing->id)->update($row);

            return;
        }

        DB::table('hpbrain_entity_mappings')->insert($row + [
            'id'           => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'tenant_id'    => $tenantId,
            'created_by'   => $actor,
            'created_date' => $now,
        ]);
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
