<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class OrganizationConfigRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_organization_configs';
    }

    protected function jsonColumns(): array
    {
        return [];
    }

    public function list(string $tenantId, ?string $orgId = null): array
    {
        $q = $this->scoped($tenantId);

        if ($orgId !== null && $orgId !== '') {
            $q->where('org_id', $orgId);
        }

        return $q->orderBy('config_key')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findConfig(string $tenantId, string $orgId, string $configKey): ?array
    {
        $row = $this->scoped($tenantId)
            ->where('org_id', $orgId)
            ->where('config_key', $configKey)
            ->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'org_id'       => $data['org_id'],
            'config_key'   => $data['config_key'],
            'config_value' => $data['config_value'] ?? null,
            'config_type'  => $data['config_type'] ?? 'scalar',
            'description'  => $data['description'] ?? null,
            'is_active'    => $data['is_active'] ?? true,
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
            'config_value' => 'config_value',
            'config_type'  => 'config_type',
            'description'  => 'description',
            'is_active'    => 'is_active',
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
