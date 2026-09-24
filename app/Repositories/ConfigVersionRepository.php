<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class ConfigVersionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_config_versions';
    }

    protected function jsonColumns(): array
    {
        return ['data'];
    }

    public function list(string $tenantId, ?string $configType = null, ?string $configKey = null): array
    {
        $q = $this->scoped($tenantId);

        if ($configType !== null && $configType !== '') {
            $q->where('config_type', $configType);
        }

        if ($configKey !== null && $configKey !== '') {
            $q->where('config_key', $configKey);
        }

        return $q->orderByDesc('version')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function getActiveVersion(string $tenantId, string $configType, string $configKey): ?array
    {
        $row = $this->scoped($tenantId)
            ->where('config_type', $configType)
            ->where('config_key', $configKey)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'              => $id,
            'tenant_id'       => $tenantId,
            'org_id'          => $data['org_id'],
            'config_type'     => $data['config_type'],
            'config_key'      => $data['config_key'],
            'version'         => (int) ($data['version'] ?? 1),
            'data'            => isset($data['data']) ? json_encode($data['data']) : json_encode([]),
            'status'          => $data['status'] ?? 'draft',
            'activated_by'    => $data['activated_by'] ?? null,
            'activated_date'  => $data['activated_date'] ?? null,
            'rolled_back_by'  => $data['rolled_back_by'] ?? null,
            'rolled_back_date'=> $data['rolled_back_date'] ?? null,
            'change_summary'  => $data['change_summary'] ?? null,
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
            'status'          => 'status',
            'activated_by'    => 'activated_by',
            'activated_date'  => 'activated_date',
            'rolled_back_by'  => 'rolled_back_by',
            'rolled_back_date'=> 'rolled_back_date',
            'change_summary'  => 'change_summary',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('data', $data)) {
            $fields['data'] = $data['data'] !== null ? json_encode($data['data']) : json_encode([]);
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
