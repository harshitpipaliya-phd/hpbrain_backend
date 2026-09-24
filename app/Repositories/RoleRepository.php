<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class RoleRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_roles';
    }

    protected function jsonColumns(): array
    {
        return ['permissions'];
    }

    public function list(string $tenantId, ?string $category = null, ?string $status = null): array
    {
        $q = $this->scoped($tenantId);

        if ($category !== null) {
            $q->where('category', $category);
        }
        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->orderBy('name')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByRoleKey(string $tenantId, string $roleKey): ?array
    {
        $row = $this->scoped($tenantId)->where('role_key', $roleKey)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'role_key'     => $data['role_key'],
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'category'     => $data['category'] ?? null,
            'permissions'  => isset($data['permissions']) ? json_encode($data['permissions']) : null,
            'is_system'    => (bool) ($data['is_system'] ?? false),
            'status'       => $data['status'] ?? 'active',
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
            'role_key'   => 'role_key',
            'name'       => 'name',
            'description'=> 'description',
            'category'   => 'category',
            'is_system'  => 'is_system',
            'status'     => 'status',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('permissions', $data)) {
            $fields['permissions'] = $data['permissions'] !== null ? json_encode($data['permissions']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
