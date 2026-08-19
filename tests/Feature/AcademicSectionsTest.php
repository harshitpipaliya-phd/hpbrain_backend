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
 * The Departments screen for a school: four teaching sections derived from the
 * standards its students are actually recorded in.
 *
 * WHAT THIS REPLACES. Lions has 7,445 children and zero rows in the connected HR
 * system, so its Departments screen was correctly and uselessly empty; the
 * alternative on offer was rendering every academic dimension the imported files
 * carry — standard, division, batch, quota, subject, exam, academic year — as
 * its own "department", which is dozens of cards answering nothing.
 *
 * THE PROPERTIES THAT MATTER, and what each test below pins:
 *
 *   1. The sections RECONCILE. Their student counts sum to the People screen's
 *      total, with anything unreadable reported as unplaced rather than
 *      dropped. A grouping that quietly loses children is worse than no
 *      grouping.
 *   2. Two spellings of one grade are one grade. The results export writes
 *      "CBSE-9" and the fee register writes "IX"; both are standard 9.
 *   3. Nothing is written to the HR system. These are derived, not stored.
 *   4. One school never sees another's children.
 */
final class AcademicSectionsTest extends TestCase
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
     * @param  array<int, array{ref: string, academic?: ?string, standard?: ?string, avg?: ?float, paid?: float}>  $students
     */
    private function seedStudents(array $students, string $tenant = self::TENANT): void
    {
        foreach ($students as $s) {
            DB::table('hpbrain_students')->insert([
                'id' => $tenant.'-'.$s['ref'],
                'tenant_id' => $tenant,
                'student_ref' => $s['ref'],
                'student_name' => 'Child '.$s['ref'],
                'academic_standard' => $s['academic'] ?? null,
                'standard' => $s['standard'] ?? null,
                'in_academic' => ($s['academic'] ?? null) === null ? 0 : 1,
                'in_fees' => ($s['standard'] ?? null) === null ? 0 : 1,
                'academic_records' => 4,
                'fee_records' => 2,
                'avg_percentage' => $s['avg'] ?? null,
                'total_paid' => $s['paid'] ?? 1000,
                'projected_at' => '2026-01-01 00:00:00',
            ]);
        }
    }

    /** One student in every band, spelled both ways. */
    private function seedOnePerBand(): void
    {
        $this->seedStudents([
            ['ref' => 'K1', 'standard' => 'SR'],                    // ambiguous code — unplaced, never guessed
            ['ref' => 'P1', 'academic' => 'CBSE-3'],                // primary
            ['ref' => 'P2', 'standard' => 'V'],                     // primary, roman
            ['ref' => 'M1', 'academic' => 'CBSE-7'],                // middle
            ['ref' => 'S1', 'standard' => 'IX'],                    // secondary, roman
            ['ref' => 'S2', 'academic' => 'CBSE-10'],               // secondary
            ['ref' => 'H1', 'academic' => 'CBSE-12'],               // higher secondary
        ]);
    }

    /** @test */
    public function students_are_grouped_into_the_school_sections_not_one_per_standard(): void
    {
        $this->seedOnePerBand();

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/sections')
            ->assertStatus(200)->json();

        $byName = collect($body['sections'])->keyBy('name');

        $this->assertSame(
            ['Primary Section', 'Middle School', 'Secondary Section', 'Higher Secondary'],
            collect($body['sections'])->pluck('name')->all(),
            'Exactly four sections, in school order.',
        );

        $this->assertSame(2, $byName['Primary Section']['students'], 'CBSE-3 and V are both primary.');
        $this->assertSame(1, $byName['Middle School']['students']);
        $this->assertSame(2, $byName['Secondary Section']['students'], 'IX and CBSE-10 are both secondary.');
        $this->assertSame(1, $byName['Higher Secondary']['students']);

        $this->assertSame('Standards 9–10', $byName['Secondary Section']['standards']);
    }

    /**
     * "CBSE-9" and "IX" are the same standard, and a student carrying both is
     * placed once.
     *
     * @test
     */
    public function the_two_spellings_of_a_standard_resolve_to_the_same_section(): void
    {
        $this->seedStudents([
            ['ref' => 'A', 'academic' => 'CBSE-9', 'standard' => 'IX'],
            ['ref' => 'B', 'academic' => 'CBSE-9'],
            ['ref' => 'C', 'standard' => 'IX'],
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/sections')->json();

        $secondary = collect($body['sections'])->firstWhere('name', 'Secondary Section');

        $this->assertSame(3, $secondary['students']);
        $this->assertCount(1, $body['sections'], 'Three students in one grade are one section, not three.');
    }

    /**
     * THE RECONCILIATION. Section totals plus anything unplaceable equal the
     * People screen's figure — always, including for grades the bands cannot
     * read.
     *
     * @test
     */
    public function section_totals_reconcile_with_the_student_count_on_every_other_screen(): void
    {
        $this->seedOnePerBand();
        // A spelling no band can read. It must be REPORTED, never dropped and
        // never guessed into a band.
        $this->seedStudents([['ref' => 'X1', 'standard' => 'REMEDIAL']]);

        $sections = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/sections')->json();

        $summary = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')->json();

        $carded = collect($sections['sections'])->sum('students');

        // 'REMEDIAL' and the bare 'SR' — two codes no band may read.
        $this->assertSame(2, $sections['totals']['unplaced'], 'Unreadable grades are reported, not dropped.');
        $this->assertSame(8, $sections['totals']['students']);
        $this->assertSame($carded + $sections['totals']['unplaced'], $sections['totals']['students']);

        // And that total is the same number the Organization overview and the
        // People screen publish.
        $this->assertSame($summary['students']['total'], $sections['totals']['students']);
    }

    /** A band nobody is in is not a section this school runs. */
    /** @test */
    public function an_empty_band_produces_no_card(): void
    {
        $this->seedStudents([['ref' => 'S1', 'academic' => 'CBSE-9']]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/sections')->json();

        $this->assertCount(1, $body['sections']);
        $this->assertSame('Secondary Section', $body['sections'][0]['name']);
    }

    /**
     * Deriving sections must not create HR departments.
     *
     * @test
     */
    public function nothing_is_written_to_the_hr_system(): void
    {
        $before = DB::table('hrms_departments')->count();

        $this->seedOnePerBand();
        $this->withHeaders($this->auth())->getJson('/api/v1/departments/'.self::TENANT.'/sections')->assertStatus(200);

        $this->assertSame($before, DB::table('hrms_departments')->count());
        $this->assertSame(
            0,
            DB::table('hrms_departments')->whereIn('department', [
                'Primary Section', 'Middle School', 'Secondary Section', 'Higher Secondary',
            ])->count(),
        );
    }

    /**
     * A section's drill-down returns that section's children and no others.
     *
     * @test
     */
    public function the_student_list_filtered_by_section_returns_only_that_bands_children(): void
    {
        $this->seedOnePerBand();

        $page = $this->withHeaders($this->auth())
            ->getJson('/api/v1/students/'.self::TENANT.'?section=secondary&page_size=50')
            ->assertStatus(200)->json();

        $this->assertSame(2, $page['total']);
        $this->assertEqualsCanonicalizing(['S1', 'S2'], collect($page['data'])->pluck('studentRef')->all());
    }

    /**
     * An unrecognised section is refused, not ignored.
     *
     * Ignoring it would return the whole school under a section heading — an
     * answer that looks right and is not.
     *
     * @test
     */
    public function an_unknown_section_is_rejected_rather_than_silently_widened(): void
    {
        $this->seedOnePerBand();

        $this->withHeaders($this->auth())
            ->getJson('/api/v1/students/'.self::TENANT.'?section=not-a-section')
            ->assertStatus(422)
            ->assertJson(['error' => 'unknown_section']);
    }

    /**
     * One school never sees another's children — including when both use the
     * same enrolment numbers and the same standards.
     *
     * @test
     */
    public function sections_are_scoped_to_the_authenticated_organization(): void
    {
        DB::table('institute_detail')->insert([
            'sub_institute_id' => 6, 'organization_name' => 'Other School',
            'organization_code' => 'OTHER', 'industry_type' => 'Education',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00',
        ]);
        (new EntityMappingSeeder(['6']))->run();

        $this->seedStudents([
            ['ref' => 'S1', 'academic' => 'CBSE-9'],
            ['ref' => 'S2', 'academic' => 'CBSE-10'],
        ]);
        // Same refs, same standards, different school.
        $this->seedStudents([
            ['ref' => 'S1', 'academic' => 'CBSE-9'],
            ['ref' => 'S2', 'academic' => 'CBSE-9'],
            ['ref' => 'S3', 'academic' => 'CBSE-9'],
        ], '6');

        $mine = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/sections')->json();
        $this->assertSame(2, collect($mine['sections'])->firstWhere('name', 'Secondary Section')['students']);

        $theirs = $this->withHeaders($this->auth('6'))
            ->getJson('/api/v1/departments/6/sections')->assertStatus(200)->json();
        $this->assertSame(3, collect($theirs['sections'])->firstWhere('name', 'Secondary Section')['students']);

        // The URL is not the authority: asking for someone else's school with
        // my token is refused, not answered.
        $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/6/sections')
            ->assertStatus(403);

        // And the drill-down obeys the same boundary.
        $page = $this->withHeaders($this->auth())
            ->getJson('/api/v1/students/'.self::TENANT.'?section=secondary&page_size=50')->json();
        $this->assertSame(2, $page['total']);
    }

    /**
     * An organization with no student data gets no sections, and no zeroes
     * pretending to be sections.
     *
     * @test
     */
    public function a_non_school_organization_gets_no_sections(): void
    {
        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/sections')
            ->assertStatus(200)->json();

        $this->assertSame([], $body['sections']);
        $this->assertSame(0, $body['totals']['students']);
    }

    /**
     * A section's average is the average of its CHILDREN, not of its grades,
     * and is null rather than zero when nobody has a mark.
     *
     * @test
     */
    public function the_section_average_is_weighted_and_never_a_stand_in_zero(): void
    {
        $this->seedStudents([
            ['ref' => 'A', 'academic' => 'CBSE-9', 'avg' => 90.0],
            ['ref' => 'B', 'academic' => 'CBSE-9', 'avg' => 80.0],
            ['ref' => 'C', 'academic' => 'CBSE-10', 'avg' => 40.0],
            // No mark recorded. Must not be averaged in as a zero.
            ['ref' => 'D', 'academic' => 'CBSE-10', 'avg' => null],
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/sections')->json();

        $secondary = collect($body['sections'])->firstWhere('name', 'Secondary Section');

        // (90 + 80 + 40) / 3 = 70. Averaging D in as a zero gives 52.5; weighting
        // grade 10's AVG by its two students gives 62.5. Both are wrong.
        $this->assertEqualsWithDelta(70.0, $secondary['averagePercentage'], 0.05);
        $this->assertSame(3, $secondary['gradedStudents']);
        $this->assertSame(4, $secondary['students'], 'The unexamined child is still in the section.');
    }
}
