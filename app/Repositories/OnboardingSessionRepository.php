<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class OnboardingSessionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_onboarding_sessions';
    }

    protected function jsonColumns(): array
    {
        return ['data', 'completed_steps'];
    }

    public function list(string $tenantId, ?string $orgId = null, ?string $status = null): array
    {
        $q = $this->scoped($tenantId);

        if ($orgId !== null) {
            $q->where('org_id', $orgId);
        }
        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->orderByDesc('created_date')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
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
            'id'             => $id,
            'tenant_id'      => $tenantId,
            'org_id'         => $data['org_id'] ?? null,
            'current_step'   => (int) ($data['current_step'] ?? 1),
            'total_steps'    => (int) ($data['total_steps'] ?? 12),
            'status'         => $data['status'] ?? 'draft',
            'data'           => isset($data['data']) ? json_encode($data['data']) : null,
            'completed_steps'=> isset($data['completed_steps']) ? json_encode($data['completed_steps']) : json_encode([]),
            'started_by'     => $data['started_by'],
            'completed_by'   => $data['completed_by'] ?? null,
            'activated_date' => $data['activated_date'] ?? null,
            'created_date'   => $now,
            'updated_date'   => $now,
        ]);

        return $this->find($tenantId, $id);
    }

    public function update(string $tenantId, string $id, array $data): ?array
    {
        $now = $this->now();
        $fields = ['updated_date' => $now];

        $map = [
            'org_id'        => 'org_id',
            'current_step'  => 'current_step',
            'total_steps'   => 'total_steps',
            'status'        => 'status',
            'started_by'    => 'started_by',
            'completed_by'  => 'completed_by',
            'activated_date'=> 'activated_date',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('data', $data)) {
            $fields['data'] = $data['data'] !== null ? json_encode($data['data']) : null;
        }
        if (array_key_exists('completed_steps', $data)) {
            $fields['completed_steps'] = $data['completed_steps'] !== null ? json_encode($data['completed_steps']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
