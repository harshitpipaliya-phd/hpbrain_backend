<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Eso\EsoCatalogue;
use App\Domain\Eso\EsoEfficacy;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The ESO Library: what the organization can actually execute.
 *
 * The catalogue endpoints and the execution gate are tested together because
 * they are one claim made twice. The library screen decides whether to offer a
 * Run button from the `readiness` block on the definition payload; the
 * execution endpoint decides whether to accept the request from EsoPreflight.
 * If those two ever diverge the screen offers a button the server refuses, or
 * — far worse — hides one the server would have accepted while telling the
 * reader the capability is unavailable. Every test below asserts the pair.
 *
 * SCHEMA IS BUILT HERE — see Tests\Support\BuildsBrainSchema for why
 * RefreshDatabase cannot run against this project's raw-MySQL migrations.
 */
final class EsoLibraryTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-alpha';

    private const OTHER_TENANT = 'tenant-beta';

    private const ACTOR = 'user-manager';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();
    }

    /** @return array<string, string> */
    private function auth(string $role = 'manager', string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => self::ACTOR, 'tenantId' => $tenant, 'role' => $role,
        ])];
    }

    /** @param array<string, mixed> $overrides */
    private function eso(array $overrides = []): string
    {
        $id = $overrides['id'] ?? Uuid::uuid4()->toString();

        DB::table('hpbrain_eso_definitions')->insert(array_merge([
            'id' => $id,
            'tenant_id' => self::TENANT,
            'eso_code' => 'ESO-'.substr((string) $id, 0, 8),
            'name' => 'Targeted fee reminder',
            'status' => 'active',
            'objective' => 'PERFORM',
            'trigger_description' => 'Collection has fallen behind the agreed cadence.',
            'created_date' => '2026-07-01 09:00:00',
        ], $overrides));

        return (string) $id;
    }

    private function approvedDecision(string $createdDate = '2026-07-20 09:00:00', ?string $recommendationId = null): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_decisions')->insert([
            'id' => $id,
            'tenant_id' => self::TENANT,
            'recommendation_id' => $recommendationId,
            'decided_by' => 'user-analyst',
            'rationale' => 'Cadence change is proportionate.',
            'status' => 'approved',
            'approved_by' => self::ACTOR,
            'approved_date' => $createdDate,
            'created_date' => $createdDate,
        ]);

        return $id;
    }

    private function plan(string $decisionId, string $createdDate = '2026-07-21 09:00:00'): void
    {
        DB::table('hpbrain_measurement_plans')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::TENANT,
            'decision_id' => $decisionId,
            'baseline_metric' => 'collection rate',
            'measurement_window_days' => 14,
            'created_by' => self::ACTOR,
            'created_date' => $createdDate,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function startRun(string $esoId, string $decisionId, array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/eso-executions', array_merge([
            'decisionId' => $decisionId,
            'esoDefinitionId' => $esoId,
            'executorType' => 'human',
        ], $body), $this->auth());
    }

    /* ───────────────────────── catalogue ───────────────────────── */

    /**
     * The count that reads "Active ESOs" on the library screen.
     *
     * It compared status to the literal 'active', while FiberValleyDemoSeeder
     * writes 'published' — so a tenant whose whole catalogue was published and
     * runnable was told it had none active, next to a total that said four.
     */
    public function test_the_active_count_recognises_every_in_service_status(): void
    {
        $this->eso(['status' => 'active']);
        $this->eso(['status' => 'published']);
        $this->eso(['status' => 'draft']);
        $this->eso(['status' => 'retired']);

        $totals = $this->getJson('/api/v1/eso-definitions/'.self::TENANT, $this->auth())
            ->assertOk()
            ->json('totals');

        self::assertSame(4, $totals['definitions']);
        self::assertSame(2, $totals['active']);
    }

    public function test_the_catalogue_is_scoped_to_the_tenant(): void
    {
        $this->eso();

        DB::table('hpbrain_eso_definitions')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::OTHER_TENANT,
            'eso_code' => 'ESO-OTHER',
            'name' => 'Another tenant capability',
            'status' => 'active',
        ]);

        $payload = $this->getJson('/api/v1/eso-definitions/'.self::TENANT, $this->auth())->assertOk()->json();

        self::assertSame(1, $payload['totals']['definitions']);
        self::assertSame('Targeted fee reminder', $payload['definitions'][0]['name']);
    }

    public function test_a_definition_from_another_tenant_is_not_readable(): void
    {
        $id = $this->eso();

        $this->getJson('/api/v1/eso-definitions/'.self::OTHER_TENANT.'/'.$id, $this->auth('manager', self::OTHER_TENANT))
            ->assertStatus(404)
            ->assertJson(['error' => 'eso_definition_not_found']);
    }

    /* ───────────────────────── readiness ───────────────────────── */

    /**
     * The screen and the server must agree. Both halves of this assertion are
     * the point: readiness.runnable false, and the run actually refused.
     */
    public function test_a_draft_eso_is_reported_unrunnable_and_refused(): void
    {
        $esoId = $this->eso(['status' => 'draft']);
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);

        $readiness = $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/'.$esoId, $this->auth())
            ->assertOk()
            ->json('readiness');

        self::assertFalse($readiness['runnable']);
        self::assertSame('eso_not_in_service', $readiness['blockers'][0]['code']);

        $response = $this->startRun($esoId, $decisionId)->assertStatus(422);

        self::assertSame('eso_preconditions_unmet', $response->json('error'));
        self::assertSame('eso_not_in_service', $response->json('blockers.0.code'));
        self::assertSame(0, DB::table('hpbrain_eso_executions')->count());
    }

    public function test_a_superseded_eso_is_refused(): void
    {
        $esoId = $this->eso(['superseded_by' => Uuid::uuid4()->toString()]);
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);

        self::assertSame('eso_superseded', $this->startRun($esoId, $decisionId)->assertStatus(422)->json('blockers.0.code'));
    }

    public function test_an_eso_reserved_for_another_executor_class_refuses_a_human_run(): void
    {
        $esoId = $this->eso(['allowed_executor_classes' => json_encode(['system'])]);
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);

        self::assertSame('executor_class_not_permitted', $this->startRun($esoId, $decisionId)->assertStatus(422)->json('blockers.0.code'));
    }

    /**
     * An empty allowed_executor_classes is the column default, not a
     * prohibition. Reading the default as "nobody may run this" would make
     * almost every authored ESO unrunnable for a reason its author never wrote.
     */
    public function test_an_eso_declaring_no_executor_class_is_runnable(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);

        $this->startRun($esoId, $decisionId)->assertCreated();
    }

    /* ───────────────────────── declared inputs ───────────────────────── */

    public function test_a_declared_input_is_required_and_the_run_is_refused_without_it(): void
    {
        $esoId = $this->eso([
            'inputs' => json_encode([['name' => 'departmentId', 'type' => 'string']]),
        ]);
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);

        $readiness = $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/'.$esoId, $this->auth())
            ->assertOk()
            ->json('readiness');

        self::assertSame('departmentId', $readiness['requiredInputs'][0]['name']);

        $refused = $this->startRun($esoId, $decisionId)->assertStatus(422);

        self::assertSame('required_inputs_missing', $refused->json('blockers.0.code'));
        self::assertStringContainsString('departmentId', $refused->json('blockers.0.message'));

        $this->startRun($esoId, $decisionId, ['inputs' => ['departmentId' => 'dept-7']])->assertCreated();
    }

    public function test_a_supplied_input_is_stored_on_the_execution(): void
    {
        $esoId = $this->eso(['inputs' => json_encode([['name' => 'departmentId', 'type' => 'string']])]);
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);

        $executionId = $this->startRun($esoId, $decisionId, ['inputs' => ['departmentId' => 'dept-7']])
            ->assertCreated()
            ->json('id');

        $stored = json_decode((string) DB::table('hpbrain_eso_executions')->where('id', $executionId)->value('input'), true);

        self::assertSame(['departmentId' => 'dept-7'], $stored['inputs']);
    }

    /**
     * An input declared as prose cannot be checked, and is reported as such
     * rather than being silently treated as satisfied — or, worse, blocking the
     * run over a requirement no code can evaluate.
     */
    public function test_a_prose_input_is_reported_as_unverifiable_and_does_not_block(): void
    {
        $esoId = $this->eso(['inputs' => json_encode(['The current roster export'])]);
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);

        $readiness = $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/'.$esoId, $this->auth())
            ->assertOk()
            ->json('readiness');

        self::assertSame([], $readiness['requiredInputs']);
        self::assertSame(['The current roster export'], $readiness['unverifiableInputs']);

        $this->startRun($esoId, $decisionId)->assertCreated();
    }

    /* ───────────────────────── preconditions ───────────────────────── */

    public function test_declared_preconditions_must_be_acknowledged_and_the_acknowledgement_is_recorded(): void
    {
        $esoId = $this->eso([
            'preconditions' => json_encode(['The unit lead has been briefed.', 'The roster export is current.']),
        ]);
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);

        $readiness = $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/'.$esoId, $this->auth())
            ->assertOk()
            ->json('readiness');

        self::assertTrue($readiness['preconditionsRequireAcknowledgement']);
        self::assertCount(2, $readiness['preconditions']);

        self::assertSame(
            'preconditions_not_acknowledged',
            $this->startRun($esoId, $decisionId)->assertStatus(422)->json('blockers.0.code'),
        );

        $executionId = $this->startRun($esoId, $decisionId, ['preconditionsAcknowledged' => true])
            ->assertCreated()
            ->json('id');

        $stored = json_decode((string) DB::table('hpbrain_eso_executions')->where('id', $executionId)->value('input'), true);

        // The attestation is only worth keeping if it carries a name.
        self::assertTrue($stored['preconditionsAcknowledged']);
        self::assertSame(self::ACTOR, $stored['preconditionsAcknowledgedBy']);
    }

    /* ───────────────────────── runnable decisions ───────────────────────── */

    public function test_runnable_decisions_lists_approved_decisions_not_yet_executed(): void
    {
        $esoId = $this->eso();
        $open = $this->approvedDecision('2026-07-20 09:00:00');
        $consumed = $this->approvedDecision('2026-07-19 09:00:00');

        $this->plan($consumed, '2026-07-19 10:00:00');
        $this->startRun($esoId, $consumed)->assertCreated();

        $payload = $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/runnable-decisions', $this->auth())
            ->assertOk()
            ->json();

        $ids = array_column($payload['decisions'], 'id');

        self::assertContains($open, $ids);
        self::assertNotContains($consumed, $ids);
    }

    public function test_runnable_decisions_excludes_unapproved_and_other_tenants(): void
    {
        DB::table('hpbrain_decisions')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::TENANT,
            'decided_by' => 'user-analyst',
            'rationale' => 'Awaiting approval.',
            'status' => 'proposed',
            'created_date' => '2026-07-20 09:00:00',
        ]);

        DB::table('hpbrain_decisions')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::OTHER_TENANT,
            'decided_by' => 'user-analyst',
            'rationale' => 'Approved elsewhere.',
            'status' => 'approved',
            'created_date' => '2026-07-20 09:00:00',
        ]);

        $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/runnable-decisions', $this->auth())
            ->assertOk()
            ->assertJsonCount(0, 'decisions');
    }

    /**
     * The route must not be swallowed by `eso-definitions/{tenantId}/{id}`.
     */
    public function test_runnable_decisions_is_not_matched_as_a_definition_id(): void
    {
        $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/runnable-decisions', $this->auth())
            ->assertOk()
            ->assertJsonStructure(['decisions', 'note']);
    }

    /* ───────────────────────── execution record ───────────────────────── */

    public function test_a_terminal_execution_is_not_re_transitioned(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);

        $executionId = $this->startRun($esoId, $decisionId)->assertCreated()->json('id');
        $url = '/api/v1/eso-executions/'.self::TENANT.'/'.$executionId.'/transition';

        $this->patchJson($url, ['status' => 'completed'], $this->auth())->assertOk();

        $this->patchJson($url, ['status' => 'failed'], $this->auth())
            ->assertStatus(422)
            ->assertJson(['error' => 'execution_already_terminal']);

        self::assertSame('completed', DB::table('hpbrain_eso_executions')->where('id', $executionId)->value('status'));
    }

    /**
     * An unknown id used to return 200 with a body of null, which a client
     * cannot tell apart from a transition that worked.
     */
    public function test_transitioning_an_unknown_execution_is_a_404(): void
    {
        $this->patchJson('/api/v1/eso-executions/'.self::TENANT.'/'.Uuid::uuid4()->toString().'/transition', [
            'status' => 'completed',
        ], $this->auth())
            ->assertStatus(404)
            ->assertJson(['error' => 'eso_execution_not_found']);
    }

    /**
     * eso_definition_id is nullable and was added after eso_id; filtering on it
     * alone reported "never run" for any execution predating that column.
     */
    public function test_history_finds_runs_recorded_against_either_eso_column(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);
        $this->startRun($esoId, $decisionId)->assertCreated();

        DB::table('hpbrain_eso_executions')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::TENANT,
            'eso_id' => $esoId,
            'eso_definition_id' => null,
            'decision_id' => $decisionId,
            'status' => 'completed',
            'executed_by' => self::ACTOR,
            'executor_type' => 'human',
            'input' => '{}',
            'created_date' => '2026-06-01 09:00:00',
        ]);

        $this->getJson('/api/v1/eso-executions/'.self::TENANT.'/eso/'.$esoId, $this->auth())
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_the_detail_payload_reports_evidence_cited_by_its_runs(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);

        $evidenceId = Uuid::uuid4()->toString();
        DB::table('hpbrain_evidence')->insert([
            'id' => $evidenceId,
            'tenant_id' => self::TENANT,
            'source' => 'import.school_fee',
            'evidence_type' => 'observation',
            'content' => json_encode(['amount' => 45260]),
            'provenance' => json_encode(['source' => 'erp', 'ts' => '2026-07-19T00:00:00Z']),
            'confidence' => 1.0,
            'hash' => hash('sha256', $evidenceId),
            'status' => 'active',
            'created_by' => 'system',
            'created_date' => '2026-07-19 09:00:00',
        ]);

        $this->startRun($esoId, $decisionId, ['evidenceIds' => [$evidenceId]])->assertCreated();

        $evidence = $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/'.$esoId, $this->auth())
            ->assertOk()
            ->json('evidence');

        self::assertCount(1, $evidence);
        self::assertSame($evidenceId, $evidence[0]['id']);
    }

    /**
     * EFFICACY IS NOT COMPLETION. A run that finished tells you the action was
     * taken, not that it worked, and the payload must never let the first stand
     * in for the second.
     */
    public function test_a_completed_run_without_outcome_evidence_reports_efficacy_as_unmeasurable(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);

        $executionId = $this->startRun($esoId, $decisionId)->assertCreated()->json('id');
        $this->patchJson('/api/v1/eso-executions/'.self::TENANT.'/'.$executionId.'/transition', [
            'status' => 'completed',
        ], $this->auth())->assertOk();

        $payload = $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/'.$esoId, $this->auth())->assertOk()->json();

        self::assertSame(1, $payload['runs']);
        self::assertSame([], $payload['efficacy']);
        self::assertSame(EsoEfficacy::UNMEASURABLE_MESSAGE, $payload['outcomeStatus']);
        self::assertSame(EsoEfficacy::UNMEASURABLE_MESSAGE, $payload['efficacyMessage']);
    }

    public function test_an_eso_that_has_never_run_is_not_called_unmeasurable(): void
    {
        $esoId = $this->eso();

        $payload = $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/'.$esoId, $this->auth())->assertOk()->json();

        self::assertSame(0, $payload['runs']);
        self::assertSame('not executed', $payload['outcomeStatus']);
        self::assertNull($payload['efficacyMessage']);
    }

    /* ───────────────────────── recommendation binding ───────────────────────── */

    /**
     * RecommendationEngine shipped `esoId: null` and a fixed sentence reading
     * "No executable object definitions exist for this organization" — printed
     * unchanged to tenants that had several. A match is a lookup of the ESO's
     * own declared gap types, never an inference from wording.
     */
    public function test_a_recommendation_binds_to_an_eso_that_declares_its_gap_type(): void
    {
        $esoId = $this->eso(['gap_types' => json_encode(['loop_never_closed'])]);

        $binding = EsoCatalogue::forTenant(self::TENANT)->bindingFor('loop_never_closed', 'Assessment');

        self::assertSame($esoId, $binding['esoId']);
        self::assertTrue($binding['esoRunnable']);
    }

    public function test_no_binding_is_invented_for_a_gap_type_no_eso_declares(): void
    {
        $this->eso(['gap_types' => json_encode(['loop_never_closed'])]);

        $binding = EsoCatalogue::forTenant(self::TENANT)->bindingFor('unowned_risk', 'Workflow');

        self::assertNull($binding['esoId']);
        self::assertFalse($binding['esoRunnable']);
        // The note must say which is true: a catalogue exists, but nothing in
        // it claims this finding.
        self::assertStringContainsString('none of them declares', $binding['esoNote']);
    }

    public function test_an_empty_catalogue_says_so_rather_than_claiming_a_missing_match(): void
    {
        $binding = EsoCatalogue::forTenant(self::TENANT)->bindingFor('loop_never_closed', 'Assessment');

        self::assertNull($binding['esoId']);
        self::assertStringContainsString('No executable object definitions exist', $binding['esoNote']);
    }

    /**
     * A withdrawn ESO is still reported — it tells the reader the capability
     * was authored and then taken out of service — but it is never offered as
     * runnable, because a Run button against a retired ESO is a dead button.
     */
    public function test_a_withdrawn_eso_matches_but_is_not_offered_as_runnable(): void
    {
        $esoId = $this->eso(['status' => 'retired', 'gap_types' => json_encode(['loop_never_closed'])]);

        $binding = EsoCatalogue::forTenant(self::TENANT)->bindingFor('loop_never_closed', 'Assessment');

        self::assertSame($esoId, $binding['esoId']);
        self::assertFalse($binding['esoRunnable']);
        self::assertStringContainsString('not currently runnable', $binding['esoNote']);
    }

    public function test_an_in_service_eso_is_preferred_over_a_withdrawn_one_for_the_same_gap_type(): void
    {
        $this->eso(['status' => 'retired', 'eso_code' => 'ESO-OLD', 'gap_types' => json_encode(['loop_never_closed'])]);
        $live = $this->eso(['status' => 'published', 'eso_code' => 'ESO-NEW', 'gap_types' => json_encode(['loop_never_closed'])]);

        $binding = EsoCatalogue::forTenant(self::TENANT)->bindingFor('loop_never_closed', 'Assessment');

        self::assertSame($live, $binding['esoId']);
        self::assertTrue($binding['esoRunnable']);
    }

    public function test_the_catalogue_never_matches_across_tenants(): void
    {
        DB::table('hpbrain_eso_definitions')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::OTHER_TENANT,
            'eso_code' => 'ESO-OTHER',
            'name' => 'Another tenant capability',
            'status' => 'active',
            'gap_types' => json_encode(['loop_never_closed']),
        ]);

        self::assertNull(EsoCatalogue::forTenant(self::TENANT)->bindingFor('loop_never_closed', 'Assessment')['esoId']);
    }

    /* ───────────────────────── efficacy fixtures ───────────────────────── */

    /** A plan with the before/after endpoints an efficacy score needs. */
    private function measuredPlan(string $decisionId, float $baseline, float $target, string $metric = 'collection rate'): void
    {
        DB::table('hpbrain_measurement_plans')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::TENANT,
            'decision_id' => $decisionId,
            'baseline_metric' => $metric,
            'baseline_value' => $baseline,
            'target_value' => $target,
            'metric_unit' => 'ratio',
            'measurement_window_days' => 14,
            'created_by' => self::ACTOR,
            'created_date' => '2026-07-19 09:00:00',
        ]);
    }

    /** @param array<string, mixed> $metrics */
    private function outcome(string $decisionId, array $metrics, string $result = 'success', ?float $confidence = 0.8, string $tenant = self::TENANT): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_outcomes')->insert([
            'id' => $id,
            'tenant_id' => $tenant,
            'decision_id' => $decisionId,
            'result' => $result,
            'metrics' => json_encode($metrics),
            'kpis' => '[]',
            'evidence_ids' => '[]',
            'confidence' => $confidence,
            'created_by' => self::ACTOR,
            'created_date' => '2026-08-01 09:00:00',
        ]);

        return $id;
    }

    private function completedRun(string $esoId, string $decisionId): string
    {
        $executionId = $this->startRun($esoId, $decisionId)->assertCreated()->json('id');

        $this->patchJson('/api/v1/eso-executions/'.self::TENANT.'/'.$executionId.'/transition', [
            'status' => 'completed',
        ], $this->auth())->assertOk();

        return $executionId;
    }

    /* ───────────────────────── efficacy ───────────────────────── */

    public function test_efficacy_is_not_measurable_before_anything_has_run(): void
    {
        $analysis = EsoEfficacy::forDefinition(self::TENANT, $this->eso());

        self::assertSame(EsoEfficacy::NOT_MEASURABLE, $analysis['status']);
        self::assertNull($analysis['score']);
        self::assertSame(0, $analysis['sampleSize']);
    }

    /**
     * A completed run with no outcome is activity, not success. The status must
     * say so and the score must stay null — never 0, which reads as a measured
     * total failure.
     */
    public function test_a_completed_run_without_an_outcome_is_insufficient_evidence_not_a_zero(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->measuredPlan($decisionId, 0.5, 0.8);
        $this->completedRun($esoId, $decisionId);

        $analysis = EsoEfficacy::forDefinition(self::TENANT, $esoId);

        self::assertSame(EsoEfficacy::INSUFFICIENT_EVIDENCE, $analysis['status']);
        self::assertNull($analysis['score']);
        self::assertNull($analysis['verdict']);
        self::assertSame(EsoEfficacy::UNMEASURABLE_MESSAGE, $analysis['message']);
        self::assertStringContainsString('outcome not yet recorded', $analysis['explanation']);
    }

    public function test_an_outcome_without_the_planned_metric_cannot_be_scored(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->measuredPlan($decisionId, 0.5, 0.8, 'collection rate');
        $this->completedRun($esoId, $decisionId);
        $this->outcome($decisionId, ['attendance' => 0.9]);

        $analysis = EsoEfficacy::forDefinition(self::TENANT, $esoId);

        self::assertSame(EsoEfficacy::INSUFFICIENT_EVIDENCE, $analysis['status']);
        self::assertNull($analysis['score']);
        self::assertStringContainsString('collection rate', $analysis['explanation']);
    }

    public function test_a_plan_without_a_baseline_or_target_cannot_be_scored(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId, '2026-07-19 09:00:00');  // no baseline_value, no target_value
        $this->completedRun($esoId, $decisionId);
        $this->outcome($decisionId, ['collection rate' => 0.9]);

        $analysis = EsoEfficacy::forDefinition(self::TENANT, $esoId);

        self::assertSame(EsoEfficacy::INSUFFICIENT_EVIDENCE, $analysis['status']);
        self::assertNull($analysis['score']);
    }

    /** Baseline 0.5, target 0.8, actual 0.8 — the whole agreed distance. */
    public function test_reaching_the_target_scores_one_and_reads_as_success(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->measuredPlan($decisionId, 0.5, 0.8);
        $this->completedRun($esoId, $decisionId);
        $this->outcome($decisionId, ['collection rate' => 0.8], confidence: 0.9);

        $analysis = EsoEfficacy::forDefinition(self::TENANT, $esoId);

        self::assertSame(EsoEfficacy::MEASURABLE, $analysis['status']);
        self::assertSame(1.0, $analysis['score']);
        self::assertSame(EsoEfficacy::SUCCESS, $analysis['verdict']);
        self::assertSame(1, $analysis['sampleSize']);
        // Confidence is READ from the outcome, never derived from sample size.
        self::assertSame(0.9, $analysis['confidence']);
        self::assertSame('collection rate', $analysis['metric']);
    }

    /** Baseline 0.5, target 0.8, actual 0.65 — half the distance. */
    public function test_partial_movement_scores_the_fraction_actually_travelled(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->measuredPlan($decisionId, 0.5, 0.8);
        $this->completedRun($esoId, $decisionId);
        $this->outcome($decisionId, ['collection rate' => 0.65]);

        $analysis = EsoEfficacy::forDefinition(self::TENANT, $esoId);

        self::assertSame(0.5, $analysis['score']);
        self::assertSame(EsoEfficacy::PARTIAL, $analysis['verdict']);
    }

    /**
     * Moving the wrong way is a FAILED verdict with a clamped score of 0 — and
     * that 0 is a measurement, which is why the status must be MEASURABLE. The
     * unmeasurable case above keeps its null precisely so the two never look
     * alike.
     */
    public function test_moving_the_wrong_way_is_a_measured_failure(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->measuredPlan($decisionId, 0.5, 0.8);
        $this->completedRun($esoId, $decisionId);
        $this->outcome($decisionId, ['collection rate' => 0.4], result: 'failure');

        $analysis = EsoEfficacy::forDefinition(self::TENANT, $esoId);

        self::assertSame(EsoEfficacy::MEASURABLE, $analysis['status']);
        self::assertSame(0.0, $analysis['score']);
        self::assertSame(EsoEfficacy::FAILED, $analysis['verdict']);
    }

    /**
     * A target BELOW the baseline — cut a queue, cut a failure rate — must work
     * without a special case, because numerator and denominator change sign
     * together.
     */
    public function test_a_downward_target_is_scored_in_the_same_direction_as_intent(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->measuredPlan($decisionId, 100.0, 20.0, 'open cases');
        $this->completedRun($esoId, $decisionId);
        $this->outcome($decisionId, ['open cases' => 60]);

        $analysis = EsoEfficacy::forDefinition(self::TENANT, $esoId);

        self::assertSame(EsoEfficacy::MEASURABLE, $analysis['status']);
        self::assertSame(0.5, $analysis['score']);
        self::assertSame(EsoEfficacy::PARTIAL, $analysis['verdict']);
    }

    /** A rolled-back run is evidence about the run, not about the intervention. */
    public function test_a_rolled_back_execution_does_not_contribute(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->measuredPlan($decisionId, 0.5, 0.8);
        $executionId = $this->startRun($esoId, $decisionId)->assertCreated()->json('id');

        $this->postJson('/api/v1/eso-executions/'.self::TENANT.'/'.$executionId.'/rollback', [
            'reason' => 'The ERP rejected the batch.',
        ], $this->auth())->assertOk();

        $this->outcome($decisionId, ['collection rate' => 0.8]);

        $analysis = EsoEfficacy::forDefinition(self::TENANT, $esoId);

        self::assertSame(EsoEfficacy::INSUFFICIENT_EVIDENCE, $analysis['status']);
        self::assertStringContainsString('rolled back', $analysis['explanation']);
    }

    public function test_the_detail_payload_carries_the_analysis_and_its_workings(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->measuredPlan($decisionId, 0.5, 0.8);
        $this->completedRun($esoId, $decisionId);
        $this->outcome($decisionId, ['collection rate' => 0.8]);

        $analysis = $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/'.$esoId, $this->auth())
            ->assertOk()
            ->json('efficacyAnalysis');

        self::assertSame(EsoEfficacy::MEASURABLE, $analysis['status']);
        // JSON does not distinguish 1 from 1.0, so this compares numerically.
        self::assertEqualsWithDelta(1.0, $analysis['score'], 0.0001);
        // Explainable: baseline, target and the arithmetic are all stated.
        self::assertStringContainsString('collection rate', $analysis['explanation']);
        self::assertStringContainsString('0.5', $analysis['explanation']);
        self::assertStringContainsString('0.8', $analysis['explanation']);
        self::assertCount(1, $analysis['contributions']);
        self::assertTrue($analysis['contributions'][0]['counted']);
        self::assertEqualsWithDelta(0.8, $analysis['contributions'][0]['actual'], 0.0001);
    }

    /* ───────────────────────── the efficacy writer ───────────────────────── */

    public function test_the_command_writes_a_snapshot_only_where_evidence_earns_one(): void
    {
        $measured = $this->eso(['eso_code' => 'ESO-MEASURED', 'gap_types' => json_encode(['loop_never_closed'])]);
        $unmeasured = $this->eso(['eso_code' => 'ESO-UNMEASURED']);

        $decisionId = $this->approvedDecision();
        $this->measuredPlan($decisionId, 0.5, 0.8);
        $this->completedRun($measured, $decisionId);
        $this->outcome($decisionId, ['collection rate' => 0.8]);

        $this->artisan('brain:compute-eso-efficacy', ['--tenant' => self::TENANT])->assertExitCode(0);

        self::assertSame(1, DB::table('hpbrain_eso_efficacy_records')->count());

        $record = DB::table('hpbrain_eso_efficacy_records')->first();

        self::assertSame($measured, $record->eso_definition_id);
        self::assertSame(self::TENANT, $record->tenant_id);
        self::assertSame(1.0, (float) $record->efficacy_score);
        self::assertSame(1, (int) $record->sample_size);
        self::assertSame('loop_never_closed', $record->gap_type);

        // The unmeasured ESO gets NO row — not a row scored zero.
        self::assertSame(0, DB::table('hpbrain_eso_efficacy_records')->where('eso_definition_id', $unmeasured)->count());
    }

    public function test_the_command_writes_nothing_in_dry_run(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->measuredPlan($decisionId, 0.5, 0.8);
        $this->completedRun($esoId, $decisionId);
        $this->outcome($decisionId, ['collection rate' => 0.8]);

        $this->artisan('brain:compute-eso-efficacy', ['--tenant' => self::TENANT, '--dry-run' => true])->assertExitCode(0);

        self::assertSame(0, DB::table('hpbrain_eso_efficacy_records')->count());
    }

    /* ───────────────────────── intelligence loop ───────────────────────── */

    public function test_the_loop_reports_absent_nodes_as_absent_rather_than_skipping_them(): void
    {
        $esoId = $this->eso();

        $loop = $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/'.$esoId, $this->auth())
            ->assertOk()
            ->json('intelligenceLoop');

        $byKind = collect($loop['nodes'])->keyBy('kind');

        // The ESO itself is real; nothing downstream of it is.
        self::assertTrue($byKind['eso']['present']);
        self::assertFalse($byKind['execution']['present']);
        self::assertFalse($byKind['outcome']['present']);
        self::assertFalse($byKind['learning']['present']);
        self::assertFalse($loop['complete']);
        self::assertSame('Outcome not yet recorded.', $byKind['outcome']['detail']);
    }

    public function test_a_walked_loop_reports_every_node_it_actually_reached(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->measuredPlan($decisionId, 0.5, 0.8);

        $evidenceId = $this->evidence();
        $executionId = $this->startRun($esoId, $decisionId, ['evidenceIds' => [$evidenceId]])->assertCreated()->json('id');
        $this->patchJson('/api/v1/eso-executions/'.self::TENANT.'/'.$executionId.'/transition', [
            'status' => 'completed',
        ], $this->auth())->assertOk();

        $outcomeId = $this->outcome($decisionId, ['collection rate' => 0.8]);

        DB::table('hpbrain_learnings')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::TENANT,
            'outcome_id' => $outcomeId,
            'pattern' => 'Targeted reminders lift collection when sent before the due date.',
            'confidence' => 0.8,
            'reusable' => true,
            'created_by' => 'brain-learn',
            'created_date' => '2026-08-02 09:00:00',
        ]);

        $loop = $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/'.$esoId, $this->auth())
            ->assertOk()
            ->json('intelligenceLoop');

        $byKind = collect($loop['nodes'])->keyBy('kind');

        self::assertSame($executionId, $byKind['execution']['id']);
        self::assertTrue($byKind['evidence']['present']);
        self::assertSame($outcomeId, $byKind['outcome']['id']);
        self::assertTrue($byKind['efficacy']['present']);
        self::assertSame(EsoEfficacy::SUCCESS, $byKind['efficacy']['detail']);
        self::assertTrue($byKind['learning']['present']);
        self::assertTrue($loop['complete']);
    }

    /* ───────────────────────── hostile tenant isolation ───────────────────────── */

    private function evidence(string $tenant = self::TENANT): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_evidence')->insert([
            'id' => $id,
            'tenant_id' => $tenant,
            'source' => 'import.school_fee',
            'evidence_type' => 'observation',
            'content' => json_encode(['amount' => 45260]),
            'provenance' => json_encode(['source' => 'erp']),
            'confidence' => 1.0,
            'hash' => hash('sha256', $id),
            'status' => 'active',
            'created_by' => 'system',
            'created_date' => '2026-07-19 09:00:00',
        ]);

        return $id;
    }

    /** Tenant B's approved decision must not authorise a run in tenant A. */
    public function test_another_tenants_decision_cannot_authorise_a_run(): void
    {
        $esoId = $this->eso();
        $foreignDecision = Uuid::uuid4()->toString();

        DB::table('hpbrain_decisions')->insert([
            'id' => $foreignDecision,
            'tenant_id' => self::OTHER_TENANT,
            'decided_by' => 'user-analyst',
            'rationale' => 'Approved in the other tenant.',
            'status' => 'approved',
            'created_date' => '2026-07-20 09:00:00',
        ]);

        DB::table('hpbrain_measurement_plans')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::OTHER_TENANT,
            'decision_id' => $foreignDecision,
            'baseline_metric' => 'collection rate',
            'created_by' => 'someone-else',
            'created_date' => '2026-07-19 09:00:00',
        ]);

        $this->startRun($esoId, $foreignDecision)
            ->assertStatus(422)
            ->assertJson(['error' => 'decision_not_found']);

        self::assertSame(0, DB::table('hpbrain_eso_executions')->count());
    }

    /**
     * Evidence belonging to another tenant must not be linkable. It is dropped
     * rather than the run being refused — the run itself is legitimate — but the
     * link table must stay clean.
     */
    public function test_another_tenants_evidence_is_never_linked_to_this_tenants_execution(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->plan($decisionId);

        $foreignEvidence = $this->evidence(self::OTHER_TENANT);
        $ownEvidence = $this->evidence();

        $executionId = $this->startRun($esoId, $decisionId, ['evidenceIds' => [$ownEvidence, $foreignEvidence]])
            ->assertCreated()
            ->json('id');

        $linked = DB::table('hpbrain_eso_execution_evidence')
            ->where('execution_id', $executionId)
            ->pluck('evidence_id')
            ->all();

        self::assertSame([$ownEvidence], $linked);
        self::assertNotContains($foreignEvidence, $linked);
    }

    /** An execution in another tenant must not be transitionable from here. */
    public function test_another_tenants_execution_cannot_be_transitioned_or_rolled_back(): void
    {
        $executionId = Uuid::uuid4()->toString();

        DB::table('hpbrain_eso_executions')->insert([
            'id' => $executionId,
            'tenant_id' => self::OTHER_TENANT,
            'eso_id' => Uuid::uuid4()->toString(),
            'decision_id' => null,
            'status' => 'running',
            'executed_by' => 'someone-else',
            'executor_type' => 'human',
            'input' => '{}',
            'created_date' => '2026-07-20 09:00:00',
        ]);

        $this->patchJson('/api/v1/eso-executions/'.self::TENANT.'/'.$executionId.'/transition', [
            'status' => 'completed',
        ], $this->auth())->assertStatus(404);

        $this->postJson('/api/v1/eso-executions/'.self::TENANT.'/'.$executionId.'/rollback', [
            'reason' => 'Attempted from the wrong tenant.',
        ], $this->auth())->assertStatus(404);

        self::assertSame('running', DB::table('hpbrain_eso_executions')->where('id', $executionId)->value('status'));
    }

    /** Another tenant's outcome must never reach this tenant's efficacy figure. */
    public function test_efficacy_never_counts_another_tenants_outcome(): void
    {
        $esoId = $this->eso();
        $decisionId = $this->approvedDecision();
        $this->measuredPlan($decisionId, 0.5, 0.8);
        $this->completedRun($esoId, $decisionId);

        // Same decision id, other tenant. Only the scoping stops this counting.
        $this->outcome($decisionId, ['collection rate' => 0.8], tenant: self::OTHER_TENANT);

        $analysis = EsoEfficacy::forDefinition(self::TENANT, $esoId);

        self::assertSame(EsoEfficacy::INSUFFICIENT_EVIDENCE, $analysis['status']);
        self::assertNull($analysis['score']);
    }

    /** Another tenant's execution history must not appear under this ESO. */
    public function test_history_never_returns_another_tenants_executions(): void
    {
        $esoId = $this->eso();

        DB::table('hpbrain_eso_executions')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::OTHER_TENANT,
            'eso_id' => $esoId,
            'decision_id' => null,
            'status' => 'completed',
            'executed_by' => 'someone-else',
            'executor_type' => 'human',
            'input' => '{}',
            'created_date' => '2026-07-20 09:00:00',
        ]);

        $this->getJson('/api/v1/eso-executions/'.self::TENANT.'/eso/'.$esoId, $this->auth())
            ->assertOk()
            ->assertJsonCount(0);
    }

    /** Another tenant's runnable decisions must never be offered here. */
    public function test_runnable_decisions_never_offers_another_tenants_decision(): void
    {
        DB::table('hpbrain_decisions')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::OTHER_TENANT,
            'decided_by' => 'user-analyst',
            'rationale' => 'Approved elsewhere.',
            'status' => 'approved',
            'approved_date' => '2026-07-20 09:00:00',
            'created_date' => '2026-07-20 09:00:00',
        ]);

        $this->getJson('/api/v1/eso-definitions/'.self::TENANT.'/runnable-decisions', $this->auth())
            ->assertOk()
            ->assertJsonCount(0, 'decisions');
    }

}
