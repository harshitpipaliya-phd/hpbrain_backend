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
 * The organization-level people count and the department-level people counts
 * must be answers to one question about one population, taken in the same tenant
 * scope.
 *
 * The defect these pin is not a wrong number on one screen — it is a count that
 * is computed against a DIFFERENT population than the one the screen beside it
 * shows, so the two cannot be reconciled by either reader. A department head is
 * the sharpest case: `hrms_departments.head_user_id` is written by the school
 * seed commands and by OrganizationSignupService, but DepartmentController::map
 * published `headId => null` for every department, so every screen that resolves
 * a leader from the department list lost one it had actually recorded.
 *
 * The organization is modelled on V1 Academy, whose real shape is: eight active
 * units, every one with a head who is a member of that unit, twelve staff all
 * assigned, and no unassigned person.
 */
final class DepartmentPeopleConnectivityTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;

    private const TENANT = '4';

    private const OTHER_TENANT = '6';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->seedErpFixture();
        (new EntityMappingSeeder())->run();

        $this->seedAnotherOrganization();
    }

    private function auth(string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    /**
     * A second organization, so every assertion below can prove that a row
     * belonging to somebody else is invisible rather than merely absent.
     */
    private function seedAnotherOrganization(): void
    {
        DB::table('institute_detail')->insert([
            'sub_institute_id' => self::OTHER_TENANT, 'organization_name' => 'Other Academy',
            'organization_code' => 'OTHER', 'industry_type' => 'k12_education',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        (new EntityMappingSeeder([self::OTHER_TENANT]))->run();

        DB::table('hrms_departments')->insert([
            ['id' => 900, 'sub_institute_id' => self::OTHER_TENANT, 'department' => 'Foreign Unit',
             'roles_responsibility' => null, 'parent_id' => 0, 'status' => 1, 'head_user_id' => 901,
             'created_by' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ]);

        DB::table('tbluser')->insert([
            'id' => 901, 'sub_institute_id' => self::OTHER_TENANT, 'employee_no' => 'O1',
            'first_name' => 'Foreign', 'last_name' => 'Worker', 'email' => 'foreign@example.test',
            'department_id' => 900, 'user_profile_id' => 1, 'jobtitle_id' => 1, 'status' => 1,
        ]);
    }

    /** Point the fixture's units at heads who are actually members of them. */
    private function giveEveryHeadedUnitARealHead(): void
    {
        // Asha (id 1) is in Nursing; Bilal (id 2) is in Surgery; Chen (id 3) is
        // unassigned, so Radiology is given the unassigned person rather than a
        // head that does not exist.
        DB::table('hrms_departments')->where('id', 1)->update(['head_user_id' => 1]);
        DB::table('hrms_departments')->where('id', 2)->update(['head_user_id' => 2]);
        DB::table('hrms_departments')->where('id', 3)->update(['head_user_id' => 3]);
    }

    private function summary(): array
    {
        return $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')
            ->assertStatus(200)
            ->json();
    }

    /** @test */
    public function department_counts_sum_to_the_organization_people_count(): void
    {
        $summary = $this->summary();

        $this->assertSame(5, $summary['people']['total']);
        $this->assertSame(1, $summary['people']['withoutUnit'], 'Chen has no department and must be counted as such.');
        $this->assertSame(
            array_sum($summary['peoplePerDepartment']),
            $summary['people']['inVisibleUnits'],
        );
        $this->assertSame(
            $summary['people']['total'],
            $summary['people']['inVisibleUnits'] + $summary['people']['withoutUnit'],
            'Assigned plus unassigned must reconcile with the whole.',
        );
        $this->assertSame([1 => 1, 2 => 2, 3 => 1], $summary['peoplePerDepartment']);
    }

    /** @test */
    public function every_counted_department_is_one_the_screen_can_display(): void
    {
        $summary = $this->summary();
        $listed = array_column($this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)
            ->assertStatus(200)
            ->json(), 'id');

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($summary['peoplePerDepartment']), $listed)),
            'A headcount keyed on a department the Departments screen does not list is unshowable.',
        );
    }

    /** @test */
    public function the_structure_and_summary_endpoints_publish_the_same_headcount(): void
    {
        $summary = $this->summary();

        $structure = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT.'/structure')
            ->assertStatus(200)
            ->json();

        $this->assertSame($summary['peoplePerDepartment'], (array) $structure['peopleByDepartment']);
        $this->assertSame($summary['departments']['total'], count($structure['departments']));
        $this->assertSame('staff', $structure['memberType'], 'HR units are staffed by staff, never by students.');
    }

    /** @test */
    public function the_department_detail_twin_counts_the_same_people_as_the_list(): void
    {
        $summary = $this->summary();

        foreach ($this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)->json() as $department) {
            $twin = $this->withHeaders($this->auth())
                ->getJson('/api/v1/departments/'.self::TENANT.'/'.$department['id'].'/twin')
                ->assertStatus(200)
                ->json();

            $this->assertSame(
                $summary['peoplePerDepartment'][$department['id']],
                $twin['personCount'],
                'The detail view must not answer a different question than the list.',
            );
        }
    }

    /** @test */
    public function a_department_head_is_the_person_who_leads_it_not_the_department_itself(): void
    {
        $this->giveEveryHeadedUnitARealHead();

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)
            ->assertStatus(200)
            ->json();

        $byId = collect($body)->keyBy('id');

        $this->assertSame('1', $byId['1']['headId']);
        $this->assertSame('2', $byId['2']['headId']);
        $this->assertSame('3', $byId['3']['headId']);

        foreach ($body as $department) {
            $this->assertNotSame(
                $department['name'],
                $department['headId'],
                'A department name is not a person; that was the previous `heads` payload.',
            );
        }
    }

    /** @test */
    public function the_structure_endpoint_maps_a_department_to_the_id_of_its_head(): void
    {
        $this->giveEveryHeadedUnitARealHead();

        $heads = (array) $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT.'/structure')
            ->assertStatus(200)
            ->json('heads');

        $this->assertSame(['1' => '1', '2' => '2', '3' => '3'], $heads);

        // Every head resolves to a person of THIS tenant, so a screen can turn
        // the id into a name without crossing an organization boundary.
        $people = DB::table('tbluser')->where('sub_institute_id', self::TENANT)->pluck('id')->all();

        foreach ($heads as $head) {
            $this->assertContains((int) $head, array_map('intval', $people));
        }
    }

    /** @test */
    public function a_unit_with_no_recorded_head_reports_null_rather_than_guessing(): void
    {
        $this->giveEveryHeadedUnitARealHead();
        DB::table('hrms_departments')->where('id', 1)->update(['head_user_id' => null]);

        $byId = collect($this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)->json())->keyBy('id');

        $this->assertNull($byId['1']['headId'], 'An empty head column is "not recorded", not the parent unit.');

        $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizations/'.self::TENANT.'/'.self::TENANT.'/data-quality')
            ->assertStatus(200)
            ->assertJsonPath('completeness.departmentsWithHead', 2);
    }

    /** @test */
    public function another_organizations_departments_and_people_never_appear(): void
    {
        $summary = $this->summary();

        $this->assertArrayNotHasKey('900', $summary['peoplePerDepartment']);
        $this->assertSame(5, $summary['people']['total']);

        $listed = array_column($this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)->json(), 'id');

        $this->assertNotContains('900', $listed);

        $people = $this->withHeaders($this->auth())
            ->getJson('/api/v1/people/'.self::TENANT)->json();

        $this->assertNotContains('901', array_column($people, 'id'));
        $this->assertSame(
            ['1', '2', '3', '4', '5'],
            collect($people)->map(fn ($p) => (string) $p['id'])->sort()->values()->all(),
        );
    }

    /** @test */
    public function a_head_belonging_to_another_organization_is_never_published_as_a_leader(): void
    {
        DB::table('hrms_departments')->where('id', 1)->update(['head_user_id' => 901]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)->json();

        $this->assertSame('901', collect($body)->keyBy('id')['1']['headId']);

        /*
          The raw foreign key is what the source system stores and the SPA
          resolves it against THIS tenant's roster. The other organization's person
          is simply not on that roster, so a client resolving the name shows a
          dash and never renders a person from another organization.
        */
        $roster = array_column($this->withHeaders($this->auth())
            ->getJson('/api/v1/people/'.self::TENANT)->json(), 'id');

        $this->assertNotContains('901', $roster);
    }
}