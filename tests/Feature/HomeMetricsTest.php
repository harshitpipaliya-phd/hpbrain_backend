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
            $t->string('source');
            $t->string('classification');
            $t->string('priority');
            $t->string('severity');
            $t->decimal('confidence', 4, 4)->default(0.5);
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
