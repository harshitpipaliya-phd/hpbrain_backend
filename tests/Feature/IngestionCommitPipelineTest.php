<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ingestion\IngestionBatch;
use App\Domain\Ingestion\IngestionService;
use App\Jobs\CommitIngestionJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The rewritten commit pipeline: chunked bulk writes, deterministic ids.
 *
 * WHAT THIS REPLACED. Every row went through
 * EventPublisher::publishInTransaction() — its own transaction plus a signal,
 * an evidence row and an outbox event, so five round trips per row. Against the
 * remote database that measured 306 rows in ~25 s, put 2,000 rows past the
 * 60-second request limit, and projected a 162,810-row file at ~3.8 hours.
 *
 * Two properties matter more than the speed and are what these tests pin:
 *
 *   1. IDEMPOTENCY. Signal ids are now derived from
 *      (tenant, source, row number, content hash) rather than uuid4(), so a
 *      retried queue job, a resubmitted request or a re-run of the same file
 *      collides with the primary key and writes nothing. The previous random
 *      ids duplicated the entire dataset on every retry — which matters far
 *      more now that commits are queued and `tries = 3`.
 *
 *   2. THE CHUNK IS THE ROLLBACK UNIT. A failing chunk takes only its own rows
 *      with it; earlier chunks stay committed and counted.
 */
