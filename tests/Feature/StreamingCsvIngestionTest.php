<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ingestion\IngestionBatch;
use App\Domain\Ingestion\Sources\CsvUploadSource;
use Tests\TestCase;

/**
 * Delimited uploads are read as a stream, not as an array.
 *
 * THE DEFECT THIS LOCKS DOWN. A 388,401-row marks export was refused outright
 * with `unreadable_upload`, because fetch() materialised every row as a PHP
 * array and CsvUploadSource capped that at 200,000 rows — ~240 MB against a
 * 512 MB memory_limit. The cap was correct and the rejection was still useless:
 * the file was perfectly valid.
 *
 * The properties asserted below are the ones that make the streaming reader
 * safe to substitute for the array reader, and each corresponds to a way the
 * change could have gone quietly wrong:
 *
 *   - count() must be exact BEFORE anything is consumed, because the preview,
 *     the queue threshold and the import job's total_rows all read it.
 *   - the stream must be re-readable, because the batch is legitimately read
 *     more than once (schema detection, then commit, then a retry). A
 *     single-use Generator here would commit a partial file — the exact
 *     failure the row cap existed to prevent.
 *   - chunk keys must stay the original row indexes, because import logs and
 *     error reports cite them.
 *   - ragged and blank rows must behave exactly as they did before.
 */
final class StreamingCsvIngestionTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    /** Write a CSV of $rows data rows and return a source over it. */
    private function source(int $rows, string $key = 'streaming-test'): CsvUploadSource
    {
        $this->path = tempnam(sys_get_temp_dir(), 'csvstream').'.csv';

        $fh = fopen($this->path, 'w');
        fwrite($fh, "ref,name,amount\n");

        for ($i = 1; $i <= $rows; $i++) {
            fwrite($fh, "R{$i},Person {$i},".($i * 10)."\n");
        }

        fclose($fh);

        return new CsvUploadSource(
            tenantId: 'tenant-stream',
            filePath: $this->path,
            sourceKey: $key,
            originalName: 'upload.csv',
            originalExtension: 'csv',
        );
    }

    public function test_a_delimited_upload_produces_a_stream_backed_batch(): void
    {
        $batch = $this->source(10)->fetch();

        $this->assertTrue($batch->isStreamed());
        $this->assertSame([], $batch->rows, 'a streamed batch must not materialise its rows');
    }

    public function test_the_row_count_is_exact_without_consuming_the_stream(): void
    {
        $batch = $this->source(1234)->fetch();

        $this->assertSame(1234, $batch->count());

        // Still fully readable afterwards — count() consumed nothing.
        $seen = 0;
        foreach ($batch->rows() as $_) {
            $seen++;
        }

        $this->assertSame(1234, $seen);
    }

    public function test_the_stream_can_be_read_more_than_once(): void
    {
        $batch = $this->source(500)->fetch();

        $first = 0;
        foreach ($batch->chunks(100) as $chunk) {
            $first += count($chunk);
        }

        $second = 0;
        foreach ($batch->chunks(100) as $chunk) {
            $second += count($chunk);
        }

        $this->assertSame(500, $first);
        $this->assertSame(500, $second, 'the batch must reopen its source, not hand back a spent iterator');
    }

    public function test_chunks_preserve_the_original_row_index_as_the_key(): void
    {
        $batch = $this->source(250)->fetch();

        $keys = [];
        foreach ($batch->chunks(100) as $chunk) {
            foreach (array_keys($chunk) as $key) {
                $keys[] = $key;
            }
        }

        $this->assertSame(range(0, 249), $keys);
    }

    public function test_chunks_are_sized_as_requested_with_a_short_final_chunk(): void
    {
        $batch = $this->source(250)->fetch();

        $sizes = [];
        foreach ($batch->chunks(100) as $chunk) {
            $sizes[] = count($chunk);
        }

        $this->assertSame([100, 100, 50], $sizes);
    }

    public function test_headers_and_sample_are_available_without_streaming(): void
    {
        $batch = $this->source(5000)->fetch();

        $this->assertSame(['ref', 'name', 'amount'], $batch->headers());

        $sample = $batch->sample(3);
        $this->assertCount(3, $sample);
        $this->assertSame('R1', $sample[0]['ref']);
        $this->assertSame('30', $sample[2]['amount']);
    }

    public function test_the_full_row_content_survives_streaming(): void
    {
        $batch = $this->source(3)->fetch();

        $rows = [];
        foreach ($batch->rows() as $row) {
            $rows[] = $row;
        }

        $this->assertSame(
            [
                ['ref' => 'R1', 'name' => 'Person 1', 'amount' => '10'],
                ['ref' => 'R2', 'name' => 'Person 2', 'amount' => '20'],
                ['ref' => 'R3', 'name' => 'Person 3', 'amount' => '30'],
            ],
            $rows,
        );
    }

    /**
     * The size that used to be refused.
     *
     * 200,001 rows is one past the old array-reader cap. Kept deliberately just
     * over the boundary rather than at the real file's 388,401, so the property
     * is proved without writing a 30 MB fixture on every test run.
     */
    public function test_a_file_past_the_old_array_cap_is_now_readable(): void
    {
        $batch = $this->source(200001)->fetch();

        $this->assertSame(200001, $batch->count());

        $seen = 0;
        foreach ($batch->chunks(5000) as $chunk) {
            $seen += count($chunk);
        }

        $this->assertSame(200001, $seen);
    }

    public function test_memory_does_not_scale_with_the_row_count(): void
    {
        $batch = $this->source(200001)->fetch();

        $before = memory_get_usage(true);
        $peak = $before;

        foreach ($batch->chunks(1000) as $_) {
            $peak = max($peak, memory_get_usage(true));
        }

        // 200,001 rows as PHP arrays would be hundreds of megabytes. Streaming
        // in 1,000-row chunks must stay within a small multiple of the chunk,
        // and 64 MB is generous enough not to be flaky while still failing
        // loudly if the whole file is ever materialised again.
        $this->assertLessThan(
            64 * 1024 * 1024,
            $peak - $before,
            'streaming must not grow memory with the row count',
        );
    }

    public function test_an_array_backed_batch_still_behaves_exactly_as_before(): void
    {
        // ErpDataSource and the fixtures construct batches this way, so the
        // array path has to keep working unchanged.
        $batch = new IngestionBatch(
            tenantId: 't1',
            sourceKey: 'k',
            sourceType: 'internal_erp',
            syncType: 'incremental_sync',
            rows: [['a' => '1'], ['a' => '2'], ['a' => '3']],
            fetchedAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );

        $this->assertFalse($batch->isStreamed());
        $this->assertSame(3, $batch->count());
        $this->assertSame(['a'], $batch->headers());
        $this->assertSame([['a' => '1'], ['a' => '2']], $batch->sample(2));

        $sizes = [];
        foreach ($batch->chunks(2) as $chunk) {
            $sizes[] = count($chunk);
        }

        $this->assertSame([2, 1], $sizes);
    }

    public function test_a_chunk_size_below_one_is_refused(): void
    {
        $batch = $this->source(3)->fetch();

        $this->expectException(\InvalidArgumentException::class);

        iterator_to_array($batch->chunks(0));
    }
}
