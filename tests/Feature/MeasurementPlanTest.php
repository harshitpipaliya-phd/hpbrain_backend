<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Events\LoopEvent;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * Invariants 3 and 4.
 *
 * INVARIANT 4 — no ESO runs without a measurement plan defined BEFORE it
 * starts. The assertion that matters is the ORDERING one: a plan that
 * post-dates the run it governs is a post-hoc justification, and a check for
 * mere existence would wave it through.
 *
 * INVARIANT 3 — every action is executable. Until this phase the ESO binding
 * was validated and then discarded, because the column did not exist.
 *
 * SCHEMA IS BUILT HERE — see Tests\Support\BuildsBrainSchema for why
 * RefreshDatabase cannot run against this project's raw-MySQL migrations.
 */
final class MeasurementPlanTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-alpha';
    private const ACTOR  = 'user-manager';

    private string $decisionId;
    private string $esoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();
        $this->buildPhase2Schema();

        $this->decisionId = Uuid::uuid4()->toString();
        $this->esoId      = Uuid::uuid4()->toString();

        DB::table('hpbrain_decisions')->insert([
            'id' => $this->decisionId, 'tenant_id' => self::TENANT,
            'decided_by' => 'user-analyst', 'rationale' => 'Cadence change is proportionate.',
            'status' => 'approved', 'created_date' => '2026-07-20 09:00:00',
        ]);

        DB::table('hpbrain_eso_definitions')->insert([
            'id' => $this->esoId, 'tenant_id' => self::TENANT,
            'eso_code' => 'ESO-FEE-REMIND', 'name' => 'Targeted fee reminder',
        ]);
    }

    /** The two structures this phase adds, plus the ESO library they bind to. */
    private function buildPhase2Schema(): void
    {
        // hpbrain_measurement_plans and recommendations.eso_id are built by
        // BuildsBrainSchema, which mirrors 2026_07_30_000100. Only the ESO
        // library is extra here.
        Schema::create('hpbrain_eso_definitions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('eso_code');
            $t->string('name');
        });
    }

    private function auth(string $role = 'manager'): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => self::ACTOR, 'tenantId' => self::TENANT, 'role' => $role,
        ])];
    }

    /** @param array<string, mixed> $overrides */
    private function seedPlan(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_measurement_plans')->insert(array_replace([
            'id'              => $id,
            'tenant_id'       => self::TENANT,
            'decision_id'     => $this->decisionId,
            'baseline_metric' => 'Grade 9 collection rate',
            'baseline_value'  => 0.51,
            'target_value'    => 0.70,
            'metric_unit'     => 'ratio',
            'measurement_window_days' => 14,
            'owner_id'        => self::ACTOR,
            'created_by'      => self::ACTOR,
            'created_date'    => '2026-07-21 09:00:00',
        ], $overrides));

        return $id;
    }

    /** @param array<string, mixed> $overrides */
    private function execute(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/eso-executions', array_replace([
            'decisionId'      => $this->decisionId,
            'esoDefinitionId' => $this->esoId,
            'executorType'    => 'human',
        ], $overrides), $this->auth());
    }

    // ---- Invariant 4 ---------------------------------------------------------

    public function test_an_execution_without_a_measurement_plan_is_refused(): void
    {
        $response = $this->execute();

        // An action we cannot measure is an action we do not take.
        $response->assertStatus(422)->assertJson(['error' => 'measurement_plan_required']);

        self::assertSame(0, DB::table('hpbrain_eso_executions')->count());
        // And no event: nothing downstream may believe a run started.
        self::assertSame(0, DB::table('hpbrain_event_store')
            ->where('type', LoopEvent::EXECUTION_STARTED->value)->count());
    }

    public function test_a_plan_created_after_the_execution_attempt_is_refused(): void
    {
        // Dated tomorrow. The row EXISTS, so a check for mere existence would
        // accept it — this is the assertion that separates a plan from a
        // post-hoc justification.
        $this->seedPlan(['created_date' => now()->addDay()->format('Y-m-d H:i:s')]);

        $response = $this->execute();

        $response->assertStatus(422)->assertJson(['error' => 'measurement_plan_required']);
        self::assertStringContainsString('pre-dates', $response->json('reason'));
        self::assertSame(0, DB::table('hpbrain_eso_executions')->count());
    }

    public function test_a_prior_plan_authorises_the_run_and_binds_it_to_the_execution(): void
    {
        $planId = $this->seedPlan();

        $response = $this->execute();

        $response->assertStatus(201);

        $execution = DB::table('hpbrain_eso_executions')->first();

        self::assertNotNull($execution);
        self::assertSame('running', $execution->status);

        // The execution names the plan it is judged against, so "what was this
        // supposed to achieve?" is answerable from the row itself.
        $input = json_decode((string) $execution->input, true);

        self::assertSame($planId, $input['measurementPlanId']);
        self::assertSame('Grade 9 collection rate', $input['baselineMetric']);
        self::assertSame(14, $input['measurementWindowDays']);

        $events = DB::table('hpbrain_event_store')
            ->where('type', LoopEvent::EXECUTION_STARTED->value)->get();

        self::assertCount(1, $events);
        // Stage 9 still inherits the decision's thread.
        self::assertSame($this->decisionId, $events[0]->correlation_id);
        self::assertSame($planId, json_decode((string) $events[0]->payload, true)['measurementPlanId']);
    }

    public function test_another_tenants_plan_does_not_authorise_this_run(): void
    {
        // Same decision id, different tenant. Scoping the lookup is what stops
        // a plan written elsewhere from unlocking a run here.
        $this->seedPlan(['tenant_id' => 'tenant-beta']);

        $this->execute()->assertStatus(422)->assertJson(['error' => 'measurement_plan_required']);
        self::assertSame(0, DB::table('hpbrain_eso_executions')->count());
    }

    public function test_the_legacy_inline_plan_is_refused_under_strict_invariant_4(): void
    {
        // Invariant 4 strict: a plan must be created via POST /measurement-plans
        // before the execution starts. An inline string in the same request is
        // no longer accepted — that was the compatibility path, and it has been
        // removed.
        $response = $this->execute(['measurementPlan' => 'Grade 9 collection rate, 14 days after the reminder.']);

        $response->assertStatus(422)->assertJson(['error' => 'measurement_plan_required']);
        self::assertSame(0, DB::table('hpbrain_measurement_plans')->count());
        self::assertSame(0, DB::table('hpbrain_eso_executions')->count());
    }

    public function test_a_plan_created_via_the_measurement_plans_endpoint_authorises_the_run(): void
    {
        // The strict path: caller creates a plan in a separate request, then
        // starts the execution. The plan's created_date pre-dates the run.
        $this->postJson('/api/v1/measurement-plans', [
            'decisionId'            => $this->decisionId,
            'baselineMetric'        => 'Grade 9 collection rate, 14 days after the reminder.',
            'measurementWindowDays' => 14,
        ], $this->auth())->assertStatus(201);

        $response = $this->execute();
        $response->assertStatus(201);

        $plan = DB::table('hpbrain_measurement_plans')->first();
        self::assertNotNull($plan);
        self::assertSame('Grade 9 collection rate, 14 days after the reminder.', $plan->baseline_metric);
    }

    // ---- Invariant 3 ---------------------------------------------------------

    public function test_an_intervene_recommendation_persists_its_eso_binding(): void
    {
        $stepId = Uuid::uuid4()->toString();
        DB::table('hpbrain_reasoning_steps')->insert([
            'id' => $stepId, 'tenant_id' => self::TENANT,
            'description' => 'Cadence, not capacity.', 'created_by' => self::ACTOR,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->postJson('/api/v1/recommendations', [
            'tenantId' => self::TENANT, 'reasoningStepId' => $stepId,
            'category' => 'intervene', 'title' => 'Run the targeted fee reminder',
            'priority' => 'high', 'confidence' => 0.8, 'esoId' => $this->esoId,
        ], $this->auth());

        $response->assertStatus(201);

        // The binding is now a property of the data. Before this phase it was
        // validated and then dropped on the floor.
        self::assertSame(
            $this->esoId,
            DB::table('hpbrain_recommendations')->where('id', $response->json('id'))->value('eso_id')
        );
    }

    public function test_a_watch_recommendation_stores_a_null_binding(): void
    {
        $stepId = Uuid::uuid4()->toString();
        DB::table('hpbrain_reasoning_steps')->insert([
            'id' => $stepId, 'tenant_id' => self::TENANT,
            'description' => 'Watch the trend.', 'created_by' => self::ACTOR,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->postJson('/api/v1/recommendations', [
            'tenantId' => self::TENANT, 'reasoningStepId' => $stepId,
            'category' => 'watch', 'title' => 'Watch Grade 9 arrears next cycle',
            'priority' => 'low', 'confidence' => 0.4,
        ], $this->auth());

        $response->assertStatus(201);

        // Nullable on purpose: watching is not acting, and requiring an ESO
        // here would force every observation to invent an action.
        self::assertNull(
            DB::table('hpbrain_recommendations')->where('id', $response->json('id'))->value('eso_id')
        );
    }
}
