<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class NavigationItemRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_navigation_items';
    }

    protected function jsonColumns(): array
    {
        return ['children'];
    }

    public function list(string $tenantId, ?string $industryCode = null, ?string $roleKey = null): array
    {
        $q = $this->scoped($tenantId)->where('is_visible', true);

        if ($industryCode !== null && $industryCode !== '') {
            $q->where('industry_code', $industryCode);
        }

        if ($roleKey !== null && $roleKey !== '') {
            $q->where('role_key', $roleKey);
        }

        return $q->orderBy('sort_order')->orderBy('label')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
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
            'id'                  => $id,
            'tenant_id'           => $tenantId,
            'industry_code'       => $data['industry_code'],
            'role_key'            => $data['role_key'],
            'item_key'            => $data['item_key'],
            'label'               => $data['label'],
            'icon'                => $data['icon'] ?? null,
            'route'               => $data['route'] ?? null,
            'parent_id'           => $data['parent_id'] ?? null,
            'sort_order'          => (int) ($data['sort_order'] ?? 0),
            'is_visible'          => $data['is_visible'] ?? true,
            'required_permission' => $data['required_permission'] ?? null,
            'required_flag'       => $data['required_flag'] ?? null,
            'required_module'     => $data['required_module'] ?? null,
            'children'            => isset($data['children']) ? json_encode($data['children']) : null,
            'created_by'          => $data['created_by'],
            'created_date'        => $now,
            'updated_date'        => $now,
        ]);

        return $this->find($tenantId, $id);
    }

    public function update(string $tenantId, string $id, array $data): ?array
    {
        $now = $this->now();
        $fields = ['updated_date' => $now];

        $map = [
            'industry_code'       => 'industry_code',
            'role_key'            => 'role_key',
            'item_key'            => 'item_key',
            'label'               => 'label',
            'icon'                => 'icon',
            'route'               => 'route',
            'parent_id'           => 'parent_id',
            'sort_order'          => 'sort_order',
            'is_visible'          => 'is_visible',
            'required_permission' => 'required_permission',
            'required_flag'       => 'required_flag',
            'required_module'     => 'required_module',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('children', $data)) {
            $fields['children'] = $data['children'] !== null ? json_encode($data['children']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
