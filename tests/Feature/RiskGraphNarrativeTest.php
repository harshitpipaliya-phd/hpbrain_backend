<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GET risks/{tenantId}/{id}/graph-narrative — RiskController::graphNarrative(),
 * Phase 5 GraphRAG's "risk factors traversed from the org graph".
 *
 * RISK IS NOT A GRAPH LABEL (GraphVocabulary::LABEL_FAMILY has no "Risk"
 * entry), so this does not call reasoningTrail($tenant, 'Risk', $id) the
 * way SignalReasoningExplanationTest/RecommendationReasoningTrailTest do
 * for their own labels. It walks the trail from the risk's linked
 * Decision (preferred) or Recommendation instead — proven below against
 * both anchors, and against a risk with neither.
 */
final class RiskGraphNarrativeTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-risk-graph-narrative';

    private const OTHER_TENANT = 'tenant-risk-graph-narrative-other';

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

    /** A Signal -> Evidence -> Recommendation -> Decision chain, reasoning_step_id populated so the backward hop has something to find. */
    private function seedFullChain(string $tenant, string $key): array
    {
        $signalId = $key.'-sig';
        $recId = $key.'-rec';
        $decId = $key.'-dec';

        DB::table('hpbrain_signals')->insert([
            'id' => $signalId, 'tenant_id' => $tenant, 'source' => 'test',
            'classification' => 'attendance', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_reasoning_steps')->insert([
            'id' => $key.'-step', 'tenant_id' => $tenant, 'signal_id' => $signalId,
            'description' => 'Repeated late check-ins indicate a transport issue.',
            'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_recommendations')->insert([
            'id' => $recId, 'tenant_id' => $tenant, 'reasoning_step_id' => $key.'-step',
            'title' => 'Call the guardian', 'dependencies' => json_encode([]),
            'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_decisions')->insert([
            'id' => $decId, 'tenant_id' => $tenant, 'recommendation_id' => $recId,
            'decided_by' => 'user-1', 'rationale' => 'Approved outreach',
            'created_date' => '2026-08-01 00:00:00',
        ]);

        return ['signalId' => $signalId, 'recId' => $recId, 'decId' => $decId];
    }

    private function insertRisk(string $tenant, string $id, ?string $decisionId, ?string $recommendationId): void
    {
        DB::table('hpbrain_risks')->insert([
            'id' => $id, 'tenant_id' => $tenant,
            'decision_id' => $decisionId, 'recommendation_id' => $recommendationId,
            'category' => 'attendance', 'probability' => 0.6, 'impact' => 'medium', 'score' => 0.3,
            'status' => 'open', 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);
    }

    public function test_a_risk_anchored_on_a_decision_walks_the_trail_back_through_its_recommendation(): void
    {
        $chain = $this->seedFullChain(self::TENANT, 'r1');
        $riskId = Uuid::uuid4()->toString();
        $this->insertRisk(self::TENANT, $riskId, $chain['decId'], null);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/risks/'.self::TENANT.'/'.$riskId.'/graph-narrative')
            ->assertOk()
            ->json();

        self::assertSame('Decision', $body['anchor']['label']);
        self::assertSame($chain['decId'], $body['anchor']['id']);
        self::assertGreaterThan(1, count($body['trail']));

        $labelsSeen = array_column($body['trail'], 'label');
        self::assertContains('Recommendation', $labelsSeen);

        self::assertSame($riskId, $body['risk']['id']);
        self::assertNull($body['narrative']);
        self::assertSame('ai_not_configured', $body['narrativeStatus']);
    }

    public function test_a_risk_anchored_only_on_a_recommendation_walks_from_there(): void
    {
        $chain = $this->seedFullChain(self::TENANT, 'r2');
        $riskId = Uuid::uuid4()->toString();
        $this->insertRisk(self::TENANT, $riskId, null, $chain['recId']);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/risks/'.self::TENANT.'/'.$riskId.'/graph-narrative')
            ->assertOk()
            ->json();

        self::assertSame('Recommendation', $body['anchor']['label']);
        self::assertSame($chain['recId'], $body['anchor']['id']);
        self::assertGreaterThan(1, count($body['trail']));
    }

    public function test_a_risk_with_no_decision_or_recommendation_link_is_no_evidence_without_a_graph_call(): void
    {
        $riskId = Uuid::uuid4()->toString();
        $this->insertRisk(self::TENANT, $riskId, null, null);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/risks/'.self::TENANT.'/'.$riskId.'/graph-narrative')
            ->assertOk()
            ->json();

        self::assertNull($body['anchor']);
        self::assertNull($body['narrative']);
        self::assertSame('no_evidence', $body['narrativeStatus']);
    }

    public function test_a_risk_whose_decision_link_is_stale_is_no_evidence_not_a_404(): void
    {
        $riskId = Uuid::uuid4()->toString();
        $this->insertRisk(self::TENANT, $riskId, 'decision-does-not-exist', null);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/risks/'.self::TENANT.'/'.$riskId.'/graph-narrative')
            ->assertOk()
            ->json();

        self::assertSame('no_evidence', $body['narrativeStatus']);
        self::assertNull($body['narrative']);
    }

    public function test_a_risk_that_does_not_exist_is_a_404(): void
    {
        $this->withHeaders($this->auth())
            ->getJson('/api/v1/risks/'.self::TENANT.'/does-not-exist/graph-narrative')
            ->assertStatus(404)
            ->assertJson(['error' => 'risk_not_found']);
    }

    public function test_a_risk_from_another_tenant_is_not_reachable_through_this_tenants_token(): void
    {
        $chain = $this->seedFullChain(self::OTHER_TENANT, 'r3');
        $riskId = Uuid::uuid4()->toString();
        $this->insertRisk(self::OTHER_TENANT, $riskId, $chain['decId'], null);

        $this->withHeaders($this->auth(self::TENANT))
            ->getJson('/api/v1/risks/'.self::TENANT.'/'.$riskId.'/graph-narrative')
            ->assertStatus(404)
            ->assertJson(['error' => 'risk_not_found']);
    }
}
