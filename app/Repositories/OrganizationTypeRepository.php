<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class OrganizationTypeRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_organization_types';
    }

    protected function jsonColumns(): array
    {
        return [];
    }

    public function list(string $tenantId, ?string $status = null): array
    {
        $q = $this->scoped($tenantId)->orderBy('sort_order')->orderBy('name');

        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByTypeKey(string $tenantId, string $typeKey): ?array
    {
        $row = $this->scoped($tenantId)->where('type_key', $typeKey)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'type_key'     => $data['type_key'],
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'icon'         => $data['icon'] ?? null,
            'sort_order'   => (int) ($data['sort_order'] ?? 0),
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
            'type_key'    => 'type_key',
            'name'        => 'name',
            'description' => 'description',
            'icon'        => 'icon',
            'sort_order'  => 'sort_order',
            'status'      => 'status',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('created_by', $data)) {
            $fields['created_by'] = $data['created_by'];
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
