<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * Characterization tests for the department endpoints, which the suite also had
 * none of.
 *
 * Departments are OrganizationUnit in the Brain's vocabulary. The response shape
 * asserted here is the one web/src/api/department.ts already consumes, so a
 * change to it breaks the SPA silently — which is exactly why it is pinned
 * before the table names underneath it move.
 */
final class DepartmentResolverParityTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;

    private const TENANT = '4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->seedErpFixture();
        (new EntityMappingSeeder())->run();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    /** @test */
    public function index_maps_source_rows_to_the_shape_the_spa_expects(): void
    {
        $response = $this->withHeaders($this->auth())->getJson('/api/v1/departments/'.self::TENANT);

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertCount(3, $body);
        $this->assertSame([
            'id'                 => '1',
            'name'               => 'Nursing',
            'description'        => 'Ward care',
            'departmentType'     => 'department',
            // parent_id 0 becomes null, not '0'.
            'parentDepartmentId' => null,
            'headId'             => null,
            'orgId'              => '4',
            'status'             => 'active',
            'createdBy'          => '1',
            'createdDate'        => '2026-01-01 00:00:00',
            'updatedDate'        => '2026-01-01 00:00:00',
        ], $body[0]);

        $this->assertSame('1', $body[1]['parentDepartmentId']);
    }

    /** @test */
    public function department_and_people_reads_reject_another_organization_url(): void
    {
        DB::table('institute_detail')->insert([
            'sub_institute_id' => 6, 'organization_name' => 'Fiber Valley',
            'organization_code' => 'FIBER-VALLEY', 'industry_type' => 'Operations',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00',
        ]);
        (new EntityMappingSeeder(['6']))->run();

        DB::table('hrms_departments')->insert([
            'id' => 10, 'sub_institute_id' => 6, 'department' => 'Cabling',
            'roles_responsibility' => 'Foreign department', 'parent_id' => 0, 'status' => 1,
            'created_by' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        DB::table('tbluser')->insert([
            'id' => 10, 'sub_institute_id' => 6, 'employee_no' => 'FV1',
            'first_name' => 'Fiber', 'last_name' => 'Worker', 'email' => 'fiber@example.test',
            'department_id' => 10, 'user_profile_id' => 1, 'jobtitle_id' => 1, 'status' => 1,
        ]);

        $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/6')
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);

        $this->withHeaders($this->auth())
            ->getJson('/api/v1/people/6')
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);
    }

    /** @test */
    public function index_prefers_the_current_imported_erp_department_cohort_over_stale_template_rows(): void
    {
        DB::table('hrms_departments')->insert([
            ['id' => 100, 'sub_institute_id' => self::TENANT, 'department' => 'Current Field Unit',
             'roles_responsibility' => null, 'parent_id' => 0, 'status' => 1, 'is_calculated' => 0,
             'created_by' => null, 'created_at' => '2026-08-04 13:27:29', 'updated_at' => '2026-08-04 13:27:29'],
            ['id' => 101, 'sub_institute_id' => self::TENANT, 'department' => 'Current Support Unit',
             'roles_responsibility' => null, 'parent_id' => 0, 'status' => 1, 'is_calculated' => 0,
             'created_by' => null, 'created_at' => '2026-08-04 13:27:30', 'updated_at' => '2026-08-04 13:27:30'],
            ['id' => 102, 'sub_institute_id' => self::TENANT, 'department' => 'Template Unit',
             'roles_responsibility' => null, 'parent_id' => 0, 'status' => 1, 'is_calculated' => 1,
             'created_by' => null, 'created_at' => null, 'updated_at' => null],
            ['id' => 103, 'sub_institute_id' => self::TENANT, 'department' => 'Old Manual Unit',
             'roles_responsibility' => null, 'parent_id' => 0, 'status' => 1, 'is_calculated' => 0,
             'created_by' => 29, 'created_at' => '2025-11-06 01:27:10', 'updated_at' => null],
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)
            ->assertStatus(200)
            ->json();

        $this->assertSame(['100', '101'], array_column($body, 'id'));

        $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/103')
            ->assertStatus(404)
            ->assertJson(['error' => 'department_not_found']);
    }

/** @test */
    public function head_id_is_read_from_the_mapped_head_column_not_hardcoded_to_null(): void
    {
        /*
          The response used to publish `headId => null` unconditionally, on the
          stated ground that the universal field 'head' had no column behind it.
          EntityMappingSeeder maps it — head -> hrms_departments.head_user_id —
          and the fixture fills it, so every one of these departments was losing a
          leader it had actually recorded, and the Departments screen's
          "headed / missing head" filters classified the whole organization as
          unled.

          What is pinned here is the RULE rather than one fixture row: the head is
          the value of the mapped column, and an empty column is null — never a
          guess at parent_id, and never a person from another tenant.
        */
        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)->json();

        $byId = collect($body)->keyBy('id');

        // Nursing is the one genuinely headless unit in the fixture.
        $this->assertNull($byId['1']['headId']);
        $this->assertSame('101', $byId['2']['headId']);
        $this->assertSame('102', $byId['3']['headId']);
    }

    /** @test */
    public function head_coverage_is_counted_from_the_head_column_not_the_parent_column(): void
    {
        /*
          `completeness.departmentsWithHead` was computed from
          `parent_id IS NULL OR parent_id = 0` — units with no PARENT — and
          published as head coverage. Any organization whose units are all
          top-level but all headed scored zero of N on a gap it does not have,
          and the same predicate was raised as a data-quality issue.

          The fixture is exactly that shape: Nursing has no head, the other two
          have both a head and a parent. So parent coverage and head coverage are
          two different numbers, and neither may borrow the other's column.
        */
        $completeness = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT.'/data-quality')
            ->assertStatus(200)
            ->json('completeness');

        // Surgery and Radiology carry head_user_id; Nursing does not.
        $this->assertSame(2, $completeness['departmentsWithHead']);
        // Only Nursing sits at the top of the hierarchy.
        $this->assertSame(2, $completeness['departmentsUnderParent']);
    }

    /** @test */
    public function show_returns_one_department_and_404s_for_a_missing_one(): void
    {
        $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/2')
            ->assertStatus(200)
            ->assertJson(['name' => 'Surgery']);

        $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/999')
            ->assertStatus(404)
            ->assertJson(['error' => 'department_not_found']);
    }

    /** @test */
    public function store_writes_through_the_resolved_columns(): void
    {
        $response = $this->withHeaders($this->auth())->postJson('/api/v1/departments', [
            'name' => 'Pharmacy', 'description' => 'Dispensing', 'parentId' => 1,
        ]);

        $response->assertStatus(201);
        $this->assertSame('Pharmacy', $response->json('name'));
        $this->assertSame('1', $response->json('parentDepartmentId'));

        $row = DB::table('hrms_departments')->where('department', 'Pharmacy')->first();
        $this->assertSame('Dispensing', $row->roles_responsibility);
        $this->assertSame(4, (int) $row->sub_institute_id);
        $this->assertSame(1, (int) $row->status);
    }

    /** @test */
    public function update_writes_only_the_supplied_fields(): void
    {
        $this->withHeaders($this->auth())
            ->patchJson('/api/v1/departments/'.self::TENANT.'/3', ['name' => 'Imaging'])
            ->assertStatus(200)->assertJson(['ok' => true]);

        $row = DB::table('hrms_departments')->where('id', 3)->first();
        $this->assertSame('Imaging', $row->department);
        $this->assertSame(1, (int) $row->parent_id, 'Untouched field must not move.');
    }

    /** @test */
    public function update_with_no_fields_is_rejected(): void
    {
        $this->withHeaders($this->auth())
            ->patchJson('/api/v1/departments/'.self::TENANT.'/3', [])
            ->assertStatus(422)
            ->assertJson(['error' => 'no_fields_to_update']);
    }

    /** @test */
    public function archive_soft_deletes_and_removes_the_row_from_the_listing(): void
    {
        $this->withHeaders($this->auth())
            ->postJson('/api/v1/departments/'.self::TENANT.'/3/archive')
            ->assertStatus(200);

        $this->assertNotNull(DB::table('hrms_departments')->where('id', 3)->value('deleted_at'));
        $this->assertCount(2, $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)->json());
    }

    /** @test */
    public function twin_counts_the_people_in_the_unit(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/2/twin');

        $response->assertStatus(200);
        $this->assertSame('Surgery', $response->json('department.name'));
        // People 2 and 4 sit in department 2.
        $this->assertSame(2, $response->json('personCount'));
        // No decisions exist, so the rate is null — an unmeasured rate is not
        // a rate of zero.
        $this->assertNull($response->json('decisionApprovalRate'));
    }

    /** @test */
    public function an_unmapped_tenant_fails_closed(): void
    {
        DB::table('hpbrain_entity_mappings')->where('tenant_id', '6')->delete();

        $status = $this->withHeaders(['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-2', 'tenantId' => '6', 'role' => 'admin',
        ])])->getJson('/api/v1/departments/6')->status();

        $this->assertNotSame(200, $status);
    }
}
