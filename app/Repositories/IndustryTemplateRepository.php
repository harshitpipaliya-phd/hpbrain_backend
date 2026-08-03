<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class IndustryTemplateRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_industry_templates';
    }

    protected function jsonColumns(): array
    {
        return ['terminology', 'modules', 'navigation', 'dashboards', 'branding', 'workflows', 'integrations'];
    }

    public function list(string $tenantId): array
    {
        return $this->scoped($tenantId)
            ->orderBy('industry_code')
            ->get()
            ->map(fn ($r) => $this->hydrate((array) $r))
            ->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByIndustryCode(string $tenantId, string $industryCode): ?array
    {
        $row = $this->scoped($tenantId)
            ->where('industry_code', $industryCode)
            ->where('is_active', true)
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
            'industry_code'   => $data['industry_code'],
            'template_name'   => $data['template_name'],
            'description'     => $data['description'] ?? null,
            'terminology'     => isset($data['terminology']) ? json_encode($data['terminology']) : json_encode([]),
            'modules'         => isset($data['modules']) ? json_encode($data['modules']) : json_encode([]),
            'navigation'      => isset($data['navigation']) ? json_encode($data['navigation']) : json_encode([]),
            'dashboards'      => isset($data['dashboards']) ? json_encode($data['dashboards']) : json_encode([]),
            'branding'        => isset($data['branding']) ? json_encode($data['branding']) : json_encode([]),
            'workflows'       => isset($data['workflows']) ? json_encode($data['workflows']) : json_encode([]),
            'integrations'    => isset($data['integrations']) ? json_encode($data['integrations']) : json_encode([]),
            'is_system'       => $data['is_system'] ?? false,
            'is_active'       => $data['is_active'] ?? true,
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
            'industry_code' => 'industry_code',
            'template_name' => 'template_name',
            'description'   => 'description',
            'is_system'     => 'is_system',
            'is_active'     => 'is_active',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        $jsonCols = ['terminology', 'modules', 'navigation', 'dashboards', 'branding', 'workflows', 'integrations'];
        foreach ($jsonCols as $col) {
            if (array_key_exists($col, $data)) {
                $fields[$col] = $data[$col] !== null ? json_encode($data[$col]) : json_encode([]);
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
