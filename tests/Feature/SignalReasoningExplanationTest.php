<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GET signals/{tenantId}/{id}/reasoning — SignalController::explainReasoning(),
 * Phase 5 GraphRAG's "why was this signal raised?" (roadmap doc 5.4).
 *
 * Retrieval (GraphQueryPort::reasoningTrail(), resolved through the
 * interface) is proven elsewhere (GraphReasoningTrailTest); this test
 * proves the WRAPPER around it: the endpoint exists, is tenant-scoped,
 * reports each of the three honest failure modes distinctly
 * (graph_unavailable is GraphProjection's own concern and not re-proven
 * here), and never calls the model when there is nothing beyond the bare
 * signal to ground a narrative in.
 */
final class SignalReasoningExplanationTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-signal-reasoning';

    private const OTHER_TENANT = 'tenant-signal-reasoning-other';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    private function auth(string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    /** Full chain: a signal with evidence, a case, a hypothesis, a recommendation and a decision. */
    private function seedFullChain(string $tenant, string $signalId): void
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

        DB::table('hpbrain_decisions')->insert([
            'id' => $signalId.'-dec-1', 'tenant_id' => $tenant, 'recommendation_id' => $signalId.'-rec-1',
            'decided_by' => 'user-1', 'rationale' => 'Approved outreach',
            'created_date' => '2026-08-01 00:00:00',
        ]);
    }

    public function test_a_signal_with_a_full_trail_grounds_the_narrative_in_every_step(): void
    {
        $this->seedFullChain(self::TENANT, 'sig-full');

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/signals/'.self::TENANT.'/sig-full/reasoning')
            ->assertOk()
            ->json();

        self::assertSame('sig-full', $body['origin']['id']);
        self::assertGreaterThan(1, count($body['trail']));
        self::assertArrayHasKey('nodes', $body);
        self::assertArrayHasKey('edges', $body);
        // The test environment deliberately runs with AI_PROVIDER="" (phpunit.xml) so
        // isConfigured() is false — proving the endpoint takes the honest
        // "model unavailable" branch rather than fabricating a narrative.
        self::assertNull($body['narrative']);
        self::assertSame('ai_not_configured', $body['narrativeStatus']);
    }

    public function test_a_signal_with_no_onward_trail_is_reported_as_no_evidence_without_calling_the_model(): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => 'sig-bare', 'tenant_id' => self::TENANT, 'source' => 'test',
            'classification' => 'attendance', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/signals/'.self::TENANT.'/sig-bare/reasoning')
            ->assertOk()
            ->json();

        self::assertNull($body['narrative']);
        self::assertSame('no_evidence', $body['narrativeStatus']);
    }

    public function test_a_signal_that_does_not_exist_is_a_404_not_a_fabricated_answer(): void
    {
        $this->withHeaders($this->auth())
            ->getJson('/api/v1/signals/'.self::TENANT.'/does-not-exist/reasoning')
            ->assertStatus(404)
            ->assertJson(['error' => 'signal_not_found']);
    }

    public function test_a_signal_from_another_tenant_is_not_reachable_through_this_tenants_token(): void
    {
        $this->seedFullChain(self::OTHER_TENANT, 'sig-other');

        $this->withHeaders($this->auth(self::TENANT))
            ->getJson('/api/v1/signals/'.self::TENANT.'/sig-other/reasoning')
            ->assertStatus(404)
            ->assertJson(['error' => 'signal_not_found']);
    }
}
