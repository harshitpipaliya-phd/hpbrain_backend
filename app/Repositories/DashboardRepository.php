<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class DashboardRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_dashboards';
    }

    protected function jsonColumns(): array
    {
        return ['layout'];
    }

    public function list(string $tenantId, ?string $orgId = null): array
    {
        $q = $this->scoped($tenantId);

        if ($orgId !== null && $orgId !== '') {
            $q->where('org_id', $orgId);
        }

        return $q->orderBy('name')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByKey(string $tenantId, ?string $orgId, string $dashboardKey): ?array
    {
        $q = $this->scoped($tenantId)->where('dashboard_key', $dashboardKey);

        if ($orgId !== null && $orgId !== '') {
            $q->where('org_id', $orgId);
        } else {
            $q->whereNull('org_id');
        }

        $row = $q->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'org_id'       => $data['org_id'] ?? null,
            'dashboard_key'=> $data['dashboard_key'],
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'industry_code'=> $data['industry_code'] ?? null,
            'role_key'     => $data['role_key'] ?? null,
            'is_default'   => $data['is_default'] ?? false,
            'is_system'    => $data['is_system'] ?? false,
            'layout'       => isset($data['layout']) ? json_encode($data['layout']) : null,
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
            'dashboard_key' => 'dashboard_key',
            'name'          => 'name',
            'description'   => 'description',
            'industry_code' => 'industry_code',
            'role_key'      => 'role_key',
            'is_default'    => 'is_default',
            'is_system'     => 'is_system',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('layout', $data)) {
            $fields['layout'] = $data['layout'] !== null ? json_encode($data['layout']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
