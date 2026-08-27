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
 * fixture, derived by reading that code: a `score` of 50.0 is (1 - 4/8) * 100
 * for four flagged conditions over five people plus three departments, and the
 * `field` values are the ERP's own column names, which is what the SPA renders
 * today.
 */
final class OrganizationResolverParityTest extends TestCase
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
        // NORMALISED, NOT RAW. The service publishes the ERP's `parent_id = 0`
        // as null and its `status = 1` as 'active', and labels which system the
        // unit came from, so a client never has to know one ERP's sentinel
        // values. This asserts that vocabulary rather than the column contents.
        $this->assertSame(
            ['id' => '1', 'name' => 'Nursing', 'parentId' => null, 'status' => 'active', 'source' => 'hr'],
            $body['departments'][0],
        );

        /*
            Grouped headcount, keyed by REAL departments only.

            The person whose department_id is 0 is not filed under a department
            called "0" — there is no such unit, and a client rendering this map
            beside the department list would print a phantom row. They are
            reported as unassigned by the summary and data-quality endpoints,
            which is where a headless person belongs.
        */
        $this->assertSame(1, $body['peopleByDepartment']['1']);
        $this->assertSame(2, $body['peopleByDepartment']['2']);
        $this->assertArrayNotHasKey('0', $body['peopleByDepartment']);

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
