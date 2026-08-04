<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Events\EventPublisher;
use App\Domain\Signals\OperationalSignalWriter;
use App\Domain\Signals\SignalRuleRegistry;
use App\Services\Import\ImportConfigurationException;
use App\Services\Import\ImportProfile;
use App\Services\Import\ImportProfileRegistry;
use App\Services\Import\WorkbookImporter;
use App\Services\ImportEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The workbook import pipeline, end to end.
 *
 * Fixtures in tests/fixtures/imports/testco are small workbooks that reproduce
 * the exact SHAPES of the FiberValley exports — a blank spacer row above the
 * header, a pivot sheet whose columns are people, a roster with monthly
 * True/False flags, sentinel strings ('NO', '#VALUE!') in a numeric column, and
 * a duplicate submission that contradicts an earlier one. Testing against the
 * real 16 MB workbook would make the suite slow and would tie it to data that
 * changes every quarter; testing against these ties it to the shapes, which do
 * not.
 *
 * The organization is 'testco' rather than 'fibervalley' so the suite does not
 * depend on the production profile staying exactly as it is today.
 */
final class FiberValleyImportTest extends TestCase
{
    private const TENANT = '77';
    private const OTHER_TENANT = '6';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildErpTables();
        $this->buildBrainTables();

        config(['import_profiles.testco' => $this->profiles()]);
    }

    // ---------------------------------------------------------------- import

    public function test_it_imports_a_tabular_sheet_beneath_a_blank_spacer_row(): void
    {
        $result = $this->importProfile('tickets');

        // Four data rows are valid; the fifth has an empty Ticket, which is a
        // required column. The header row must NOT be counted as data.
        $this->assertSame(5, $result['counts']['read']);
        $this->assertSame(4, $result['counts']['created']);
        $this->assertSame(1, $result['counts']['error']);

        $keys = DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)->pluck('natural_key')->all();

        sort($keys);
        $this->assertSame(['T-001', 'T-002', 'T-003', 'T-004'], $keys);

        // The regression that motivated explicit generator control: the header
        // row arriving as a record whose ticket number is the word 'Ticket'.
        $this->assertNotContains('Ticket', $keys);
    }

    public function test_excel_date_serials_become_mysql_datetimes(): void
    {
        $this->importProfile('tickets');

        $row = $this->record('T-001');

        // Written by the fixture as a real Excel date, which is stored in the
        // file as the serial 45748.31... — not as text.
        $this->assertSame('2025-04-01 07:33:01', $row->occurred_at);
        $this->assertSame('2025-04-01 16:43:55', $row->closed_at);
    }

    public function test_unparseable_numerics_become_null_never_zero(): void
    {
        $this->importProfile('tickets');

        // 'NO' is a real sentinel in the FiberValley Hours column. Casting it
        // to 0 would silently improve every SLA figure it touches.
        $this->assertNull($this->record('T-003')->metric_value);

        // Compared numerically, not as a string: MySQL returns a DECIMAL column
        // as '9.1800' and SQLite as the float 9.18. Asserting the string would
        // pass on the suite's SQLite connection and fail against production.
        $this->assertEqualsWithDelta(9.18, (float) $this->record('T-001')->metric_value, 0.0001);
    }

    public function test_status_is_derived_from_the_presence_of_a_close_date(): void
    {
        $this->importProfile('tickets');

        $this->assertSame('closed', $this->record('T-001')->status);
        $this->assertSame('open', $this->record('T-003')->status);
    }

    public function test_unmapped_columns_are_preserved_in_the_payload(): void
    {
        $this->importProfile('tickets');

        $payload = json_decode((string) $this->record('T-001')->payload, true);

        $this->assertSame('sub-a', $payload['UserId'] ?? null);
    }

    // ----------------------------------------------------------- idempotency

    public function test_reimporting_the_same_workbook_creates_nothing_new(): void
    {
        $first = $this->importProfile('tickets');
        $second = $this->importProfile('tickets');

        $this->assertSame(4, $first['counts']['created']);
        $this->assertSame(0, $second['counts']['created']);
        $this->assertSame(4, $second['counts']['skipped']);

        $this->assertSame(4, DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)->count());
    }

    public function test_repeated_imports_converge_on_identical_state(): void
    {
        $this->importProfile('attendance');
        $afterFirst = $this->fingerprint();

        $this->importProfile('attendance');
        $afterSecond = $this->fingerprint();

        $this->importProfile('attendance');
        $afterThird = $this->fingerprint();

        // The fixture contains a duplicate submission for 2025-04-02 that
        // contradicts the earlier one. Whichever occurrence wins, it must win
        // on EVERY run — otherwise the table only settles after a second
        // import, and the first import's data is quietly different.
        $this->assertSame($afterFirst, $afterSecond);
        $this->assertSame($afterSecond, $afterThird);
    }

    public function test_the_last_occurrence_of_a_duplicate_key_wins(): void
    {
        $this->importProfile('attendance');

        // Row 3 of the fixture re-submits 2025-04-02 with Asha marked Present,
        // overriding the Week-off recorded in row 2.
        $this->assertSame('Present', $this->record('2025-04-02|Asha Patel')->status);
    }

    // ----------------------------------------------------------------- pivot

    public function test_matrix_sheets_are_unpivoted_into_one_record_per_person(): void
    {
        $result = $this->importProfile('attendance');

        $this->assertSame(3, $result['counts']['read']);

        $rows = DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)->where('dataset', 'attendance')
            ->orderBy('natural_key')->get();

        // 2025-04-01: Asha Present, Chirag Absent (Bhavin blank -> skipped)
        // 2025-04-02: Asha + Bhavin across two submissions
        $this->assertCount(4, $rows);
        $this->assertSame('Absent', $this->record('2025-04-01|Chirag Desai')->status);
    }

    public function test_a_blank_attendance_cell_is_not_recorded_as_an_absence(): void
    {
        $this->importProfile('attendance');

        // Bhavin has no entry on 2025-04-01. A blank means "no submission",
        // which the sheet distinguishes from an explicit 'Absent'. Inventing an
        // absence would put a false accusation against a named employee into
        // the intelligence layer.
        $this->assertNull(DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)
            ->where('natural_key', '2025-04-01|Bhavin Shah')->first());
    }

    public function test_context_columns_are_carried_not_treated_as_people(): void
    {
        $this->importProfile('attendance');

        $row = $this->record('2025-04-01|Asha Patel');

        $this->assertSame('Faiyaz Shaikh', $row->supervisor_name);

        // 'TL Name or Manager Name' and 'Month' are context, not entities.
        $owners = DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)->pluck('owner_name')->all();

        $this->assertNotContains('TL Name or Manager Name', $owners);
        $this->assertNotContains('Month', $owners);
    }

    // ------------------------------------------------------------ ERP roster

    public function test_the_roster_loads_into_the_erp_tables(): void
    {
        $this->importProfile('roster');

        $this->assertSame(3, DB::table('tbluser')->where('sub_institute_id', self::TENANT)->count());
        $this->assertSame(2, DB::table('hrms_departments')->where('sub_institute_id', self::TENANT)->count());

        $asha = DB::table('tbluser')->where('sub_institute_id', self::TENANT)
            ->where('first_name', 'Asha')->first();

        $this->assertSame('Patel', $asha->last_name);
        $this->assertSame(1, (int) $asha->status);
    }

    public function test_a_person_whose_last_month_flag_is_false_is_marked_inactive(): void
    {
        $this->importProfile('roster');

        $bhavin = DB::table('tbluser')->where('sub_institute_id', self::TENANT)
            ->where('first_name', 'Bhavin')->first();

        // Apr True, May False — the LAST flag is the current standing. Reading
        // the first, or OR-ing them, would keep a leaver on the active roll.
        $this->assertSame(0, (int) $bhavin->status);
    }

    public function test_roster_import_never_deletes_and_reimport_does_not_duplicate(): void
    {
        $this->importProfile('roster');
        $this->importProfile('roster');

        $this->assertSame(3, DB::table('tbluser')->where('sub_institute_id', self::TENANT)->count());
    }

    // ------------------------------------------------------------- isolation

    public function test_importing_leaves_other_tenants_untouched(): void
    {
        DB::table('tbluser')->insert([
            'first_name' => 'Existing', 'last_name' => 'Person',
            'sub_institute_id' => self::OTHER_TENANT, 'status' => 1,
            // Both NOT NULL in the ERP, so the fixture demands them too.
            'email' => 'existing.person@example.test', 'password' => '',
        ]);

        $before = [
            'people'  => DB::table('tbluser')->where('sub_institute_id', self::OTHER_TENANT)->count(),
            'records' => DB::table('hpbrain_operational_records')->where('tenant_id', self::OTHER_TENANT)->count(),
            'signals' => DB::table('hpbrain_signals')->where('tenant_id', self::OTHER_TENANT)->count(),
        ];

        $this->importProfile('tickets');
        $this->importProfile('roster');

        $this->assertSame($before['people'], DB::table('tbluser')->where('sub_institute_id', self::OTHER_TENANT)->count());
        $this->assertSame($before['records'], DB::table('hpbrain_operational_records')->where('tenant_id', self::OTHER_TENANT)->count());
        $this->assertSame($before['signals'], DB::table('hpbrain_signals')->where('tenant_id', self::OTHER_TENANT)->count());
    }

    // -------------------------------------------------------- config safety

    public function test_a_profile_naming_a_missing_column_fails_before_writing_anything(): void
    {
        try {
            $this->importProfile('broken');
            $this->fail('Expected ImportConfigurationException for a renamed column.');
        } catch (ImportConfigurationException $e) {
            $this->assertStringContainsString('Ticket', $e->getMessage());
        }

        // The point of failing early: nothing may be half-imported under a
        // mapping that silently nulls a column.
        $this->assertSame(0, DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)->count());
    }

    public function test_a_profile_without_a_natural_key_is_rejected(): void
    {
        $this->expectException(ImportConfigurationException::class);

        ImportProfile::fromConfig('testco', 'keyless', [
            'file' => 'x.xlsx', 'sheet' => 'Data', 'loader' => 'operational',
        ]);
    }

    // ---------------------------------------------------------- intelligence

    public function test_imported_data_produces_signals_with_linked_evidence(): void
    {
        $this->importProfile('tickets');

        // Thresholds lowered so the four-row fixture trips the rules the real
        // 65k-row dataset trips.
        config([
            'brain.operational_signals.complaint_sla_minimum' => 1,
            'brain.operational_signals.root_cause_blank_share' => 0.1,
            'brain.operational_signals.repeat_complaint_threshold' => 2,
            'brain.operational_signals.repeat_complaint_minimum' => 1,
        ]);

        // The dataset name the rules key off.
        DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)->update(['dataset' => 'complaint']);

        // The operational rules are attached by the datasets the tenant holds
        // and write through OperationalSignalWriter. RuleEvaluator is not
        // exercised here — it evaluates the rules held as rows, which is a
        // separate family with its own tests.
        $writer = new OperationalSignalWriter(app(EventPublisher::class));
        $created = 0;

        foreach (app(SignalRuleRegistry::class)->extraRulesFor($writer, self::TENANT) as $rule) {
            if ($rule()['created'] ?? false) {
                $created++;
            }
        }

        $this->assertGreaterThan(0, $created);

        $signals = DB::table('hpbrain_signals')->where('tenant_id', self::TENANT)->get();
        $rules = $signals->map(fn ($s) => json_decode((string) $s->metadata, true)['rule'] ?? null)->all();

        $this->assertContains('complaint_sla_breach', $rules);

        // Every signal an operational rule raises must cite the specific
        // records behind it, with the evidence linked to the signal — not left
        // orphaned.
        $sla = $signals->first(fn ($s) => (json_decode((string) $s->metadata, true)['rule'] ?? '') === 'complaint_sla_breach');

        $this->assertGreaterThan(0, DB::table('hpbrain_evidence')
            ->where('tenant_id', self::TENANT)->where('signal_id', $sla->id)->count());
    }

    public function test_a_tenant_with_no_imported_data_gets_exactly_the_existing_erp_rules(): void
    {
        // No import at all. The registry must contribute nothing, and signal
        // generation must behave precisely as it did before this change.
        DB::table('hrms_departments')->insert([
            'sub_institute_id' => self::OTHER_TENANT, 'department' => 'Ops',
            'parent_id' => 0, 'status' => 1, 'is_calculated' => 0,
        ]);

        $writer = new OperationalSignalWriter(app(EventPublisher::class));
        $extra = app(SignalRuleRegistry::class)->extraRulesFor($writer, self::OTHER_TENANT);

        // The registry contributes NOTHING for a tenant that has imported no
        // operational data. Whatever rules that tenant gets, it gets from
        // hpbrain_signal_rules exactly as it did before this change — this
        // assertion is the guarantee that the import feature is invisible to
        // organizations that do not use it.
        $this->assertSame([], $extra);

        foreach ($extra as $rule) {
            $rule();
        }

        $rules = DB::table('hpbrain_signals')->where('tenant_id', self::OTHER_TENANT)->get()
            ->map(fn ($s) => json_decode((string) $s->metadata, true)['rule'] ?? null)->all();

        foreach ($rules as $rule) {
            $this->assertStringStartsNotWith('complaint_', (string) $rule);
            $this->assertStringStartsNotWith('work_order_', (string) $rule);
        }
    }

    // ------------------------------------------------------------- rollback

    public function test_rollback_removes_only_the_records_of_that_job(): void
    {
        $ticketRun = $this->importProfile('tickets');
        $this->importProfile('attendance');

        $before = DB::table('hpbrain_operational_records')->where('tenant_id', self::TENANT)->count();

        $ok = app(ImportEngine::class)->rollbackImport($ticketRun['job_id'], self::TENANT);

        $this->assertTrue($ok);
        $this->assertSame(0, DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)->where('dataset', 'ticket')->count());

        // The attendance records imported by the other job survive.
        $this->assertSame($before - 4, DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)->count());

        $this->assertSame('rolled_back', DB::table('hpbrain_import_jobs')
            ->where('id', $ticketRun['job_id'])->value('status'));
    }

    public function test_rollback_never_deletes_erp_master_data(): void
    {
        $run = $this->importProfile('roster');

        app(ImportEngine::class)->rollbackImport($run['job_id'], self::TENANT);

        // Staff are ERP master data referenced by other rows and cited as
        // evidence. Undoing a spreadsheet load must not be able to erase them.
        $this->assertSame(3, DB::table('tbluser')->where('sub_institute_id', self::TENANT)->count());
    }

    // -------------------------------------------------------- job/log wiring

    public function test_an_import_is_visible_through_the_existing_import_job_api(): void
    {
        $run = $this->importProfile('tickets');

        $job = DB::table('hpbrain_import_jobs')->where('id', $run['job_id'])->first();

        $this->assertSame(self::TENANT, $job->tenant_id);
        $this->assertSame('xlsx', $job->import_type);
        $this->assertSame('completed_with_errors', $job->status);
        $this->assertSame(4, (int) $job->success_count);
        $this->assertSame(1, (int) $job->error_count);
    }

    public function test_profiles_are_published_into_the_existing_entity_mapping_table(): void
    {
        $this->importProfile('tickets');
        $this->importProfile('tickets');

        $mappings = DB::table('hpbrain_entity_mappings')
            ->where('tenant_id', self::TENANT)->where('source_system', 'testco')->get();

        // Refreshed rather than accumulated: a second import of the same
        // profile must not double the rows.
        $byField = $mappings->pluck('source_field', 'universal_field')->all();
        $this->assertCount(count($byField), $mappings);

        // WITHOUT THESE TWO THE ENTITY IS UNUSABLE. EntityResolver requires an
        // 'id' and a 'tenantKey' on every mapped entity and throws while
        // building the map for the WHOLE tenant if either is missing — so a
        // half-declared entity here breaks Person, Organization and every
        // other mapping too. This assertion is the one that was missing.
        $this->assertArrayHasKey('id', $byField);
        $this->assertArrayHasKey('tenantKey', $byField);
        $this->assertSame('tenant_id', $byField['tenantKey']);

        // source_entity is read by the resolver AS THE TABLE NAME, so it must
        // be the table, never the profile key.
        $this->assertSame(
            ['hpbrain_operational_records'],
            $mappings->pluck('source_entity')->unique()->values()->all()
        );

        // The claim behind the two assertions above, stated directly: the
        // published mapping actually resolves.
        $source = app(\App\Domain\Universal\EntityResolver::class)
            ->resolve(self::TENANT, 'OperationalRecord:ticket');

        $this->assertSame('hpbrain_operational_records', $source->table);
        $this->assertSame('tenant_id', $source->tenantKey);
        $this->assertSame('id', $source->primaryKey);
    }

    public function test_the_roster_publishes_no_person_mapping_of_its_own(): void
    {
        $this->importProfile('roster');

        // Person is already mapped to tbluser. A second source_entity for the
        // same universal entity is AMBIGUOUS, which makes it unresolvable —
        // and the roster needs no mapping of its own, because it writes into
        // the very tables the existing mapping describes.
        $this->assertSame(0, DB::table('hpbrain_entity_mappings')
            ->where('tenant_id', self::TENANT)
            ->where('source_system', 'testco')
            ->where('universal_entity', 'Person')
            ->count());
    }

    // ------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function importProfile(string $key): array
    {
        $profile = app(ImportProfileRegistry::class)->find('testco', $key);

        return app(WorkbookImporter::class)->import(self::TENANT, $profile, [
            'actor'          => 'phpunit',
            'base_directory' => base_path('tests/fixtures/imports/testco'),
        ]);
    }

    private function record(string $naturalKey): object
    {
        $row = DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)->where('natural_key', $naturalKey)->first();

        $this->assertNotNull($row, "No operational record with natural_key '{$naturalKey}'.");

        return $row;
    }

    private function fingerprint(): string
    {
        return md5(DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)
            ->orderBy('dataset')->orderBy('natural_key')
            ->pluck('row_hash')->implode(','));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function profiles(): array
    {
        return [
            'tickets' => [
                'file' => 'Tickets*.xlsx', 'sheet' => 'Data', 'header_row' => 2,
                'shape' => 'tabular', 'loader' => 'operational', 'dataset' => 'ticket',
                'key' => ['Ticket'], 'required' => ['Ticket', 'GenerationDate'],
                'map' => [
                    'occurred_at' => 'GenerationDate', 'closed_at' => 'CloseDate',
                    'owner_name' => 'Engineer Name', 'zone' => 'zone',
                    'subject_ref' => 'UserId', 'metric_value' => 'Hours',
                ],
                'casts' => [
                    'occurred_at' => 'datetime', 'closed_at' => 'datetime', 'metric_value' => 'decimal',
                ],
                'constants' => ['metric_unit' => 'hours'],
                'derive' => ['status' => 'closed_if_present:CloseDate'],
                'payload' => ['UserId'],
            ],
            'attendance' => [
                'file' => 'Staff Attendance*.xlsx', 'sheet' => 'Attendance', 'header_row' => 2,
                'shape' => 'matrix', 'loader' => 'operational', 'dataset' => 'attendance',
                'matrix' => [
                    'row_key' => 'Date', 'row_key_cast' => 'datetime',
                    'entity_to' => 'owner_name', 'value_to' => 'status', 'skip_blank' => true,
                    'carry' => ['supervisor_name' => 'TL Name or Manager Name'],
                    'ignore' => ['Month'],
                ],
            ],
            'roster' => [
                'file' => 'Staff Attendance*.xlsx', 'sheet' => 'Left ot Join', 'header_row' => 3,
                'shape' => 'tabular', 'loader' => 'erp_roster',
                'key' => ['Employee Name'], 'required' => ['Employee Name'],
                'map' => [
                    'full_name' => 'Employee Name', 'department' => 'Department', 'manager' => 'TL/Manager',
                ],
                'active_flags' => ['Apr-2025', 'May-2025'],
            ],
            'broken' => [
                'file' => 'Broken*.xlsx', 'sheet' => 'Data', 'header_row' => 2,
                'shape' => 'tabular', 'loader' => 'operational', 'dataset' => 'ticket',
                'key' => ['Ticket'], 'required' => ['Ticket'],
                'map' => ['occurred_at' => 'GenerationDate'],
                'casts' => ['occurred_at' => 'datetime'],
            ],
        ];
    }

    private function buildErpTables(): void
    {
        Schema::create('institute_detail', function ($t) {
            $t->increments('id');
            $t->string('sub_institute_id');
            $t->string('organization_name')->nullable();
            $t->string('organization_code')->nullable();
            $t->string('industry_type')->nullable();
            // bigint FK to tbluser.id in the ERP. As a string here, the fixture
            // accepted the seeder's 'seed:fibervalley' marker that MySQL
            // rejected with 22007.
            $t->integer('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        // MIRRORS THE ERP, INCLUDING THE AWKWARD PARTS. This fixture used to
        // declare is_calculated not at all and created_by as a string, so the
        // roster loader passed against SQLite and failed against MySQL with
        // 1364 and 22007 respectively — 96 rows, every one of them an error.
        // A fixture that is more permissive than production tests nothing.
        Schema::create('hrms_departments', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('department');
            $t->text('roles_responsibility')->nullable();
            $t->integer('parent_id')->default(0);
            $t->integer('status')->default(1);
            // NOT NULL with NO default, exactly as the ERP declares it.
            $t->integer('is_calculated');
            // bigint FK to tbluser.id in the ERP, not a label.
            $t->integer('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('tbluserprofilemaster', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('name');
            $t->integer('status')->default(1);
        });

        Schema::create('tbluser', function ($t) {
            $t->increments('id');
            $t->string('employee_no')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            // NOT NULL *and* UNIQUE in the ERP. Declared nullable here, this
            // fixture accepted the empty string the loader used to write and
            // production rejected the second one with 1062 — so the blank-email
            // decision could never have been tested where it actually breaks.
            $t->string('email');
            $t->unique('email', 'tbluser_email_unique');
            // NOT NULL with no default in the ERP.
            $t->string('password');
            $t->integer('department_id')->nullable();
            $t->integer('user_profile_id')->nullable();
            $t->integer('sub_institute_id');
            $t->integer('status')->default(1);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        DB::table('institute_detail')->insert([
            'sub_institute_id' => self::TENANT, 'organization_name' => 'TestCo',
            'organization_code' => 'TESTCO', 'created_by' => null,
        ]);

        DB::table('tbluserprofilemaster')->insert([
            'sub_institute_id' => (int) self::TENANT, 'name' => 'Employee', 'status' => 1,
        ]);
    }

    private function buildBrainTables(): void
    {
        $builder = new class {
            use \Tests\Support\BuildsBrainSchema;

            public function build(): void
            {
                $this->buildBrainSchema();
            }
        };

        $builder->build();
    }
}
