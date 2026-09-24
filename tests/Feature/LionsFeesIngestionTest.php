<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\IngestionBatch;
use App\Domain\Ingestion\IngestionService;
use App\Domain\Ingestion\Sources\CsvUploadSource;
use App\Domain\Signals\OperationalSignalWriter;
use App\Domain\Signals\SignalRuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

final class LionsFeesIngestionTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = '8';
    private const SOURCE = 'lions-fees-data';
    private const CSV = __DIR__.'/../../storage/app/ingestion/8/eec0f90fa9a82b3c4676eed540bbebb8.csv';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();
        $this->buildLionsOrganizationMapping();
        $this->insertDatasetSource();
    }

    public function test_field_map_accepts_and_rejects_new_canonical_targets(): void
    {
        $map = FieldMap::fromConfig([
            'measure' => 'Amount',
            'measure_unit' => 'Currency',
            'subject_ref' => 'GR NO.',
            'category' => 'Student Quota',
            'not_canonical' => 'Ignored',
            'owner' => '',
        ]);

        // sample() rather than ->rows: delimited uploads are stream-backed now
        // so a file larger than memory can be imported, and a stream-backed
        // batch leaves ->rows empty by design. The row being asserted on is
        // the same one.
        $row = $this->batch()->sample(2)[1];

        self::assertSame('48550', $map->value($row, 'measure'));
        self::assertSame('10818', $map->value($row, 'subject_ref'));
        self::assertSame('General', $map->value($row, 'category'));
        self::assertFalse($map->has('not_canonical'));
        self::assertFalse($map->has('owner'));
    }

    public function test_real_lions_csv_rows_commit_to_operational_records(): void
    {
        $result = $this->importRealCsv();

        self::assertSame(10430, $result['success']);
        self::assertSame(1, $result['skipped']);

        $first = DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)
            ->where('dataset', 'school_fee')
            ->where('natural_key', '1')
            ->first();

        self::assertNotNull($first);
        self::assertSame('10818', $first->subject_ref);
        self::assertSame('48550', (string) (int) $first->metric_value);
        self::assertSame('INR', $first->metric_unit);
        self::assertSame('2025-09-10 00:00:00', $first->occurred_at);

        $payload = json_decode((string) $first->payload, true);
        self::assertSame('AYUSH KUMAR MANDAL', $payload['Student Name']);
        self::assertSame('IX', $payload['Standard']);
    }

    public function test_fee_missing_collector_rule_fires_above_and_is_silent_below_real_threshold(): void
    {
        $this->importRealCsv();

        config(['brain.operational_signals.fee_missing_collector_share' => 0.30]);
        config(['brain.operational_signals.fee_zero_amount_minimum' => 999]);
        config(['brain.operational_signals.fee_collector_concentration_share' => 0.99]);

        $this->runOperationalRules();

        self::assertSame(1, $this->signalCount('fee_missing_collector'));
        $this->assertFeeSignalHasScopeAndSourceRows('fee_missing_collector');

        $this->clearSignals();

        config(['brain.operational_signals.fee_missing_collector_share' => 0.99]);
        $this->runOperationalRules();

        self::assertSame(0, $this->signalCount('fee_missing_collector'));
    }

    public function test_fee_zero_amount_rule_fires_above_and_is_silent_below_real_threshold(): void
    {
        $this->importRealCsv();

        config(['brain.operational_signals.fee_missing_collector_share' => 0.99]);
        config(['brain.operational_signals.fee_zero_amount_minimum' => 20]);
        config(['brain.operational_signals.fee_collector_concentration_share' => 0.99]);

        $this->runOperationalRules();

        self::assertSame(1, $this->signalCount('fee_zero_amount_concessions'));

        $this->clearSignals();

        config(['brain.operational_signals.fee_zero_amount_minimum' => 29]);
        $this->runOperationalRules();

        self::assertSame(0, $this->signalCount('fee_zero_amount_concessions'));
    }

    public function test_fee_collector_concentration_rule_fires_above_and_is_silent_below_real_threshold(): void
    {
        $this->importRealCsv();

        config(['brain.operational_signals.fee_missing_collector_share' => 0.99]);
        config(['brain.operational_signals.fee_zero_amount_minimum' => 999]);
        config(['brain.operational_signals.fee_collector_concentration_share' => 0.65]);

        $this->runOperationalRules();

        self::assertSame(1, $this->signalCount('fee_collector_concentration'));

        $this->clearSignals();

        config(['brain.operational_signals.fee_collector_concentration_share' => 0.99]);
        $this->runOperationalRules();

        self::assertSame(0, $this->signalCount('fee_collector_concentration'));
    }

    private function importRealCsv(): array
    {
        $service = app(IngestionService::class);
        $batch = $this->batch();
        $job = $service->preview($batch, 'test-actor')['job'];

        return $service->commit($job['id'], $batch, $this->fieldMap(), 'test-actor');
    }

    private function batch(): IngestionBatch
    {
        return (new CsvUploadSource(self::TENANT, realpath(self::CSV) ?: self::CSV, self::SOURCE))->fetch();
    }

    private function fieldMap(): array
    {
        return [
            'external_ref'       => 'Sr No.',
            'subject_ref'        => 'GR NO.',
            'title'              => 'Student Name',
            'state'              => 'Payment Mode',
            'owner'              => 'Collected By',
            'category'           => 'Student Quota',
            'measure'            => 'Amount',
            'evidence_timestamp' => 'Receipt Date',
        ];
    }

    private function insertDatasetSource(): void
    {
        DB::table('hpbrain_data_sources')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::TENANT,
            'source_key' => self::SOURCE,
            'display_name' => 'Lions Fees Data',
            'source_type' => 'dataset',
            'field_map' => json_encode($this->fieldMap()),
            'config' => json_encode(['dataset' => 'school_fee', 'measure_unit' => 'INR']),
            'is_active' => 1,
            'created_by' => 'test',
            'created_date' => '2026-08-12 00:00:00',
            'updated_date' => '2026-08-12 00:00:00',
        ]);
    }

    private function buildLionsOrganizationMapping(): void
    {
        Schema::create('institute_detail', function ($t) {
            $t->integer('sub_institute_id')->primary();
            $t->string('organization_name')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        DB::table('institute_detail')->insert([
            'sub_institute_id' => 8,
            'organization_name' => 'Lions',
            'deleted_at' => null,
        ]);

        foreach ([
            'id' => 'sub_institute_id',
            'tenantKey' => 'sub_institute_id',
            'name' => 'organization_name',
            'deletedAt' => 'deleted_at',
        ] as $field => $column) {
            DB::table('hpbrain_entity_mappings')->insert([
                'id' => Uuid::uuid4()->toString(),
                'tenant_id' => self::TENANT,
                'source_system' => 'erp',
                'source_entity' => 'institute_detail',
                'source_field' => $column,
                'universal_entity' => 'Organization',
                'universal_field' => $field,
                'mapping_type' => 'direct',
                'is_active' => 1,
                'created_by' => 'test',
                'created_date' => '2026-08-12 00:00:00',
            ]);
        }
    }

    private function runOperationalRules(): void
    {
        $writer = app(OperationalSignalWriter::class);

        foreach (app(SignalRuleRegistry::class)->extraRulesFor($writer, self::TENANT) as $rule) {
            $rule();
        }
    }

    private function signalCount(string $rule): int
    {
        return DB::table('hpbrain_signals')
            ->where('tenant_id', self::TENANT)
            ->where('rule_key', $rule)
            ->count();
    }

    private function assertFeeSignalHasScopeAndSourceRows(string $rule): void
    {
        $signal = DB::table('hpbrain_signals')
            ->where('tenant_id', self::TENANT)
            ->where('rule_key', $rule)
            ->first();

        self::assertSame('8', (string) $signal->org_id);

        $evidence = DB::table('hpbrain_evidence')
            ->where('tenant_id', self::TENANT)
            ->where('signal_id', $signal->id)
            ->get();

        self::assertNotEmpty($evidence);

        foreach ($evidence as $row) {
            $content = json_decode((string) $row->content, true);
            self::assertSame('import.school_fee', $content['source']);
            self::assertNotEmpty($content['recordId']);
            self::assertGreaterThan(0, $content['sourceRow']);
            self::assertNotEmpty($content['studentRef']);
            self::assertArrayHasKey('amount', $content);
        }
    }

    private function clearSignals(): void
    {
        DB::table('hpbrain_evidence')->where('tenant_id', self::TENANT)->delete();
        DB::table('hpbrain_event_store')->where('tenant_id', self::TENANT)->delete();
        DB::table('hpbrain_signals')->where('tenant_id', self::TENANT)->delete();
    }
}
