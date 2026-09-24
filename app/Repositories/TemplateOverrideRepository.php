<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class TemplateOverrideRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_template_overrides';
    }

    protected function jsonColumns(): array
    {
        return ['override_data'];
    }

    public function list(string $tenantId, ?string $orgId = null, ?string $templateType = null, ?bool $isActive = null): array
    {
        $q = $this->scoped($tenantId);

        if ($orgId !== null) {
            $q->where('org_id', $orgId);
        }
        if ($templateType !== null) {
            $q->where('template_type', $templateType);
        }
        if ($isActive !== null) {
            $q->where('is_active', $isActive);
        }

        return $q->orderBy('template_type')->orderBy('template_key')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByTemplate(string $tenantId, string $templateType, string $templateKey, string $level): ?array
    {
        $row = $this->scoped($tenantId)
            ->where('template_type', $templateType)
            ->where('template_key', $templateKey)
            ->where('override_level', $level)
            ->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'            => $id,
            'tenant_id'     => $tenantId,
            'org_id'        => $data['org_id'] ?? null,
            'template_type' => $data['template_type'],
            'template_key'  => $data['template_key'],
            'override_level'=> $data['override_level'] ?? 'organization',
            'override_data' => isset($data['override_data']) ? json_encode($data['override_data']) : null,
            'is_active'     => (bool) ($data['is_active'] ?? true),
            'created_by'    => $data['created_by'],
            'created_date'  => $now,
            'updated_date'  => $now,
        ]);

        return $this->find($tenantId, $id);
    }

    public function update(string $tenantId, string $id, array $data): ?array
    {
        $now = $this->now();
        $fields = ['updated_date' => $now];

        $map = [
            'org_id'         => 'org_id',
            'template_type'  => 'template_type',
            'template_key'   => 'template_key',
            'override_level' => 'override_level',
            'is_active'      => 'is_active',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('override_data', $data)) {
            $fields['override_data'] = $data['override_data'] !== null ? json_encode($data['override_data']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
