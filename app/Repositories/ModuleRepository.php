<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class ModuleRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_modules';
    }

    protected function jsonColumns(): array
    {
        return ['dependencies', 'config_schema'];
    }

    public function list(string $tenantId): array
    {
        return $this->scoped($tenantId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => $this->hydrate((array) $r))
            ->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByKey(string $tenantId, string $moduleKey): ?array
    {
        $row = $this->scoped($tenantId)->where('module_key', $moduleKey)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'module_key'   => $data['module_key'],
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'version'      => $data['version'] ?? null,
            'category'     => $data['category'] ?? null,
            'is_core'      => $data['is_core'] ?? false,
            'is_enabled'   => $data['is_enabled'] ?? true,
            'dependencies' => isset($data['dependencies']) ? json_encode($data['dependencies']) : null,
            'config_schema'=> isset($data['config_schema']) ? json_encode($data['config_schema']) : null,
            'sort_order'   => (int) ($data['sort_order'] ?? 0),
            'created_by'   => $data['created_by'],
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        return $this->find($tenantId, $id);
    }

    public function update(string $tenantId, string $id, array $data): ?array
    {
        $now = $this->now();
        $fields = ['updated_date' => $now];

        $map = [
            'module_key'  => 'module_key',
            'name'        => 'name',
            'description' => 'description',
            'version'     => 'version',
            'category'    => 'category',
            'is_core'     => 'is_core',
            'is_enabled'  => 'is_enabled',
            'sort_order'  => 'sort_order',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('dependencies', $data)) {
            $fields['dependencies'] = $data['dependencies'] !== null ? json_encode($data['dependencies']) : null;
        }

        if (array_key_exists('config_schema', $data)) {
            $fields['config_schema'] = $data['config_schema'] !== null ? json_encode($data['config_schema']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
