<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class LocationRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_locations';
    }

    protected function jsonColumns(): array
    {
        return ['metadata'];
    }

    public function list(string $tenantId, ?string $orgId = null, ?bool $isHeadquarters = null): array
    {
        $q = $this->scoped($tenantId);

        if ($orgId !== null) {
            $q->where('org_id', $orgId);
        }
        if ($isHeadquarters !== null) {
            $q->where('is_headquarters', $isHeadquarters);
        }

        return $q->orderBy('name')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByOrg(string $tenantId, string $orgId): array
    {
        return $this->list($tenantId, $orgId);
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'               => $id,
            'tenant_id'        => $tenantId,
            'org_id'           => $data['org_id'] ?? null,
            'location_type_id' => $data['location_type_id'] ?? null,
            'name'             => $data['name'],
            'address'          => $data['address'] ?? null,
            'city'             => $data['city'] ?? null,
            'state'            => $data['state'] ?? null,
            'country'          => $data['country'] ?? null,
            'postal_code'      => $data['postal_code'] ?? null,
            'timezone'         => $data['timezone'] ?? null,
            'phone'            => $data['phone'] ?? null,
            'email'            => $data['email'] ?? null,
            'metadata'         => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'is_headquarters'  => (bool) ($data['is_headquarters'] ?? false),
            'status'           => $data['status'] ?? 'active',
            'created_by'       => $data['created_by'],
            'created_date'     => $now,
            'updated_date'     => $now,
        ]);

        return $this->find($tenantId, $id);
    }

    public function update(string $tenantId, string $id, array $data): ?array
    {
        $now = $this->now();
        $fields = ['updated_date' => $now];

        $map = [
            'org_id'           => 'org_id',
            'location_type_id' => 'location_type_id',
            'name'             => 'name',
            'address'          => 'address',
            'city'             => 'city',
            'state'            => 'state',
            'country'          => 'country',
            'postal_code'      => 'postal_code',
            'timezone'         => 'timezone',
            'phone'            => 'phone',
            'email'            => 'email',
            'is_headquarters'  => 'is_headquarters',
            'status'           => 'status',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('metadata', $data)) {
            $fields['metadata'] = $data['metadata'] !== null ? json_encode($data['metadata']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
