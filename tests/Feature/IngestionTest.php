<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ingestion\IngestionService;
use App\Domain\Ingestion\Sources\CsvUploadSource;
use App\Repositories\DataSourceRepository;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The gate this feature exists to pass: ingestion WRITES.
 *
 * ImportEngine::processImport() reported 400 successes on a 400-row file and
 * inserted nothing, and no test in the suite could tell — because there were no
 * import tests at all. Every assertion below therefore counts real rows in
 * hpbrain_signals, hpbrain_evidence and hpbrain_event_store rather than reading
 * the job's own success_count, which is the number that lied.
 */
final class IngestionTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = '4';

    private const ACTOR = 'test-actor';

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();

        $this->csv = tempnam(sys_get_temp_dir(), 'ingest').'.csv';

        // Deliberately awkward, the way real exports are: a UTF-8 BOM on the
        // first header, a row with no remark, a ragged short row, and a blank
        // separator line.
        file_put_contents($this->csv, "\u{FEFF}Subject,Assigned To,Status,Remarks,Start Date\n"
            ."Fix projector,Priya,Open,Replaced the bulb,2026-07-01\n"
            ."Order lab kits,Ravi,Closed,,2026-07-02\n"
            ."\n"
            ."Audit register,Meera,Open\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        parent::tearDown();
    }

    private function batch(): \App\Domain\Ingestion\IngestionBatch
    {
        return (new CsvUploadSource(self::TENANT, $this->csv, 'internal-upload-test'))->fetch();
    }

    private function map(): array
    {
        return [
            'title'              => 'Subject',
            'owner'              => 'Assigned To',
            'state'              => 'Status',
            'evidence_text'      => 'Remarks',
            'evidence_timestamp' => 'Start Date',
        ];
    }

    /** @test */
    public function the_bom_is_stripped_from_the_first_header(): void
    {
        // Left in place, "\u{FEFF}Subject" and "Subject" are different keys and
        // a saved field map silently matches nothing on the next export.
        $this->assertSame('Subject', $this->batch()->headers()[0]);
    }

    /** @test */
    public function ragged_and_blank_rows_are_handled_without_aborting_the_file(): void
    {
        $batch = $this->batch();

        // Three data rows: the blank separator line is dropped, the short row
        // is kept with its missing tail read as ''.
        $this->assertSame(3, $batch->count());
        $this->assertSame('', $batch->rows[2]['Start Date']);
    }

    /** @test */
    public function preview_writes_nothing_to_the_graph(): void
    {
        $result = app(IngestionService::class)->preview($this->batch(), self::ACTOR);

        $this->assertSame(IngestionService::PREVIEWED, $result['job']['status']);
        $this->assertSame(0, DB::table('hpbrain_signals')->count());
        $this->assertSame(0, DB::table('hpbrain_evidence')->count());
    }

    /** @test */
    public function commit_writes_real_signals_and_evidence(): void
    {
        $service = app(IngestionService::class);
        $batch = $this->batch();
        $job = $service->preview($batch, self::ACTOR)['job'];

        $result = $service->commit($job['id'], $batch, $this->map(), self::ACTOR);

        $this->assertSame(3, $result['success']);

        // The assertion the old engine would have failed: real rows, counted
        // from the table rather than from the job.
        $this->assertSame(3, DB::table('hpbrain_signals')->where('tenant_id', self::TENANT)->count());

        // Only the rows that actually carry a remark become evidence. The
        // second row's remark is blank and the third has no remark column
        // value at all — neither may manufacture an empty Evidence record.
        $this->assertSame(1, DB::table('hpbrain_evidence')->where('tenant_id', self::TENANT)->count());
    }

    /** @test */
    public function every_committed_signal_emits_its_loop_event(): void
    {
        $service = app(IngestionService::class);
        $batch = $this->batch();
        $job = $service->preview($batch, self::ACTOR)['job'];
        $service->commit($job['id'], $batch, $this->map(), self::ACTOR);

        // A row written without its event is invisible to replay. One event
        // per signal, never one per batch.
        $this->assertSame(
            3,
            DB::table('hpbrain_event_store')->where('type', 'ObservationMade')->count(),
        );
    }

    /** @test */
    public function a_missing_state_becomes_undetermined_rather_than_a_guess(): void
    {
        $service = app(IngestionService::class);
        $batch = $this->batch();
        $job = $service->preview($batch, self::ACTOR)['job'];

        // Map everything except state's source column to a header that does
        // not exist, so no row can supply one.
        $map = $this->map();
        $map['state'] = 'No Such Column';

        $service->commit($job['id'], $batch, $map, self::ACTOR);

        $classifications = DB::table('hpbrain_signals')->pluck('classification')->unique()->all();

        $this->assertSame(['UNDETERMINED'], array_values($classifications));
    }

    /** @test */
    public function commit_refuses_an_incomplete_field_map(): void
    {
        $service = app(IngestionService::class);
        $batch = $this->batch();
        $job = $service->preview($batch, self::ACTOR)['job'];

        $this->expectException(\InvalidArgumentException::class);

        // No 'title' binding: there would be nothing to name the signal, and
        // defaulting it would invent an observation nobody made.
        $service->commit($job['id'], $batch, ['owner' => 'Assigned To'], self::ACTOR);
    }

    /** @test */
    public function every_signal_carries_provenance_back_to_its_source_row(): void
    {
        $service = app(IngestionService::class);
        $batch = $this->batch();
        $job = $service->preview($batch, self::ACTOR)['job'];
        $service->commit($job['id'], $batch, $this->map(), self::ACTOR);

        $metadata = json_decode(
            (string) DB::table('hpbrain_signals')->orderBy('created_date')->value('metadata'),
            true,
        );

        $this->assertSame('internal-upload-test', $metadata['provenance']['sourceKey']);
        $this->assertSame($job['id'], $metadata['provenance']['importJobId']);
        $this->assertIsInt($metadata['provenance']['rowNumber']);
    }

    /** @test */
    public function rollback_data_is_populated_so_the_existing_rollback_route_works(): void
    {
        $service = app(IngestionService::class);
        $batch = $this->batch();
        $job = $service->preview($batch, self::ACTOR)['job'];
        $service->commit($job['id'], $batch, $this->map(), self::ACTOR);

        $stored = json_decode(
            (string) DB::table('hpbrain_import_jobs')->where('id', $job['id'])->value('rollback_data'),
            true,
        );

        // ImportEngine::rollbackImport() deletes from "hpbrain_{$entityType}",
        // so these keys must be exactly 'signals' and 'evidence'.
        $this->assertCount(3, $stored['created_ids']['signals']);
        $this->assertCount(1, $stored['created_ids']['evidence']);
    }

    /** @test */
    public function a_source_checkpoint_is_never_cleared_by_a_null(): void
    {
        $sources = app(DataSourceRepository::class);
        $sources->create(self::TENANT, [
            'source_key'  => 'erp-person',
            'source_type' => 'internal_erp',
            'display_name'=> 'ERP People',
            'checkpoint'  => '1200',
            'created_by'  => self::ACTOR,
        ]);

        // A CSV run produces no watermark. Left unguarded this would reset the
        // sync to the beginning of time and re-ingest the entire ERP.
        $sources->advanceCheckpoint(self::TENANT, 'erp-person', null);

        $this->assertSame('1200', $sources->findByKey(self::TENANT, 'erp-person')['checkpoint']);
    }
}
