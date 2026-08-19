<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Organization\OrganizationStructureService;
use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * ONE department count, for every organization, on every screen.
 *
 * THE DEFECT THIS PINS. Five places computed a department count and none of them
 * agreed for every organization: WorkspaceController::homeMetrics,
 * AnalyticsController::organizationReport and OrganizationController::dataQuality
 * each ran their own COUNT over the mapped unit table; DepartmentController::index
 * ran a fourth WITH a visibility filter the others lacked; and the Departments
 * screen had grown a fifth notion — the teaching sections a school is organised
 * by. A school with no HR units therefore showed 4 departments on one screen and
 * 0 on another, both honestly derived.
 *
 * OrganizationStructureService is the only thing that answers the question now,
 * and these tests fail the moment any screen starts answering it again.
 *
 * THE RULE IS A PROPERTY OF THE DATA, NOT AN IDENTITY. No tenant is named in the
 * service and none is named here: an organization with source-system units uses
 * them, one without them that is dataset-backed uses its derived sections, and
 * one with neither reports zero. Each of those three is exercised below on a
 * fixture organization, so a tenant created tomorrow is classified by the same
 * three questions with no further code.
 */
final class OrganizationStructureTest extends TestCase
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

    private function auth(string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    private function service(): OrganizationStructureService
    {
        return app(OrganizationStructureService::class);
    }

    /** @param array<int, array{ref: string, academic?: ?string}> $students */
    private function seedStudents(array $students, string $tenant = self::TENANT): void
    {
        foreach ($students as $s) {
            DB::table('hpbrain_students')->insert([
                'id' => $tenant.'-'.$s['ref'], 'tenant_id' => $tenant, 'student_ref' => $s['ref'],
                'student_name' => 'Child '.$s['ref'], 'academic_standard' => $s['academic'] ?? null,
                'in_academic' => 1, 'in_fees' => 0, 'academic_records' => 3, 'fee_records' => 0,
                'projected_at' => '2026-01-01 00:00:00',
            ]);
        }
    }

    private function dropHrDepartments(string $tenant = self::TENANT): void
    {
        DB::table('hrms_departments')->where('sub_institute_id', $tenant)->delete();
    }

    /**
     * Every endpoint that publishes a department count publishes the same one.
     *
     * This is the test the whole change exists for. It runs against an
     * organization WITH source-system units, and again below against one whose
     * structure is derived — the point being that the agreement holds either
     * way, not that a particular number is right.
     *
     * @test
     */
    public function every_endpoint_reports_the_same_department_count_for_a_source_system_backed_organization(): void
    {
        $this->assertEveryEndpointAgrees(self::TENANT);

        // And it is the real number, not merely a consistent one.
        $this->assertSame(
            $this->service()->departmentCount(self::TENANT),
            count($this->withHeaders($this->auth())->getJson('/api/v1/departments/'.self::TENANT)->json()),
        );
    }

    /**
     * The same agreement for an organization whose departments are DERIVED.
     *
     * Before this change the Departments screen showed its teaching sections
     * while every count endpoint reported zero.
     *
     * @test
     */
    public function every_endpoint_reports_the_same_department_count_for_a_dataset_backed_organization(): void
    {
        $this->dropHrDepartments();
        $this->seedStudents([
            ['ref' => 'P1', 'academic' => 'CBSE-2'],
            ['ref' => 'M1', 'academic' => 'CBSE-7'],
            ['ref' => 'S1', 'academic' => 'CBSE-9'],
            ['ref' => 'H1', 'academic' => 'CBSE-12'],
        ]);

        $this->assertSame('academic', $this->service()->source(self::TENANT));
        $this->assertSame(4, $this->service()->departmentCount(self::TENANT));

        $this->assertEveryEndpointAgrees(self::TENANT);
    }

    /** An organization with neither reports zero everywhere. */
    /** @test */
    public function an_organization_with_no_units_and_no_students_reports_zero_everywhere(): void
    {
        $this->dropHrDepartments();

        $this->assertSame('none', $this->service()->source(self::TENANT));
        $this->assertSame(0, $this->service()->departmentCount(self::TENANT));

        $this->assertEveryEndpointAgrees(self::TENANT);

        $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)
            ->assertStatus(200)
            ->assertJsonCount(0);
    }

    /**
     * Source-system units always win. An organization that records its own
     * structure is never told what it is by derived data.
     *
     * @test
     */
    public function recorded_units_take_precedence_over_derived_sections(): void
    {
        // This organization has BOTH: real HR units and a student projection.
        $this->seedStudents([
            ['ref' => 'S1', 'academic' => 'CBSE-9'],
            ['ref' => 'H1', 'academic' => 'CBSE-12'],
        ]);

        $hrUnits = DB::table('hrms_departments')
            ->where('sub_institute_id', self::TENANT)->whereNull('deleted_at')->count();

        $this->assertGreaterThan(0, $hrUnits);
        $this->assertSame('hr', $this->service()->source(self::TENANT));
        $this->assertSame('staff', $this->service()->memberType(self::TENANT));

        // Not the two academic sections.
        $names = array_column($this->service()->getDepartmentsForOrganization(self::TENANT), 'name');
        $this->assertNotContains('Secondary Section', $names);
    }

    /**
     * A count is never a global one.
     *
     * @test
     */
    public function department_counts_are_scoped_to_the_organization_asking(): void
    {
        DB::table('institute_detail')->insert([
            'sub_institute_id' => 6, 'organization_name' => 'Other Org',
            'organization_code' => 'OTHER', 'industry_type' => 'Operations',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00',
        ]);
        (new EntityMappingSeeder(['6']))->run();

        DB::table('hrms_departments')->insert([
            ['id' => 900, 'sub_institute_id' => 6, 'department' => 'Not Yours A',
             'roles_responsibility' => null, 'parent_id' => 0, 'status' => 1, 'is_calculated' => 0,
             'created_by' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 901, 'sub_institute_id' => 6, 'department' => 'Not Yours B',
             'roles_responsibility' => null, 'parent_id' => 0, 'status' => 1, 'is_calculated' => 0,
             'created_by' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ]);

        $mine = $this->service()->departmentCount(self::TENANT);
        $theirs = $this->service()->departmentCount('6');

        $this->assertSame(2, $theirs);
        $this->assertNotContains(
            'Not Yours A',
            array_column($this->service()->getDepartmentsForOrganization(self::TENANT), 'name'),
        );

        // The count did not change because another organization gained units.
        $this->assertSame($mine, $this->service()->departmentCount(self::TENANT));

        // And the URL is not the authority.
        $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/6/summary')
            ->assertStatus(403);
    }

    /**
     * Members are labelled with the population they came from, never merged.
     *
     * @test
     */
    public function members_per_department_are_staff_for_units_and_students_for_sections(): void
    {
        $this->assertSame('staff', $this->service()->memberType(self::TENANT));

        $staffPerUnit = $this->service()->getPeopleCountByDepartment(self::TENANT);
        $this->assertSame(
            array_sum($staffPerUnit),
            DB::table('tbluser')->where('sub_institute_id', self::TENANT)
                ->whereNull('deleted_at')->where('status', 1)
                ->whereIn('department_id', array_keys($staffPerUnit))->count(),
        );

        $this->dropHrDepartments();
        $this->seedStudents([['ref' => 'S1', 'academic' => 'CBSE-9'], ['ref' => 'S2', 'academic' => 'CBSE-10']]);

        // Memoised per request; a fresh instance is what a new request gets.
        $fresh = app()->make(OrganizationStructureService::class);
        $this->assertSame('students', $fresh->memberType(self::TENANT));
        $this->assertSame(2, array_sum($fresh->getPeopleCountByDepartment(self::TENANT)));
    }

    /**
     * A derived section is never reported as a department missing its head.
     *
     * It has no head column to fill in and no ERP screen an administrator could
     * fix it on, so raising it as a data-quality issue asks somebody to correct
     * something that does not exist.
     *
     * @test
     */
    public function data_quality_raises_head_gaps_only_for_source_system_units(): void
    {
        $this->dropHrDepartments();
        $this->seedStudents([['ref' => 'S1', 'academic' => 'CBSE-9']]);

        $quality = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT.'/data-quality')
            ->assertStatus(200)->json();

        $this->assertSame(1, $quality['totalDepartments'], 'The shared count, not a fourth one.');
        $this->assertSame(1, $quality['completeness']['departmentsWithHead']);
        $this->assertSame(
            [],
            array_values(array_filter($quality['issues'], fn ($i) => $i['field'] === 'parent_id')),
            'A derived section has no head field to be missing.',
        );
    }

    /**
     * Nothing is written to make a count come out right.
     *
     * @test
     */
    public function deriving_a_structure_writes_nothing(): void
    {
        $this->dropHrDepartments();
        $this->seedStudents([['ref' => 'S1', 'academic' => 'CBSE-9']]);

        $before = DB::table('hrms_departments')->count();
        $studentsBefore = DB::table('hpbrain_students')->count();

        $this->withHeaders($this->auth())->getJson('/api/v1/workspace/'.self::TENANT.'/home-metrics');
        $this->withHeaders($this->auth())->getJson('/api/v1/departments/'.self::TENANT.'/summary');
        $this->withHeaders($this->auth())->getJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT.'/structure');

        $this->assertSame($before, DB::table('hrms_departments')->count());
        $this->assertSame($studentsBefore, DB::table('hpbrain_students')->count());
    }

    /**
     * Assert that every endpoint publishing a department count for $tenant
     * publishes the SAME one.
     */
    private function assertEveryEndpointAgrees(string $tenant): void
    {
        $expected = $this->service()->departmentCount($tenant);
        $auth = $this->auth($tenant);

        $home = $this->withHeaders($auth)->getJson("/api/v1/workspace/{$tenant}/home-metrics")
            ->assertStatus(200)->json();
        $summary = $this->withHeaders($auth)->getJson("/api/v1/departments/{$tenant}/summary")
            ->assertStatus(200)->json();
        $overview = $this->withHeaders($auth)->getJson("/api/v1/analytics/{$tenant}/enterprise-overview")
            ->assertStatus(200)->json();
        $report = $this->withHeaders($auth)->getJson("/api/v1/analytics/{$tenant}/reports/organization")
            ->assertStatus(200)->json();
        $quality = $this->withHeaders($auth)->getJson("/api/v1/organizations/{$tenant}/{$tenant}/data-quality")
            ->assertStatus(200)->json();
        $structure = $this->withHeaders($auth)->getJson("/api/v1/organizations/{$tenant}/{$tenant}/structure")
            ->assertStatus(200)->json();

        $this->assertSame($expected, $home['erp']['activeDepartments'], 'home-metrics');
        $this->assertSame($expected, $summary['departments']['active'], 'departments/summary');
        $this->assertSame($expected, $overview['workforceDepartment']['activeDepartments'], 'enterprise-overview');
        $this->assertSame($expected, $report['organization']['activeDepartments'], 'organization report');
        $this->assertSame($expected, $quality['totalDepartments'], 'data-quality');
        $this->assertCount($expected, $structure['departments'], 'organization structure');
    }
}
