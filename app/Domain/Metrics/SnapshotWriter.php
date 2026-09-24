<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Writes one metric snapshot per tenant per metric per day, idempotently.
 *
 * IDEMPOTENCY IS ENFORCED HERE, NOT BY THE UNIQUE INDEX, and the reason is a
 * subtlety worth stating. The table declares
 *
 *     UNIQUE (tenant_id, snapshot_date, metric_key, dimension_key)
 *
 * but dimension_key is nullable, and in MySQL — as in the SQL standard — two
 * NULLs are not equal, so that index does NOT prevent a second row for a
 * dimensionless metric. Running brain:snapshot twice in a day would silently
 * duplicate every un-dimensioned series and double-count it in any chart. So
 * every write is a lookup-then-update, with the null case matched explicitly by
 * whereNull.
 *
 * A NULL VALUE IS A RESULT. A metric with no denominator — a reuse rate on a
 * tenant with no learnings, a deficit with no assessments — is written with
 * value NULL, never 0. A reuse rate of null is not a reuse rate of zero, and a
 * series that converts one to the other draws a flat line along the bottom of
 * the chart and presents it as a measurement.
 */
final class SnapshotWriter
{
    public function __construct(private readonly string $snapshotDate)
    {
    }

    public function write(
        string $tenantId,
        string $metricKey,
        ?float $value,
        ?string $dimensionKey = null,
        ?float $confidence = null,
        ?int $sampleN = null,
    ): void {
        $existing = DB::table('hpbrain_metric_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('snapshot_date', $this->snapshotDate)
            ->where('metric_key', $metricKey)
            ->when(
                $dimensionKey === null,
                fn ($q) => $q->whereNull('dimension_key'),
                fn ($q) => $q->where('dimension_key', $dimensionKey),
            )
            ->value('id');

        $values = [
            'value'      => $value,
            'confidence' => $confidence,
            'sample_n'   => $sampleN,
        ];

        if ($existing !== null) {
            DB::table('hpbrain_metric_snapshots')->where('id', $existing)->update($values);

            return;
        }

        DB::table('hpbrain_metric_snapshots')->insert($values + [
            'id'            => Uuid::uuid4()->toString(),
            'tenant_id'     => $tenantId,
            'snapshot_date' => $this->snapshotDate,
            'metric_key'    => $metricKey,
            'dimension_key' => $dimensionKey,
            'created_date'  => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * A rate, written as NULL when the denominator is zero.
     *
     * The single most repeated shape in the snapshot command, and the single
     * easiest place to accidentally write 0. Doing the division in one place
     * means the null case is decided once.
     */
    public function writeRate(
        string $tenantId,
        string $metricKey,
        int|float $numerator,
        int|float $denominator,
        ?string $dimensionKey = null,
    ): void {
        $this->write(
            $tenantId,
            $metricKey,
            $denominator > 0 ? round($numerator / $denominator, 4) : null,
            $dimensionKey,
            sampleN: (int) $denominator,
        );
    }
}
