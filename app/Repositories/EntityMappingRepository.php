<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class EntityMappingRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_entity_mappings';
    }

    protected function jsonColumns(): array
    {
        return [];
    }

    public function list(string $tenantId): array
    {
        return $this->scoped($tenantId)
            ->orderBy('source_system')
            ->orderBy('source_entity')
            ->orderBy('source_field')
            ->get()
            ->map(fn ($r) => $this->hydrate((array) $r))
            ->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findBySource(string $tenantId, string $sourceSystem, ?string $sourceEntity = null): array
    {
        $q = $this->scoped($tenantId)->where('source_system', $sourceSystem);

        if ($sourceEntity !== null && $sourceEntity !== '') {
            $q->where('source_entity', $sourceEntity);
        }

        return $q->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'                  => $id,
            'tenant_id'           => $tenantId,
            'source_system'       => $data['source_system'],
            'source_entity'       => $data['source_entity'],
            'source_field'        => $data['source_field'],
            'universal_entity'    => $data['universal_entity'],
            'universal_field'     => $data['universal_field'],
            'mapping_type'        => $data['mapping_type'] ?? 'direct',
            'transform_expression' => $data['transform_expression'] ?? null,
            'lookup_table'        => $data['lookup_table'] ?? null,
            'is_active'           => $data['is_active'] ?? true,
            'created_by'          => $data['created_by'],
            'created_date'        => $now,
            'updated_date'        => $now,
        ]);

        return $this->find($tenantId, $id);
    }

    public function update(string $tenantId, string $id, array $data): ?array
    {
        $now = $this->now();
        $fields = ['updated_date' => $now];

        $map = [
            'source_system'        => 'source_system',
            'source_entity'        => 'source_entity',
            'source_field'         => 'source_field',
            'universal_entity'     => 'universal_entity',
            'universal_field'      => 'universal_field',
            'mapping_type'         => 'mapping_type',
            'transform_expression' => 'transform_expression',
            'lookup_table'         => 'lookup_table',
            'is_active'            => 'is_active',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
