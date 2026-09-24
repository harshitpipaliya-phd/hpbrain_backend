<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class SkillRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_skills';
    }

    protected function jsonColumns(): array
    {
        return ['metadata'];
    }

    public function list(string $tenantId, ?string $category = null, ?string $status = null): array
    {
        $q = $this->scoped($tenantId);

        if ($category !== null) {
            $q->where('category', $category);
        }
        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->orderBy('name')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findBySkillKey(string $tenantId, string $skillKey): ?array
    {
        $row = $this->scoped($tenantId)->where('skill_key', $skillKey)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'skill_key'    => $data['skill_key'],
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'category'     => $data['category'] ?? null,
            'level'        => $data['level'] ?? null,
            'metadata'     => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'status'       => $data['status'] ?? 'active',
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
            'skill_key'  => 'skill_key',
            'name'       => 'name',
            'description'=> 'description',
            'category'   => 'category',
            'level'      => 'level',
            'status'     => 'status',
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
