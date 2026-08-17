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
 *
 * ────────────────────────────────────────────────────────────────────────────
 * TWO BACKINGS: AN ARRAY, OR A REWINDABLE STREAM.
 *
 * Every row used to be materialised as a PHP array before anything could look
 * at the batch, which put a hard ceiling on file size — CsvUploadSource refused
 * anything over 200,000 rows because ~240 MB of arrays against a 512 MB
 * memory_limit was the real wall. A 388,401-row marks export was rejected
 * outright with `unreadable_upload`, correctly but uselessly.
 *
 * A batch may now instead carry a FACTORY that returns a fresh iterator over
 * the source. Memory then depends on the chunk size, not the row count.
 *
 * IT IS A FACTORY AND NOT A GENERATOR, and that is the important detail. A
 * Generator can be consumed exactly once, and this batch is legitimately read
 * more than once: schema detection reads a sample, the committer reads every
 * row, and a retry reads them all again. Handing round a half-consumed
 * Generator would silently commit a partial file — the precise failure mode the
 * row cap existed to prevent. Every call to rows() opens a new stream.
 *
 * ARRAY-BACKED BATCHES BEHAVE EXACTLY AS BEFORE. `$rows` is still a public
 * array for them, so ErpDataSource, the fixtures and every existing test are
 * untouched. Consumers should nonetheless prefer count(), headers(), sample()
 * and chunks(), which work for both backings; reading `->rows` directly is
 * correct only where the caller already knows the batch is array-backed.
 */
final class IngestionBatch
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  Raw rows, keyed by the
     *         source's own real column names — untouched and unmapped. Empty
     *         when the batch is stream-backed; use rows() or chunks().
     * @param  (\Closure(): iterable<int, array<string, mixed>>)|null  $rowStream
     *         Returns a FRESH iterator each call. Set only by streaming sources.
     * @param  int|null  $streamCount  Row count, counted during the source's
     *         own scan. Required with $rowStream, because an iterator cannot be
     *         counted without consuming it.
     * @param  array<int, string>  $streamHeaders  Column names in order.
     * @param  array<int, array<string, mixed>>  $streamSample  A few real rows,
     *         held for preview and schema detection so neither has to stream.
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
        private readonly ?\Closure $rowStream = null,
        private readonly ?int $streamCount = null,
        private readonly array $streamHeaders = [],
        private readonly array $streamSample = [],
    ) {
    }

    public function isStreamed(): bool
    {
        return $this->rowStream !== null;
    }

    /**
     * Every row, as a fresh iterator.
     *
     * Safe to call repeatedly: a stream-backed batch reopens its source, an
     * array-backed one hands back the array.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function rows(): iterable
    {
        return $this->rowStream === null ? $this->rows : ($this->rowStream)();
    }

    /**
     * Rows in groups of $size, preserving the original row index as the key.
     *
     * The index is what import logs and error reports cite when they name the
     * row a problem was found on, so it has to survive chunking — which is why
     * this yields keyed arrays rather than bare lists.
     *
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    public function chunks(int $size): \Generator
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Chunk size must be at least 1.');
        }

        if ($this->rowStream === null) {
            yield from array_chunk($this->rows, $size, true);

            return;
        }

        $chunk = [];
        $index = 0;

        foreach (($this->rowStream)() as $row) {
            $chunk[$index] = $row;
            $index++;

            if (count($chunk) >= $size) {
                yield $chunk;
                // Released before the next chunk is built, so peak memory is
                // one chunk and not the whole file.
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            yield $chunk;
        }
    }

    /**
     * A few real rows, for preview and schema detection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sample(int $limit = 50): array
    {
        if ($this->rowStream === null) {
            return array_slice($this->rows, 0, $limit);
        }

        return array_slice($this->streamSample, 0, $limit);
    }

    /** @return array<int, string> the source's own column names, in order */
    public function headers(): array
    {
        if ($this->streamHeaders !== []) {
            return $this->streamHeaders;
        }

        $first = $this->rows[0] ?? ($this->streamSample[0] ?? []);

        return array_keys($first);
    }

    public function count(): int
    {
        return $this->streamCount ?? count($this->rows);
    }
}
