<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class OrganizationUnitRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_organization_units';
    }

    protected function jsonColumns(): array
    {
        return ['metadata'];
    }

    public function list(string $tenantId, string $orgId, ?string $unitType = null, ?string $status = null): array
    {
        $q = $this->scoped($tenantId)->where('org_id', $orgId);

        if ($unitType !== null) {
            $q->where('unit_type', $unitType);
        }
        if ($status !== null) {
            $q->where('status', $status);
        }

        // ORDERED BY NAME ALONE. This read used to lead with
        // orderBy('sort_order'), and hpbrain_organization_units has no such
        // column — the migration never declared one — so every call raised
        // SQLSTATE[42S22] and this endpoint had never once returned a row
        // (docs/API-FUNCTIONAL-AUDIT.md F1).
        //
        // The column is NOT added, because it would be dead the moment it
        // existed: sort_order appears exactly once in this class, in this
        // ORDER BY. create() and update() do not write it, no caller supplies
        // it, and no screen offers a way to reorder units. The five tables that
        // legitimately carry sort_order — industries, modules, navigation_items,
        // organization_types, terminology — each pair the read with a write.
        // Adding a column that nothing populates would sort every row equal and
        // fall through to name regardless, which is exactly what this line now
        // does, minus a column to explain to the next reader.
        //
        // If ordering units by hand becomes a requirement, the change is a
        // migration plus a write path in create()/update() — a feature, and a
        // different piece of work from repairing a broken read.
        return $q->orderBy('name')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $orgId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('org_id', $orgId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByOrg(string $tenantId, string $orgId): array
    {
        return $this->list($tenantId, $orgId);
    }

    public function findByParent(string $tenantId, string $orgId, string $parentUnitId): array
    {
        return $this->scoped($tenantId)->where('org_id', $orgId)->where('parent_unit_id', $parentUnitId)->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'org_id'       => $data['org_id'] ?? null,
            'unit_type'    => $data['unit_type'] ?? 'department',
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'code'         => $data['code'] ?? null,
            'parent_unit_id' => $data['parent_unit_id'] ?? null,
            'head_id'      => $data['head_id'] ?? null,
            'location'     => $data['location'] ?? null,
            'cost_center'  => $data['cost_center'] ?? null,
            'status'       => $data['status'] ?? 'active',
            'metadata'     => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_by'   => $data['created_by'],
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        return $this->find($tenantId, $data['org_id'] ?? null, $id);
    }

    public function update(string $tenantId, string $orgId, string $id, array $data): ?array
    {
        $now = $this->now();
        $fields = ['updated_date' => $now];

        $map = [
            'org_id'        => 'org_id',
            'unit_type'     => 'unit_type',
            'name'          => 'name',
            'description'   => 'description',
            'code'          => 'code',
            'parent_unit_id'=> 'parent_unit_id',
            'head_id'       => 'head_id',
            'location'      => 'location',
            'cost_center'   => 'cost_center',
            'status'        => 'status',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('metadata', $data)) {
            $fields['metadata'] = $data['metadata'] !== null ? json_encode($data['metadata']) : null;
        }

        $this->scoped($tenantId)->where('org_id', $orgId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $orgId, $id);
    }

    public function delete(string $tenantId, string $orgId, string $id): bool
    {
        return $this->scoped($tenantId)->where('org_id', $orgId)->where('id', $id)->delete() > 0;
    }

    public function getHierarchy(string $tenantId, string $orgId, ?string $parentId = null): array
    {
        $units = $this->scoped($tenantId)
            ->where('org_id', $orgId)
            ->where('parent_unit_id', $parentId)
            ->get()
            ->map(fn ($r) => $this->hydrate((array) $r))
            ->all();

        foreach ($units as &$unit) {
            $unit['children'] = $this->getHierarchy($tenantId, $orgId, $unit['id']);
        }

        return $units;
    }
}
