<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Events\LoopEvent;
use App\Domain\Events\EventPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for Organization Intelligence Home metrics and signal generation.
 */
final class HomeMetricsTest extends TestCase
{
    use \Tests\Support\SeedsEntityMappings;

    private const TENANT = '1';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('institute_detail', function ($t) {
            $t->string('sub_institute_id');
            $t->string('organization_name')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('org_details', function ($t) {
            $t->string('sub_institute_id');
            $t->string('legal_name')->nullable();
            $t->string('logo')->nullable();
        });

        Schema::create('hrms_departments', function ($t) {
            $t->integer('id')->primary();
            $t->integer('sub_institute_id');
            $t->string('department');
            $t->integer('parent_id')->default(0);
            $t->integer('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('tbluserprofilemaster', function ($t) {
            $t->integer('id')->primary();
            $t->integer('sub_institute_id');
            $t->string('name');
            $t->integer('status')->default(1);
        });

        Schema::create('tbluser', function ($t) {
            $t->integer('id')->primary();
            $t->string('employee_no')->nullable();
            $t->string('password')->nullable();
            $t->string('plain_password')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('mobile')->nullable();
            $t->string('gender')->nullable();
            $t->integer('department_id')->nullable();
            $t->integer('sub_institute_id');
            $t->integer('user_profile_id')->nullable();
            $t->integer('status')->default(1);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('hpbrain_signals', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            // Who the signal is about. Present on the real table and on the
            // shared replica in Tests\Support\BuildsBrainSchema; this local
            // copy had drifted without them, so a detector that writes the
            // subject failed here and nowhere else.
            $t->string('org_id', 36)->nullable();
            $t->string('department_id', 36)->nullable();
            $t->text('related_entity_type')->nullable();
            $t->string('related_entity_id', 36)->nullable();
            $t->string('source');
            $t->string('classification');
            // Which rule raised the signal. Re-detection matches on it to
            // refresh an open signal rather than raise a duplicate.
            $t->string('rule_key', 100)->nullable();
            $t->string('priority');
            $t->string('severity');
            // (6,4) not (4,4): four digits with four after the point cannot
            // represent 1.0000, and a rule may state full confidence.
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->text('metadata')->nullable();
            $t->string('status')->default('new');
            $t->string('created_by')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_cases', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('title');
            $t->string('status')->default('open');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_outcomes', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('result')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_learnings', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->integer('reusable')->default(0);
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_recommendations', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('status')->default('pending');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_decisions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('status')->default('proposed');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_operational_records', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('source_id', 36);
            $t->string('dataset', 100);
            $t->string('natural_key', 191);
            $t->string('subject_ref', 191)->nullable();
            $t->string('title')->nullable();
            $t->string('state')->nullable();
            $t->string('owner_name')->nullable();
            $t->string('category')->nullable();
            $t->decimal('metric_value', 18, 4)->nullable();
            $t->string('metric_unit')->nullable();
            $t->timestamp('occurred_at')->nullable();
            $t->text('payload')->nullable();
            $t->integer('source_row')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_evidence', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('signal_id', 36);
            $t->string('source');
            $t->string('evidence_type')->default('observation');
            $t->text('content');
            $t->text('provenance');
            $t->decimal('confidence', 4, 4)->default(0.5);
            $t->string('hash');
            $t->string('status')->default('active');
            $t->string('created_by')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_event_store', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('type');
            $t->string('tenant_id', 36);
            $t->string('entity_type');
            $t->string('entity_id', 36);
            $t->string('actor_id', 36);
            $t->text('payload');
            $t->text('metadata')->nullable();
            $t->string('correlation_id', 36)->nullable();
            $t->string('causation_id', 36)->nullable();
            $t->string('idempotency_key', 36)->nullable()->unique();
            $t->string('status')->default('pending');
            $t->integer('retry_count')->default(0);
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('hpbrain_refresh_tokens', function ($t) {
            $t->string('jti', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('user_id', 36);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('hpbrain_audit_logs', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('entity_type');
            $t->string('entity_id', 36);
            $t->string('action');
            $t->string('actor_id', 36);
            $t->text('changes')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        DB::table('institute_detail')->insert([
            'sub_institute_id' => self::TENANT, 'organization_name' => 'Test Org', 'deleted_at' => null,
        ]);

        DB::table('org_details')->insert([
            'sub_institute_id' => self::TENANT, 'legal_name' => 'Test Org Legal', 'logo' => null,
        ]);

        DB::table('tbluserprofilemaster')->insert([
            'id' => 1, 'sub_institute_id' => 1, 'name' => 'Employee', 'status' => 1,
        ]);

        // Home metrics resolve their source instead of naming it, so the
        // fixture has to say where this tenant keeps its people.
        $this->installEntityMappings([self::TENANT]);
        $this->installSignalRules();
    }

    public function test_home_metrics_returns_erp_and_intelligence_counts(): void
    {
        DB::table('tbluser')->insert([
            'id' => 1, 'employee_no' => 'E001', 'password' => null, 'plain_password' => null,
            'first_name' => 'Ada', 'last_name' => 'Analyst', 'email' => 'ada@test.com',
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 1,
            'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        DB::table('hrms_departments')->insert([
            'id' => 1, 'sub_institute_id' => 1, 'department' => 'Engineering',
            'parent_id' => 0, 'status' => 1, 'deleted_at' => null,
        ]);

        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $response = $this->getJson('/api/v1/workspace/'.self::TENANT.'/home-metrics', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'tenantId',
            'erp' => ['activePeople', 'activeDepartments', 'peopleWithoutDepartment', 'departmentsWithoutManager', 'peopleWithoutProfile'],
            'intelligence' => ['openSignals', 'highSignals', 'pendingRecommendations', 'openDecisions'],
            'attention' => [],
            'dataFreshness' => ['erp', 'brain'],
        ]);

        $this->assertSame(1, $response->json('erp.activePeople'));
        $this->assertSame(1, $response->json('erp.activeDepartments'));
    }

    public function test_home_metrics_detects_people_without_department(): void
    {
        DB::table('tbluser')->insert([
            'id' => 2, 'employee_no' => 'E002', 'password' => null, 'plain_password' => null,
            'first_name' => 'Bob', 'last_name' => 'Builder', 'email' => 'bob@test.com',
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 1,
            'department_id' => null, 'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $response = $this->getJson('/api/v1/workspace/'.self::TENANT.'/home-metrics', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('erp.peopleWithoutDepartment'));

        $attention = collect($response->json('attention'));
        $this->assertTrue($attention->contains('id', 'people-without-dept'));
    }

    public function test_home_metrics_detects_departments_without_manager(): void
    {
        DB::table('hrms_departments')->insert([
            'id' => 2, 'sub_institute_id' => 1, 'department' => 'Sales',
            'parent_id' => 0, 'status' => 1, 'deleted_at' => null,
        ]);

        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $response = $this->getJson('/api/v1/workspace/'.self::TENANT.'/home-metrics', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('erp.departmentsWithoutManager'));

        $attention = collect($response->json('attention'));
        $this->assertTrue($attention->contains('id', 'depts-without-manager'));
    }

    public function test_home_metrics_detects_high_signals(): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => 'sig-1', 'tenant_id' => self::TENANT, 'source' => 'test',
            'classification' => 'workforce', 'priority' => 'high', 'severity' => 'critical',
            'status' => 'new', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $response = $this->getJson('/api/v1/workspace/'.self::TENANT.'/home-metrics', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('intelligence.highSignals'));

        $attention = collect($response->json('attention'));
        $this->assertTrue($attention->contains('id', 'high-signals'));
    }

    public function test_home_metrics_surfaces_pipeline_without_rule_cause_review_action(): void
    {
        DB::table('hpbrain_operational_records')->insert([
            'id' => 'op-1',
            'tenant_id' => self::TENANT,
            'source_id' => 'source-1',
            'dataset' => 'school_fee',
            'natural_key' => 'receipt-1',
            'subject_ref' => 'student-1',
            'title' => 'Fee receipt',
            'state' => 'Paid',
            'owner_name' => 'Collector',
            'category' => 'Tuition',
            'metric_value' => 1000,
            'metric_unit' => 'INR',
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'payload' => json_encode([]),
            'source_row' => 1,
            'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => null,
        ]);

        DB::table('hpbrain_signals')->insert([
            'id' => 'sig-review',
            'tenant_id' => self::TENANT,
            'source' => 'import.school_fee',
            'classification' => 'fee_collection_concentration',
            'priority' => 'medium',
            'severity' => 'medium',
            'status' => 'new',
            'confidence' => 0.9,
            'rule_key' => 'fee_collector_concentration',
            'metadata' => json_encode(['rule' => 'fee_collector_concentration']),
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $response = $this->getJson('/api/v1/workspace/'.self::TENANT.'/home-metrics', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertSame('signals_detected', $response->json('pipeline.stage'));
        $this->assertSame(1, $response->json('pipeline.counts.operationalRecords'));
        $this->assertSame(1, $response->json('pipeline.review.unclassifiedRuleKeys'));
        $this->assertSame(['fee_collector_concentration'], $response->json('pipeline.review.unclassified'));

        $attention = collect($response->json('attention'));
        $this->assertFalse($attention->contains('id', 'root-cause-review'));
    }

    public function test_home_metrics_returns_data_driven_school_fee_intelligence(): void
    {
        $now = now()->format('Y-m-d H:i:s');

        DB::table('hpbrain_operational_records')->insert([
            [
                'id' => 'fee-1',
                'tenant_id' => self::TENANT,
                'source_id' => 'source-1',
                'dataset' => 'school_fee',
                'natural_key' => 'R-1',
                'subject_ref' => 'S-1',
                'title' => 'Sunrise fee',
                'state' => 'Overdue',
                'owner_name' => 'Collector A',
                'category' => 'Tuition Fee',
                'metric_value' => 10000,
                'metric_unit' => 'INR',
                'occurred_at' => '2025-06-01 00:00:00',
                'payload' => json_encode([
                    'receipt_no' => 'R-1',
                    'student_ref' => 'S-1',
                    'class_name' => 'Grade 7',
                    'section' => 'A',
                    'department' => 'Secondary School',
                    'fee_type' => 'Tuition Fee',
                    'amount_due' => '10000',
                    'concession_amount' => '0',
                    'net_amount' => '10000',
                    'amount_paid' => '4000',
                    'balance_amount' => '6000',
                    'payment_status' => 'Overdue',
                    'payment_method' => 'UPI',
                    'payment_date' => '2025-06-01',
                    'scholarship_type' => 'Merit',
                    'attendance_pct' => '70',
                    'exam_average_pct' => '58',
                    'risk_level' => 'Medium',
                ]),
                'source_row' => 1,
                'created_date' => $now,
                'updated_date' => null,
            ],
            [
                'id' => 'fee-2',
                'tenant_id' => self::TENANT,
                'source_id' => 'source-1',
                'dataset' => 'school_fee',
                'natural_key' => 'R-2',
                'subject_ref' => 'S-2',
                'title' => 'Sunrise fee',
                'state' => 'Paid',
                'owner_name' => 'Collector B',
                'category' => 'Exam Fee',
                'metric_value' => 2000,
                'metric_unit' => 'INR',
                'occurred_at' => '2025-06-03 00:00:00',
                'payload' => json_encode([
                    'receipt_no' => 'R-2',
                    'student_ref' => 'S-2',
                    'class_name' => 'Grade 8',
                    'section' => 'B',
                    'department' => 'Secondary School',
                    'fee_type' => 'Exam Fee',
                    'amount_due' => '2000',
                    'concession_amount' => '0',
                    'net_amount' => '2000',
                    'amount_paid' => '2000',
                    'balance_amount' => '0',
                    'payment_status' => 'Paid',
                    'payment_method' => 'Cash',
                    'payment_date' => '2025-06-03',
                    'scholarship_type' => 'None',
                    'attendance_pct' => '88',
                    'exam_average_pct' => '74',
                    'risk_level' => 'Low',
                ]),
                'source_row' => 2,
                'created_date' => $now,
                'updated_date' => null,
            ],
        ]);

        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $response = $this->getJson('/api/v1/workspace/'.self::TENANT.'/home-metrics', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('domainIntelligence.fees.overview.students'));
        $this->assertSame(1, $response->json('domainIntelligence.fees.overview.departments'));
        $this->assertSame(12000, $response->json('domainIntelligence.fees.overview.totalNet'));
        $this->assertSame(6000, $response->json('domainIntelligence.fees.overview.totalCollected'));
        $this->assertSame(6000, $response->json('domainIntelligence.fees.overview.totalOutstanding'));
        $this->assertSame(0.5, $response->json('domainIntelligence.fees.overview.collectionRate'));
        $this->assertSame(1, $response->json('domainIntelligence.fees.overview.defaulters'));
        $this->assertFalse($response->json('domainIntelligence.fees.availability.dueDate'));
        $this->assertFalse($response->json('domainIntelligence.fees.availability.reminderHistory'));
        $this->assertSame('S-1', $response->json('domainIntelligence.fees.defaulters.0.studentRef'));
        $this->assertContains('Attendance is below 75%.', $response->json('domainIntelligence.fees.defaulters.0.riskFactors'));
        $this->assertSame('Secondary School', $response->json('domainIntelligence.fees.analytics.byDepartment.0.name'));
        $this->assertSame('Merit', $response->json('domainIntelligence.fees.analytics.byScholarship.0.name'));
        $this->assertSame('Medium', $response->json('domainIntelligence.fees.analytics.riskLevelRows.0.name'));
        $this->assertSame(1, $response->json('domainIntelligence.fees.analytics.riskLevelStudents.0.count'));
        $this->assertSame('S-1', $response->json('domainIntelligence.fees.priorityRecovery.0.studentRef'));
        $this->assertSame(0.4, $response->json('domainIntelligence.fees.priorityRecovery.0.collectionRate'));
    }

    public function test_home_metrics_returns_empty_attention_when_all_clear(): void
    {
        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $response = $this->getJson('/api/v1/workspace/'.self::TENANT.'/home-metrics', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertSame(1, count($response->json('attention')));
        $this->assertSame('all-clear', $response->json('attention.0.id'));
    }

    public function test_signal_generation_creates_signals_for_erp_issues(): void
    {
        DB::table('tbluser')->insert([
            'id' => 3, 'employee_no' => 'E003', 'password' => null, 'plain_password' => null,
            'first_name' => 'Carol', 'last_name' => 'Coder', 'email' => 'carol@test.com',
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 1,
            'department_id' => null, 'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $response = $this->postJson('/api/v1/signals/generate', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertGreaterThan(0, $response->json('data.created'));

        $signalCount = DB::table('hpbrain_signals')
            ->where('tenant_id', self::TENANT)
            ->where('source', 'erp.data_quality')
            ->count();

        $this->assertGreaterThan(0, $signalCount);
    }

    public function test_signal_generation_is_tenant_scoped(): void
    {
        DB::table('tbluser')->insert([
            'id' => 4, 'employee_no' => 'E004', 'password' => null, 'plain_password' => null,
            'first_name' => 'Dave', 'last_name' => 'Dev', 'email' => 'dave@test.com',
            'sub_institute_id' => 2, 'user_profile_id' => 1, 'status' => 1,
            'department_id' => null, 'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $this->postJson('/api/v1/signals/generate', [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(200);

        $foreignSignals = DB::table('hpbrain_signals')
            ->where('tenant_id', '2')
            ->count();

        $this->assertSame(0, $foreignSignals);
    }

    public function test_organization_report_returns_erp_and_intelligence_metrics(): void
    {
        DB::table('tbluser')->insert([
            'id' => 10, 'employee_no' => 'E010', 'password' => null, 'plain_password' => null,
            'first_name' => 'Test', 'last_name' => 'User', 'email' => 'test@test.com',
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 1,
            'department_id' => 1, 'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        DB::table('hrms_departments')->insert([
            'id' => 10, 'sub_institute_id' => 1, 'department' => 'Test Dept',
            'parent_id' => 1, 'status' => 1, 'deleted_at' => null,
        ]);

        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $response = $this->getJson('/api/v1/analytics/'.self::TENANT.'/reports/organization', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'tenantId', 'generatedAt',
            'organization' => ['activePeople', 'activeDepartments', 'peopleWithoutDepartment', 'peopleWithoutProfile'],
            'intelligence' => ['openSignals', 'highSignals', 'pendingRecommendations', 'openDecisions'],
            'dataQuality' => ['score'],
        ]);

        $this->assertSame(1, $response->json('organization.activePeople'));
        $this->assertSame(1, $response->json('organization.activeDepartments'));
    }

    public function test_people_report_returns_distribution_and_quality(): void
    {
        DB::table('hrms_departments')->insert([
            'id' => 20, 'sub_institute_id' => 1, 'department' => 'Test Dept',
            'parent_id' => 0, 'status' => 1, 'deleted_at' => null,
        ]);

        DB::table('tbluser')->insert([
            'id' => 11, 'employee_no' => 'E011', 'password' => null, 'plain_password' => null,
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@test.com',
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 1,
            'department_id' => 20, 'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $response = $this->getJson('/api/v1/analytics/'.self::TENANT.'/reports/people', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'tenantId', 'generatedAt',
            'byDepartment', 'byRole',
            'missingProfile', 'missingDepartment', 'inactive',
        ]);

        $this->assertGreaterThanOrEqual(1, $response->json('byDepartment.0.count'));
    }

    public function test_intelligence_report_returns_loop_metrics(): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => 'sig-report-1', 'tenant_id' => self::TENANT, 'source' => 'test',
            'classification' => 'workforce', 'priority' => 'medium', 'severity' => 'medium',
            'confidence' => 0.8, 'status' => 'new', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $response = $this->getJson('/api/v1/analytics/'.self::TENANT.'/reports/intelligence', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'tenantId', 'generatedAt',
            'signals' => ['total', 'byStatus', 'bySeverity'],
            'cases' => ['total', 'byStatus'],
            'recommendations' => ['total', 'byStatus', 'byCategory'],
            'decisions' => ['total', 'byStatus'],
            'outcomes' => ['total', 'byResult'],
            'learnings' => ['total', 'reusable'],
            'evidence' => ['total', 'averageConfidence'],
        ]);

        $this->assertSame(1, $response->json('signals.total'));
    }

    public function test_reports_are_tenant_scoped(): void
    {
        $token = \App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ]);

        $response = $this->getJson('/api/v1/analytics/'.self::TENANT.'/reports/organization', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertSame(self::TENANT, $response->json('tenantId'));
    }
}
