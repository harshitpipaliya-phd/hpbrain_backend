<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Capability\DemandService;
use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * Phase 5: demand, deficit, and the daily series.
 *
 * The assertions that matter most are the NULL ones. "We are 40 short" and "we
 * have never measured" are different claims, and the second rendered as the
 * first is the most damaging thing this system could do — it would send someone
 * to fix a shortfall that may not exist, and look authoritative doing it.
 */
final class DemandAndSnapshotTest extends TestCase
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

        // People 1 and 2 hold job role 1; the fixture gives every person
        // jobtitle_id 1.
        DB::table('hpbrain_job_role_capability_requirements')->insert([
            'tenant_id' => self::TENANT, 'job_role_id' => '1',
            'capability_id' => 'cap-triage', 'required_level' => 4.0,
        ]);
    }

    private function demand(): DemandService
    {
        return app(DemandService::class);
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        return $this->demand()->keyedByCapability(self::TENANT);
    }

    private function assign(string $id, string $capability, string $target): void
    {
        DB::table('hpbrain_capability_assignments')->insert([
            'id' => $id, 'tenant_id' => self::TENANT, 'capability_id' => $capability,
            'target_type' => 'Person', 'target_id' => $target,
            'assigned_by' => 'test', 'assigned_date' => '2026-03-01 00:00:00', 'status' => 'active',
        ]);
    }

    private function assess(string $assignmentId, ?float $level, string $date = '2026-04-01 00:00:00'): void
    {
        DB::table('hpbrain_capability_proficiency')->insert([
            'id' => 'p-'.$assignmentId.'-'.substr(md5($date), 0, 6),
            'tenant_id' => self::TENANT, 'assignment_id' => $assignmentId,
            'knowledge_level' => $level, 'ability_level' => $level, 'skill_level' => $level,
            'behaviour_level' => $level, 'attitude_level' => $level,
            'assessed_date' => $date, 'created_date' => $date,
        ]);
    }

    // ---- demand ----------------------------------------------------------

    /** @test */
    public function demand_is_headcount_times_required_level(): void
    {
        // Five active people all hold job role 1, required level 4.
        $row = $this->rows()['cap-triage'];

        $this->assertEqualsWithDelta(20.0, $row['demand'], 0.0001);
        $this->assertSame(5, $row['headcount']);
        $this->assertEqualsWithDelta(4.0, $row['requiredLevel'], 0.0001);
    }

    /** @test */
    public function a_capability_with_no_assessments_reports_null_deficit_not_a_negative_one(): void
    {
        // THE assertion of this phase. Demand is 20 and supply is unknown; the
        // shortfall is unknown, not 20.
        $row = $this->rows()['cap-triage'];

        $this->assertNotNull($row['demand']);
        $this->assertNull($row['supply']);
        $this->assertNull($row['deficit'], 'Unmeasured supply must not produce a negative deficit.');
        $this->assertSame(0, $row['assessedCount']);
    }

    /** @test */
    public function deficit_is_supply_minus_demand_once_both_are_known(): void
    {
        $this->assign('a1', 'cap-triage', '1');
        $this->assess('a1', 3.0);
        $this->assign('a2', 'cap-triage', '2');
        $this->assess('a2', 5.0);

        $row = $this->rows()['cap-triage'];

        // Two assessed people, means 3 and 5, so supply is 8 against demand 20.
        $this->assertEqualsWithDelta(8.0, $row['supply'], 0.0001);
        $this->assertEqualsWithDelta(-12.0, $row['deficit'], 0.0001);
        $this->assertSame(2, $row['assessedCount']);
    }

    /** @test */
    public function only_the_latest_assessment_per_assignment_counts(): void
    {
        $this->assign('a1', 'cap-triage', '1');
        $this->assess('a1', 1.0, '2026-01-01 00:00:00');
        $this->assess('a1', 4.0, '2026-06-01 00:00:00');

        // A person reassessed twice contributes once, at their most recent level.
        $this->assertEqualsWithDelta(4.0, $this->rows()['cap-triage']['supply'], 0.0001);
    }

    /** @test */
    public function an_assignment_with_no_assessed_dimension_contributes_nothing_not_zero(): void
    {
        $this->assign('a1', 'cap-triage', '1');
        $this->assess('a1', 4.0);
        $this->assign('a2', 'cap-triage', '2');
        $this->assess('a2', null);

        $row = $this->rows()['cap-triage'];

        // Averaging the unassessed one in as zero would halve the organization's
        // supply through the act of creating an assignment.
        $this->assertEqualsWithDelta(4.0, $row['supply'], 0.0001);
        $this->assertSame(1, $row['assessedCount']);
    }

    /** @test */
    public function coverage_is_null_when_nobody_needs_the_capability(): void
    {
        $this->assign('a1', 'cap-orphan', '1');
        $this->assess('a1', 3.0);

        $row = $this->rows()['cap-orphan'];

        // No requirement, so no headcount needing it. A coverage of "none of
        // nobody" is not zero coverage.
        $this->assertNull($row['demand']);
        $this->assertNull($row['coverage']);
        $this->assertNull($row['deficit']);
        $this->assertNotNull($row['supply']);
    }

    /** @test */
    public function coverage_is_the_assessed_share_of_those_who_need_it(): void
    {
        $this->assign('a1', 'cap-triage', '1');
        $this->assess('a1', 3.0);

        // One of five people who need it has been measured.
        $this->assertEqualsWithDelta(0.2, $this->rows()['cap-triage']['coverage'], 0.0001);
    }

    // ---- snapshots -------------------------------------------------------

    /** @test */
    public function the_snapshot_command_writes_a_row_per_metric(): void
    {
        $this->artisan('brain:snapshot', ['--date' => '2026-08-04'])->assertExitCode(0);

        $keys = DB::table('hpbrain_metric_snapshots')
            ->where('tenant_id', self::TENANT)->distinct()->pluck('metric_key')->all();
        sort($keys);

        foreach ([
            'capability.coverage', 'capability.deficit', 'evidence.meanConfidence',
            'memory.learnings', 'recommendations.pending', 'score.decisionAcceptance',
            'signals.high', 'signals.open',
        ] as $expected) {
            $this->assertContains($expected, $keys);
        }
    }

    /** @test */
    public function the_snapshot_is_idempotent_within_a_day(): void
    {
        // The unique index cannot enforce this: dimension_key is nullable and
        // two NULLs are not equal in SQL, so a second run would duplicate every
        // dimensionless series and double-count it in any chart.
        $this->artisan('brain:snapshot', ['--date' => '2026-08-04'])->assertExitCode(0);
        $first = DB::table('hpbrain_metric_snapshots')->count();

        $this->artisan('brain:snapshot', ['--date' => '2026-08-04'])->assertExitCode(0);

        $this->assertSame($first, DB::table('hpbrain_metric_snapshots')->count());
    }

    /** @test */
    public function a_rate_with_no_denominator_is_stored_as_null_not_zero(): void
    {
        $this->artisan('brain:snapshot', ['--date' => '2026-08-04'])->assertExitCode(0);

        // No decisions exist, so acceptance has no denominator.
        $row = DB::table('hpbrain_metric_snapshots')
            ->where('tenant_id', self::TENANT)
            ->where('metric_key', 'score.decisionAcceptance')
            ->first();

        $this->assertNotNull($row, 'The metric should be recorded even when unmeasurable.');
        $this->assertNull($row->value, 'A rate over nothing is null, never 0.');
        $this->assertSame(0, (int) $row->sample_n);
    }

    /** @test */
    public function an_unmeasured_deficit_is_snapshotted_as_null(): void
    {
        $this->artisan('brain:snapshot', ['--date' => '2026-08-04'])->assertExitCode(0);

        $row = DB::table('hpbrain_metric_snapshots')
            ->where('metric_key', 'capability.deficit')
            ->where('dimension_key', 'cap-triage')
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->value);
    }

    /** @test */
    public function separate_days_form_a_series(): void
    {
        $this->artisan('brain:snapshot', ['--date' => '2026-08-01'])->assertExitCode(0);

        DB::table('hpbrain_signals')->insert([
            'id' => 's1', 'tenant_id' => self::TENANT, 'source' => 'test',
            'classification' => 'x', 'priority' => 'low', 'severity' => 'low',
            'status' => 'new', 'created_by' => 'test', 'created_date' => '2026-08-02 00:00:00',
        ]);

        $this->artisan('brain:snapshot', ['--date' => '2026-08-02'])->assertExitCode(0);

        $series = DB::table('hpbrain_metric_snapshots')
            ->where('tenant_id', self::TENANT)->where('metric_key', 'signals.open')
            ->orderBy('snapshot_date')->pluck('value')->map(fn ($v) => (float) $v)->all();

        $this->assertSame([0.0, 1.0], $series);
    }

    // ---- the trend endpoint ----------------------------------------------

    /** @test */
    public function the_trend_endpoint_returns_a_real_series(): void
    {
        $this->artisan('brain:snapshot', ['--date' => '2026-08-03'])->assertExitCode(0);
        $this->artisan('brain:snapshot', ['--date' => '2026-08-04'])->assertExitCode(0);

        $token = Jwt::issueAccess(['id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin']);

        $body = $this->getJson(
            '/api/v1/analytics/'.self::TENANT.'/trend?metric=signals.open&days=3650',
            ['Authorization' => 'Bearer '.$token],
        )->assertStatus(200)->json();

        $this->assertSame('signals.open', $body['metric']);
        $this->assertSame(2, $body['points']);
        $this->assertCount(2, $body['series']['__all__']);
        $this->assertSame('2026-08-03', $body['series']['__all__'][0]['date']);
    }

    /** @test */
    public function the_trend_endpoint_returns_nulls_as_nulls(): void
    {
        $this->artisan('brain:snapshot', ['--date' => '2026-08-04'])->assertExitCode(0);

        $token = Jwt::issueAccess(['id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin']);

        $body = $this->getJson(
            '/api/v1/analytics/'.self::TENANT.'/trend?metric=score.decisionAcceptance&days=3650',
            ['Authorization' => 'Bearer '.$token],
        )->assertStatus(200)->json();

        // A day with no denominator is a GAP in the series. Coalescing it to 0
        // would draw a flat line along the bottom and call it a measurement.
        $this->assertNull($body['series']['__all__'][0]['value']);
    }

    /** @test */
    public function the_trend_endpoint_names_what_it_has_when_no_metric_is_given(): void
    {
        $this->artisan('brain:snapshot', ['--date' => '2026-08-04'])->assertExitCode(0);

        $token = Jwt::issueAccess(['id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin']);

        $body = $this->getJson(
            '/api/v1/analytics/'.self::TENANT.'/trend',
            ['Authorization' => 'Bearer '.$token],
        )->assertStatus(422)->json();

        $this->assertSame('metric_required', $body['error']);
        $this->assertContains('signals.open', $body['available']);
    }
}
