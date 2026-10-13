<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GET organizational-memory/{tenantId}/decision/{decisionId}/lineage —
 * OrganizationalMemoryController::decisionLineage(), ADR-005's
 * "Decisions→outcomes→learnings→mental models" chain (task 3 of the batch
 * this test was written for) — the one row in that effort that combines the
 * KG half (GraphQueryPort::reasoningTrail()) with the RAG half
 * (RetrievalService::searchMemory()) in a single narrative.
 *
 * WHAT THIS PROVES, GIVEN THE TEST SUITE RUNS WITH AI_PROVIDER="" (phpunit.xml).
 * Not the generated narrative text (every other *NarrativeTest in this app
 * takes the same honest ai_not_configured branch instead of mocking a model)
 * — what is specific to this endpoint is that BOTH halves are actually
 * retrieved and merged: the graph trail's own node count, AND a learning
 * that shares the trail's vocabulary being found through searchMemory()'s
 * keyword fallback and counted in `memoryDocumentsFound`.
 */
final class DecisionLineageNarrativeTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-decision-lineage';

    private const OTHER_TENANT = 'tenant-decision-lineage-other';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();

        // See MentalModelAskTest's setUp() for why: a fast fake 401 stands
        // in for this installation's real, reachable-but-unauthorized
        // GEMINI_API_KEY, so these tests exercise the keyword fallback
        // without paying GeminiProvider's real 30-second HTTP timeout —
        // multiplied here across up to 3 memoryDocumentsForTrail() terms
        // per request.
        Http::fake(fn () => Http::response('unauthorized', 401));
    }

    private function auth(string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    /** Same shape as SignalReasoningExplanationTest::seedFullChain(). */
    private function seedFullChain(string $tenant, string $signalId): string
    {
        DB::table('hpbrain_signals')->insert([
            'id' => $signalId, 'tenant_id' => $tenant, 'source' => 'test',
            'classification' => 'attendance', 'severity' => 'high', 'confidence' => 0.8,
            'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_evidence')->insert([
            'id' => $signalId.'-ev-1', 'tenant_id' => $tenant, 'signal_id' => $signalId,
            'source' => 'test', 'content' => json_encode(['text' => 'Three late check-ins']),
            'provenance' => json_encode([]), 'hash' => 'h-'.$signalId, 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_cases')->insert([
            'id' => $signalId.'-case-1', 'tenant_id' => $tenant, 'signal_id' => $signalId,
            'title' => 'Attendance case', 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_case_signals')->insert([
            'tenant_id' => $tenant, 'case_id' => $signalId.'-case-1', 'signal_id' => $signalId,
            'linked_by' => 'system', 'linked_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_hypotheses')->insert([
            'id' => $signalId.'-hyp-1', 'tenant_id' => $tenant, 'case_id' => $signalId.'-case-1',
            'statement' => 'Transport issue', 'root_cause_family' => 'logistics',
            'proposed_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_recommendations')->insert([
            'id' => $signalId.'-rec-1', 'tenant_id' => $tenant,
            'title' => 'Call the guardian', 'dependencies' => json_encode([$signalId.'-ev-1']),
            'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        $decisionId = $signalId.'-dec-1';

        DB::table('hpbrain_decisions')->insert([
            'id' => $decisionId, 'tenant_id' => $tenant, 'recommendation_id' => $signalId.'-rec-1',
            'decided_by' => 'user-1', 'rationale' => 'Approved outreach',
            'created_date' => '2026-08-01 00:00:00',
        ]);

        return $decisionId;
    }

    public function test_a_decision_with_a_full_trail_and_a_matching_learning_grounds_both_halves(): void
    {
        $decisionId = $this->seedFullChain(self::TENANT, 'sig-full');

        // expandRecommendation()'s backward walk to Signal/Case goes through
        // hpbrain_reasoning_steps.reasoning_step_id — a column
        // RecommendVerb::persist() always writes null in the real pipeline
        // (see GraphVocabulary::RELATIONSHIPS['led_to']'s own note), so a
        // decision's realistic reasoningTrail from Decision stops at its
        // Recommendation; this fixture does not fabricate a reasoning_steps
        // row to reach further than production data actually would. The
        // learning's pattern below CONTAINS the Recommendation's own title
        // verbatim ("Call the guardian"), which is the trail node title
        // memoryDocumentsForTrail() searches on and the exact substring
        // searchMemory()'s `LIKE '%term%'` keyword fallback needs — with no
        // embedding provider in play, a near-miss wording ("Calling the
        // guardian") would not match, so this is deliberately not a
        // paraphrase.
        DB::table('hpbrain_learnings')->insert([
            'id' => 'learning-guardian-1', 'tenant_id' => self::TENANT,
            'pattern' => 'Call the guardian: reduces repeat absences',
            'description' => 'Call the guardian: reduces repeat absences',
            'domain' => 'guardian-outreach', 'confidence' => 0.8, 'reusable' => 1,
            'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizational-memory/'.self::TENANT.'/decision/'.$decisionId.'/lineage')
            ->assertOk()
            ->json();

        self::assertSame($decisionId, $body['origin']['id']);
        self::assertGreaterThan(1, count($body['trail']), 'The KG half: an onward/upstream trail beyond the bare decision.');
        self::assertGreaterThan(0, $body['memoryDocumentsFound'], 'The RAG half: a learning found through searchMemory().');
        self::assertNull($body['narrative']);
        self::assertSame('ai_not_configured', $body['narrativeStatus']);
    }

    public function test_a_decision_with_no_onward_trail_and_no_matching_memory_is_no_evidence(): void
    {
        // No recommendation_id at all: expandDecision() has nothing to walk
        // to, so the trail stays at the bare decision — the genuine
        // no-onward-trail case, same as SignalReasoningExplanationTest's
        // 'sig-bare' for Signal.
        DB::table('hpbrain_decisions')->insert([
            'id' => 'dec-bare', 'tenant_id' => self::TENANT, 'recommendation_id' => null,
            'decided_by' => 'user-1', 'rationale' => 'Zzqxw unrelated rationale',
            'created_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizational-memory/'.self::TENANT.'/decision/dec-bare/lineage')
            ->assertOk()
            ->json();

        self::assertNull($body['narrative']);
        self::assertSame('no_evidence', $body['narrativeStatus']);
    }

    public function test_a_decision_that_does_not_exist_is_a_404_not_a_fabricated_answer(): void
    {
        $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizational-memory/'.self::TENANT.'/decision/does-not-exist/lineage')
            ->assertStatus(404)
            ->assertJson(['error' => 'decision_not_found']);
    }

    public function test_a_decision_from_another_tenant_is_not_reachable_through_this_tenants_token(): void
    {
        $decisionId = $this->seedFullChain(self::OTHER_TENANT, 'sig-other');

        $this->withHeaders($this->auth(self::TENANT))
            ->getJson('/api/v1/organizational-memory/'.self::TENANT.'/decision/'.$decisionId.'/lineage')
            ->assertStatus(404)
            ->assertJson(['error' => 'decision_not_found']);
    }
}
