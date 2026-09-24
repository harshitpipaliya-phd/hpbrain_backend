<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GET /people/{tenantId}/{id}/twin — the person profile.
 *
 * The fixture is the shape the school tenant actually has: students in the ERP's
 * person table, one class-section organizational unit each, and imported fee
 * records that name the student by the same reference the ERP holds. That shape
 * is what the screen is for, and the properties asserted below are the ones the
 * screen depends on being true.
 *
 * The tests that matter most are the negative ones. Two tenants deliberately use
 * the SAME student reference and the SAME student name, because reference and
 * name are how records are attached to a person — if the tenant filter were ever
 * dropped from one of those reads, this fixture is what catches it.
 */
final class PersonProfileTest extends TestCase
{
    use \Tests\Support\BuildsBrainSchema;
    use \Tests\Support\SeedsEntityMappings;

    private const TENANT = '900';

    private const OTHER_TENANT = '901';

    /** The student every assertion is about. */
    private const STUDENT = 11;

    /** Same reference, same name, different tenant. */
    private const OTHER_STUDENT = 21;

    /** A person in the same tenant that nothing references. */
    private const UNTOUCHED = 12;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();
        $this->buildErpTables();
        $this->installEntityMappings([self::TENANT, self::OTHER_TENANT]);
        $this->seedErp();
        $this->seedRecords();
    }

    // ---- Identity ----------------------------------------------------------

    public function test_it_returns_the_person_as_the_tenants_own_source_holds_them(): void
    {
        $person = $this->profile()->json('person');

        $this->assertSame('Aanya Sharma', $person['displayName']);
        $this->assertSame('STU-0001', $person['externalRef']);
        $this->assertSame('aanya.guardian@school.test', $person['email']);
        // Resolved through the mapping, not read from a hardcoded table name.
        $this->assertSame('Student', $person['role']);
        $this->assertSame('Grade 9 B', $person['departmentName']);
    }

    public function test_the_organization_is_named_from_the_tenants_organization_source(): void
    {
        $this->assertSame(
            ['id' => self::TENANT, 'name' => 'Northgate School', 'code' => 'NGS', 'industry' => 'Education'],
            $this->profile()->json('organization')
        );
    }

    /**
     * The regression this pins: the endpoint used to override the mapped name and
     * email with `$row['first_name']`, `['last_name']` and `['email']` read
     * literally, so a source that names those columns anything else answered with
     * empty strings. The fixture's person table uses the standard column names,
     * so the guard is that the values are non-empty AND equal to the mapped ones.
     */
    public function test_name_and_email_come_from_the_mapped_columns(): void
    {
        $person = $this->profile()->json('person');

        $this->assertSame('Aanya', $person['firstName']);
        $this->assertSame('Sharma', $person['lastName']);
        $this->assertNotSame('', $person['email']);
    }

    // ---- Attachment --------------------------------------------------------

    public function test_records_are_attached_by_the_reference_stored_on_them(): void
    {
        $linkage = $this->profile()->json('linkage');

        $bySubject = collect($linkage['rules'])->firstWhere('column', 'subject_ref');

        $this->assertSame('STU-0001', $bySubject['value']);
        $this->assertSame(3, $bySubject['records']);
        $this->assertSame(3, $linkage['records']);
    }

    public function test_records_naming_the_person_as_owner_are_attached_and_labelled_separately(): void
    {
        $profile = $this->profile(self::UNTOUCHED);

        $byOwner = collect($profile->json('linkage.rules'))->firstWhere('column', 'owner_name');

        $this->assertSame(1, $byOwner['records']);
        $this->assertSame(['Handled by'], $profile->json('activity.records.0.linkedBy'));
    }

    /**
     * THE ISOLATION TEST. The other tenant's student carries the same reference
     * and the same name, and owns fee records of their own. None of them may
     * appear here, and none of their money may reach these totals.
     */
    public function test_an_identical_reference_in_another_tenant_is_not_attached(): void
    {
        $profile = $this->profile();

        $this->assertSame(3, $profile->json('linkage.records'));
        $this->assertSame(3, $profile->json('activity.total'));

        foreach ($profile->json('activity.records') as $record) {
            $this->assertStringStartsWith('NGS-', (string) $record['reference']);
        }

        // 12000 + 12000 + 6000 for this tenant. The other tenant's student has an
        // invoice for 999999, which would be impossible to miss in this figure.
        //
        // assertEquals, not assertSame: a whole float survives json_encode as
        // `30000` and decodes to an int, so identity would be asserting a
        // property of JSON rather than of the total.
        $this->assertEquals(30000, $profile->json('finance.net'));
    }

    public function test_a_person_from_another_tenant_cannot_be_read(): void
    {
        $this->getJson('/api/v1/people/'.self::TENANT.'/'.self::OTHER_STUDENT.'/twin', $this->auth())
            ->assertStatus(404)
            ->assertJson(['error' => 'person_not_found']);
    }

    public function test_the_route_tenant_cannot_be_switched_to_another_organization(): void
    {
        $this->getJson('/api/v1/people/'.self::OTHER_TENANT.'/'.self::OTHER_STUDENT.'/twin', $this->auth())
            ->assertStatus(403);
    }

    // ---- Money -------------------------------------------------------------

    public function test_fee_totals_are_summed_from_the_stored_invoice_payloads(): void
    {
        $finance = $this->profile()->json('finance');

        $this->assertEquals(33000, $finance['billed']);
        $this->assertEquals(3000, $finance['concession']);
        $this->assertEquals(30000, $finance['net']);
        $this->assertEquals(24000, $finance['paid']);
        $this->assertEquals(6000, $finance['outstanding']);
        $this->assertEquals(80, $finance['collectedPct']);
        $this->assertSame('INR', $finance['currency']);
        $this->assertFalse($finance['partial']);
        $this->assertCount(3, $finance['invoices']);
    }

    public function test_only_outstanding_that_is_past_its_due_date_counts_as_overdue(): void
    {
        // One of the three invoices is unpaid, and only it carries days_overdue.
        $this->assertEquals(6000, $this->profile()->json('finance.overdue'));
    }

    public function test_the_last_payment_is_the_most_recent_one_that_moved_money(): void
    {
        $this->assertEquals(
            ['date' => '2026-02-12', 'amount' => 12000, 'method' => 'UPI'],
            $this->profile()->json('finance.lastPayment')
        );
    }

    public function test_the_class_and_section_come_from_the_most_recent_record(): void
    {
        $academic = $this->profile()->json('academic');

        $this->assertSame('9', $academic['class']);
        $this->assertSame('B', $academic['section']);
        $this->assertSame('2025-26', $academic['academicYear']);
        $this->assertSame(1, $academic['classesOnRecord']);
    }

    public function test_the_guardian_on_the_imported_record_is_offered_when_the_register_is_empty(): void
    {
        $guardians = $this->profile()->json('contacts.guardians');

        $this->assertCount(1, $guardians);
        $this->assertSame('Ravi Sharma', $guardians[0]['firstName']);
        // Labelled with where it came from, so a reader is never told the
        // guardian register holds a row it does not hold.
        $this->assertSame('fee_record', $guardians[0]['origin']);
    }

    public function test_the_guardian_register_wins_when_it_has_a_row(): void
    {
        DB::table('hpbrain_guardians')->insert([
            'id' => 'g-1', 'tenant_id' => self::TENANT, 'student_person_id' => (string) self::STUDENT,
            'first_name' => 'Meera', 'last_name' => 'Sharma', 'relationship' => 'Mother',
            'email' => 'meera@school.test', 'phone' => '900', 'is_primary_contact' => true,
            'created_by' => 'test',
        ]);

        $guardians = $this->profile()->json('contacts.guardians');

        $this->assertCount(1, $guardians);
        $this->assertSame('Meera', $guardians[0]['firstName']);
        $this->assertSame('guardian_register', $guardians[0]['origin']);
    }

    // ---- Absence is absence, not zero --------------------------------------

    /**
     * The rule the whole screen rests on. A person nothing has been recorded
     * about must come back with NULL sections, not empty ones — "no fee record
     * exists" and "the fees are zero" are different facts, and only one of them
     * is true here.
     */
    public function test_a_person_with_no_fee_records_gets_null_rather_than_zeroed_totals(): void
    {
        $profile = $this->profile(self::UNTOUCHED);

        $this->assertNull($profile->json('finance'));
        $this->assertNull($profile->json('academic'));
    }

    public function test_an_unassessed_person_scores_null_rather_than_zero(): void
    {
        $score = $this->profile()->json('intelligence.score');

        $this->assertNull($score['score']);
        $this->assertNull($score['breakdown']['capabilityScore']);
        $this->assertNull($score['breakdown']['decisionQuality']);
        $this->assertNull($score['breakdown']['executionSuccess']);
    }

    // ---- Signals -----------------------------------------------------------

    public function test_only_signals_naming_this_person_as_their_subject_are_returned(): void
    {
        DB::table('hpbrain_signals')->insert([
            [
                'id' => 's-mine', 'tenant_id' => self::TENANT, 'source' => 'erp.data_quality',
                'classification' => 'workforce', 'rule_key' => 'people_without_department',
                'priority' => 'medium', 'severity' => 'medium', 'confidence' => 1,
                'related_entity_type' => 'Person', 'related_entity_id' => (string) self::STUDENT,
                'status' => 'new', 'metadata' => '{"title":"Missing department"}',
                'created_by' => 'system', 'created_date' => '2026-03-01 09:00:00',
            ],
            // Same person id, other tenant.
            [
                'id' => 's-other-tenant', 'tenant_id' => self::OTHER_TENANT, 'source' => 'erp.data_quality',
                'classification' => 'workforce', 'rule_key' => 'people_without_department',
                'priority' => 'medium', 'severity' => 'medium', 'confidence' => 1,
                'related_entity_type' => 'Person', 'related_entity_id' => (string) self::STUDENT,
                'status' => 'new', 'metadata' => null,
                'created_by' => 'system', 'created_date' => '2026-03-01 09:00:00',
            ],
            // An organization-scoped aggregate names no person, so it must not
            // appear on anyone's page.
            [
                'id' => 's-org', 'tenant_id' => self::TENANT, 'source' => 'erp.data_quality',
                'classification' => 'workforce', 'rule_key' => 'departments_without_head',
                'priority' => 'medium', 'severity' => 'medium', 'confidence' => 1,
                'related_entity_type' => null, 'related_entity_id' => null,
                'status' => 'new', 'metadata' => null,
                'created_by' => 'system', 'created_date' => '2026-03-01 09:00:00',
            ],
        ]);

        $intelligence = $this->profile()->json('intelligence');

        $this->assertSame(1, $intelligence['signalCount']);
        $this->assertSame('s-mine', $intelligence['signals'][0]['id']);
        $this->assertSame('Missing department', $intelligence['signals'][0]['title']);
    }

    // ---- Timeline ----------------------------------------------------------

    public function test_the_timeline_is_built_from_real_dated_rows_newest_first(): void
    {
        $timeline = $this->profile()->json('timeline');

        $dates = array_column($timeline['events'], 'at');
        $sorted = $dates;
        rsort($sorted);

        $this->assertSame($sorted, $dates);
        $this->assertContains('Person record created', array_column($timeline['events'], 'title'));
        // Three invoices plus the created/updated pair on the person row.
        $this->assertSame(5, $timeline['total']);
    }

    // ---- Compatibility -----------------------------------------------------

    /**
     * The keys the shipped SPA reads. They are projections of the same service
     * the new keys come from; this asserts they did not fall off in the rewrite.
     */
    public function test_the_legacy_response_keys_are_still_present(): void
    {
        $this->profile()->assertJsonStructure([
            'capabilityCount', 'capabilityScores',
            'decisionParticipation' => ['total', 'approved'],
            'learningContributions', 'recentActivity', 'guardians', 'executionHistory',
            'individualScore' => ['score', 'breakdown'],
        ]);
    }

    // ---- Fixture -----------------------------------------------------------

    private function profile(int $personId = self::STUDENT): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/people/'.self::TENANT.'/'.$personId.'/twin', $this->auth())
            ->assertStatus(200);
    }

    /** @return array<string, string> */
    private function auth(string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    private function buildErpTables(): void
    {
        Schema::create('institute_detail', function ($t) {
            $t->string('sub_institute_id');
            $t->string('organization_name')->nullable();
            $t->string('organization_code')->nullable();
            $t->string('industry_type')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('org_details', function ($t) {
            $t->integer('id')->primary();
            $t->string('sub_institute_id');
            $t->string('legal_name')->nullable();
            $t->string('logo')->nullable();
        });

        Schema::create('hrms_departments', function ($t) {
            $t->integer('id')->primary();
            $t->string('sub_institute_id');
            $t->string('department');
            $t->string('roles_responsibility')->nullable();
            $t->integer('parent_id')->default(0);
            $t->integer('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('hrms_job_titles', function ($t) {
            $t->integer('id')->primary();
            $t->string('sub_institute_id');
            $t->string('title');
            $t->integer('is_active')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('tbluserprofilemaster', function ($t) {
            $t->integer('id')->primary();
            $t->string('sub_institute_id');
            $t->string('name');
            $t->integer('status')->default(1);
        });

        Schema::create('tbluser', function ($t) {
            $t->integer('id')->primary();
            $t->string('employee_no')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('mobile')->nullable();
            $t->string('gender')->nullable();
            $t->integer('department_id')->nullable();
            $t->integer('jobtitle_id')->nullable();
            $t->string('joined_date')->nullable();
            $t->string('sub_institute_id');
            $t->integer('user_profile_id')->nullable();
            $t->integer('status')->default(1);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
    }

    private function seedErp(): void
    {
        DB::table('institute_detail')->insert([
            ['sub_institute_id' => self::TENANT, 'organization_name' => 'Northgate School', 'organization_code' => 'NGS', 'industry_type' => 'Education'],
            ['sub_institute_id' => self::OTHER_TENANT, 'organization_name' => 'Southgate School', 'organization_code' => 'SGS', 'industry_type' => 'Education'],
        ]);

        DB::table('hrms_departments')->insert([
            ['id' => 501, 'sub_institute_id' => self::TENANT, 'department' => 'Grade 9 B'],
            ['id' => 502, 'sub_institute_id' => self::OTHER_TENANT, 'department' => 'Grade 9 B'],
        ]);

        DB::table('tbluserprofilemaster')->insert([
            ['id' => 61, 'sub_institute_id' => self::TENANT, 'name' => 'Student'],
            ['id' => 62, 'sub_institute_id' => self::OTHER_TENANT, 'name' => 'Student'],
        ]);

        DB::table('tbluser')->insert([
            [
                'id' => self::STUDENT, 'employee_no' => 'STU-0001',
                'first_name' => 'Aanya', 'last_name' => 'Sharma',
                'email' => 'aanya.guardian@school.test', 'mobile' => '9000000001',
                'department_id' => 501, 'sub_institute_id' => self::TENANT, 'user_profile_id' => 61,
                'status' => 1, 'created_at' => '2025-06-01 08:00:00', 'updated_at' => '2025-06-02 08:00:00',
            ],
            [
                'id' => self::UNTOUCHED, 'employee_no' => 'STU-0002',
                'first_name' => 'Kiran', 'last_name' => 'Rao',
                'email' => 'kiran.guardian@school.test', 'mobile' => '9000000002',
                'department_id' => 501, 'sub_institute_id' => self::TENANT, 'user_profile_id' => 61,
                'status' => 1, 'created_at' => '2025-06-01 08:00:00', 'updated_at' => '2025-06-01 08:00:00',
            ],
            // Deliberately the same reference and the same name, in the other
            // tenant. Everything this endpoint reads is keyed on one or the other.
            [
                'id' => self::OTHER_STUDENT, 'employee_no' => 'STU-0001',
                'first_name' => 'Aanya', 'last_name' => 'Sharma',
                'email' => 'aanya@southgate.test', 'mobile' => '9010000001',
                'department_id' => 502, 'sub_institute_id' => self::OTHER_TENANT, 'user_profile_id' => 62,
                'status' => 1, 'created_at' => '2025-06-01 08:00:00', 'updated_at' => '2025-06-01 08:00:00',
            ],
        ]);
    }

    private function seedRecords(): void
    {
        $invoice = static function (array $over): string {
            return json_encode(array_merge([
                'student_id' => 'STU-0001', 'student_name' => 'Aanya Sharma',
                'admission_no' => 'ADM-9001', 'class' => '9', 'section' => 'B',
                'academic_year' => '2025-26', 'fee_component' => 'Tuition',
                'guardian_name' => 'Ravi Sharma', 'guardian_phone' => '9000000009',
                'guardian_email' => 'ravi.sharma@school.test',
                'gross_fee_amount' => '11000', 'discount_amount' => '1000',
                'net_fee_amount' => '10000', 'amount_paid' => '10000',
                'outstanding_amount' => '0', 'payment_status' => 'Paid',
                'payment_method' => 'UPI', 'days_overdue' => '0',
            ], $over), JSON_THROW_ON_ERROR);
        };

        $rows = [
            [
                'id' => 'r-1', 'natural_key' => 'NGS-INV-1', 'occurred_at' => '2025-12-10 00:00:00',
                'status' => 'Paid', 'metric_value' => 12000, 'subject_ref' => 'STU-0001',
                'payload' => $invoice([
                    'invoice_id' => 'NGS-INV-1', 'fee_period' => '2025-12', 'fee_due_date' => '2025-12-10',
                    'gross_fee_amount' => '13200', 'discount_amount' => '1200',
                    'net_fee_amount' => '12000', 'amount_paid' => '12000', 'outstanding_amount' => '0',
                    'payment_date' => '2025-12-08', 'payment_method' => 'Cash',
                ]),
            ],
            [
                'id' => 'r-2', 'natural_key' => 'NGS-INV-2', 'occurred_at' => '2026-02-10 00:00:00',
                'status' => 'Paid', 'metric_value' => 12000, 'subject_ref' => 'STU-0001',
                'payload' => $invoice([
                    'invoice_id' => 'NGS-INV-2', 'fee_period' => '2026-02', 'fee_due_date' => '2026-02-10',
                    'gross_fee_amount' => '13200', 'discount_amount' => '1200',
                    'net_fee_amount' => '12000', 'amount_paid' => '12000', 'outstanding_amount' => '0',
                    'payment_date' => '2026-02-12', 'payment_method' => 'UPI',
                ]),
            ],
            [
                'id' => 'r-3', 'natural_key' => 'NGS-INV-3', 'occurred_at' => '2026-04-10 00:00:00',
                'status' => 'Unpaid', 'metric_value' => 6000, 'subject_ref' => 'STU-0001',
                'payload' => $invoice([
                    'invoice_id' => 'NGS-INV-3', 'fee_period' => '2026-04', 'fee_due_date' => '2026-04-10',
                    'gross_fee_amount' => '6600', 'discount_amount' => '600',
                    'net_fee_amount' => '6000', 'amount_paid' => '0', 'outstanding_amount' => '6000',
                    'payment_status' => 'Unpaid', 'days_overdue' => '14', 'payment_date' => '',
                ]),
            ],
        ];

        foreach ($rows as $i => $row) {
            DB::table('hpbrain_operational_records')->insert(array_merge([
                'tenant_id' => self::TENANT, 'org_id' => self::TENANT, 'dataset' => 'school_fee',
                'source_file' => 'northgate_fees.csv', 'source_row' => $i + 2,
                'category' => 'Tuition', 'metric_unit' => 'INR',
                'row_hash' => 'hash-'.$row['id'], 'import_job_id' => 'job-1',
                'created_date' => '2026-05-01 10:00:00',
            ], $row));
        }

        // A record the untouched person handled rather than one about them: the
        // second attachment rule, which must be labelled differently.
        DB::table('hpbrain_operational_records')->insert([
            'id' => 'r-owned', 'tenant_id' => self::TENANT, 'org_id' => self::TENANT,
            'dataset' => 'helpdesk', 'natural_key' => 'NGS-TKT-1',
            'occurred_at' => '2026-03-01 00:00:00', 'status' => 'Closed', 'category' => 'Transport query',
            'owner_name' => 'Kiran Rao', 'row_hash' => 'hash-owned', 'created_date' => '2026-03-02 10:00:00',
        ]);

        // The other tenant's identical student, with money large enough that any
        // leak into this tenant's totals would be unmissable.
        DB::table('hpbrain_operational_records')->insert([
            'id' => 'r-other', 'tenant_id' => self::OTHER_TENANT, 'org_id' => self::OTHER_TENANT,
            'dataset' => 'school_fee', 'natural_key' => 'SGS-INV-1',
            'occurred_at' => '2026-05-10 00:00:00', 'status' => 'Unpaid', 'category' => 'Tuition',
            'subject_ref' => 'STU-0001', 'owner_name' => 'Aanya Sharma',
            'metric_value' => 999999, 'metric_unit' => 'INR', 'row_hash' => 'hash-other',
            'payload' => json_encode([
                'student_id' => 'STU-0001', 'class' => '12', 'section' => 'Z',
                'net_fee_amount' => '999999', 'amount_paid' => '0', 'outstanding_amount' => '999999',
                'payment_status' => 'Unpaid',
            ], JSON_THROW_ON_ERROR),
            'created_date' => '2026-05-11 10:00:00',
        ]);
    }
}
