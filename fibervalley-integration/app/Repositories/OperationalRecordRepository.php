<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * Read access to hpbrain_operational_records.
 *
 * Extends BaseRepository so tenant scoping is structural rather than
 * remembered, and so payload comes back as an array instead of the raw JSON
 * string PDO hands over — the same reason every other repository here does.
 *
 * Everything below is a READ. Writes go through the import loaders, which are
 * the only code that should ever create an operational record: a record with no
 * import job behind it has no provenance, and provenance is what lets the
 * Intelligence module cite evidence.
 */
final class OperationalRecordRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_operational_records';
    }

    protected function jsonColumns(): array
    {
        return ['payload'];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(string $tenantId, string $dataset, array $filters = [], int $limit = 500): array
    {
        $q = $this->scoped($tenantId)->where('dataset', $dataset);

        foreach (['status', 'zone', 'owner_name', 'supervisor_name', 'category'] as $column) {
            if (! empty($filters[$column])) {
                $q->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['since'])) {
            $q->where('occurred_at', '>=', $filters['since']);
        }

        return $q->orderByDesc('occurred_at')->limit($limit)->get()
            ->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    public function countFor(string $tenantId, string $dataset): int
    {
        return $this->scoped($tenantId)->where('dataset', $dataset)->count();
    }

    /**
     * Which datasets this tenant actually has, with row counts.
     *
     * Drives SignalRuleRegistry: rules are attached because the data exists,
     * never because the organization is named FiberValley. That is what keeps
     * the second organization onto this path a config change rather than a code
     * change.
     *
     * @return array<string, int> dataset => count
     */
    public function datasetCounts(string $tenantId): array
    {
        return $this->scoped($tenantId)
            ->select('dataset', DB::raw('COUNT(*) as total'))
            ->groupBy('dataset')
            ->pluck('total', 'dataset')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * The most recent complete month present in a dataset, as 'Y-m-01'.
     *
     * Rules evaluate against the latest month rather than all history, because
     * "412 tickets breached SLA" across a full year is noise, while "38 breached
     * last month" is actionable. Returns null when the dataset is empty, and
     * callers must treat that as "no signal", never as zero.
     */
    public function latestPeriod(string $tenantId, string $dataset): ?string
    {
        $max = $this->scoped($tenantId)
            ->where('dataset', $dataset)
            ->whereNotNull('occurred_at')
            ->max('occurred_at');

        if ($max === null) {
            return null;
        }

        return date('Y-m-01 00:00:00', strtotime((string) $max));
    }

    /**
     * Grouped counts for one dataset over an optional window.
     *
     * @param  array<string, mixed>  $where
     * @return array<int, array{label: ?string, total: int}>
     */
    public function countsGroupedBy(
        string $tenantId,
        string $dataset,
        string $column,
        array $where = [],
        ?string $since = null,
        int $limit = 20
    ): array {
        $allowed = ['zone', 'area', 'category', 'sub_category', 'owner_name', 'supervisor_name', 'status'];

        if (! in_array($column, $allowed, true)) {
            // Never interpolate a caller-supplied column into SQL. The allow
            // list is the whole defence.
            throw new \InvalidArgumentException("Cannot group operational records by '{$column}'.");
        }

        $q = $this->scoped($tenantId)
            ->where('dataset', $dataset)
            ->select($column.' as label', DB::raw('COUNT(*) as total'))
            ->groupBy($column)
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($limit);

        foreach ($where as $key => $value) {
            if (is_array($value)) {
                $q->whereIn($key, $value);
            } else {
                $q->where($key, $value);
            }
        }

        if ($since !== null) {
            $q->where('occurred_at', '>=', $since);
        }

        return $q->get()->map(fn ($r) => [
            'label' => $r->label === null ? null : (string) $r->label,
            'total' => (int) $r->total,
        ])->all();
    }

    /**
     * A small sample of rows matching a condition, for evidence.
     *
     * Every signal must cite the specific records that triggered it — the
     * Product Bible's rule that no rule produces a signal without traceable
     * evidence. This is how the rules get those records.
     *
     * @param  array<string, mixed>  $where
     * @return array<int, array<string, mixed>>
     */
    public function sample(string $tenantId, string $dataset, array $where = [], ?string $since = null, int $limit = 5): array
    {
        $q = $this->scoped($tenantId)->where('dataset', $dataset);

        foreach ($where as $key => $value) {
            if (is_array($value)) {
                $q->whereIn($key, $value);
            } elseif ($value === null) {
                $q->whereNull($key);
            } else {
                $q->where($key, $value);
            }
        }

        if ($since !== null) {
            $q->where('occurred_at', '>=', $since);
        }

        return $q->orderByDesc('occurred_at')->limit($limit)->get()
            ->map(fn ($r) => $this->hydrate((array) $r))->all();
    }

    /**
     * @param  array<string, mixed>  $where
     */
    public function count(string $tenantId, string $dataset, array $where = [], ?string $since = null): int
    {
        $q = $this->scoped($tenantId)->where('dataset', $dataset);

        foreach ($where as $key => $value) {
            if (is_array($value)) {
                $q->whereIn($key, $value);
            } elseif ($value === null) {
                $q->whereNull($key);
            } else {
                $q->where($key, $value);
            }
        }

        if ($since !== null) {
            $q->where('occurred_at', '>=', $since);
        }

        return $q->count();
    }

    /**
     * Count records whose numeric column exceeds a threshold.
     *
     * NULL is excluded rather than treated as zero. A complaint whose duration
     * could not be parsed has an UNKNOWN duration, and counting it as 0 hours
     * would quietly improve the SLA figure with every unparseable row — the
     * exact inversion of what an honest metric should do.
     */
    public function countAbove(string $tenantId, string $dataset, string $column, float $threshold, ?string $since = null): int
    {
        return $this->thresholdQuery($tenantId, $dataset, $column, $threshold, $since)->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sampleAbove(
        string $tenantId,
        string $dataset,
        string $column,
        float $threshold,
        ?string $since = null,
        int $limit = 5
    ): array {
        return $this->thresholdQuery($tenantId, $dataset, $column, $threshold, $since)
            ->orderByDesc($column)
            ->limit($limit)
            ->get()
            ->map(fn ($r) => $this->hydrate((array) $r))
            ->all();
    }

    /**
     * Subjects (subscribers) appearing more than N times in a window.
     *
     * The source workbook has a 'Complains more then 1 time' column, but this
     * counts the rows instead. That column is a spreadsheet formula whose value
     * was frozen when the export was taken; counting rows is derived from the
     * data actually imported and stays correct when a partial month is
     * re-imported.
     *
     * @return array<int, array{subject_ref: string, total: int, zone: ?string}>
     */
    public function repeatSubjects(
        string $tenantId,
        string $dataset,
        ?string $since,
        int $minimum,
        int $limit = 25
    ): array {
        $q = $this->scoped($tenantId)
            ->where('dataset', $dataset)
            ->whereNotNull('subject_ref')
            ->select('subject_ref', DB::raw('COUNT(*) as total'), DB::raw('MAX(zone) as zone'))
            ->groupBy('subject_ref')
            ->havingRaw('COUNT(*) >= ?', [$minimum])
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($limit);

        if ($since !== null) {
            $q->where('occurred_at', '>=', $since);
        }

        return $q->get()->map(fn ($r) => [
            'subject_ref' => (string) $r->subject_ref,
            'total'       => (int) $r->total,
            'zone'        => $r->zone === null ? null : (string) $r->zone,
        ])->all();
    }

    private function thresholdQuery(string $tenantId, string $dataset, string $column, float $threshold, ?string $since): \Illuminate\Database\Query\Builder
    {
        // Allow list for the same reason as countsGroupedBy: the column name
        // reaches SQL directly and must never come from a caller unchecked.
        if (! in_array($column, ['metric_value', 'quantity'], true)) {
            throw new \InvalidArgumentException("Cannot threshold operational records on '{$column}'.");
        }

        $q = $this->scoped($tenantId)
            ->where('dataset', $dataset)
            ->whereNotNull($column)
            ->where($column, '>', $threshold);

        if ($since !== null) {
            $q->where('occurred_at', '>=', $since);
        }

        return $q;
    }

    /**
     * Records whose payload column equals a value.
     *
     * JSON extraction cannot use an index, so this is only ever called against
     * datasets small enough for it to be free — the 12-row help-desk month
     * summary. Never call it against complaints.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allForDataset(string $tenantId, string $dataset, int $limit = 1000): array
    {
        return $this->scoped($tenantId)
            ->where('dataset', $dataset)
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => $this->hydrate((array) $r))
            ->all();
    }
}
