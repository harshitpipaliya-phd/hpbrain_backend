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
 * The Organization overview, the Departments screen and the Intelligence
 * Workspace must publish the SAME department and people counts.
 *
 * THE DEFECT THIS PINS, measured on the live database before the fix: tenant 7's
 * Organization overview reported 24 departments and 81 people while its
 * Departments screen listed 5 departments and 76 people. Neither number was
 * invented — they were answers to different questions. The list excluded the
 * ERP's `is_calculated` template rows and superseded cohorts (which are not
 * departments anybody works in); every COUNT in the product included them,
 * because the exclusion lived in one endpoint instead of in the definition.
 *
 * The definition now lives in App\Domain\Organization\DepartmentVisibilityScope
 * and the counts in App\Domain\Organization\FoundationCounts, and every surface
 * reads them. These tests fail if any surface starts counting for itself again.
 */
final class FoundationCountConsistencyTest extends TestCase
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

    /**
     * Add the exact row shapes that made the two screens disagree: an ERP
     * template unit, a superseded manual unit, and staff sitting in each.
     */
    private function seedTemplateAndSupersededUnits(): void
    {
        DB::table('hrms_departments')->insert([
            ['id' => 100, 'sub_institute_id' => self::TENANT, 'department' => 'Current Field Unit',
             'roles_responsibility' => null, 'parent_id' => 0, 'status' => 1, 'is_calculated' => 0,
             'created_by' => null, 'created_at' => '2026-08-04 13:27:29', 'updated_at' => '2026-08-04 13:27:29'],
            ['id' => 102, 'sub_institute_id' => self::TENANT, 'department' => 'Template Unit',
             'roles_responsibility' => null, 'parent_id' => 0, 'status' => 1, 'is_calculated' => 1,
             'created_by' => null, 'created_at' => null, 'updated_at' => null],
            ['id' => 103, 'sub_institute_id' => self::TENANT, 'department' => 'Old Manual Unit',
             'roles_responsibility' => null, 'parent_id' => 0, 'status' => 1, 'is_calculated' => 0,
             'created_by' => 29, 'created_at' => '2025-11-06 01:27:10', 'updated_at' => null],
        ]);

        DB::table('tbluser')->insert([
            ['id' => 200, 'sub_institute_id' => self::TENANT, 'employee_no' => 'E200',
             'first_name' => 'In', 'last_name' => 'Visible', 'email' => 'in@example.test',
             'department_id' => 100, 'user_profile_id' => 1, 'jobtitle_id' => 1, 'status' => 1],
            ['id' => 201, 'sub_institute_id' => self::TENANT, 'employee_no' => 'E201',
             'first_name' => 'On', 'last_name' => 'Template', 'email' => 'tmpl@example.test',
             'department_id' => 102, 'user_profile_id' => 1, 'jobtitle_id' => 1, 'status' => 1],
        ]);
    }

    /** @test */
    public function the_summary_endpoint_agrees_with_the_department_list_it_describes(): void
    {
        $this->seedTemplateAndSupersededUnits();

        $list = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)->assertStatus(200)->json();

        $summary = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')->assertStatus(200)->json();

        $active = count(array_filter($list, fn ($d) => $d['status'] === 'active'));

        $this->assertSame(count($list), $summary['departments']['total'], 'Total must be the rows the screen lists.');
        $this->assertSame($active, $summary['departments']['active']);

        // And it must actually be excluding something, or the assertion above
        // would pass on a scope that had quietly stopped working.
        $this->assertGreaterThan(
            0,
            DB::table('hrms_departments')->where('sub_institute_id', self::TENANT)->count() - count($list),
            'The fixture must contain rows the visibility scope excludes.',
        );
    }

    /** @test */
    public function the_home_metrics_tile_and_the_departments_screen_publish_one_number(): void
    {
        $this->seedTemplateAndSupersededUnits();

        $home = $this->withHeaders($this->auth())
            ->getJson('/api/v1/workspace/'.self::TENANT.'/home-metrics')->assertStatus(200)->json();

        $summary = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')->assertStatus(200)->json();

        $erp = $home['erp'] ?? $home;

        $this->assertSame($summary['departments']['active'], $erp['activeDepartments']);
        $this->assertSame($summary['people']['total'], $erp['activePeople']);
    }

    /**
     * Per-unit headcount covers exactly the units the screen shows, and the
     * screen is told how many staff sit outside them rather than being left to
     * infer it from a sum that does not add up.
     *
     * @test
     */
    public function per_department_headcount_covers_only_the_visible_units_and_says_so(): void
    {
        $this->seedTemplateAndSupersededUnits();

        $summary = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')->assertStatus(200)->json();

        $perDepartment = (array) $summary['peoplePerDepartment'];

        $this->assertArrayHasKey('100', $perDepartment, 'A visible unit must be counted.');
        $this->assertArrayNotHasKey('102', $perDepartment, 'A template unit must not be counted.');

        $this->assertSame(array_sum($perDepartment), $summary['people']['inVisibleUnits']);
        $this->assertLessThanOrEqual($summary['people']['total'], $summary['people']['inVisibleUnits']);
    }

    /** @test */
    public function the_summary_is_scoped_to_the_authenticated_tenant_not_the_url(): void
    {
        DB::table('institute_detail')->insert([
            'sub_institute_id' => 6, 'organization_name' => 'Other Org',
            'organization_code' => 'OTHER', 'industry_type' => 'Operations',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00',
        ]);
        (new EntityMappingSeeder(['6']))->run();

        DB::table('hrms_departments')->insert([
            'id' => 500, 'sub_institute_id' => 6, 'department' => 'Not Yours',
            'roles_responsibility' => null, 'parent_id' => 0, 'status' => 1,
            'created_by' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/6/summary')
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);

        $mine = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')->json();

        $this->assertArrayNotHasKey('500', (array) $mine['peoplePerDepartment']);
        $this->assertSame(self::TENANT, $mine['tenantId']);
    }

    /**
     * Students are not people.
     *
     * The Departments screen's "People" tile used to sum each unit's twin
     * headcount, and on a school tenant the twin substitutes a STUDENT count for
     * it — so that tile published thousands against an overview that meant
     * staff. Imported academic rows must never reach the people count.
     *
     * @test
     */
    public function imported_operational_records_never_become_people(): void
    {
        $before = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')->json('people.total');

        $this->seedImportedRecords(25);

        $after = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')->json('people.total');

        $this->assertSame($before, $after, '25 imported academic rows must not become 25 employees.');
    }

    // =====================================================================
    // The four populations, and what each number is a count OF
    // =====================================================================

    private function seedImportedRecords(int $n, string $tenant = self::TENANT): void
    {
        for ($i = 1; $i <= $n; $i++) {
            DB::table('hpbrain_operational_records')->insert([
                'id' => $tenant.'-rec-'.$i, 'tenant_id' => $tenant, 'dataset' => 'results',
                'natural_key' => 'K'.$i, 'subject_ref' => 'STU'.$i, 'metric_value' => 80,
                'row_hash' => hash('sha256', $tenant.$i),
                'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
            ]);
        }
    }

    /** @param array<int, array{ref: string, academic?: int, fees?: int}> $students */
    private function seedStudents(array $students, string $tenant = self::TENANT): void
    {
        foreach ($students as $student) {
            DB::table('hpbrain_students')->insert([
                'id' => $tenant.'-'.$student['ref'],
                'tenant_id' => $tenant,
                'student_ref' => $student['ref'],
                'student_name' => 'Child '.$student['ref'],
                'in_academic' => $student['academic'] ?? 1,
                'in_fees' => $student['fees'] ?? 0,
                'academic_records' => 10,
                'fee_records' => 2,
                'projected_at' => '2026-01-01 00:00:00',
            ]);
        }
    }

    /**
     * Four populations, four numbers, none of them derived from another.
     *
     * THE DEFECT THIS PINS. Lions' overview showed "People 1 · Departments 0 ·
     * Imported records 398,831" while its People screen showed 7,445 students.
     * Every figure was correct and tenant-scoped; the screen named none of them,
     * so it read as a contradiction. The fix is that all four are published
     * together and separately — never summed, never substituted for one another.
     *
     * @test
     */
    public function staff_departments_students_and_records_are_four_separate_counts(): void
    {
        $this->seedImportedRecords(40);
        $this->seedStudents([
            ['ref' => '10821', 'academic' => 1, 'fees' => 1],
            ['ref' => '11624', 'academic' => 1, 'fees' => 0],
            ['ref' => '11625', 'academic' => 0, 'fees' => 1],
        ]);

        $summary = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')->assertStatus(200)->json();

        // Staff comes from the ERP roster and did not move when students and
        // records arrived.
        $erpStaff = DB::table('tbluser')->where('sub_institute_id', self::TENANT)
            ->whereNull('deleted_at')->where('status', 1)->count();
        $this->assertSame($erpStaff, $summary['people']['total']);

        $this->assertSame(3, $summary['students']['total']);
        $this->assertSame(1, $summary['students']['inBothFiles'], 'One child is in both files.');
        $this->assertSame(40, $summary['records']['total'], '40 source rows describing 3 children.');

        // The relationship the overview must be able to state: many rows, fewer
        // children, and neither of them is the staff figure.
        $this->assertGreaterThan($summary['students']['total'], $summary['records']['total']);
        $this->assertNotSame($summary['students']['total'], $summary['people']['total']);
    }

    /**
     * The Organization overview publishes the same four numbers as the
     * Departments screen, in one response, so it can label them.
     *
     * @test
     */
    public function home_metrics_publishes_students_and_records_beside_staff(): void
    {
        $this->seedImportedRecords(40);
        $this->seedStudents([['ref' => 'A1'], ['ref' => 'A2']]);

        $home = $this->withHeaders($this->auth())
            ->getJson('/api/v1/workspace/'.self::TENANT.'/home-metrics')->assertStatus(200)->json();

        $summary = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')->json();

        $this->assertSame($summary['students']['total'], $home['imported']['students']);
        $this->assertSame($summary['records']['total'], $home['imported']['records']);
        $this->assertSame($summary['people']['total'], $home['erp']['activePeople']);
        $this->assertTrue($home['imported']['studentsSupported']);
    }

    /**
     * The same enrolment number in two organizations is two children.
     *
     * @test
     */
    public function students_and_records_never_leak_between_organizations(): void
    {
        DB::table('institute_detail')->insert([
            'sub_institute_id' => 6, 'organization_name' => 'Other School',
            'organization_code' => 'OTHER', 'industry_type' => 'Education',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00',
        ]);
        (new EntityMappingSeeder(['6']))->run();

        // Deliberately the SAME enrolment number and the same natural keys.
        $this->seedStudents([['ref' => '10821'], ['ref' => '11624']]);
        $this->seedStudents([['ref' => '10821']], '6');
        $this->seedImportedRecords(40);
        $this->seedImportedRecords(5, '6');

        $mine = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')->json();

        $this->assertSame(2, $mine['students']['total'], "The other organization's child must not be counted here.");
        $this->assertSame(40, $mine['records']['total']);

        $theirs = $this->withHeaders($this->auth('6'))
            ->getJson('/api/v1/departments/6/summary')->assertStatus(200)->json();

        $this->assertSame(1, $theirs['students']['total']);
        $this->assertSame(5, $theirs['records']['total']);
    }

    /**
     * A duplicate logical student cannot be counted twice, because the
     * projection cannot hold it twice.
     *
     * @test
     */
    public function the_same_child_in_two_source_files_is_one_student(): void
    {
        $this->seedStudents([['ref' => '10821', 'academic' => 1, 'fees' => 1]]);

        // The same enrolment number again — the row the fee file would produce.
        // UNIQUE (tenant_id, student_ref) is what makes this impossible.
        $second = fn () => DB::table('hpbrain_students')->insert([
            'id' => 'dupe', 'tenant_id' => self::TENANT, 'student_ref' => '10821',
            'student_name' => 'Child 10821', 'in_academic' => 0, 'in_fees' => 1,
            'academic_records' => 0, 'fee_records' => 2, 'projected_at' => '2026-01-01 00:00:00',
        ]);

        try {
            $second();
            $this->fail('A second row for the same enrolment number must be rejected by the unique key.');
        } catch (\Illuminate\Database\QueryException) {
            // Expected.
        }

        $summary = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')->json();

        $this->assertSame(1, $summary['students']['total']);
    }

    /**
     * An organization with no HR departments reports zero — and the zero is
     * about departments, not about the imported data it does hold.
     *
     * @test
     */
    public function an_organization_with_no_hr_departments_reports_zero_while_still_holding_students(): void
    {
        DB::table('hrms_departments')->where('sub_institute_id', self::TENANT)->delete();

        $this->seedStudents([['ref' => 'S1'], ['ref' => 'S2'], ['ref' => 'S3']]);
        $this->seedImportedRecords(12);

        $summary = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')->assertStatus(200)->json();

        $this->assertSame(0, $summary['departments']['total']);
        $this->assertSame(0, $summary['departments']['active']);
        $this->assertSame([], (array) $summary['peoplePerDepartment']);

        // The empty department list is not an empty organization.
        $this->assertSame(3, $summary['students']['total']);
        $this->assertSame(12, $summary['records']['total']);

        $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT)
            ->assertStatus(200)
            ->assertJsonCount(0);
    }
}
