<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class FormRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_forms';
    }

    protected function jsonColumns(): array
    {
        return ['fields', 'validation_rules'];
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

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'              => $id,
            'tenant_id'       => $tenantId,
            'org_id'          => $data['org_id'],
            'form_key'        => $data['form_key'],
            'name'            => $data['name'],
            'description'     => $data['description'] ?? null,
            'entity_type'     => $data['entity_type'] ?? null,
            'fields'          => isset($data['fields']) ? json_encode($data['fields']) : json_encode([]),
            'validation_rules'=> isset($data['validation_rules']) ? json_encode($data['validation_rules']) : json_encode([]),
            'submit_action'   => $data['submit_action'] ?? null,
            'is_active'       => $data['is_active'] ?? true,
            'version'         => $data['version'] ?? 1,
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
            'form_key'      => 'form_key',
            'name'          => 'name',
            'description'   => 'description',
            'entity_type'   => 'entity_type',
            'submit_action' => 'submit_action',
            'is_active'     => 'is_active',
            'version'       => 'version',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('fields', $data)) {
            $fields['fields'] = $data['fields'] !== null ? json_encode($data['fields']) : json_encode([]);
        }

        if (array_key_exists('validation_rules', $data)) {
            $fields['validation_rules'] = $data['validation_rules'] !== null ? json_encode($data['validation_rules']) : json_encode([]);
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
