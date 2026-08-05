<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Sources;

use App\Domain\Ingestion\DataSource;
use App\Domain\Ingestion\IngestionBatch;

/**
 * External ingestion — a file already on disk after upload.
 *
 * The simplest possible real source: no auth, no third party, no network. It
 * exists to prove the DataSource → FieldMap → Signal/Evidence path end to end
 * before the harder sources are attempted.
 *
 * NATIVE fgetcsv, NOT league/csv. ImportEngine already parses CSV this way, so
 * adding a Composer dependency would have meant two parsers in one codebase for
 * one file format — and in an environment where `composer require` has been a
 * live blocker, it would have made the whole feature un-installable for the
 * sake of convenience. The three things league/csv would have given us for free
 * are handled explicitly below: BOM, ragged rows, duplicate headers.
 *
 * A CSV UPLOAD IS ALWAYS A FULL HISTORICAL BATCH. $sinceCheckpoint is accepted
 * and ignored, because a file has no "since". It stays in the signature because
 * every other source needs it, and an interface that varied per source would
 * defeat the point of having one.
 */
final class CsvUploadSource implements DataSource
{
    /**
     * Rows are held in memory, so the file size is bounded at the HTTP layer.
     * This cap is the second line of defence: a 20 MB CSV of short rows is
     * roughly 200k rows, and materialising those as PHP arrays is where the
     * memory goes, not the file itself.
     */
    private const MAX_ROWS = 50000;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $filePath,
        private readonly string $sourceKey,
    ) {
    }

    public function fetch(?string $sinceCheckpoint = null): IngestionBatch
    {
        if (! is_readable($this->filePath)) {
            // Thrown, not returned as an empty batch — see the DataSource
            // docblock. This is also the exact failure the Laravel 11 default
            // disk root produces when a caller builds the path by hand:
            // ->store() writes under storage/app/private, storage_path('app/…')
            // points one directory too high, and is_readable() is false.
            throw new \RuntimeException("Cannot read upload at {$this->filePath}");
        }

        $handle = fopen($this->filePath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open upload at {$this->filePath}");
        }

        try {
            $headers = $this->readHeaders($handle);
            $rows = [];

            while (($record = fgetcsv($handle)) !== false) {
                // fgetcsv yields [null] for a blank line. Skipping it here
                // keeps blank separator lines out of the row count, which is
                // the number the reviewer checks the preview against.
                if ($record === [null] || $record === []) {
                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw new \RuntimeException(
                        'CSV exceeds '.self::MAX_ROWS.' rows; split the file or use an incremental source.'
                    );
                }

                $rows[] = $this->combine($headers, $record);
            }
        } finally {
            fclose($handle);
        }

        return new IngestionBatch(
            tenantId: $this->tenantId,
            sourceKey: $this->sourceKey,
            sourceType: 'csv_upload',
            syncType: 'one_time_historical_import',
            rows: $rows,
            fetchedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            // No watermark exists for a file. Recording null keeps the run
            // honestly labelled as a one-off rather than seeding a checkpoint
            // that would make the NEXT run skip real rows.
            nextCheckpoint: null,
            sourceRef: $this->filePath,
        );
    }

    /**
     * @param  resource  $handle
     * @return array<int, string>
     */
    private function readHeaders($handle): array
    {
        $headers = fgetcsv($handle);

        if ($headers === false || $headers === [null]) {
            throw new \RuntimeException('CSV has no header row.');
        }

        $seen = [];
        $clean = [];

        foreach ($headers as $index => $header) {
            $name = trim((string) $header);

            // Excel writes a UTF-8 BOM at the start of the file, which lands
            // inside the FIRST header name. Left in place it makes "Subject"
            // and "\u{FEFF}Subject" different keys, so a field map saved from
            // one export silently matches nothing in the next.
            if ($index === 0) {
                $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
            }

            if ($name === '') {
                $name = 'column_'.($index + 1);
            }

            // Duplicate headers are common in exported spreadsheets. Left
            // alone, array_combine keeps only the last one and the earlier
            // column vanishes without a word.
            if (isset($seen[$name])) {
                $seen[$name]++;
                $name .= '_'.$seen[$name];
            } else {
                $seen[$name] = 1;
            }

            $clean[] = $name;
        }

        return $clean;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string|null>  $record
     * @return array<string, string>
     */
    private function combine(array $headers, array $record): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            // Ragged rows are the normal case in hand-edited exports.
            // array_combine() would throw on a length mismatch and abort the
            // whole file; a short row is far more likely to be a trailing
            // empty cell than a corrupt file, so the missing tail reads as ''.
            $row[$header] = trim((string) ($record[$index] ?? ''));
        }

        return $row;
    }
}
