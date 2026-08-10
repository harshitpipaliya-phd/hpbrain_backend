<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

final class ImportLogRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_import_logs';
    }

    protected function jsonColumns(): array
    {
        return ['data'];
    }

    public function list(string $tenantId, ?string $importJobId = null, ?string $action = null): array
    {
        $q = $this->scoped($tenantId);

        if ($importJobId !== null) {
            $q->where('import_job_id', $importJobId);
        }
        if ($action !== null) {
            $q->where('action', $action);
        }

        return $q->orderBy('row_number')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function findByImportJob(string $tenantId, string $importJobId): array
    {
        return $this->list($tenantId, $importJobId);
    }

    /**
     * Insert many log rows in one statement, returning nothing.
     *
     * WHY THIS EXISTS. create() inserts and then SELECTs the row back so it can
     * return it — two round trips. Ingestion calls it once per row and nothing
     * reads the return value, so a 306-row commit spent 612 round trips
     * building an audit trail nobody was waiting on. Against the remote MySQL
     * this project uses (~40 ms RTT) that alone was ~25 seconds of the 60-second
     * timeout that made commit fail outright.
     *
     * Chunked because MySQL's max_allowed_packet and the bound-parameter limit
     * both cap how much one INSERT can carry, and an import job can produce two
     * hundred thousand log rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function createMany(string $tenantId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = $this->now();

        $payload = array_map(fn (array $data): array => [
            'id'            => $this->newId(),
            'tenant_id'     => $tenantId,
            'import_job_id' => $data['import_job_id'],
            'row_number'    => $data['row_number'] ?? null,
            'action'        => $data['action'],
            'entity_type'   => $data['entity_type'] ?? null,
            'entity_id'     => $data['entity_id'] ?? null,
            'data'          => isset($data['data']) ? json_encode($data['data']) : null,
            'error_message' => $data['error_message'] ?? null,
            'created_date'  => $now,
        ], $rows);

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table($this->table())->insert($chunk);
        }
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->newId();
        $now = $this->now();

        DB::table($this->table())->insert([
            'id'            => $id,
            'tenant_id'     => $tenantId,
            'import_job_id' => $data['import_job_id'],
            'row_number'    => $data['row_number'] ?? null,
            'action'        => $data['action'],
            'entity_type'   => $data['entity_type'] ?? null,
            'entity_id'     => $data['entity_id'] ?? null,
            'data'          => isset($data['data']) ? json_encode($data['data']) : null,
            'error_message' => $data['error_message'] ?? null,
            'created_date'  => $now,
        ]);

        return $this->find($tenantId, $id);
    }

    public function deleteByJob(string $tenantId, string $importJobId): int
    {
        return $this->scoped($tenantId)->where('import_job_id', $importJobId)->delete();
    }
}
