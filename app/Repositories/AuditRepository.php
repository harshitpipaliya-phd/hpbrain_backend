<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * AuditRepository — Brain-owned data.
 *
 * Table is hpbrain_-prefixed because the Brain shares a database with the
 * institute ERP and must never collide with it. Every read is tenant-scoped
 * through BaseRepository::scoped().
 */
final class AuditRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_audit_logs';
    }

    /** @return array<int, string> */
    protected function jsonColumns(): array
    {
        return ['changes'];
    }

    public function list(string $tenantId, ?string $status = null): array
    {
        $q = $this->scoped($tenantId);

        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->orderByDesc('created_date')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function findById(string $tenantId, string $id): ?array
    {
        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function insert(array $row): array
    {
        $row['id']           ??= $this->newId();
        $row['created_date'] ??= $this->now();

        // NOT scoped(): scoped() applies a WHERE clause, which is meaningless
        // on an INSERT and would silently no-op on some drivers.
        DB::table($this->table())->insert($row);

        return $row;
    }

    public function updateFields(string $tenantId, string $id, array $fields): ?array
    {
        $this->scoped($tenantId)->where('id', $id)->update($fields);

        return $this->findById($tenantId, $id);
    }
}
