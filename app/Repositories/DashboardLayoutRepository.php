<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class DashboardLayoutRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_dashboard_layouts';
    }

    protected function jsonColumns(): array
    {
        return ['widgets'];
    }

    public function list(string $tenantId, ?string $dashboardId = null): array
    {
        $q = $this->scoped($tenantId);

        if ($dashboardId !== null && $dashboardId !== '') {
            $q->where('dashboard_id', $dashboardId);
        }

        return $q->orderBy('created_date')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByDashboard(string $tenantId, string $dashboardId): ?array
    {
        $row = $this->scoped($tenantId)->where('dashboard_id', $dashboardId)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'dashboard_id' => $data['dashboard_id'],
            'layout_type'  => $data['layout_type'] ?? 'grid',
            'grid_columns' => $data['grid_columns'] ?? 12,
            'grid_rows'    => $data['grid_rows'] ?? 12,
            'widgets'      => isset($data['widgets']) ? json_encode($data['widgets']) : json_encode([]),
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
            'dashboard_id' => 'dashboard_id',
            'layout_type'  => 'layout_type',
            'grid_columns' => 'grid_columns',
            'grid_rows'    => 'grid_rows',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('widgets', $data)) {
            $fields['widgets'] = $data['widgets'] !== null ? json_encode($data['widgets']) : json_encode([]);
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
