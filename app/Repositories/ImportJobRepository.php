<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class ImportJobRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_import_jobs';
    }

    protected function jsonColumns(): array
    {
        return ['error_report', 'rollback_data'];
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
            'import_type'    => $data['import_type'],
            'entity_type'    => $data['entity_type'],
            'status'         => $data['status'] ?? 'pending',
            'total_rows'     => (int) ($data['total_rows'] ?? 0),
            'processed_rows' => (int) ($data['processed_rows'] ?? 0),
            'success_count'  => (int) ($data['success_count'] ?? 0),
            'error_count'    => (int) ($data['error_count'] ?? 0),
            'duplicate_count'=> (int) ($data['duplicate_count'] ?? 0),
            'error_report'   => isset($data['error_report']) ? json_encode($data['error_report']) : null,
            'rollback_data'  => isset($data['rollback_data']) ? json_encode($data['rollback_data']) : null,
            'started_by'     => $data['started_by'],
            'completed_date' => $data['completed_date'] ?? null,
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
            'import_type'     => 'import_type',
            'entity_type'     => 'entity_type',
            'status'          => 'status',
            'total_rows'      => 'total_rows',
            'processed_rows'  => 'processed_rows',
            'success_count'   => 'success_count',
            'error_count'     => 'error_count',
            'duplicate_count' => 'duplicate_count',
            'started_by'      => 'started_by',
            'completed_date'  => 'completed_date',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if (array_key_exists('error_report', $data)) {
            $fields['error_report'] = $data['error_report'] !== null ? json_encode($data['error_report']) : null;
        }
        if (array_key_exists('rollback_data', $data)) {
            $fields['rollback_data'] = $data['rollback_data'] !== null ? json_encode($data['rollback_data']) : null;
        }

        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->find($tenantId, $id);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->scoped($tenantId)->where('id', $id)->delete() > 0;
    }
}
