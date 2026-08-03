<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class OrganizationModuleRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_organization_modules';
    }

    protected function jsonColumns(): array
    {
        return ['config'];
    }

    public function list(string $tenantId, ?string $orgId = null): array
    {
        $q = $this->scoped($tenantId);

        if ($orgId !== null && $orgId !== '') {
            $q->where('org_id', $orgId);
        }

        return $q->orderBy('created_date')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByOrgAndModule(string $tenantId, string $orgId, string $moduleId): ?array
    {
        $row = $this->scoped($tenantId)
            ->where('org_id', $orgId)
            ->where('module_id', $moduleId)
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
            'module_id'    => $data['module_id'],
            'is_enabled'   => $data['is_enabled'] ?? true,
            'config'       => isset($data['config']) ? json_encode($data['config']) : null,
            'enabled_by'   => $data['enabled_by'] ?? null,
            'enabled_date' => $data['is_enabled'] ?? true ? $now : null,
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
            'is_enabled' => 'is_enabled',
            'enabled_by' => 'enabled_by',
            'enabled_date' => 'enabled_date',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('config', $data)) {
            $fields['config'] = $data['config'] !== null ? json_encode($data['config']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
