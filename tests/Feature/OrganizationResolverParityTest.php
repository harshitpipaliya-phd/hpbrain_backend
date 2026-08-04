<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * Characterization tests for the organization endpoints, written because the
 * suite had NONE.
 *
 * Phase 2 replaces every hardcoded ERP table name with a resolved one and claims
 * the behaviour is identical. That claim was unverifiable here: no test called
 * GET /organizations, /structure or /data-quality, so the largest file in the
 * change set (31 references) could have been rewritten into anything and the
 * suite would still have gone green.
 *
 * The expected values below are the ones the pre-Phase-2 code produced for this
 * fixture, derived by reading that code — a `score` of 62.5 is what
 * (1 - 3/8) * 100 gives for three flawed rows out of five people and three
 * departments, and the `field` values are the ERP's own column names, which is
 * what the SPA renders today.
 */
final class OrganizationResolverParityTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = '4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->seedErp();
        (new EntityMappingSeeder())->run();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    private function buildErpSchema(): void
    {
        Schema::create('institute_detail', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('organization_name')->nullable();
            $t->string('organization_code')->nullable();
            $t->string('industry_type')->nullable();
            $t->integer('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('org_details', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('legal_name')->nullable();
            $t->string('logo')->nullable();
            $t->integer('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('hrms_departments', function ($t) {
            $t->integer('id')->primary();
            $t->integer('sub_institute_id');
            $t->string('department');
            $t->text('roles_responsibility')->nullable();
            $t->integer('parent_id')->default(0);
            $t->integer('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('tbluser', function ($t) {
            $t->integer('id')->primary();
            $t->integer('sub_institute_id');
            $t->string('employee_no')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('mobile')->nullable();
            $t->integer('department_id')->nullable();
            $t->integer('jobtitle_id')->nullable();
            $t->integer('user_profile_id')->nullable();
            $t->date('joined_date')->nullable();
            $t->integer('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('tbluserprofilemaster', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('name');
            $t->integer('status')->default(1);
        });

        Schema::create('hrms_job_titles', function ($t) {
            $t->integer('id')->primary();
            $t->integer('sub_institute_id');
            $t->string('title');
            $t->integer('is_active')->default(1);
        });
    }

    private function seedErp(): void
    {
        DB::table('institute_detail')->insert([
            'sub_institute_id' => 4, 'organization_name' => 'SIDS HealthCare',
            'organization_code' => 'SIDS', 'industry_type' => 'Healthcare',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00',
        ]);
        DB::table('org_details')->insert([
            'sub_institute_id' => 4, 'legal_name' => 'SIDS HealthCare Pvt Ltd', 'logo' => 'sids.png',
        ]);

        DB::table('hrms_departments')->insert([
            ['id' => 1, 'sub_institute_id' => 4, 'department' => 'Nursing',
             'roles_responsibility' => 'Ward care', 'parent_id' => 0, 'status' => 1, 'deleted_at' => null],
            ['id' => 2, 'sub_institute_id' => 4, 'department' => 'Surgery',
             'roles_responsibility' => null, 'parent_id' => 1, 'status' => 1, 'deleted_at' => null],
            ['id' => 3, 'sub_institute_id' => 4, 'department' => 'Radiology',
             'roles_responsibility' => null, 'parent_id' => 1, 'status' => 1, 'deleted_at' => null],
        ]);

        // Five active people. One has no department, one has no profile, one has
        // no email — three flawed rows, which is what makes the score checkable.
        $people = [
            [1, 'E1', 'Asha',  'Rao',   'asha@x.test',  1, 1],
            [2, 'E2', 'Bilal', 'Khan',  'bilal@x.test', 2, 1],
            [3, 'E3', 'Chen',  'Wu',    'chen@x.test',  0, 1],   // no department
            [4, 'E4', 'Dev',   'Patel', 'dev@x.test',   2, 0],   // no profile
            [5, 'E5', 'Eve',   'Silva', '',             3, 1],   // no email
        ];

        foreach ($people as [$id, $no, $first, $last, $email, $dept, $profile]) {
            DB::table('tbluser')->insert([
                'id' => $id, 'sub_institute_id' => 4, 'employee_no' => $no,
                'first_name' => $first, 'last_name' => $last, 'email' => $email,
                'department_id' => $dept, 'user_profile_id' => $profile, 'status' => 1,
            ]);
        }

        DB::table('tbluserprofilemaster')->insert([
            'sub_institute_id' => 4, 'name' => 'Employee', 'status' => 1,
        ]);
    }

    /** @test */
    public function index_returns_the_organization_with_its_satellite_columns(): void
    {
        $response = $this->withHeaders($this->auth())->getJson('/api/v1/organizations/'.self::TENANT);

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertCount(1, $body);
        $this->assertSame(4, (int) $body[0]['id']);
        $this->assertSame('SIDS HealthCare', $body[0]['name']);
        $this->assertSame('SIDS', $body[0]['org_code']);
        $this->assertSame('Healthcare', $body[0]['industry']);
        // The correlated subqueries against org_details.
        $this->assertSame('SIDS HealthCare Pvt Ltd', $body[0]['legal_name']);
        $this->assertSame('sids.png', $body[0]['logo']);
    }

    /** @test */
    public function structure_returns_units_headcount_and_heads(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT.'/structure');

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertCount(3, $body['departments']);
        $this->assertSame(
            ['id' => '1', 'name' => 'Nursing', 'parentId' => '0', 'status' => '1'],
            $body['departments'][0],
        );

        // Grouped headcount, including the person whose department_id is 0.
        $this->assertSame(1, $body['peopleByDepartment']['1']);
        $this->assertSame(2, $body['peopleByDepartment']['2']);
        $this->assertSame(1, $body['peopleByDepartment']['0']);

        $this->assertSame('Radiology', $body['heads']['3']);
    }

    /** @test */
    public function data_quality_scores_and_names_the_source_columns(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT.'/data-quality');

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame(5, $body['totalPeople']);
        $this->assertSame(3, $body['totalDepartments']);

        // One department has parent_id = 0, so it counts as "without head" under
        // the existing rule. 3 people-issues + 1 department-issue = 4 over a
        // denominator of 5 + 3: (1 - 4/8) * 100 = 50.0
        // assertEquals, not assertSame: PHP rounds to float 50.0 and JSON
        // serialises that as `50`, which decodes back as an int.
        $this->assertEquals(50.0, $body['score']);

        // ERP column names, unchanged — this is what the SPA renders and what an
        // administrator would go and fix.
        $fields = array_column($body['issues'], 'field');
        $this->assertContains('department_id', $fields);
        $this->assertContains('user_profile_id', $fields);
        $this->assertContains('email', $fields);
        $this->assertContains('parent_id', $fields);

        $this->assertSame(4, $body['completeness']['peopleWithDepartment']);
        $this->assertSame(4, $body['completeness']['peopleWithProfile']);
        $this->assertSame(4, $body['completeness']['peopleWithEmail']);
        $this->assertSame(2, $body['completeness']['departmentsWithHead']);
    }

    /** @test */
    public function show_finds_the_organization_by_id(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT);

        $response->assertStatus(200);
        $this->assertSame('SIDS HealthCare', $response->json('name'));
    }

    /** @test */
    public function update_writes_through_the_resolved_columns(): void
    {
        $response = $this->withHeaders($this->auth())
            ->patchJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT, [
                'name' => 'SIDS Health', 'industry' => 'healthcare',
            ]);

        $response->assertStatus(200)->assertJson(['ok' => true]);

        $row = DB::table('institute_detail')->where('sub_institute_id', 4)->first();
        $this->assertSame('SIDS Health', $row->organization_name);
        $this->assertSame('healthcare', $row->industry_type);
    }

    /** @test */
    public function creating_an_organization_provisions_both_satellite_rows(): void
    {
        $response = $this->withHeaders($this->auth())->postJson('/api/v1/organizations', [
            'name' => 'New Clinic', 'orgCode' => 'NC', 'industry' => 'healthcare',
            'legalName' => 'New Clinic Ltd', 'logo' => 'nc.png',
        ]);

        $response->assertStatus(201);
        $newId = (int) $response->json('id');
        $this->assertSame(5, $newId, 'Next id is max(sub_institute_id) + 1.');

        $this->assertSame('New Clinic Ltd',
            DB::table('org_details')->where('sub_institute_id', $newId)->value('legal_name'));

        // The ERP requires every institute to own an 'Employee' profile.
        $this->assertTrue(
            DB::table('tbluserprofilemaster')->where('sub_institute_id', $newId)
                ->where('name', 'Employee')->exists(),
        );

        // The mapping tenant is plumbing and must not surface in the payload.
        $this->assertArrayNotHasKey('mappingTenantId', $response->json());
    }

    /** @test */
    public function archive_soft_deletes_rather_than_destroying_erp_data(): void
    {
        $this->withHeaders($this->auth())
            ->postJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT.'/archive')
            ->assertStatus(200);

        $this->assertNotNull(
            DB::table('institute_detail')->where('sub_institute_id', 4)->value('deleted_at'),
        );
    }

    /** @test */
    public function a_tenant_with_no_mappings_fails_closed_rather_than_reading_another(): void
    {
        // The whole point of the resolver. Tenant 6 exists as a JWT claim but has
        // no mapping rows; it must not fall through to tenant 4's tables.
        DB::table('hpbrain_entity_mappings')->where('tenant_id', '6')->delete();

        $status = $this->withHeaders(['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-2', 'tenantId' => '6', 'role' => 'admin',
        ])])->getJson('/api/v1/organizations/6')->status();

        $this->assertNotSame(200, $status, 'An unmapped tenant must not receive rows.');
    }
}
