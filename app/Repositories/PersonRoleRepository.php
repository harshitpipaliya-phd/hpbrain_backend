<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class PersonRoleRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_person_roles';
    }

    protected function jsonColumns(): array
    {
        return ['metadata'];
    }

    public function list(string $tenantId, ?string $personId = null, ?string $roleId = null, ?string $orgId = null): array
    {
        $q = $this->scoped($tenantId);

        if ($personId !== null) {
            $q->where('person_id', $personId);
        }
        if ($roleId !== null) {
            $q->where('role_id', $roleId);
        }
        if ($orgId !== null) {
            $q->where('org_id', $orgId);
        }

        return $q->orderByDesc('start_date')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByPerson(string $tenantId, string $personId): array
    {
        return $this->list($tenantId, $personId);
    }

    public function findByRole(string $tenantId, string $roleId): array
    {
        return $this->list($tenantId, null, $roleId);
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'person_id'    => $data['person_id'],
            'role_id'      => $data['role_id'],
            'org_id'       => $data['org_id'] ?? null,
            'unit_id'      => $data['unit_id'] ?? null,
            'start_date'   => $data['start_date'] ?? null,
            'end_date'     => $data['end_date'] ?? null,
            'is_primary'   => (bool) ($data['is_primary'] ?? false),
            'metadata'     => isset($data['metadata']) ? json_encode($data['metadata']) : null,
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
            'person_id'  => 'person_id',
            'role_id'    => 'role_id',
            'org_id'     => 'org_id',
            'unit_id'    => 'unit_id',
            'start_date' => 'start_date',
            'end_date'   => 'end_date',
            'is_primary' => 'is_primary',
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
