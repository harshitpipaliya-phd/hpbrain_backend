<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class FeatureFlagRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_feature_flags';
    }

    protected function jsonColumns(): array
    {
        return ['rules'];
    }

    public function list(string $tenantId): array
    {
        return $this->scoped($tenantId)
            ->orderBy('flag_key')
            ->orderBy('level')
            ->get()
            ->map(fn ($r) => $this->hydrate((array) $r))
            ->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByKeyAndLevel(string $tenantId, string $flagKey, string $level, ?string $levelId = null): ?array
    {
        $q = $this->scoped($tenantId)
            ->where('flag_key', $flagKey)
            ->where('level', $level);

        if ($levelId !== null && $levelId !== '') {
            $q->where('level_id', $levelId);
        } else {
            $q->whereNull('level_id');
        }

        $row = $q->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'                 => $id,
            'tenant_id'          => $tenantId,
            'flag_key'           => $data['flag_key'],
            'flag_name'          => $data['flag_name'],
            'description'        => $data['description'] ?? null,
            'enabled'            => $data['enabled'] ?? true,
            'level'              => $data['level'] ?? 'platform',
            'level_id'           => $data['level_id'] ?? null,
            'rollout_percentage' => (int) ($data['rollout_percentage'] ?? 100),
            'rules'              => isset($data['rules']) ? json_encode($data['rules']) : null,
            'created_by'         => $data['created_by'],
            'created_date'       => $now,
            'updated_date'       => $now,
        ]);

        return $this->find($tenantId, $id);
    }

    public function update(string $tenantId, string $id, array $data): ?array
    {
        $now = $this->now();
        $fields = ['updated_date' => $now];

        $map = [
            'flag_key'           => 'flag_key',
            'flag_name'          => 'flag_name',
            'description'        => 'description',
            'enabled'            => 'enabled',
            'level'              => 'level',
            'level_id'           => 'level_id',
            'rollout_percentage' => 'rollout_percentage',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('rules', $data)) {
            $fields['rules'] = $data['rules'] !== null ? json_encode($data['rules']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
