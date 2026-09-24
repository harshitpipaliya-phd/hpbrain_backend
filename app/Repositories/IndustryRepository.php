<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class IndustryRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_industries';
    }

    protected function jsonColumns(): array
    {
        return ['settings'];
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

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'code'         => $data['code'],
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'icon'         => $data['icon'] ?? null,
            'sort_order'   => (int) ($data['sort_order'] ?? 0),
            'status'       => $data['status'] ?? 'active',
            'settings'     => isset($data['settings']) ? json_encode($data['settings']) : null,
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
            'code'        => 'code',
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

        if (array_key_exists('settings', $data)) {
            $fields['settings'] = $data['settings'] !== null ? json_encode($data['settings']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }

    public function findByCode(string $tenantId, string $code): ?array
    {
        $row = $this->scoped($tenantId)->where('code', $code)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }
}
