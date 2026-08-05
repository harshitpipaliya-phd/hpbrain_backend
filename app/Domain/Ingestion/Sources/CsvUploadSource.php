<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Sources;

use App\Domain\Ingestion\DataSource;
use App\Domain\Ingestion\IngestionBatch;
use League\Csv\Reader;

/**
 * Phase 2 (external ingestion, file upload) — the first real, concrete
 * DataSource implementation. Deliberately the simplest possible source:
 * no auth, no third-party API, just a file already sitting on disk after
 * upload. This exists specifically to prove the DataSource → field
 * mapping → Case/Signal/Evidence pipeline works end-to-end before adding
 * the real complexity of Phase 1 (internal DB connections) or Phase 3
 * (third-party OAuth).
 */
final class CsvUploadSource implements DataSource
{
    public function __construct(
        private readonly string $filePath,
        private readonly string $sourceId,
    ) {
    }

    public function fetch(?string $sinceCheckpoint = null): IngestionBatch
    {
        // A CSV upload has no "since last checkpoint" — every upload is a
        // full, one-time historical batch. $sinceCheckpoint intentionally
        // unused here; kept in the signature because every other real
        // source (K12DataSource, a future GoogleDriveSource) needs it.
        if (!is_readable($this->filePath)) {
            throw new \RuntimeException("Cannot read upload at {$this->filePath}");
        }

        $csv = Reader::createFromPath($this->filePath, 'r');
        $csv->setHeaderOffset(0);

        $rows = [];
        foreach ($csv->getRecords() as $record) {
            $rows[] = $record;
        }

        return new IngestionBatch(
            sourceId: $this->sourceId,
            sourceType: 'csv_upload',
            syncType: 'one_time_historical_import',
            rows: $rows,
            fetchedAt: new \DateTimeImmutable(),
        );
    }
}
