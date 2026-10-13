<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GET recommendations/{tenantId}/{id}/reasoning-trail —
 * RecommendationController::reasoningTrail(), Phase 5 GraphRAG's "show me
 * the reasoning trail" (roadmap doc 5.5). Same shape as
 * SignalReasoningExplanationTest, starting from the other end of the same
 * chain.
 */
final class RecommendationReasoningTrailTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-rec-reasoning';

    private const OTHER_TENANT = 'tenant-rec-reasoning-other';

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

    private function seedFullChain(string $tenant, string $recId): void
    {
        $signalId = $recId.'-sig';
        $evidenceId = $recId.'-ev';

        DB::table('hpbrain_signals')->insert([
            'id' => $signalId, 'tenant_id' => $tenant, 'source' => 'test',
            'classification' => 'attendance', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_evidence')->insert([
            'id' => $evidenceId, 'tenant_id' => $tenant, 'signal_id' => $signalId,
            'source' => 'test', 'content' => json_encode(['text' => 'Three late check-ins']),
            'provenance' => json_encode([]), 'hash' => 'h-'.$evidenceId, 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00',
        ]);

        // expandRecommendation()'s BACKWARD hop (Signal/Case <- Recommendation)
        // joins on reasoning_step_id, not on `dependencies` — see
        // GraphVocabulary::RELATIONSHIPS['led_to']'s own comment: the live
        // RecommendVerb pipeline always writes reasoning_step_id null, so
        // that join finds nothing for real recommendations today. The join
        // itself still works when the column IS populated, which this
        // fixture does deliberately so the backward hop has something real
        // to prove against.
        DB::table('hpbrain_reasoning_steps')->insert([
            'id' => $recId.'-step', 'tenant_id' => $tenant, 'signal_id' => $signalId,
            'description' => 'Repeated late check-ins indicate a transport issue.',
            'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_recommendations')->insert([
            'id' => $recId, 'tenant_id' => $tenant, 'reasoning_step_id' => $recId.'-step',
            'title' => 'Call the guardian', 'dependencies' => json_encode([$evidenceId]),
            'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_decisions')->insert([
            'id' => $recId.'-dec', 'tenant_id' => $tenant, 'recommendation_id' => $recId,
            'decided_by' => 'user-1', 'rationale' => 'Approved outreach',
            'created_date' => '2026-08-01 00:00:00',
        ]);
    }

    public function test_a_recommendation_with_a_full_trail_grounds_the_narrative_in_every_step(): void
    {
        $this->seedFullChain(self::TENANT, 'rec-full');

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/recommendations/'.self::TENANT.'/rec-full/reasoning-trail')
            ->assertOk()
            ->json();

        self::assertSame('rec-full', $body['origin']['id']);
        self::assertGreaterThan(1, count($body['trail']));

        $labelsSeen = array_column($body['trail'], 'label');
        self::assertContains('Signal', $labelsSeen);
        self::assertContains('Decision', $labelsSeen);

        self::assertNull($body['narrative']);
        self::assertSame('ai_not_configured', $body['narrativeStatus']);
    }

    public function test_a_recommendation_with_no_links_either_way_is_no_evidence(): void
    {
        DB::table('hpbrain_recommendations')->insert([
            'id' => 'rec-bare', 'tenant_id' => self::TENANT,
            'title' => 'Isolated recommendation', 'dependencies' => json_encode([]),
            'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/recommendations/'.self::TENANT.'/rec-bare/reasoning-trail')
            ->assertOk()
            ->json();

        self::assertNull($body['narrative']);
        self::assertSame('no_evidence', $body['narrativeStatus']);
    }

    public function test_a_recommendation_that_does_not_exist_is_a_404(): void
    {
        $this->withHeaders($this->auth())
            ->getJson('/api/v1/recommendations/'.self::TENANT.'/does-not-exist/reasoning-trail')
            ->assertStatus(404)
            ->assertJson(['error' => 'recommendation_not_found']);
    }

    public function test_a_recommendation_from_another_tenant_is_not_reachable(): void
    {
        $this->seedFullChain(self::OTHER_TENANT, 'rec-other');

        $this->withHeaders($this->auth(self::TENANT))
            ->getJson('/api/v1/recommendations/'.self::TENANT.'/rec-other/reasoning-trail')
            ->assertStatus(404)
            ->assertJson(['error' => 'recommendation_not_found']);
    }
}
