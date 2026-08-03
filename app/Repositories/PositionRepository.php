<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class PositionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_positions';
    }

    protected function jsonColumns(): array
    {
        return ['metadata'];
    }

    public function list(string $tenantId, ?string $orgId = null, ?string $unitId = null): array
    {
        $q = $this->scoped($tenantId);

        if ($orgId !== null) {
            $q->where('org_id', $orgId);
        }
        if ($unitId !== null) {
            $q->where('unit_id', $unitId);
        }

        return $q->orderBy('title')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByOrg(string $tenantId, string $orgId): array
    {
        return $this->list($tenantId, $orgId);
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'                   => $id,
            'tenant_id'            => $tenantId,
            'org_id'               => $data['org_id'] ?? null,
            'unit_id'              => $data['unit_id'] ?? null,
            'title'                => $data['title'],
            'description'          => $data['description'] ?? null,
            'employment_type'      => $data['employment_type'] ?? null,
            'is_vacant'            => (bool) ($data['is_vacant'] ?? false),
            'reports_to_position_id' => $data['reports_to_position_id'] ?? null,
            'metadata'             => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'status'               => $data['status'] ?? 'active',
            'created_by'           => $data['created_by'],
            'created_date'         => $now,
            'updated_date'         => $now,
        ]);

        return $this->find($tenantId, $id);
    }

    public function update(string $tenantId, string $id, array $data): ?array
    {
        $now = $this->now();
        $fields = ['updated_date' => $now];

        $map = [
            'org_id'                  => 'org_id',
            'unit_id'                 => 'unit_id',
            'title'                   => 'title',
            'description'             => 'description',
            'employment_type'         => 'employment_type',
            'is_vacant'               => 'is_vacant',
            'reports_to_position_id'  => 'reports_to_position_id',
            'status'                  => 'status',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('metadata', $data)) {
            $fields['metadata'] = $data['metadata'] !== null ? json_encode($data['metadata']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
