<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\PromptTemplates;
use App\Domain\Ai\Providers\NullAiProvider;
use App\Domain\Verbs\EvaluateVerb;
use App\Domain\Verbs\RecommendVerb;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The model sits behind an interface, every call is recorded, and nothing the
 * model says is believed without checking (ADR-004, Invariant 7).
 *
 * NO TEST HERE MAKES A NETWORK CALL. Every case binds a NullAiProvider carrying
 * the exact canned body the case is about, so an assertion about a parse
 * failure or a fabricated citation is deterministic rather than dependent on
 * what a real model happened to say. Http::preventStrayRequests() makes that a
 * guarantee rather than an intention.
 *
 * SCHEMA IS BUILT HERE — see Tests\Support\BuildsBrainSchema for why
 * RefreshDatabase cannot run on this project.
 */
final class AiProviderTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-alpha';
    private const ANALYST = 'user-analyst';

    private string $signalId;
    private string $evidenceId;

    protected function setUp(): void
    {
        parent::setUp();

        // Any HTTP call from this point is a test failure, not a slow test.
        Http::preventStrayRequests();

        $this->buildBrainSchema();

        // A named provider, so AiGateway::isConfigured() is true and the verbs
        // run. The bound driver is still the null one — see bindProvider().
        config(['brain.ai.provider' => 'null']);

        $this->seedPromptTemplates();

        $this->signalId = Uuid::uuid4()->toString();
        DB::table('hpbrain_signals')->insert([
            'id' => $this->signalId, 'tenant_id' => self::TENANT, 'org_id' => 'org-1',
            'source' => 'fee_ledger', 'classification' => 'Finance', 'severity' => 'high',
            'created_by' => self::ANALYST, 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $this->evidenceId = Uuid::uuid4()->toString();
        DB::table('hpbrain_evidence')->insert([
            'id' => $this->evidenceId, 'tenant_id' => self::TENANT, 'signal_id' => $this->signalId,
            'source' => 'fee_ledger_export', 'content' => json_encode(['note' => 'arrears']),
            'provenance' => json_encode(['source' => 'ERP', 'ts' => '2026-07-20T09:00:00Z', 'confidence' => 0.82]),
            'confidence' => 0.82, 'hash' => str_repeat('a', 64), 'created_by' => self::ANALYST,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function seedPromptTemplates(): void
    {
        (new \Database\Seeders\PromptTemplateSeeder())->run();
    }

    /** Binds the canned driver this case needs, replacing the container's. */
    private function bindProvider(?string $content = null, ?\Throwable $throws = null): void
    {
        $this->app->instance(AiProvider::class, new NullAiProvider(
            content: $content, throws: $throws, inputTokens: 1200, outputTokens: 340
        ));
        // AiGateway is constructed with the provider, so it must be rebuilt too.
        $this->app->forgetInstance(\App\Domain\Ai\AiGateway::class);
    }

    private function recommend(string $role = 'analyst')
    {
        return app(RecommendVerb::class)->run(self::TENANT, $this->signalId, self::ANALYST, $role);
    }

    private function evaluate(string $role = 'analyst')
    {
        return app(EvaluateVerb::class)->run(self::TENANT, $this->signalId, self::ANALYST, $role);
    }

    /** A well-formed recommendation citing only supplied evidence. */
    private function goodRecommendation(): string
    {
        return json_encode(['claims' => [[
            'title' => 'Review reminder cadence for Grade 9 families',
            'category' => 'investigate', 'priority' => 'high', 'confidence' => 0.71,
            'rationale' => 'Arrears track the reminder schedule.',
            'evidenceRefs' => [$this->evidenceId],
        ]]], JSON_THROW_ON_ERROR);
    }

    // ---- Invariant 7: every call is recorded --------------------------------

    public function test_a_successful_call_writes_exactly_one_execution_row(): void
    {
        $this->bindProvider($this->goodRecommendation());

        $result = $this->recommend();

        self::assertFalse($result->isUndetermined(), 'gaps: '.implode(', ', $result->gaps));

        $rows = DB::table('hpbrain_ai_executions')->get();

        self::assertCount(1, $rows);
        self::assertSame('null', $rows[0]->provider);
        self::assertSame(NullAiProvider::MODEL, $rows[0]->model);
        self::assertSame('completed', $rows[0]->status);
        self::assertSame(1200, (int) $rows[0]->input_tokens);
        self::assertSame(340, (int) $rows[0]->output_tokens);
        self::assertNotNull($rows[0]->latency_ms);
        self::assertSame('verb.recommend', $rows[0]->service_name);
        self::assertSame(self::ANALYST, $rows[0]->user_id);
        // What it was reasoning ABOUT — an execution row that cannot be tied to
        // an entity cannot make a recommendation traceable.
        self::assertSame('Signal', $rows[0]->entity_type);
        self::assertSame($this->signalId, $rows[0]->entity_id);
        // The null driver is not priced, and an unpriced call records NULL
        // rather than asserting it was free.
        self::assertNull($rows[0]->estimated_cost_usd);
    }

    public function test_a_failed_call_still_writes_an_execution_row(): void
    {
        $this->bindProvider(throws: new RuntimeException('anthropic_http_529: overloaded'));

        $result = $this->recommend();

        // The failure is recorded BEFORE it is re-thrown: a provider outage is
        // the circumstance in which the execution log matters most.
        $rows = DB::table('hpbrain_ai_executions')->get();

        self::assertCount(1, $rows);
        self::assertSame('failed', $rows[0]->status);
        self::assertStringContainsString('overloaded', (string) $rows[0]->error);
        self::assertNull($rows[0]->input_tokens);

        // And the caller is told, rather than being handed a guess.
        self::assertTrue($result->isUndetermined());
        self::assertSame(['ai_call_failed'], $result->gaps);
        self::assertSame(0, DB::table('hpbrain_recommendations')->count());
    }

    // ---- Nothing the model says is believed without checking ----------------

    public function test_unparseable_json_is_undetermined_and_writes_no_recommendation(): void
    {
        // The classic failure: a helpful paragraph instead of the object.
        $this->bindProvider('I would suggest reviewing the reminder cadence for Grade 9.');

        $result = $this->recommend();

        self::assertTrue($result->isUndetermined());
        self::assertContains('ai_response_unparseable', $result->gaps);
        // Never the raw text as a conclusion.
        self::assertNull($result->value);
        self::assertSame(0, DB::table('hpbrain_recommendations')->count());
        // The call still happened and is still recorded.
        self::assertSame(1, DB::table('hpbrain_ai_executions')->count());
    }

    public function test_a_claim_citing_evidence_it_was_never_given_is_dropped(): void
    {
        $fabricated = Uuid::uuid4()->toString();

        // Two claims: one honest, one citing an id that was never in the
        // grounding set. A model citing evidence it was not given has invented
        // a source, and an invented source looks exactly like a real one.
        $this->bindProvider(json_encode(['claims' => [
            [
                'title' => 'Review reminder cadence', 'category' => 'investigate',
                'priority' => 'high', 'confidence' => 0.7,
                'evidenceRefs' => [$this->evidenceId],
            ],
            [
                'title' => 'Restructure the fee schedule', 'category' => 'intervene',
                'priority' => 'critical', 'confidence' => 0.95,
                'evidenceRefs' => [$fabricated],
            ],
        ]], JSON_THROW_ON_ERROR));

        $result = $this->recommend();

        self::assertFalse($result->isUndetermined(), 'gaps: '.implode(', ', $result->gaps));

        // Exactly one recommendation was written: the honest one.
        $written = DB::table('hpbrain_recommendations')->get();

        self::assertCount(1, $written);
        self::assertSame('Review reminder cadence', $written[0]->title);

        // And the drop is REPORTED, naming the fabricated id — a silently
        // dropped claim is indistinguishable from a model that never made it.
        $dropped = $result->value['droppedClaims'];

        self::assertNotEmpty($dropped);
        self::assertStringContainsString('ai_claim_cited_ungrounded_evidence', implode(' ', $dropped));
        self::assertStringContainsString($fabricated, implode(' ', $dropped));
    }

    public function test_a_claim_missing_a_required_field_is_dropped_not_defaulted(): void
    {
        // No priority. Filling one in would be the system inventing the very
        // thing it asked the model to supply.
        $this->bindProvider(json_encode(['claims' => [[
            'title' => 'Review reminder cadence', 'category' => 'investigate',
            'confidence' => 0.7, 'evidenceRefs' => [$this->evidenceId],
        ]]], JSON_THROW_ON_ERROR));

        $result = $this->recommend();

        self::assertTrue($result->isUndetermined());
        self::assertContains('ai_claim_missing_fields:priority', $result->gaps);
        self::assertSame(0, DB::table('hpbrain_recommendations')->count());
    }

    // ---- The pipeline refuses before it spends money -------------------------

    public function test_empty_grounding_returns_undetermined_without_calling_the_provider(): void
    {
        $this->bindProvider($this->goodRecommendation());

        // A signal nobody has evidenced, in a tenant with no learnings.
        $bare = Uuid::uuid4()->toString();
        DB::table('hpbrain_signals')->insert([
            'id' => $bare, 'tenant_id' => self::TENANT, 'source' => 'x', 'classification' => 'Finance',
            'created_by' => self::ANALYST, 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $result = app(RecommendVerb::class)->run(self::TENANT, $bare, self::ANALYST, 'analyst');

        self::assertTrue($result->isUndetermined());
        self::assertContains('no_grounding_evidence', $result->gaps);
        // ADR-004: ungrounded generation is prohibited — so the provider is
        // never reached, and no money is spent asking a question that could
        // only be answered by invention.
        self::assertSame(0, DB::table('hpbrain_ai_executions')->count());
    }

    public function test_an_actionable_category_without_an_eso_binding_is_rejected(): void
    {
        // Module 3's rule, now shared by both writers of this table: a model
        // may not tell somebody to intervene without naming what to run.
        $this->bindProvider(json_encode(['claims' => [[
            'title' => 'Restructure the fee schedule now', 'category' => 'intervene',
            'priority' => 'critical', 'confidence' => 0.9,
            'evidenceRefs' => [$this->evidenceId],
        ]]], JSON_THROW_ON_ERROR));

        $result = $this->recommend();

        self::assertTrue($result->isUndetermined());
        self::assertContains('eso_binding_required', $result->gaps);
        self::assertSame(0, DB::table('hpbrain_recommendations')->count());
    }

    public function test_the_same_rule_rejects_an_actionable_recommendation_over_http(): void
    {
        // The HTTP writer and the verb enforce ONE rule object, so they cannot
        // drift apart.
        $stepId = Uuid::uuid4()->toString();
        DB::table('hpbrain_reasoning_steps')->insert([
            'id' => $stepId, 'tenant_id' => self::TENANT, 'signal_id' => $this->signalId,
            'description' => 'seeded', 'created_by' => self::ANALYST,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $this->postJson('/api/v1/recommendations', [
            'tenantId' => self::TENANT, 'reasoningStepId' => $stepId,
            'category' => 'intervene', 'title' => 'Restructure the fee schedule',
            'priority' => 'critical', 'confidence' => 0.9,
        ], ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => self::ANALYST, 'tenantId' => self::TENANT, 'role' => 'analyst',
        ])])->assertStatus(422)->assertJson(['error' => 'eso_binding_required']);
    }

    // ---- EVALUATE ------------------------------------------------------------

    public function test_evaluate_writes_a_reasoning_step_with_a_clamped_confidence(): void
    {
        // 1.4 is not great certainty, it is a schema violation — and storing it
        // would corrupt every average over confidence_score.
        $this->bindProvider(json_encode(['assessments' => [[
            'assessment' => 'The case rests on one export and is weakly corroborated.',
            'confidence' => 1.4,
            'evidenceRefs' => [$this->evidenceId],
        ]]], JSON_THROW_ON_ERROR));

        $result = $this->evaluate();

        self::assertFalse($result->isUndetermined(), 'gaps: '.implode(', ', $result->gaps));

        $step = DB::table('hpbrain_reasoning_steps')->first();

        self::assertNotNull($step);
        $confidence = (float) $step->confidence_score;
        self::assertGreaterThanOrEqual(0.0, $confidence);
        self::assertLessThanOrEqual(1.0, $confidence);
        self::assertSame(1.0, $confidence);
        // Machine-authored steps are marked as such; a ledger that cannot tell
        // them from a person's judgement is not auditable.
        self::assertStringStartsWith('[EVALUATE/ai]', (string) $step->description);
        self::assertSame(1, DB::table('hpbrain_ai_executions')->where('service_name', 'verb.evaluate')->count());
    }

    // ---- Traceability to the exact prompt version ---------------------------

    public function test_the_execution_row_names_the_prompt_version_that_produced_it(): void
    {
        $this->bindProvider($this->goodRecommendation());

        $this->recommend();

        $active = app(PromptTemplates::class)->active(self::TENANT, 'recommend');
        $execution = DB::table('hpbrain_ai_executions')->first();

        self::assertSame($active['id'], $execution->prompt_template_id);
        self::assertSame(1, $active['version']);

        // A newer active version supersedes it, and the NEXT call records the
        // new id — which is the whole point of storing the id rather than the
        // prompt text.
        $newId = Uuid::uuid4()->toString();
        DB::table('hpbrain_prompt_templates')->insert([
            'id' => $newId, 'tenant_id' => PromptTemplates::SHARED, 'name' => 'recommend',
            'template' => 'v2 prompt', 'variables' => '[]', 'version' => 2,
            'previous_version_id' => $active['id'], 'status' => 'active',
            'created_by' => 'system', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $this->recommend();

        // Counted, not ordered. Both rows are written within the same second
        // and their ids are random UUIDs, so ORDER BY created_date, id decides
        // nothing — the first call used v1 and the second used v2, and that is
        // exactly what one row of each proves.
        self::assertSame(2, DB::table('hpbrain_ai_executions')->count());
        self::assertSame(1, DB::table('hpbrain_ai_executions')
            ->where('prompt_template_id', $active['id'])->count());
        self::assertSame(1, DB::table('hpbrain_ai_executions')
            ->where('prompt_template_id', $newId)->count());
    }

    // ---- The honest refusal is preserved ------------------------------------

    public function test_summarize_evidence_still_refuses_when_no_provider_is_configured(): void
    {
        // The golden intelligence-flow test asserts this exact gap string and
        // must never need editing.
        config(['brain.ai.provider' => '']);

        $response = $this->postJson('/api/v1/ai/evidence/summarize', [
            'signalId' => $this->signalId,
        ], ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => self::ANALYST, 'tenantId' => self::TENANT, 'role' => 'analyst',
        ])]);

        $response->assertStatus(200);
        self::assertSame('UNDETERMINED', $response->json('state'));
        self::assertSame(['no_ai_provider_configured'], $response->json('gaps'));
        self::assertContains($this->evidenceId, $response->json('evidenceRefs'));
        // Nothing was called, so nothing was recorded.
        self::assertSame(0, DB::table('hpbrain_ai_executions')->count());
    }

    public function test_the_verbs_refuse_when_no_provider_is_configured(): void
    {
        config(['brain.ai.provider' => '']);
        $this->bindProvider($this->goodRecommendation());

        $result = $this->recommend();

        // The canned driver is bound, and it is still not used: an unconfigured
        // deployment must not produce fiction that reads like reasoning.
        self::assertTrue($result->isUndetermined());
        self::assertSame(['no_ai_provider_configured'], $result->gaps);
        self::assertSame(0, DB::table('hpbrain_ai_executions')->count());
        self::assertSame(0, DB::table('hpbrain_recommendations')->count());
    }

    public function test_governance_refuses_a_role_without_create(): void
    {
        $this->bindProvider($this->goodRecommendation());

        $this->expectException(\RuntimeException::class);

        try {
            $this->recommend(role: 'viewer');
        } finally {
            // Refused before grounding, so before the provider — a caller who
            // may not create must not be able to spend money either.
            self::assertSame(0, DB::table('hpbrain_ai_executions')->count());
        }
    }
}
