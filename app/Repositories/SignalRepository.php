<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * SignalRepository — Brain-owned data.
 *
 * Table is hpbrain_-prefixed because the Brain shares a database with the
 * institute ERP and must never collide with it. Every read is tenant-scoped
 * through BaseRepository::scoped().
 */
final class SignalRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_signals';
    }

    /** @return array<int, string> */
    protected function jsonColumns(): array
    {
        return ['metadata'];
    }

    public function list(string $tenantId, ?string $status = null): array
    {
        $q = $this->scoped($tenantId);

        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->orderByDesc('created_date')->get()->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    /**
     * Signals awaiting reasoning, RULE-DERIVED ONES FIRST.
     *
     * WHY THIS IS NOT list() WITH A FILTER. list() orders newest-first, which is
     * right for a screen — the most recent thing is what somebody scrolling
     * wants. It is wrong for spending a reasoning budget. Ingestion turns each
     * imported spreadsheet row into its own signal, and those arrive in bulk and
     * recent: one tenant here holds 1,499 of them against 8 rule-derived
     * findings. Newest-first means a --limit of any sane size is consumed
     * entirely by imported rows, and the findings the Brain actually DERIVED —
     * the ones with a rule behind them, evidence attached and a case open — are
     * never reached at all. Every provider call would be spent on the least
     * informative signals in the database.
     *
     * So `rule_key IS NOT NULL` sorts first, newest-first within each group.
     * Imported signals are not excluded — they are simply behind the findings.
     *
     * `(rule_key IS NULL)` yields 0/1 on both MySQL and SQLite, so one ORDER BY
     * serves the ERP database and the test suite alike.
     *
     * Ordering, status and limit are all applied in SQL. list() pulls every
     * signal a tenant has into PHP before anything narrows it, which on that
     * same tenant is 1,499 rows hydrated to discard 1,494.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingForReasoning(string $tenantId, int $limit): array
    {
        return $this->scoped($tenantId)
            ->whereIn('status', ['new', 'triaged'])
            ->orderByRaw('(rule_key IS NULL) asc')
            ->orderByDesc('created_date')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => $this->hydrate((array) $r))
            ->all();
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
