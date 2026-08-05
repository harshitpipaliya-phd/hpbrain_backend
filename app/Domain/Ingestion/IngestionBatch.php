<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

/**
 * The canonical shape every DataSource returns, regardless of whether the
 * raw origin was a CSV, a database row, or a third-party API response.
 *
 * Deliberately dumb — just rows plus provenance. Field mapping (turning
 * a row into a real Case/Signal/Evidence) is a separate, later step, not
 * this class's job. Keeping extraction and mapping separate is what lets
 * field mapping be reviewed/corrected by a human (or AI-suggested, see
 * Phase 1 note in CAP-025) without touching source-connection code at all.
 */
final class IngestionBatch
{
    /**
     * @param array<int, array<string, mixed>> $rows Raw rows, keys are the
     *   source's own real column/field names — untouched, unmapped.
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $sourceType,   // 'csv_upload' | 'internal_db' | 'external_api'
        public readonly string $syncType,     // 'one_time_historical_import' | 'incremental_sync'
        public readonly array $rows,
        public readonly \DateTimeImmutable $fetchedAt,
    ) {
    }
}
