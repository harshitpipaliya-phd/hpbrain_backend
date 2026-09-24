<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\SeedsEntityMappings;
use Tests\TestCase;

/**
 * GET /people/{tenantId}/{id}/intelligence — the redesigned Person Profile payload.
 *
 * The fixture deliberately crosses the two attachment columns (owner_name vs
 * supervisor_name) and the two presence datasets (field_attendance "Present"
 * vs an absent record on the same day) so the mismatch detector (D3) has
 * something to find. The tests pin the contract the OpenAPI yaml declares.
 */
final class PersonIntelligenceTest extends TestCase
{
    use BuildsBrainSchema;
    use SeedsEntityMappings;

    private const TENANT = '900';
    private const OTHER_TENANT = '901';
    private const PERSON = 11;
    private const OTHER = 21;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildErpTables();
        $this->installEntityMappings([self::TENANT, self::OTHER_TENANT]);
        $this->seedErp();
        $this->seedRecords();
    }

    public function test_endpoint_returns_the_standing_band_with_a_real_score_when_anything_is_measurable(): void
    {
        $payload = $this->intelligence()->json();

        $this->assertSame(self::PERSON, (int) $payload['person']['id']);
        $this->assertSame('Aanya Sharma', $payload['person']['name']);
        $this->assertSame('Northgate School', $payload['person']['orgName']);
        $this->assertContains($payload['standing']['band'], ['steady', 'watch', 'support', 'undetermined']);
    }

    public function test_confidence_ring_counts_only_measurable_dimensions(): void
    {
        $confidence = $this->intelligence()->json('confidence');

        $this->assertIsInt($confidence['measurableDimensions']);
        $this->assertGreaterThan(0, $confidence['measurableDimensions']);
        $this->assertLessThanOrEqual($confidence['totalDimensions'], $confidence['measurableDimensions']);
        $this->assertGreaterThan(0, $confidence['totalDimensions']);
    }

    public function test_since_refresh_changes_respect_the_3_4_cell_cap(): void
    {
        $changes = $this->intelligence()->json('sinceRefresh.changes');

        $this->assertIsArray($changes);
        $this->assertLessThanOrEqual(4, count($changes));
        foreach ($changes as $c) {
            $this->assertContains($c['direction'], ['up', 'down', 'flat']);
            $this->assertNotEmpty($c['label']);
        }
    }

    public function test_contribution_block_reports_weekly_trend_and_team_share(): void
    {
        $contrib = $this->intelligence()->json('contribution');

        $this->assertSame(8, count($contrib['weeklyTrend']));
        $this->assertGreaterThan(0, $contrib['handledTotal']);
        $this->assertIsInt($contrib['supervisedCount']);
        $this->assertIsBool($contrib['highLoad']);
    }

    /**
     * REGRESSION: the Contribution card and the Records tab must agree.
     *
     * `handledTotal` was the sum of the 8-week trend, so on a tenant whose
     * import covers a finished period it read 0 while the Records tab listed
     * every one of the same person's rows. One screen, two numbers for one set,
     * disagreeing. The total counts every handled record; the trend keeps its
     * window, because "how steady is the load" is a question about recent weeks
     * and "how much have they handled" is not.
     */
    public function test_handled_total_counts_every_handled_record_not_only_the_trend_window(): void
    {
        $tenant = self::TENANT;
        $today = now()->toDateString();

        // A record well outside the 8-week trend window, same owner.
        DB::table('hpbrain_operational_records')->insert([
            $this->row(
                'old-1',
                $tenant,
                'work_order',
                'W-OLD-1',
                $today,
                now()->subMonths(10)->format('Y-m-d H:i:s'),
                'closed',
                null,
                'Aanya Sharma',
            ),
        ]);

        $payload = $this->intelligence()->json();
        $contrib = $payload['contribution'];

        $attached = (int) DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenant)
            ->where('owner_name', 'Aanya Sharma')
            ->count();

        $this->assertSame($attached, $contrib['handledTotal'], 'handledTotal must count every attached record.');
        $this->assertGreaterThan(
            array_sum($contrib['weeklyTrend']),
            $contrib['handledTotal'],
            'The out-of-window record must be counted in the total but not in the 8-week trend.',
        );
        $this->assertSame($contrib['handledTotal'], $payload['recordsPage']['total'], 'The Contribution card and the Records tab must report the same set.');
    }

    public function test_presence_block_reports_mismatch_pattern_when_cross_source_dates_disagree(): void
    {
        $payload = $this->intelligence()->json();

        $this->assertGreaterThanOrEqual(1, $payload['consistency']['mismatches']['count']);
        $this->assertNotEmpty($payload['consistency']['mismatches']['sampleDates']);
        $this->assertStringContainsString('device sync', $payload['consistency']['mismatches']['likelyCause']);
    }

    /**
     * REGRESSION: a numeric flag column must not manufacture contradictions.
     *
     * This ERP exports employee_checkin with status "0" on every row. Read as
     * "absent" it disagreed with the attendance dataset on every working day —
     * 163 invented contradictions for a person present on 97% of their recorded
     * days, capping the standing penalty and moving them a band.
     */
    public function test_a_numeric_status_flag_does_not_count_as_an_absence(): void
    {
        $tenant = self::TENANT;
        $today = now()->toDateString();
        $day = now()->subDays(9)->format('Y-m-d');

        // A day the attendance dataset calls Present, beside a check-in row
        // carrying the source system's "0" flag.
        DB::table('hpbrain_operational_records')->insert([
            $this->row('flag-att', $tenant, 'field_attendance', 'A-FLAG', $today, $day, 'Present', 8.0, 'Aanya Sharma'),
            $this->row('flag-chk', $tenant, 'employee_checkin', 'C-FLAG', $today, $day, '0', null, 'Aanya Sharma'),
        ]);

        $payload = $this->intelligence()->json();

        $this->assertNotContains(
            $day,
            $payload['consistency']['mismatches']['sampleDates'],
            'A numeric flag is not an attendance state and must not contradict a Present record.',
        );

        foreach ($payload['recordsPage']['items'] as $row) {
            if (in_array($row['recordKey'], ['A-FLAG', 'C-FLAG'], true)) {
                $this->assertFalse($row['mismatch'], 'A flag-column day must not be tinted as a mismatch.');
            }
        }
    }

    /**
     * REGRESSION: hours are null when unrecorded, and averaged per day.
     *
     * The average was the 8-week hours total divided by 60 days of present
     * records, and the week index was computed from a signed week difference
     * that pushed every figure out of the window — so a person averaging 9.8
     * hours read "0h". Zero hours worked and no hours recorded are opposite
     * findings (R2), and only one of them is about the person.
     */
    public function test_average_hours_is_per_day_and_null_when_no_hours_are_recorded(): void
    {
        $presence = $this->intelligence()->json('presence');

        // The fixture records 8.5h and 8.0h days, so the average must land in
        // a day's range — never a multi-day total, never a zero.
        $this->assertNotNull($presence['avgHours'], 'Hours are on file, so an average must be reported.');
        $this->assertGreaterThan(0, $presence['avgHours']);
        $this->assertLessThanOrEqual(24, $presence['avgHours'], 'An average day cannot exceed 24 hours.');

        // The long-hours threshold is a day's length, so an ordinary week must
        // not trip it.
        $this->assertFalse($presence['longHoursFlag'], '8.5h days are below the 9.5h threshold.');
    }

    public function test_capability_renders_undetermined_when_no_assessment_is_on_file(): void
    {
        $capability = $this->intelligence()->json('capability');

        $this->assertSame('UNDETERMINED', $capability['trajectory']);
        $this->assertSame('UNDETERMINED', $capability['vsRole']);
    }

    public function test_loop_counts_are_present_and_zero_when_uninvolved(): void
    {
        $loop = $this->intelligence()->json('loop');

        $this->assertSame(0, $loop['signals']);
        $this->assertSame(0, $loop['cases']);
        $this->assertSame(0, $loop['decisions']);
        $this->assertSame(0, $loop['executions']);
    }

    public function test_records_page_is_paginated_and_marks_mismatch_rows(): void
    {
        $page = $this->intelligence()->json('recordsPage');

        $this->assertSame(1, $page['page']);
        $this->assertGreaterThan(0, $page['total']);
        $this->assertNotEmpty($page['items']);

        $hasMismatch = false;
        foreach ($page['items'] as $row) {
            $this->assertArrayHasKey('mismatch', $row);
            if ($row['mismatch'] === true) {
                $hasMismatch = true;
            }
        }
        $this->assertTrue($hasMismatch, 'mismatch row should be present in page 1');
    }

    public function test_blind_spots_are_listed_when_dimensions_are_unmeasurable(): void
    {
        $spots = $this->intelligence()->json('blindSpots');

        $this->assertIsArray($spots);
        foreach ($spots as $spot) {
            $this->assertNotEmpty($spot['dimension']);
            $this->assertNotEmpty($spot['reason']);
            $this->assertNotEmpty($spot['fixLabel']);
            $this->assertNotEmpty($spot['fixRoute']);
        }
    }

    public function test_score_explain_reproduces_the_backend_formula(): void
    {
        $explain = $this->intelligence()->json('scoreExplain');
        $standing = $this->intelligence()->json('standing');

        $sum = 0.0;
        foreach ($explain['components'] as $c) {
            $this->assertArrayHasKey('valuePct', $c);
            $this->assertArrayHasKey('weight', $c);
            $this->assertArrayHasKey('points', $c);
            $sum += $c['points'];
        }

        if ($explain['penalty'] !== null) {
            $sum -= (float) $explain['penalty']['points'];
        }
        $sum = max(0.0, min(100.0, round($sum, 1)));

        $this->assertSame($standing['score'], $explain['total']);
    }

    public function test_volume_does_not_rank_while_role_unassigned(): void
    {
        $payload = $this->intelligence()->json();

        $this->assertFalse($payload['person']['roleAssigned']);

        $reasons = $payload['standing']['reason'] ?? '';
        $this->assertStringContainsString('role unassigned', $reasons);
        $this->assertStringContainsString('not used as ranking', $reasons);
    }

    public function test_404_when_person_belongs_to_another_tenant(): void
    {
        $this->getJson('/api/v1/people/'.self::OTHER_TENANT.'/'.self::PERSON.'/intelligence', $this->auth())
            ->assertStatus(403);
    }

    public function test_404_for_a_missing_person(): void
    {
        $this->getJson('/api/v1/people/'.self::TENANT.'/9999/intelligence', $this->auth())
            ->assertStatus(404);
    }

    // ---- Fixture -----------------------------------------------------------

    private function intelligence(int $personId = self::PERSON): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/people/'.self::TENANT.'/'.$personId.'/intelligence', $this->auth())
            ->assertStatus(200);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    private function buildErpTables(): void
    {
        Schema::create('institute_detail', function ($t) {
            $t->string('sub_institute_id');
            $t->string('organization_name')->nullable();
            $t->string('organization_code')->nullable();
            $t->string('industry_type')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('hrms_departments', function ($t) {
            $t->integer('id')->primary();
            $t->string('sub_institute_id');
            $t->string('department');
            $t->integer('parent_id')->default(0);
            $t->integer('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('tbluserprofilemaster', function ($t) {
            $t->integer('id')->primary();
            $t->string('sub_institute_id');
            $t->string('name');
            $t->integer('status')->default(1);
        });

        Schema::create('tbluser', function ($t) {
            $t->integer('id')->primary();
            $t->string('employee_no')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('mobile')->nullable();
            $t->integer('department_id')->nullable();
            $t->integer('jobtitle_id')->nullable();
            $t->string('sub_institute_id');
            $t->integer('user_profile_id')->nullable();
            $t->integer('status')->default(1);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
    }

    private function seedErp(): void
    {
        DB::table('institute_detail')->insert([
            ['sub_institute_id' => self::TENANT, 'organization_name' => 'Northgate School', 'organization_code' => 'NGS', 'industry_type' => 'Education'],
            ['sub_institute_id' => self::OTHER_TENANT, 'organization_name' => 'Southgate School', 'organization_code' => 'SGS', 'industry_type' => 'Education'],
        ]);

        DB::table('hrms_departments')->insert([
            ['id' => 501, 'sub_institute_id' => self::TENANT, 'department' => 'CST'],
            ['id' => 502, 'sub_institute_id' => self::OTHER_TENANT, 'department' => 'CST'],
        ]);

        DB::table('tbluserprofilemaster')->insert([
            ['id' => 61, 'sub_institute_id' => self::TENANT, 'name' => 'Employee'],
            ['id' => 62, 'sub_institute_id' => self::OTHER_TENANT, 'name' => 'Employee'],
        ]);

        DB::table('tbluser')->insert([
            [
                'id' => self::PERSON, 'employee_no' => 'EMP-0011',
                'first_name' => 'Aanya', 'last_name' => 'Sharma',
                'email' => 'aanya@school.test', 'mobile' => '9000000011',
                'department_id' => 501, 'sub_institute_id' => self::TENANT, 'user_profile_id' => 61,
                'status' => 1, 'created_at' => '2025-06-01 08:00:00', 'updated_at' => '2025-06-02 08:00:00',
            ],
            [
                'id' => self::OTHER, 'employee_no' => 'EMP-0021',
                'first_name' => 'Aanya', 'last_name' => 'Sharma',
                'email' => 'aanya@other.test', 'mobile' => '9000000021',
                'sub_institute_id' => self::OTHER_TENANT,
                'department_id' => 502, 'user_profile_id' => 62, 'status' => 1,
                'created_at' => '2025-06-01 08:00:00', 'updated_at' => '2025-06-02 08:00:00',
            ],
        ]);
    }

    private function seedRecords(): void
    {
        $today = now()->toDateString();

        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = $this->row('rec-' . $i, self::TENANT, 'complaint', 'C-' . $i, $today, now()->subDays($i)->format('Y-m-d H:i:s'), 'closed', null, 'Aanya Sharma');
        }
        for ($i = 0; $i < 6; $i++) {
            $rows[] = $this->row('fa-' . $i, self::TENANT, 'field_attendance', 'A-' . $i, $today, now()->subDays($i)->format('Y-m-d'), 'Present', 8.5, 'Aanya Sharma');
        }

        $mismatchDay = now()->subDays(3)->format('Y-m-d');
        $rows[] = $this->row('mis-1', self::TENANT, 'field_attendance', 'A-M1', $today, $mismatchDay, 'Present', 8.0, 'Aanya Sharma');
        $rows[] = $this->row('mis-2', self::TENANT, 'work_order', 'W-M1', $today, $mismatchDay, 'absent', null, 'Aanya Sharma');
        $rows[] = $this->row('oth-1', self::OTHER_TENANT, 'complaint', 'O-1', $today, $mismatchDay, 'closed', null, 'Aanya Sharma');

        DB::table('hpbrain_operational_records')->insert($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id, string $tenant, string $dataset, string $key, string $today, string $occurredAt, string $status, ?float $metric, string $owner): array
    {
        return [
            'id' => $id, 'tenant_id' => $tenant, 'dataset' => $dataset, 'natural_key' => $key,
            'owner_name' => $owner, 'supervisor_name' => null, 'occurred_at' => $occurredAt,
            'status' => $status, 'category' => 'Test', 'sub_category' => null,
            'metric_value' => $metric, 'metric_unit' => 'hours', 'quantity' => null,
            'payload' => null, 'row_hash' => bin2hex(random_bytes(8)),
            'created_date' => $today, 'updated_date' => $today,
        ];
    }
}
