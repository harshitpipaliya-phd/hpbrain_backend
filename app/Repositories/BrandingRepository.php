<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class BrandingRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_branding';
    }

    protected function jsonColumns(): array
    {
        return [];
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

    public function findByOrg(string $tenantId, string $orgId): ?array
    {
        $row = $this->scoped($tenantId)
            ->where('org_id', $orgId)
            ->where('is_active', true)
            ->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'                  => $id,
            'tenant_id'           => $tenantId,
            'org_id'              => $data['org_id'],
            'name'                => $data['name'] ?? null,
            'logo_url'            => $data['logo_url'] ?? null,
            'favicon_url'         => $data['favicon_url'] ?? null,
            'primary_color'       => $data['primary_color'] ?? null,
            'secondary_color'     => $data['secondary_color'] ?? null,
            'accent_color'        => $data['accent_color'] ?? null,
            'font_family'         => $data['font_family'] ?? null,
            'login_background_url'=> $data['login_background_url'] ?? null,
            'email_header_url'    => $data['email_header_url'] ?? null,
            'custom_css'          => $data['custom_css'] ?? null,
            'is_active'           => $data['is_active'] ?? true,
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
            'name'                 => 'name',
            'logo_url'             => 'logo_url',
            'favicon_url'          => 'favicon_url',
            'primary_color'        => 'primary_color',
            'secondary_color'      => 'secondary_color',
            'accent_color'         => 'accent_color',
            'font_family'          => 'font_family',
            'login_background_url' => 'login_background_url',
            'email_header_url'     => 'email_header_url',
            'custom_css'           => 'custom_css',
            'is_active'            => 'is_active',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
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
