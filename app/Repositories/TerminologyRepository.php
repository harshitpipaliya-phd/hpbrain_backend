<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class TerminologyRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_terminology';
    }

    protected function jsonColumns(): array
    {
        return [];
    }

    public function list(string $tenantId): array
    {
        return $this->scoped($tenantId)
            ->orderBy('industry_code')
            ->orderBy('sort_order')
            ->orderBy('entity_type')
            ->get()
            ->map(fn ($r) => $this->hydrate((array) $r))
            ->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByIndustryAndEntity(string $tenantId, string $industryCode, string $entityType): ?array
    {
        $row = $this->scoped($tenantId)
            ->where('industry_code', $industryCode)
            ->where('entity_type', $entityType)
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
            'industry_code' => $data['industry_code'],
            'entity_type'  => $data['entity_type'],
            'display_name' => $data['display_name'],
            'plural_name'  => $data['plural_name'] ?? null,
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
            'industry_code' => 'industry_code',
            'entity_type'   => 'entity_type',
            'display_name'  => 'display_name',
            'plural_name'   => 'plural_name',
            'description'   => 'description',
            'icon'          => 'icon',
            'sort_order'    => 'sort_order',
            'status'        => 'status',
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
