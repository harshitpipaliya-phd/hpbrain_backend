<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class DashboardWidgetRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_dashboard_widgets';
    }

    protected function jsonColumns(): array
    {
        return ['config_schema', 'default_config'];
    }

    public function list(string $tenantId): array
    {
        return $this->scoped($tenantId)
            ->orderBy('category')
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

    public function findByKey(string $tenantId, string $widgetKey): ?array
    {
        $row = $this->scoped($tenantId)->where('widget_key', $widgetKey)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'              => $id,
            'tenant_id'       => $tenantId,
            'widget_key'      => $data['widget_key'],
            'name'            => $data['name'],
            'description'     => $data['description'] ?? null,
            'category'        => $data['category'] ?? null,
            'component_type'  => $data['component_type'],
            'config_schema'   => isset($data['config_schema']) ? json_encode($data['config_schema']) : null,
            'default_config'  => isset($data['default_config']) ? json_encode($data['default_config']) : null,
            'icon'            => $data['icon'] ?? null,
            'is_system'       => $data['is_system'] ?? false,
            'created_by'      => $data['created_by'],
            'created_date'    => $now,
            'updated_date'    => $now,
        ]);

        return $this->find($tenantId, $id);
    }

    public function update(string $tenantId, string $id, array $data): ?array
    {
        $now = $this->now();
        $fields = ['updated_date' => $now];

        $map = [
            'widget_key'     => 'widget_key',
            'name'           => 'name',
            'description'    => 'description',
            'category'       => 'category',
            'component_type' => 'component_type',
            'icon'           => 'icon',
            'is_system'      => 'is_system',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('config_schema', $data)) {
            $fields['config_schema'] = $data['config_schema'] !== null ? json_encode($data['config_schema']) : null;
        }

        if (array_key_exists('default_config', $data)) {
            $fields['default_config'] = $data['default_config'] !== null ? json_encode($data['default_config']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
