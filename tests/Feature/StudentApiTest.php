<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The student surface: paging, search, tenant isolation, and the two datasets.
 *
 * TWO TENANTS ARE SEEDED IN EVERY TEST, and that is the point of the fixture
 * rather than a detail of it. A school's roster is the most sensitive thing this
 * application holds, and a query that forgets its tenant filter returns a
 * plausible-looking page of the wrong children's names. Each test below asserts
 * both halves: that the caller sees their own students, and that the other
 * tenant's are absent — the second assertion is the one that catches a dropped
 * `where('tenant_id', …)`.
 *
 * THE ROUTE ORDER IS ASSERTED, NOT ASSUMED. `students/{tenantId}/{id}` was
 * registered before the literal /summary, /search and /structure, so all three
 * were swallowed and answered 404 as though the student did not exist. Each has
 * a test here that would fail again if the order regressed.
 */
final class StudentApiTest extends TestCase
{
    use BuildsBrainSchema;

    private const LIONS = 'tenant-school-a';
    private const SUNRISE = 'tenant-school-b';

    private const ACADEMIC = 'a-results';
    private const FEES = 'a-fees';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->seedTenantA();
        $this->seedTenantB();
    }

    private function auth(string $tenant): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-students', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    /** The tenant under test: two datasets, four students, one in both files. */
    private function seedTenantA(): void
    {
        $this->source(self::LIONS, self::ACADEMIC, 'academic');
        $this->source(self::LIONS, self::FEES, 'fees');

        // ARJUN is in both files; PRIYA results only; MEERA fees only.
        $this->student(self::LIONS, '1001', 'ARJUN SHARMA', [
            'in_academic' => 1, 'in_fees' => 1, 'academic_standard' => 'CBSE-2', 'standard' => 'IX',
            'division' => 'B', 'academic_records' => 12, 'fee_records' => 3, 'avg_percentage' => 88.50,
            'total_paid' => 30000, 'first_academic_year' => '2018', 'last_academic_year' => '2019',
            'first_receipt_date' => '2025-04-01', 'last_receipt_date' => '2025-06-01',
        ]);
        $this->student(self::LIONS, '1002', 'PRIYA NAIR', [
            'in_academic' => 1, 'academic_standard' => 'CBSE-1', 'academic_records' => 11, 'avg_percentage' => 41.25,
            'first_academic_year' => '2018', 'last_academic_year' => '2018',
        ]);
        $this->student(self::LIONS, '1003', 'MEERA IYER', [
            'in_fees' => 1, 'standard' => 'X', 'fee_records' => 2, 'total_paid' => 18000,
            'first_receipt_date' => '2025-05-01', 'last_receipt_date' => '2025-07-01',
        ]);

        // One result row and one receipt for ARJUN, plus the impossible score
        // the real export contains, so the anomaly path has something to find.
        $this->record(self::LIONS, self::ACADEMIC, '1001|2018|CBSE-2|MATHEMATICS|Written', [
            'subject_ref' => '1001', 'status' => 'CBSE-2', 'category' => 'MATHEMATICS', 'sub_category' => 'Written',
            'metric_value' => 158, 'quantity' => 180, 'occurred_at' => '2018-01-01 00:00:00',
            'payload' => ['syear' => '2018', 'student_name' => 'ARJUN SHARMA'],
        ]);
        $this->record(self::LIONS, self::ACADEMIC, '1001|2018|CBSE-2|SCIENCE|Activity', [
            'subject_ref' => '1001', 'status' => 'CBSE-2', 'category' => 'SCIENCE', 'sub_category' => 'Activity',
            'metric_value' => 35, 'quantity' => 30, 'occurred_at' => '2018-01-01 00:00:00',
            'payload' => ['syear' => '2018', 'student_name' => 'ARJUN SHARMA'],
        ]);
        $this->record(self::LIONS, self::FEES, '1|1001|R-77', [
            'subject_ref' => '1001', 'status' => 'IX', 'category' => 'UPI', 'sub_category' => 'April',
            'owner_name' => 'FRONT DESK', 'metric_value' => 10000, 'occurred_at' => '2025-04-01 00:00:00',
            'payload' => ['Receipt No' => 'R-77', 'Division' => 'B', 'Student Name' => 'ARJUN SHARMA'],
        ]);
    }

    /** A second school whose data must never appear in the first's responses. */
    private function seedTenantB(): void
    {
        $this->source(self::SUNRISE, 'b-results', 'academic');

        $this->student(self::SUNRISE, '1001', 'OTHER TENANT CHILD', [
            'in_academic' => 1, 'academic_standard' => 'CBSE-9', 'academic_records' => 20, 'avg_percentage' => 99.00,
        ]);
        $this->record(self::SUNRISE, 'b-results', '1001|2020|CBSE-9|MATHEMATICS|Written', [
            'subject_ref' => '1001', 'status' => 'CBSE-9', 'category' => 'OTHER TENANT SUBJECT',
            'sub_category' => 'Written', 'metric_value' => 99, 'quantity' => 100,
            'occurred_at' => '2020-01-01 00:00:00', 'payload' => ['syear' => '2020'],
        ]);
    }

    private function source(string $tenantId, string $key, string $role): void
    {
        DB::table('hpbrain_data_sources')->insert([
            'id' => $tenantId.'-'.$key, 'tenant_id' => $tenantId, 'source_key' => $key,
            'display_name' => $key, 'source_type' => 'dataset', 'is_active' => 1,
            'config' => json_encode(['dataset_role' => $role, 'dataset' => $key]),
            'created_by' => 'test', 'created_date' => '2026-01-01 00:00:00',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function student(string $tenantId, string $ref, string $name, array $attributes = []): void
    {
        DB::table('hpbrain_students')->insert(array_merge([
            'id' => $tenantId.'-'.$ref, 'tenant_id' => $tenantId, 'student_ref' => $ref,
            'student_name' => $name, 'projected_at' => '2026-01-01 00:00:00',
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function record(string $tenantId, string $dataset, string $key, array $attributes = []): void
    {
        $payload = $attributes['payload'] ?? [];
        unset($attributes['payload']);

        DB::table('hpbrain_operational_records')->insert(array_merge([
            'id' => substr(md5($tenantId.$dataset.$key), 0, 36),
            'tenant_id' => $tenantId, 'dataset' => $dataset, 'natural_key' => $key,
            'row_hash' => hash('sha256', $key), 'payload' => json_encode($payload),
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ], $attributes));
    }

    // ---- listing and paging ------------------------------------------------

    /** @test */
    public function it_returns_a_page_of_students_with_a_total(): void
    {
        $response = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'?page_size=2');

        $response->assertOk();
        $response->assertJsonPath('total', 3);
        $response->assertJsonPath('pageSize', 2);
        $this->assertCount(2, $response->json('data'));
    }

    /** @test */
    public function the_page_size_is_capped_however_large_the_request(): void
    {
        // Otherwise ?page_size=999999 is a full-table read wearing a page's
        // clothes, which is the exact failure this surface exists to prevent.
        $response = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'?page_size=999999');

        $response->assertOk();
        $this->assertSame(100, $response->json('pageSize'));
    }

    /** @test */
    public function a_student_of_another_tenant_is_never_listed(): void
    {
        $response = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'?page_size=100');

        $response->assertOk();
        $names = array_column($response->json('data'), 'studentName');
        $this->assertContains('ARJUN SHARMA', $names);
        $this->assertNotContains('OTHER TENANT CHILD', $names);
    }

    /** @test */
    public function search_matches_name_and_enrollment_number_within_the_tenant(): void
    {
        $byName = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'/search?q=PRIYA');

        $byName->assertOk();
        $this->assertSame(['PRIYA NAIR'], array_column($byName->json('data'), 'studentName'));

        // '1001' exists in BOTH tenants, so this is the sharpest isolation
        // check available: the same key must return only the caller's student.
        $byRef = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'/search?q=1001');

        $byRef->assertOk();
        $this->assertSame(['ARJUN SHARMA'], array_column($byRef->json('data'), 'studentName'));
    }

    /** @test */
    public function the_cohort_filter_splits_matched_from_single_file_students(): void
    {
        $headers = $this->auth(self::LIONS);

        $matched = $this->withHeaders($headers)->getJson('/api/v1/students/'.self::LIONS.'?cohort=matched');
        $matched->assertOk()->assertJsonPath('total', 1);
        $this->assertSame('ARJUN SHARMA', $matched->json('data.0.studentName'));

        $academicOnly = $this->withHeaders($headers)->getJson('/api/v1/students/'.self::LIONS.'?cohort=academicOnly');
        $academicOnly->assertOk()->assertJsonPath('total', 1);
        $this->assertSame('PRIYA NAIR', $academicOnly->json('data.0.studentName'));

        $feesOnly = $this->withHeaders($headers)->getJson('/api/v1/students/'.self::LIONS.'?cohort=feesOnly');
        $feesOnly->assertOk()->assertJsonPath('total', 1);
        $this->assertSame('MEERA IYER', $feesOnly->json('data.0.studentName'));
    }

    // ---- the literal routes that were being swallowed by /{id} -------------

    /** @test */
    public function summary_reports_cohorts_and_is_not_matched_as_a_student_id(): void
    {
        $response = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'/summary');

        $response->assertOk();
        $response->assertJsonPath('total', 3);
        $response->assertJsonPath('matched', 1);
        $response->assertJsonPath('academicOnly', 1);
        $response->assertJsonPath('feesOnly', 1);
        $response->assertJsonPath('datasets.academic', self::ACADEMIC);
        $response->assertJsonPath('datasets.fees', self::FEES);
    }

    /** @test */
    public function structure_returns_dataset_dimensions_not_departments(): void
    {
        $response = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'/structure');

        $response->assertOk();
        $response->assertJsonPath('kind', 'academic_structure');

        $keys = array_column($response->json('dimensions'), 'key');
        $this->assertContains('standard', $keys);
        $this->assertContains('subject', $keys);

        // The other tenant's subject must not appear in this tenant's structure.
        $this->assertStringNotContainsString('OTHER TENANT SUBJECT', json_encode($response->json()));
    }

    /** @test */
    public function intelligence_is_computed_from_this_tenants_rows_only(): void
    {
        $response = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'/intelligence');

        $response->assertOk();
        $response->assertJsonPath('cohorts.matched', 1);
        $response->assertJsonPath('availability.hasAcademic', true);
        $response->assertJsonPath('availability.hasFees', true);
        $this->assertStringNotContainsString('OTHER TENANT', json_encode($response->json()));
    }

    /** @test */
    public function fee_intelligence_never_reports_an_amount_the_source_does_not_contain(): void
    {
        $response = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'/intelligence');

        $response->assertOk();
        // Present as a named explanation of what cannot be derived...
        $this->assertArrayHasKey('outstanding', $response->json('fees.notDerivable'));
        // ...and absent as a figure.
        $this->assertNull($response->json('fees.outstanding'));
        $this->assertNull($response->json('fees.collectionRate'));
        $this->assertSame(10000.0, (float) $response->json('fees.totalCollected'));
    }

    // ---- one student -------------------------------------------------------

    /** @test */
    public function a_student_profile_carries_both_record_types(): void
    {
        $response = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'/'.self::LIONS.'-1001');

        $response->assertOk();
        $response->assertJsonPath('student.studentName', 'ARJUN SHARMA');
        $response->assertJsonPath('academicRecords.total', 2);
        $response->assertJsonPath('feeRecords.total', 1);
        $response->assertJsonPath('relationship.matched', true);
    }

    /** @test */
    public function a_profile_flags_records_that_describe_different_periods(): void
    {
        // Results 2018-2019, receipts from 2025. Presenting them as one current
        // picture of a child would be the single most misleading thing this
        // screen could do, so the server states that they are not.
        $response = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'/'.self::LIONS.'-1001');

        $response->assertOk();
        $response->assertJsonPath('relationship.contemporaneous', false);
    }

    /** @test */
    public function an_impossible_score_is_reported_rather_than_hidden(): void
    {
        $response = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'/'.self::LIONS.'-1001/academic-records');

        $response->assertOk();
        $rows = collect($response->json('data'));
        $anomalous = $rows->firstWhere('subject', 'SCIENCE');

        $this->assertTrue($anomalous['anomalous'], '35 of 30 marks must be flagged, not silently dropped.');
        $this->assertFalse($rows->firstWhere('subject', 'MATHEMATICS')['anomalous']);
    }

    /** @test */
    public function another_tenants_student_id_is_not_found(): void
    {
        $response = $this->withHeaders($this->auth(self::LIONS))
            ->getJson('/api/v1/students/'.self::LIONS.'/'.self::SUNRISE.'-1001');

        $response->assertStatus(404);
    }

    /** @test */
    public function an_organization_without_datasets_reports_no_students_rather_than_failing(): void
    {
        // The People and Departments screens both ask this question first for
        // every organization, including the ones with no dataset at all, so it
        // has to answer cleanly rather than error.
        $response = $this->withHeaders($this->auth('tenant-with-nothing'))
            ->getJson('/api/v1/students/tenant-with-nothing/summary');

        $response->assertOk();
        $response->assertJsonPath('total', 0);
        $response->assertJsonPath('datasets.academic', null);
    }
}
