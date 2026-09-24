<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * The registry of places the Brain can read from.
 *
 * Tenant-scoped through BaseRepository::scoped() like every other hpbrain_
 * repository — a source names ERP tables, so listing another tenant's sources
 * would be a step towards reading their rows, not merely a display leak.
 */
final class DataSourceRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_data_sources';
    }

    /** @return array<int, string> */
    protected function jsonColumns(): array
    {
        return ['field_map'];
    }

    /** @return array<int, array<string, mixed>> */
    public function list(string $tenantId, bool $activeOnly = true): array
    {
        $q = $this->scoped($tenantId);

        if ($activeOnly) {
            $q->where('is_active', 1);
        }

        return $q->orderBy('source_key')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function findByKey(string $tenantId, string $sourceKey): ?array
    {
        $row = $this->scoped($tenantId)->where('source_key', $sourceKey)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    /** @param array<string, mixed> $data */
    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'               => $id,
            'tenant_id'        => $tenantId,
            'source_key'       => $data['source_key'],
            'source_type'      => $data['source_type'],
            'display_name'     => $data['display_name'],
            'universal_entity' => $data['universal_entity'] ?? null,
            'field_map'        => isset($data['field_map']) ? json_encode($data['field_map']) : null,
            'checkpoint'       => $data['checkpoint'] ?? null,
            'last_synced_at'   => $data['last_synced_at'] ?? null,
            'is_active'        => (int) ($data['is_active'] ?? 1),
            'created_by'       => $data['created_by'],
            'created_date'     => $now,
            'updated_date'     => $now,
        ]);

        return $this->findByKey($tenantId, $data['source_key']);
    }

    /**
     * Advance the checkpoint after a successful run.
     *
     * SEPARATE FROM update() ON PURPOSE. This is the one write that decides
     * what the NEXT run will skip. A null checkpoint leaves the stored value
     * alone rather than clearing it — a source that could not produce a
     * watermark must not silently reset the sync to the beginning of time.
     */
    public function advanceCheckpoint(string $tenantId, string $sourceKey, ?string $checkpoint): void
    {
        $fields = ['last_synced_at' => $this->now(), 'updated_date' => $this->now()];

        if ($checkpoint !== null && $checkpoint !== '') {
            $fields['checkpoint'] = $checkpoint;
        }

        $this->scoped($tenantId)->where('source_key', $sourceKey)->update($fields);
    }

    /** @param array<string, mixed> $fieldMap */
    public function saveFieldMap(string $tenantId, string $sourceKey, array $fieldMap): ?array
    {
        $this->scoped($tenantId)->where('source_key', $sourceKey)->update([
            'field_map'    => json_encode($fieldMap),
            'updated_date' => $this->now(),
        ]);

        return $this->findByKey($tenantId, $sourceKey);
    }
}
