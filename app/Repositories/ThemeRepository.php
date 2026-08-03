<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class ThemeRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_themes';
    }

    protected function jsonColumns(): array
    {
        return ['colors', 'typography', 'spacing', 'borderRadius', 'shadows'];
    }

    public function list(string $tenantId): array
    {
        return $this->scoped($tenantId)
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

    public function findByKey(string $tenantId, string $themeKey): ?array
    {
        $row = $this->scoped($tenantId)->where('theme_key', $themeKey)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findDefault(string $tenantId): ?array
    {
        $row = $this->scoped($tenantId)->where('is_default', true)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'theme_key'    => $data['theme_key'],
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'colors'       => isset($data['colors']) ? json_encode($data['colors']) : null,
            'typography'   => isset($data['typography']) ? json_encode($data['typography']) : null,
            'spacing'      => isset($data['spacing']) ? json_encode($data['spacing']) : null,
            'borderRadius' => isset($data['borderRadius']) ? json_encode($data['borderRadius']) : null,
            'shadows'      => isset($data['shadows']) ? json_encode($data['shadows']) : null,
            'is_dark'      => $data['is_dark'] ?? false,
            'is_default'   => $data['is_default'] ?? false,
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
            'theme_key'  => 'theme_key',
            'name'       => 'name',
            'description'=> 'description',
            'is_dark'    => 'is_dark',
            'is_default' => 'is_default',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        $jsonCols = ['colors', 'typography', 'spacing', 'borderRadius', 'shadows'];
        foreach ($jsonCols as $col) {
            if (array_key_exists($col, $data)) {
                $fields[$col] = $data[$col] !== null ? json_encode($data[$col]) : null;
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
