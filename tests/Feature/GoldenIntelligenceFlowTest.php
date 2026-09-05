<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Events\LoopEvent;
use App\Domain\Learning\MemoryGrounding;
use App\Support\Jwt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * THE GOLDEN INTELLIGENCE-FLOW TEST (FBR §3.6, EB-DP Ch.10 and Ch.12).
 *
 * Modules 1–6 each proved one stage in isolation. Nothing had ever proved they
 * compose, which means "the loop turns" was an opinion. This walks the whole
 * path — signal, evidence, reasoning, decision, execution, outcome, learning,
 * memory — through the real HTTP endpoints and the real console consumer, and
 * asserts the integrity checkpoint at each step rather than the status code.
 *
 * A 200 with an empty body passes a smoke test and must fail this one.
 *
 * THE HONESTY ASSERTIONS COME FIRST, AND THEY ARE THE POINT. FBR §3.5 makes it
 * a PASS CONDITION that a run legitimately returns UNKNOWN at step 4 or
 * UNDETERMINED at step 7, and the Pilot Acceptance Checklist warns that a green
 * board with no honest UNKNOWN anywhere "usually means the system is
 * fabricating confidence". So this test asserts the Brain says "I don't know"
 * before it asserts the Brain concludes anything. A build that answers
 * confidently at step 4 with no evidence recorded fails here, and that failure
 * is correct.
 *
 * NOT RefreshDatabase — see Tests\Support\BuildsBrainSchema for why it cannot
 * be used on this project (the migrations are raw MySQL DDL and the suite is
 * pinned to SQLite). Each test method still starts from an empty database,
 * which is the property RefreshDatabase would have provided.
 */
final class GoldenIntelligenceFlowTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-alpha';

    // Separation of duties: the analyst proposes, the manager approves. The
    // approval gate is meaningless if one identity can do both.
    private const ANALYST = 'user-analyst';
    private const MANAGER = 'user-manager';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();

        DB::table('hpbrain_auth_users')->insert([
            ['id' => self::ANALYST, 'tenant_id' => self::TENANT, 'email' => 'analyst@x.test',
             'name' => 'Ada Analyst', 'role' => 'analyst', 'password_hash' => Hash::make('x')],
            ['id' => self::MANAGER, 'tenant_id' => self::TENANT, 'email' => 'manager@x.test',
             'name' => 'Mo Manager', 'role' => 'manager', 'password_hash' => Hash::make('x')],
        ]);
    }

    private function auth(string $role, string $id, string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => $id, 'tenantId' => $tenant, 'role' => $role,
        ])];
    }

    private function analyst(): array
    {
        return $this->auth('analyst', self::ANALYST);
    }

    private function manager(): array
    {
        return $this->auth('manager', self::MANAGER);
    }

    /** @return array<int, object> */
    private function events(LoopEvent $event): array
    {
        return DB::table('hpbrain_event_store')->where('type', $event->value)->get()->all();
    }

    private function drainOutbox(): void
    {
        Artisan::call('brain:process-events', ['--once' => true]);
    }

    // =====================================================================
    // 7a — the whole loop, once, end to end
    // =====================================================================

    public function test_the_loop_turns_from_signal_to_organizational_memory(): void
    {
        // ---- Step 3. Reality: something was noticed. --------------------
        $signalId = $this->postJson('/api/v1/signals', [
            'source'         => 'fee_ledger',
            'classification' => 'Finance',
            'priority'       => 'high',
            'severity'       => 'high',
            'confidence'     => 0.8,
        ], $this->analyst())->assertStatus(201)->json('id');

        self::assertCount(1, $this->events(LoopEvent::OBSERVATION_MADE));

        // The signal must point at somebody. who_is_affected is one of the
        // seven UODM questions and there is no endpoint that sets it.
        DB::table('hpbrain_signals')->where('id', $signalId)->update([
            'related_entity_type' => 'Department',
            'related_entity_id'   => 'dept-9',
        ]);

        // ---- Step 4. HONESTY CHECKPOINT ONE. ----------------------------
        // An unevidenced signal must produce nothing, not a default row and
        // not a fabricated confidence.
        $evidence = $this->getJson(
            '/api/v1/evidence/'.self::TENANT."/signal/{$signalId}", $this->analyst()
        )->assertStatus(200)->json();

        self::assertSame([], $evidence, 'An unevidenced signal must return exactly [].');

        $explainBefore = $this->postJson(
            '/api/v1/reasoning-engine/'.self::TENANT.'/explain',
            ['signalId' => $signalId], $this->analyst()
        )->assertStatus(200);

        // Asked to explain something it has no grounds for, the Brain says so.
        // ADR-004: ungrounded generation is prohibited.
        self::assertSame('UNDETERMINED', $explainBefore->json('state'));
        self::assertContains('no_grounding_evidence', $explainBefore->json('gaps'));
        self::assertNull($explainBefore->json('value'), 'An undetermined result carries no value.');

        // ---- Step 6. Evidence, with provenance. -------------------------
        $evidenceId = $this->postJson('/api/v1/evidence', [
            'tenantId'   => self::TENANT,
            'signalId'   => $signalId,
            'source'     => 'fee_ledger_export',
            'content'    => ['note' => 'Grade 9 arrears concentrated in 14 families.'],
            'provenance' => [
                'source'     => 'ERP fee ledger, nightly export',
                'ts'         => '2026-07-20T09:00:00Z',
                'confidence' => 0.82,
            ],
        ], $this->analyst())->assertStatus(201)->json('id');

        $stored = DB::table('hpbrain_evidence')->where('id', $evidenceId)->first();
        $provenance = json_decode((string) $stored->provenance, true);

        // Invariant 1: no evidence without provenance. The column defaults to
        // '{}', so only the stored value proves anything.
        self::assertNotSame([], $provenance);
        self::assertSame('ERP fee ledger, nightly export', $provenance['source']);
        self::assertSame('2026-07-20T09:00:00Z', $provenance['ts']);
        self::assertSame(0.82, $provenance['confidence']);
        self::assertNotSame('', (string) $stored->hash);
        self::assertSame(64, strlen((string) $stored->hash));

        // Regression guard for the original hollow-write defect: an empty
        // rules() let this endpoint discard the entire payload and write a row
        // that looked like evidence and proved nothing.
        $this->postJson('/api/v1/evidence', [
            'tenantId' => self::TENANT,
            'signalId' => $signalId,
            'source'   => 'fee_ledger_export',
            'content'  => ['note' => 'no provenance'],
        ], $this->analyst())->assertStatus(422)->assertJsonValidationErrors('provenance');

        self::assertCount(1, $this->events(LoopEvent::EVIDENCE_RECORDED));

        // ---- Step 7. HONESTY CHECKPOINT TWO. ----------------------------
        // No AI provider is wired. The honest answer is UNDETERMINED naming
        // the gap — never a templated summary a caller cannot tell apart from
        // a real one.
        $summary = $this->postJson('/api/v1/ai/evidence/summarize', [
            'signalId' => $signalId,
        ], $this->analyst())->assertStatus(200);

        self::assertSame('UNDETERMINED', $summary->json('state'));
        self::assertNotEmpty($summary->json('gaps'));
        self::assertContains('no_ai_provider_configured', $summary->json('gaps'));
        // It still says what it DID have — the gap is the provider, not the data.
        self::assertContains($evidenceId, $summary->json('evidenceRefs'));

        // A case and a stated hypothesis. EXPLAIN cannot answer
        // what_is_the_root_cause_family or what_would_falsify_it without one,
        // and inventing either is the fabrication this build refuses.
        $caseId = $this->postJson('/api/v1/cases', [
            'title' => 'Grade 9 fee arrears', 'signalId' => $signalId,
        ], $this->analyst())->assertStatus(201)->json('id');

        $this->postJson('/api/v1/hypotheses', [
            'caseId'          => $caseId,
            'statement'       => 'Arrears track the reminder schedule, not ability to pay.',
            'rootCauseFamily' => 'Process',
            'confidence'      => 0.72,
        ], $this->analyst())->assertStatus(201);

        $explainAfter = $this->postJson(
            '/api/v1/reasoning-engine/'.self::TENANT.'/explain',
            ['signalId' => $signalId], $this->analyst()
        )->assertStatus(200);

        // Now grounded, the Brain answers — and names what it stood on.
        self::assertSame('DECIDED', $explainAfter->json('state'), 'gaps: '.json_encode($explainAfter->json('gaps')));
        self::assertNotEmpty($explainAfter->json('evidenceRefs'));
        self::assertContains($evidenceId, $explainAfter->json('evidenceRefs'));
        self::assertSame('Process', $explainAfter->json('value.rootCause'));

        // ---- Step 8. Decision, and the governance gate. -----------------
        $reasoningStepId = $this->postJson('/api/v1/reasoning', [
            'signalId'    => $signalId,
            'caseId'      => $caseId,
            'description' => 'Reminder cadence, not household income, explains the shortfall.',
        ], $this->analyst())->assertStatus(201)->json('id');

        self::assertCount(1, $this->events(LoopEvent::DELIBERATED));

        // The mental model the outcome's domain is derived from. Nothing in the
        // API binds a reasoning step to a mental model, so this is a direct
        // write — see the report.
        $modelId = Uuid::uuid4()->toString();
        DB::table('hpbrain_mental_models')->insert([
            'id' => $modelId, 'tenant_id' => self::TENANT, 'name' => 'Fee recovery',
            'domain' => 'Finance', 'created_by' => self::ANALYST,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);
        DB::table('hpbrain_reasoning_steps')->where('id', $reasoningStepId)
            ->update(['mental_model_id' => $modelId]);

        $recommendationId = $this->postJson('/api/v1/recommendations', [
            'tenantId'        => self::TENANT,
            'reasoningStepId' => $reasoningStepId,
            'category'        => 'investigate',
            'title'           => 'Review reminder cadence for Grade 9 families',
            'priority'        => 'high',
            'confidence'      => 0.71,
        ], $this->analyst())->assertStatus(201)->json('id');

        $decisionId = $this->postJson('/api/v1/decisions', [
            'tenantId'         => self::TENANT,
            'recommendationId' => $recommendationId,
            'rationale'        => 'Cadence change is proportionate to the measured shortfall.',
        ], $this->analyst())->assertStatus(201)->json('id');

        // Born proposed. A decision that is born approved makes the gate
        // decorative — you cannot authorize a transition that already happened.
        self::assertSame('proposed', DB::table('hpbrain_decisions')->where('id', $decisionId)->value('status'));

        // The analyst who proposed it may not approve it.
        $this->postJson("/api/v1/decisions/".self::TENANT."/{$decisionId}/approve", [], $this->analyst())
            ->assertStatus(403)
            ->assertJson(['required' => 'decision.approve']);

        // Nor may a manager approve their own proposal — Invariant 7.
        $ownDecisionId = $this->postJson('/api/v1/decisions', [
            'tenantId'         => self::TENANT,
            'recommendationId' => $recommendationId,
            'rationale'        => 'Proposed and approved by one hand is not a control.',
        ], $this->manager())->assertStatus(201)->json('id');

        $this->postJson("/api/v1/decisions/".self::TENANT."/{$ownDecisionId}/approve", [], $this->manager())
            ->assertStatus(409)
            ->assertJson(['error' => 'self_approval_forbidden']);

        // A different human approves.
        $approved = $this->postJson(
            "/api/v1/decisions/".self::TENANT."/{$decisionId}/approve",
            ['note' => 'Reviewed the evidence; proportionate.'], $this->manager()
        )->assertStatus(200);

        self::assertSame('approved', $approved->json('status'));
        self::assertSame(self::MANAGER, $approved->json('approved_by'));

        // Idempotent: a retried approval appends no second event, because
        // downstream treats DecisionReached as the trigger to execute.
        $this->postJson("/api/v1/decisions/".self::TENANT."/{$decisionId}/approve", [], $this->manager())
            ->assertStatus(200);

        self::assertCount(1, $this->events(LoopEvent::DECISION_REACHED));

        self::assertSame(1, DB::table('hpbrain_audit_logs')
            ->where('entity_id', $decisionId)->where('action', 'decision.approve')->count());

        // ---- Step 9. Measurement plan (Invariant 4 strict). ----------------
        $this->postJson('/api/v1/measurement-plans', [
            'decisionId'            => $decisionId,
            'baselineMetric'        => 'Grade 9 collection rate, 14 days after the reminder.',
            'measurementWindowDays' => 14,
        ], $this->manager())->assertStatus(201);

        // ---- Step 9. Execution. -----------------------------------------
        // The ESO being run must be one the organization actually published.
        // EsoExecutionController refuses an unknown definition (eso_not_found)
        // and EsoPreflight refuses a draft one, so a random UUID here was
        // asserting that the loop reaches stage 9 while never letting it. The
        // columns set below are the ones the live table declares NOT NULL
        // (2026_01_01_003300_eso_definition_library) plus a published status:
        // 'active' is in EsoStatus::IN_SERVICE, 'draft' — the column default —
        // is not. No inputs and no preconditions are declared, so the run needs
        // neither values nor an attestation.
        $esoDefinitionId = Uuid::uuid4()->toString();

        DB::table('hpbrain_eso_definitions')->insert([
            'id'         => $esoDefinitionId,
            'tenant_id'  => self::TENANT,
            'org_id'     => 'org-alpha',
            'eso_code'   => 'ESO-FEE-REMIND',
            'name'       => 'Targeted fee reminder',
            'objective'  => 'improve',
            'status'     => 'active',
            'created_by' => self::MANAGER,
        ]);

        $this->postJson('/api/v1/eso-executions', [
            'decisionId'      => $decisionId,
            'esoDefinitionId' => $esoDefinitionId,
            'executorType'    => 'human',            // EXECUTE stays dark: human only
        ], $this->manager())->assertStatus(201);

        $execution = $this->events(LoopEvent::EXECUTION_STARTED);

        self::assertCount(1, $execution);
        // Inherits the decision's thread rather than starting its own.
        self::assertSame($decisionId, $execution[0]->correlation_id);

        // ---- Step 10. Outcome. -------------------------------------------
        $this->postJson('/api/v1/outcomes', [
            'tenantId'    => self::TENANT,
            'decisionId'  => $decisionId,
            'result'      => 'success',
            'metrics'     => ['collectionRate' => 0.74, 'baseline' => 0.51],
            'evidenceIds' => [$evidenceId],
            'confidence'  => 0.78,
            'feedback'    => 'Reminder landed on schedule; arrears fell within one cycle.',
        ], $this->manager())->assertStatus(201);

        self::assertCount(1, $this->events(LoopEvent::OUTCOME_RECORDED));

        // ---- Step 11. Memory. ---------------------------------------------
        $this->drainOutbox();

        self::assertCount(1, $this->events(LoopEvent::LEARNING_WRITTEN));

        $this->drainOutbox();

        self::assertCount(1, $this->events(LoopEvent::MEMORY_UPDATED));

        $learnings = DB::table('hpbrain_learnings')->get();

        self::assertCount(1, $learnings);
        // A success is offered back as a pattern to repeat; a failure would be
        // written with reusable = 0 (ADR-005).
        self::assertSame(1, (int) $learnings[0]->reusable);
        self::assertSame('Finance', $learnings[0]->domain, 'The domain must survive the walk from decision to learning.');

        // Replay. FBR §3.5: write-back must be idempotent, because a replayed
        // event must not teach the organization the same lesson twice.
        DB::table('hpbrain_event_store')
            ->where('type', LoopEvent::OUTCOME_RECORDED->value)
            ->update(['status' => 'pending']);

        $this->drainOutbox();

        self::assertSame(1, DB::table('hpbrain_learnings')->count(), 'Replay must not write a second learning.');
        self::assertCount(1, $this->events(LoopEvent::LEARNING_WRITTEN));

        // ---- The flywheel. -------------------------------------------------
        $memory = app(MemoryGrounding::class);

        $retrieved = $memory->retrieveFor(self::TENANT, 'Finance');

        self::assertCount(1, $retrieved);
        self::assertSame($learnings[0]->id, $retrieved[0]['id']);

        $memory->recordGrounding(
            self::TENANT, (string) $learnings[0]->id, 'Signal', $signalId, self::ANALYST
        );

        self::assertCount(1, $this->events(LoopEvent::LEARNING_GROUNDED));

        $stats = $memory->compoundingStats(self::TENANT);

        self::assertSame(1, $stats['learningsWritten']);
        self::assertSame(1, $stats['learningsReusable']);
        self::assertNotNull($stats['reuseRate'], 'A learning that has grounded something gives a real reuse rate.');
        self::assertSame(1.0, $stats['reuseRate']);

        // ---- The audit trail. Pilot §A's last row. -------------------------
        // "The audit trail reconstructs the case from the event log." One
        // query, one correlation id, the whole second half of the loop.
        $thread = DB::table('hpbrain_event_store')
            ->where('correlation_id', $decisionId)
            ->orderBy('created_at')->orderBy('id')
            ->pluck('type')->all();

        foreach ([
            LoopEvent::DECISION_REACHED,
            LoopEvent::EXECUTION_STARTED,
            LoopEvent::OUTCOME_RECORDED,
            LoopEvent::LEARNING_WRITTEN,
            LoopEvent::MEMORY_UPDATED,
        ] as $expected) {
            self::assertContains($expected->value, $thread, "{$expected->value} is missing from the decision's thread.");
        }

        // Causation is deterministic where created_at is not: every event in
        // this test is written within the same second, so ordering by
        // timestamp cannot prove sequence. The causation chain can.
        $outcomeEvent  = $this->events(LoopEvent::OUTCOME_RECORDED)[0];
        $learningEvent = $this->events(LoopEvent::LEARNING_WRITTEN)[0];
        $memoryEvent   = $this->events(LoopEvent::MEMORY_UPDATED)[0];

        self::assertSame($outcomeEvent->id, $learningEvent->causation_id);
        self::assertSame($learningEvent->id, $memoryEvent->causation_id);
    }

    // =====================================================================
    // 7b — compounding. The one that matters most.
    // =====================================================================

    public function test_a_second_case_is_provably_informed_by_the_first(): void
    {
        $first = $this->runFirstCaseToLearning();

        $learningId = (string) DB::table('hpbrain_learnings')->value('id');

        self::assertNotSame('', $learningId, 'Case one must have produced a learning to reuse.');

        // ---- A second, independent signal in the same tenant. ------------
        $signalId = $this->postJson('/api/v1/signals', [
            'source'         => 'attendance_register',
            'classification' => 'Finance',      // same wedge of memory
            'priority'       => 'medium',
            'severity'       => 'medium',
        ], $this->analyst())->assertStatus(201)->json('id');

        DB::table('hpbrain_signals')->where('id', $signalId)->update([
            'related_entity_type' => 'Department', 'related_entity_id' => 'dept-4',
        ]);

        $evidenceId = $this->postJson('/api/v1/evidence', [
            'tenantId'   => self::TENANT,
            'signalId'   => $signalId,
            'source'     => 'attendance_export',
            'content'    => ['note' => 'Second-term absences cluster in the same cohort.'],
            'provenance' => [
                'source' => 'ERP attendance register', 'ts' => '2026-07-26T09:00:00Z', 'confidence' => 0.7,
            ],
        ], $this->analyst())->assertStatus(201)->json('id');

        $caseId = $this->postJson('/api/v1/cases', [
            'title' => 'Second-term absence cluster', 'signalId' => $signalId,
        ], $this->analyst())->assertStatus(201)->json('id');

        $this->postJson('/api/v1/hypotheses', [
            'caseId'          => $caseId,
            'statement'       => 'Absence follows the same communication cadence as arrears.',
            'rootCauseFamily' => 'Process',
            'confidence'      => 0.6,
        ], $this->analyst())->assertStatus(201);

        $explain = $this->postJson(
            '/api/v1/reasoning-engine/'.self::TENANT.'/explain',
            ['signalId' => $signalId], $this->analyst()
        )->assertStatus(200);

        // THE ASSERTION THIS MODULE EXISTS FOR. Case two's answer stands on
        // case one's learning. Without this the flywheel does not turn and
        // everything above is theatre.
        self::assertSame('DECIDED', $explain->json('state'), 'gaps: '.json_encode($explain->json('gaps')));
        self::assertContains(
            $learningId,
            $explain->json('evidenceRefs'),
            "Case two's grounding must include case one's learning."
        );
        self::assertContains($learningId, $explain->json('value.groundedOnLearnings'));
        // And it also stood on its own evidence — memory supplements, never
        // replaces, the evidence for the specific question.
        self::assertContains($evidenceId, $explain->json('evidenceRefs'));

        // The reuse is recorded, linking case one's learning to case two's
        // signal — this is what makes "is memory compounding?" measurable
        // rather than an article of faith.
        $grounded = $this->events(LoopEvent::LEARNING_GROUNDED);

        self::assertCount(1, $grounded);
        self::assertSame($learningId, $grounded[0]->entity_id);
        self::assertSame($signalId, $grounded[0]->correlation_id);

        $payload = json_decode((string) $grounded[0]->payload, true);

        self::assertSame($signalId, $payload['groundedEntityId']);
        self::assertSame('Signal', $payload['groundedEntityType']);

        self::assertNotNull(app(MemoryGrounding::class)->compoundingStats(self::TENANT)['reuseRate']);
    }

    // =====================================================================
    // 7c — tenant isolation across the loop's new write surfaces
    // =====================================================================

    public function test_the_loop_does_not_leak_across_tenants(): void
    {
        $foreignDecisionId = Uuid::uuid4()->toString();

        DB::table('hpbrain_decisions')->insert([
            'id' => $foreignDecisionId, 'tenant_id' => 'tenant-beta',
            'decided_by' => 'their-analyst', 'rationale' => 'theirs',
            'status' => 'approved', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        // Reading another tenant's evidence.
        $this->getJson('/api/v1/evidence/tenant-beta/signal/'.Uuid::uuid4()->toString(), $this->manager())
            ->assertStatus(403)->assertJson(['error' => 'tenant_mismatch']);

        // Approving another tenant's decision.
        $this->postJson("/api/v1/decisions/tenant-beta/{$foreignDecisionId}/approve", [], $this->manager())
            ->assertStatus(403)->assertJson(['error' => 'tenant_mismatch']);

        // Explaining another tenant's signal.
        $this->postJson('/api/v1/reasoning-engine/tenant-beta/explain',
            ['signalId' => Uuid::uuid4()->toString()], $this->manager())
            ->assertStatus(403)->assertJson(['error' => 'tenant_mismatch']);

        // Recording an outcome against another tenant's decision. POST
        // /outcomes carries NO {tenantId} segment, so EnsureTenantScope has
        // nothing to compare and cannot answer 403 — the tenant is taken from
        // the token and the controller refuses the foreign decision itself.
        // The isolation property holds; the status code differs, and asserting
        // 403 here would be asserting a mechanism that does not exist on this
        // route. See the report.
        $response = $this->postJson('/api/v1/outcomes', [
            'tenantId'    => self::TENANT,
            'decisionId'  => $foreignDecisionId,
            'result'      => 'success',
            'metrics'     => ['x' => 1],
            'evidenceIds' => [Uuid::uuid4()->toString()],
            'confidence'  => 0.5,
        ], $this->manager());

        $response->assertStatus(422)->assertJson(['error' => 'decision_not_approved']);
        // What matters: nothing was written into either tenant.
        self::assertSame(0, DB::table('hpbrain_outcomes')->count());
        self::assertSame('approved', DB::table('hpbrain_decisions')->where('id', $foreignDecisionId)->value('status'));
    }

    // =====================================================================
    // Helper: case one, compressed, for the compounding test.
    // =====================================================================

    /** @return array{signalId: string, decisionId: string, evidenceId: string} */
    private function runFirstCaseToLearning(): array
    {
        $signalId = $this->postJson('/api/v1/signals', [
            'source' => 'fee_ledger', 'classification' => 'Finance',
            'priority' => 'high', 'severity' => 'high', 'confidence' => 0.8,
        ], $this->analyst())->json('id');

        DB::table('hpbrain_signals')->where('id', $signalId)->update([
            'related_entity_type' => 'Department', 'related_entity_id' => 'dept-9',
        ]);

        $evidenceId = $this->postJson('/api/v1/evidence', [
            'tenantId'   => self::TENANT,
            'signalId'   => $signalId,
            'source'     => 'fee_ledger_export',
            'content'    => ['note' => 'Arrears concentrated in 14 families.'],
            'provenance' => ['source' => 'ERP fee ledger', 'ts' => '2026-07-20T09:00:00Z', 'confidence' => 0.82],
        ], $this->analyst())->json('id');

        $reasoningStepId = $this->postJson('/api/v1/reasoning', [
            'signalId' => $signalId, 'description' => 'Cadence, not capacity.',
        ], $this->analyst())->json('id');

        $modelId = Uuid::uuid4()->toString();
        DB::table('hpbrain_mental_models')->insert([
            'id' => $modelId, 'tenant_id' => self::TENANT, 'name' => 'Fee recovery',
            'domain' => 'Finance', 'created_by' => self::ANALYST,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);
        DB::table('hpbrain_reasoning_steps')->where('id', $reasoningStepId)
            ->update(['mental_model_id' => $modelId]);

        $recommendationId = $this->postJson('/api/v1/recommendations', [
            'tenantId' => self::TENANT, 'reasoningStepId' => $reasoningStepId,
            'category' => 'investigate', 'title' => 'Review reminder cadence',
            'priority' => 'high', 'confidence' => 0.71,
        ], $this->analyst())->json('id');

        $decisionId = $this->postJson('/api/v1/decisions', [
            'tenantId' => self::TENANT, 'recommendationId' => $recommendationId,
            'rationale' => 'Cadence change is proportionate to the shortfall.',
        ], $this->analyst())->json('id');

        $this->postJson("/api/v1/decisions/".self::TENANT."/{$decisionId}/approve", [], $this->manager())
            ->assertStatus(200);

        $this->postJson('/api/v1/outcomes', [
            'tenantId' => self::TENANT, 'decisionId' => $decisionId, 'result' => 'success',
            'metrics' => ['collectionRate' => 0.74], 'evidenceIds' => [$evidenceId], 'confidence' => 0.78,
        ], $this->manager())->assertStatus(201);

        // Two passes: OutcomeRecorded -> LearningWritten -> MemoryUpdated.
        $this->drainOutbox();
        $this->drainOutbox();

        return ['signalId' => $signalId, 'decisionId' => $decisionId, 'evidenceId' => $evidenceId];
    }
}
