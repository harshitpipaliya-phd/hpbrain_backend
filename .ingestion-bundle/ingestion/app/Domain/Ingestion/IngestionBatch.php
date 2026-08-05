<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

/**
 * The canonical shape every DataSource returns, whatever the raw origin was.
 *
 * Deliberately dumb — rows plus provenance. Turning a row into a Signal or a
 * piece of Evidence is FieldMap's job, not this class's. Keeping extraction and
 * mapping apart is what lets a mapping be corrected by a human without touching
 * source-connection code, and what lets a second source be added as one new
 * file in Sources/.
 *
 * IT CARRIES tenantId. Everything downstream of here writes to hpbrain_ tables,
 * all of which are tenant-scoped, and a batch that did not know its tenant
 * would force every consumer to be told separately — which is exactly the shape
 * of mistake EnsureTenantScope exists to make impossible.
 *
 * nextCheckpoint IS THE HIGHEST WATERMARK SEEN, NOT "now". A wall-clock
 * checkpoint silently loses any row written during the run. Sources that cannot
 * produce a real watermark return null, and the run is recorded as a full
 * historical import rather than pretending to be incremental.
 */
final class IngestionBatch
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  Raw rows, keyed by the
     *         source's own real column names — untouched and unmapped.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $sourceKey,
        public readonly string $sourceType,   // 'csv_upload' | 'internal_erp'
        public readonly string $syncType,     // 'one_time_historical_import' | 'incremental_sync'
        public readonly array $rows,
        public readonly \DateTimeImmutable $fetchedAt,
        public readonly ?string $nextCheckpoint = null,
        public readonly ?string $sourceRef = null,
    ) {
    }

    /** @return array<int, string> the source's own column names, in order */
    public function headers(): array
    {
        $first = $this->rows[0] ?? [];

        return array_keys($first);
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
