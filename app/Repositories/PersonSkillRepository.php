<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class PersonSkillRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_person_skills';
    }

    protected function jsonColumns(): array
    {
        return ['metadata'];
    }

    public function list(string $tenantId, ?string $personId = null, ?string $skillId = null): array
    {
        $q = $this->scoped($tenantId);

        if ($personId !== null) {
            $q->where('person_id', $personId);
        }
        if ($skillId !== null) {
            $q->where('skill_id', $skillId);
        }

        return $q->orderByDesc('assessed_date')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
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

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'                => $id,
            'tenant_id'         => $tenantId,
            'person_id'         => $data['person_id'],
            'skill_id'          => $data['skill_id'],
            'proficiency_level' => $data['proficiency_level'] ?? null,
            'proficiency_score' => $data['proficiency_score'] ?? null,
            'assessed_by'       => $data['assessed_by'] ?? null,
            'assessed_date'     => $data['assessed_date'] ?? null,
            'metadata'          => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_by'        => $data['created_by'],
            'created_date'      => $now,
            'updated_date'      => $now,
        ]);

        return $this->find($tenantId, $id);
    }

    public function update(string $tenantId, string $id, array $data): ?array
    {
        $now = $this->now();
        $fields = ['updated_date' => $now];

        $map = [
            'proficiency_level' => 'proficiency_level',
            'proficiency_score' => 'proficiency_score',
            'assessed_by'       => 'assessed_by',
            'assessed_date'     => 'assessed_date',
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
