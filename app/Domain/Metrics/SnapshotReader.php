<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the one fact behind "movement since last time" for a metric
 * SnapshotWriter has been recording: the value from the measurement before
 * the most recent one.
 *
 * WHY THE OLDER OF THE TWO, NOT THE NEWEST. A caller comparing today's
 * live-computed figure against snapshot history must not compare it to
 * itself — and if today's scheduled snapshot already ran before this request
 * landed, the newest row IS today's figure. So this reads the two most recent
 * rows and returns the older one; the newest is read only to confirm a second
 * measurement exists at all. Fewer than two rows means movement cannot be
 * shown yet, and that is `null`, not a manufactured zero.
 *
 * WHAT THIS DOES NOT DO. It does not compute a delta, round a number, or word
 * a sentence — those are presentation, and presentation differs by caller
 * (a department's health score is an integer band; another metric might not
 * be). This returns the raw previous measurement; the caller decides what to
 * do with it, the same division of labour SnapshotWriter already keeps on the
 * write side.
 *
 * Extracted from DepartmentVerdict::delta(), which was the only caller before
 * this existed; DepartmentVerdictTest pins the read behaviour this preserves.
 */
final class SnapshotReader
{
    /** @return array{value: float, date: string}|null */
    public function previousMeasurement(string $tenantId, string $metricKey, ?string $dimensionKey = null): ?array
    {
        if (! Schema::hasTable('hpbrain_metric_snapshots')) {
            return null;
        }

        $rows = DB::table('hpbrain_metric_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('metric_key', $metricKey)
            ->when(
                $dimensionKey === null,
                fn ($q) => $q->whereNull('dimension_key'),
                fn ($q) => $q->where('dimension_key', $dimensionKey),
            )
            ->whereNotNull('value')
            ->orderByDesc('snapshot_date')
            ->limit(2)
            ->get(['snapshot_date', 'value']);

        if ($rows->count() < 2) {
            return null;
        }

        $previous = $rows[1];

        return [
            'value' => (float) $previous->value,
            'date' => (string) $previous->snapshot_date,
        ];
    }
}