final class IngestionCommitPipelineTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = '4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildJobTables();
    }

    private function service(): IngestionService
    {
        return app(IngestionService::class);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function batch(array $rows, string $tenant = self::TENANT, string $key = 'fees'): IngestionBatch
    {
        return new IngestionBatch(
            tenantId: $tenant,
            sourceKey: $key,
            sourceType: 'csv_upload',
            syncType: 'one_time_historical_import',
            sourceRef: '/tmp/fees.csv',
            rows: $rows,
            fetchedAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(int $n, string $prefix = 'Row'): array
    {
        return array_map(fn (int $i): array => [
            'Name'    => "{$prefix} {$i}",
            'Status'  => $i % 2 === 0 ? 'Paid' : 'Pending',
            'Remarks' => "note {$i}",
            'Ref'     => "REF{$i}",
        ], range(1, $n));
    }

    /** @return array<string, string> */
    private function map(): array
    {
        return [
            'title'         => 'Name',
            'state'         => 'Status',
            'evidence_text' => 'Remarks',
            'external_ref'  => 'Ref',
        ];
    }

    private function newJob(string $tenant = self::TENANT): string
    {
        $id = (string) \Ramsey\Uuid\Uuid::uuid4();

        DB::table('hpbrain_import_jobs')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'status' => 'previewed',
            'total_rows' => 0, 'processed_rows' => 0, 'success_count' => 0,
            'error_count' => 0, 'duplicate_count' => 0,
            'created_date' => '2026-01-01 00:00:00',
        ]);

        return $id;
    }

    // =====================================================================
    // Correctness across the chunk boundary
    // =====================================================================

    /**
     * More rows than one chunk holds, so the loop is genuinely exercised.
     *
     * 1,200 rows spans three chunks of 500. A rewrite that quietly processed
     * only the first chunk would still return a plausible-looking result.
     */
    public function test_every_row_across_multiple_chunks_is_written(): void
    {
        $jobId = $this->newJob();

        $result = $this->service()->commit($jobId, $this->batch($this->rows(1200)), $this->map(), 'actor');

        self::assertSame(1200, $result['success']);
        self::assertSame(0, $result['errors']);

        self::assertSame(1200, DB::table('hpbrain_signals')->where('tenant_id', self::TENANT)->count());
        self::assertSame(1200, DB::table('hpbrain_evidence')->where('tenant_id', self::TENANT)->count());

        // One outbox event per signal — the guarantee publishInTransaction gave.
        self::assertSame(1200, DB::table('hpbrain_event_store')->where('tenant_id', self::TENANT)->count());

        $job = DB::table('hpbrain_import_jobs')->where('id', $jobId)->first();
        self::assertSame('completed', $job->status);
        self::assertSame(1200, (int) $job->processed_rows);
        self::assertSame(1200, (int) $job->success_count);
    }

    // =====================================================================
    // Idempotency
    // =====================================================================

    /**
     * Committing the same rows twice writes them once.
     *
     * THE REGRESSION THIS PINS: uuid4() ids meant a retry produced entirely new
     * primary keys, so every re-run doubled the dataset. With `tries = 3` on the
     * queued job that turned one transient database blip into a triplicated
     * import.
     */
    public function test_recommitting_the_same_rows_creates_no_duplicates(): void
    {
        $rows = $this->rows(600);

        $first = $this->service()->commit($this->newJob(), $this->batch($rows), $this->map(), 'actor');
        self::assertSame(600, $first['success']);
        self::assertSame(600, DB::table('hpbrain_signals')->count());

        $second = $this->service()->commit($this->newJob(), $this->batch($rows), $this->map(), 'actor');

        // Still 600 rows in the database, and the result says so honestly
        // rather than reporting 600 fresh successes.
        self::assertSame(600, DB::table('hpbrain_signals')->count());
        self::assertSame(0, $second['success'], 'A repeat must not be counted as new writes.');
        self::assertSame(600, $second['skipped'], 'Skipped rows are duplicates and must be reported as such.');
    }

    /** A changed value is a different observation and gets its own signal. */
    public function test_a_changed_row_is_not_treated_as_a_duplicate(): void
    {
        $rows = $this->rows(10);
        $this->service()->commit($this->newJob(), $this->batch($rows), $this->map(), 'actor');
        self::assertSame(10, DB::table('hpbrain_signals')->count());

        $rows[0]['Status'] = 'Overdue';   // same row number, different content
        $this->service()->commit($this->newJob(), $this->batch($rows), $this->map(), 'actor');

        self::assertSame(11, DB::table('hpbrain_signals')->count());
    }

    /**
     * TWO DIFFERENT FILES CARRYING THE SAME RECORDS WRITE THEM ONCE.
     *
     * THE REGRESSION THIS PINS. Signal ids were derived from
     * (tenant, source, ROW NUMBER, byte hash of the row), so a record's
     * POSITION IN A FILE was part of its identity. File A and file B both
     * containing students 1–3 produced six rows for three children, and
     * re-cutting one export with an extra line near the top duplicated
     * everything below it. Identity is now the mapped business fields, which
     * no file layout can change.
     */
    public function test_overlapping_rows_from_two_different_files_are_stored_once(): void
    {
        $fileA = $this->rows(3);                             // records 1, 2, 3
        $fileB = array_merge(
            [['Name' => 'Row 9', 'Status' => 'Paid', 'Remarks' => 'note 9', 'Ref' => 'REF9']],
            $this->rows(3),                                  // the SAME 1, 2, 3, now at rows 2-4
        );

        $this->service()->commit($this->newJob(), $this->batch($fileA), $this->map(), 'actor');
        self::assertSame(3, DB::table('hpbrain_signals')->count());

        $second = $this->service()->commit($this->newJob(), $this->batch($fileB), $this->map(), 'actor');

        self::assertSame(4, DB::table('hpbrain_signals')->count(), 'Three overlapping records plus one new one.');
        self::assertSame(1, $second['success']);
        self::assertSame(3, $second['skipped']);
    }

    /**
     * Formatting is not identity.
     *
     * Whitespace, letter case and column ORDER all differ here, and none of
     * them changes which record this is. The user-visible symptom without this
     * was a second import of the same register — re-exported from a different
     * tool — doubling the whole dataset.
     */
    public function test_whitespace_case_and_column_order_do_not_create_duplicates(): void
    {
        $this->service()->commit($this->newJob(), $this->batch($this->rows(3)), $this->map(), 'actor');
        self::assertSame(3, DB::table('hpbrain_signals')->count());

        $reformatted = array_map(fn (array $row): array => [
            // Different key order, padded and re-cased values.
            'Ref'     => '  '.$row['Ref'].' ',
            'Remarks' => $row['Remarks'],
            'Status'  => strtoupper((string) $row['Status']),
            'Name'    => '  '.str_replace(' ', '  ', (string) $row['Name']).'  ',
        ], $this->rows(3));

        $result = $this->service()->commit($this->newJob(), $this->batch($reformatted), $this->map(), 'actor');

        self::assertSame(3, DB::table('hpbrain_signals')->count());
        self::assertSame(0, $result['success']);
        self::assertSame(3, $result['skipped']);
    }

    /**
     * A repeated external reference is NOT on its own a duplicate.
     *
     * REAL DATA, NOT A HYPOTHETICAL. Tenant 1000010's fee export carries
     * receipt 4707 twice — once for TANMAY SHUKLA, once for ADILALI MD. RAFIK
     * SHAIKH. An earlier attempt at this fix keyed identity on the external
     * reference alone and would have deleted one of those children's receipts.
     * Losing a real record is strictly worse than keeping a duplicate, and this
     * test exists so that trade is never made again.
     */
    public function test_two_records_sharing_an_external_reference_are_kept_apart(): void
    {
        $rows = [
            ['Name' => 'TANMAY SHUKLA', 'Status' => 'Paid', 'Remarks' => 'cash', 'Ref' => '4707'],
            ['Name' => 'ADILALI MD. RAFIK SHAIKH', 'Status' => 'Paid', 'Remarks' => 'cash', 'Ref' => '4707'],
        ];

        $result = $this->service()->commit($this->newJob(), $this->batch($rows), $this->map(), 'actor');

        self::assertSame(2, $result['success']);
        self::assertSame(2, DB::table('hpbrain_signals')->count());
    }

    /** The same record twice inside ONE file is still one record. */
    public function test_a_record_repeated_within_one_file_is_written_once(): void
    {
        $rows = $this->rows(3);
        $rows[] = $rows[0];
        $rows[] = $rows[2];

        $result = $this->service()->commit($this->newJob(), $this->batch($rows), $this->map(), 'actor');

        self::assertSame(3, DB::table('hpbrain_signals')->count());
        self::assertSame(3, $result['success']);
        self::assertSame(2, $result['skipped']);
    }

    /**
     * Two organizations numbering a student identically stay two students.
     *
     * The tenant is inside the derived id AND leads the UNIQUE index, so this
     * holds even when every mapped field is byte-identical.
     */
    public function test_identical_records_in_two_tenants_never_merge(): void
    {
        $rows = [['Name' => 'Same Child', 'Status' => 'Paid', 'Remarks' => 'x', 'Ref' => '10821']];

        $this->service()->commit($this->newJob('4'), $this->batch($rows, '4'), $this->map(), 'actor');
        $this->service()->commit($this->newJob('9'), $this->batch($rows, '9'), $this->map(), 'actor');

        self::assertSame(1, DB::table('hpbrain_signals')->where('tenant_id', '4')->count());
        self::assertSame(1, DB::table('hpbrain_signals')->where('tenant_id', '9')->count());
    }

    /** Two tenants uploading identical files do not collide. */
    public function test_identical_rows_in_two_tenants_are_separate_signals(): void
    {
        $rows = $this->rows(20);

        $this->service()->commit($this->newJob('4'), $this->batch($rows, '4'), $this->map(), 'actor');
        $this->service()->commit($this->newJob('9'), $this->batch($rows, '9'), $this->map(), 'actor');

        self::assertSame(20, DB::table('hpbrain_signals')->where('tenant_id', '4')->count());
        self::assertSame(20, DB::table('hpbrain_signals')->where('tenant_id', '9')->count());
        self::assertSame(40, DB::table('hpbrain_signals')->count());
    }

    // =====================================================================
    // Tenant isolation
    // =====================================================================

    public function test_every_written_row_belongs_to_the_committing_tenant(): void
    {
        $this->service()->commit($this->newJob('9'), $this->batch($this->rows(300), '9'), $this->map(), 'actor');

        self::assertSame(0, DB::table('hpbrain_signals')->where('tenant_id', '<>', '9')->count());
        self::assertSame(0, DB::table('hpbrain_evidence')->where('tenant_id', '<>', '9')->count());
        self::assertSame(0, DB::table('hpbrain_event_store')->where('tenant_id', '<>', '9')->count());
    }

    // =====================================================================
    // Reporting
    // =====================================================================

    /** A row with no mapped title is skipped and counted, never silently lost. */
    public function test_untitled_rows_are_skipped_and_reported(): void
    {
        $rows = $this->rows(10);
        $rows[3]['Name'] = null;
        $rows[7]['Name'] = null;

        $jobId = $this->newJob();
        $result = $this->service()->commit($jobId, $this->batch($rows), $this->map(), 'actor');

        self::assertSame(8, $result['success']);
        self::assertSame(2, $result['skipped']);
        self::assertSame(8, DB::table('hpbrain_signals')->count());

        // And the reason is on the audit trail, per row.
        self::assertSame(
            2,
            DB::table('hpbrain_import_logs')
                ->where('import_job_id', $jobId)->where('action', 'skipped')->count(),
        );
    }

    /** Provenance survives the rewrite: every signal names its source row. */
    public function test_provenance_is_preserved_through_bulk_writes(): void
    {
        $this->service()->commit($this->newJob(), $this->batch($this->rows(600)), $this->map(), 'actor');

        $meta = json_decode(
            (string) DB::table('hpbrain_signals')->orderBy('id')->value('metadata'),
            true,
        );

        self::assertArrayHasKey('provenance', $meta);
        self::assertSame('fees', $meta['provenance']['sourceKey']);
        self::assertArrayHasKey('rowNumber', $meta['provenance']);
        self::assertArrayHasKey('importJobId', $meta['provenance']);
    }

    // =====================================================================
    // Queue
    // =====================================================================

    /** The queued job carries the tenant explicitly — there is no request. */
    public function test_the_queued_job_is_constructed_with_its_tenant(): void
    {
        $job = new CommitIngestionJob('9', 'job-1', '/tmp/f.csv', 'fees', $this->map(), 'actor');

        $reflected = new \ReflectionClass($job);
        $tenant = $reflected->getProperty('tenantId');
        $tenant->setAccessible(true);

        self::assertSame('9', $tenant->getValue($job));
        self::assertSame(3, $job->tries, 'Retries are only safe because commit is idempotent.');
    }

    // =====================================================================
    // Fixture
    // =====================================================================

    private function buildJobTables(): void
    {
        if (! Schema::hasTable('hpbrain_import_jobs')) {
            Schema::create('hpbrain_import_jobs', function ($t) {
                $t->string('id', 36)->primary();
                $t->string('tenant_id', 36);
                $t->string('org_id', 36)->nullable();
                $t->string('source_id')->nullable();
                $t->string('source_ref')->nullable();
                $t->string('import_type')->nullable();
                $t->string('entity_type')->nullable();
                $t->string('sync_type')->nullable();
                $t->string('checkpoint')->nullable();
                $t->string('status')->default('pending');
                $t->integer('total_rows')->default(0);
                $t->integer('processed_rows')->default(0);
                $t->integer('success_count')->default(0);
                $t->integer('error_count')->default(0);
                $t->integer('duplicate_count')->default(0);
                $t->text('field_map')->nullable();
                $t->text('error_report')->nullable();
                $t->text('rollback_data')->nullable();
                $t->string('started_by')->nullable();
                $t->timestamp('fetched_at')->nullable();
                $t->timestamp('created_date')->nullable();
                $t->timestamp('updated_date')->nullable();
                $t->timestamp('completed_date')->nullable();
            });
        }

        if (! Schema::hasTable('hpbrain_import_logs')) {
            Schema::create('hpbrain_import_logs', function ($t) {
                $t->string('id', 36)->primary();
                $t->string('tenant_id', 36);
                $t->string('import_job_id', 36);
                $t->integer('row_number')->nullable();
                $t->string('action');
                $t->string('entity_type')->nullable();
                $t->string('entity_id')->nullable();
                $t->text('data')->nullable();
                $t->text('error_message')->nullable();
                $t->timestamp('created_date')->nullable();
            });
        }
    }
}
