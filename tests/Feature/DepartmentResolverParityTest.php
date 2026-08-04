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
    public function head_id_stays_null_because_the_source_has_no_such_column(): void
    {
        // OrganizationUnit.head is unmapped in this ERP. The honest rendering is
        // an explicit null, never a guess at parent_id.
        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)->json();

        foreach ($body as $row) {
            $this->assertNull($row['headId']);
        }
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
