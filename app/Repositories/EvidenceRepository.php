<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * EvidenceRepository — Brain-owned data.
 *
 * Table is hpbrain_-prefixed because the Brain shares a database with the
 * institute ERP and must never collide with it. Every read is tenant-scoped
 * through BaseRepository::scoped().
 */
final class EvidenceRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_evidence';
    }

    /** @return array<int, string> */
    protected function jsonColumns(): array
    {
        return ['content', 'provenance'];
    }

    /**
     * $signalId filters in SQL rather than in PHP. idx_evidence_signal is
     * (tenant_id, signal_id) — the controller previously pulled every evidence
     * row in the tenant and filtered the result set, so the index existed and
     * was never used, and the cost grew with the tenant instead of with the
     * answer.
     */
    /**
     * @param  string|null  $since  ISO/SQL timestamp; only evidence recorded at
     *         or after it. Applied to created_date, matching SignalRepository.
     * @param  int|null  $limit  newest first, so a capped read returns the most
     *         recent evidence rather than an arbitrary slice.
     *
     * BOTH DEFAULT TO NULL AND THE UNFILTERED CALL RETURNS EVERYTHING, exactly
     * as before — the parameters narrow, they do not silently truncate. That
     * matters because an endpoint that quietly capped its own output would make
     * a partial answer indistinguishable from a complete one.
     *
     * They exist because this endpoint had no way to ask for less. Measured on
     * the Lions tenant: 10,430 evidence rows serialised to 8.95 MB in 6,258 ms,
     * to render a screen that shows 25 at a time.
     */
    public function list(
        string $tenantId,
        ?string $status = null,
        ?string $signalId = null,
        ?string $since = null,
        ?int $limit = null,
    ): array {
        $q = $this->scoped($tenantId);

        if ($status !== null) {
            $q->where('status', $status);
        }

        if ($signalId !== null) {
            $q->where('signal_id', $signalId);
        }

        if ($since !== null) {
            $q->where('created_date', '>=', $since);
        }

        $q->orderByDesc('created_date');

        // Applied AFTER the ordering, so `limit` means "the newest N".
        if ($limit !== null) {
            $q->limit($limit);
        }

        return $q->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
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
