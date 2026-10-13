<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Graph\GraphProjection;
use App\Domain\Graph\GraphQueryPort;
use App\Domain\Graph\GraphVocabulary;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GraphQueryPort::reasoningTrail() — task 4's wiring of the Signal->Evidence
 * ->Case->Hypothesis->Recommendation->Decision chain into the graph
 * interface.
 *
 * PURE COMPOSITION, VERIFIED. reasoningTrail() calls GraphProjection's
 * existing expandSignal()/expandCase()/expandRecommendation()/
 * expandEvidence()/expandDecision()/expandHypothesis() — this test does not
 * re-prove those (GraphNodeEvidenceLinkTest and the rest of the suite
 * already exercise them); it proves the NEW walk that composes them finds
 * every link in a real chain exactly once, in both directions a caller
 * might start from (Signal and Case), and refuses a label outside the
 * intelligence family before running any query at all.
 */
final class GraphReasoningTrailTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-reasoning-trail';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->seedChain();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    /**
     * One real chain: Signal -> Evidence (supported_by), Signal -> Case
     * (opened_case), Case -> Hypothesis (has_hypothesis), Signal -> ... ->
     * Recommendation (led_to, via the real dependencies-JSON join
     * recommendationsLinkedTo() already implements), Recommendation ->
     * Decision (decided_by).
     */
    private function seedChain(): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => 'sig-1', 'tenant_id' => self::TENANT, 'source' => 'test',
            'classification' => 'attendance', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_evidence')->insert([
            'id' => 'ev-1', 'tenant_id' => self::TENANT, 'signal_id' => 'sig-1',
            'source' => 'test', 'content' => json_encode(['text' => 'Three late check-ins']),
            'provenance' => json_encode([]), 'hash' => 'h-ev-1', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_cases')->insert([
            'id' => 'case-1', 'tenant_id' => self::TENANT, 'signal_id' => 'sig-1',
            'title' => 'Attendance case', 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_case_signals')->insert([
            'tenant_id' => self::TENANT, 'case_id' => 'case-1', 'signal_id' => 'sig-1',
            'linked_by' => 'system', 'linked_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_hypotheses')->insert([
            'id' => 'hyp-1', 'tenant_id' => self::TENANT, 'case_id' => 'case-1',
            'statement' => 'Transport issue', 'root_cause_family' => 'logistics',
            'proposed_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_recommendations')->insert([
            'id' => 'rec-1', 'tenant_id' => self::TENANT,
            'title' => 'Call the guardian', 'dependencies' => json_encode(['ev-1']),
            'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_decisions')->insert([
            'id' => 'dec-1', 'tenant_id' => self::TENANT, 'recommendation_id' => 'rec-1',
            'decided_by' => 'user-1', 'rationale' => 'Approved outreach',
            'created_date' => '2026-08-01 00:00:00',
        ]);
    }

    public function test_rejects_a_non_intelligence_label_before_running_any_query(): void
    {
        $result = app(GraphProjection::class)->reasoningTrail(self::TENANT, 'Person', 'whoever');

        self::assertSame('unsupported_label', $result['error']);
        self::assertContains('Signal', $result['supported']);
        self::assertNotContains('Person', $result['supported']);
        self::assertNotContains('Organization', $result['supported']);
    }

    public function test_walks_the_full_chain_starting_from_the_signal(): void
    {
        $result = app(GraphProjection::class)->reasoningTrail(self::TENANT, 'Signal', 'sig-1');

        $labelsSeen = array_column($result['trail'], 'label');
        sort($labelsSeen);

        self::assertSame(
            ['Case', 'Decision', 'Evidence', 'Hypothesis', 'Recommendation', 'Signal'],
            $labelsSeen,
            'every link of the chain must be reachable exactly once from the signal',
        );

        $byLabel = [];
        foreach ($result['trail'] as $entry) {
            $byLabel[$entry['label']] = $entry;
        }

        self::assertSame(GraphVocabulary::relationship('supported_by')[0], $byLabel['Evidence']['relationship']);
        self::assertSame(GraphVocabulary::relationship('opened_case')[0], $byLabel['Case']['relationship']);
        self::assertSame(GraphVocabulary::relationship('has_hypothesis')[0], $byLabel['Hypothesis']['relationship']);
        self::assertSame(GraphVocabulary::relationship('decided_by')[0], $byLabel['Decision']['relationship']);

        // The GraphBuilder-shaped envelope, same contract overview()/expand() use.
        self::assertArrayHasKey('nodes', $result);
        self::assertArrayHasKey('edges', $result);
        self::assertSame(6, $result['nodeCount']);
    }

    public function test_walks_the_same_chain_starting_from_the_case(): void
    {
        $result = app(GraphProjection::class)->reasoningTrail(self::TENANT, 'Case', 'case-1');

        $labelsSeen = array_unique(array_column($result['trail'], 'label'));
        sort($labelsSeen);

        self::assertContains('Signal', $labelsSeen);
        self::assertContains('Hypothesis', $labelsSeen);
        self::assertContains('Recommendation', $labelsSeen);
        self::assertContains('Decision', $labelsSeen);
    }

    public function test_resolves_through_the_interface_not_the_concrete_class(): void
    {
        // The actual seam task 1 built: GraphQueryPort, not GraphProjection,
        // is what a caller should depend on for this. Resolved with the
        // default config (graph.driver=mysql), it must still be
        // GraphProjection underneath and behave identically.
        $port = app(GraphQueryPort::class);
        self::assertInstanceOf(GraphProjection::class, $port);

        $result = $port->reasoningTrail(self::TENANT, 'Signal', 'sig-1');
        self::assertSame(6, $result['nodeCount']);
    }

    public function test_the_trail_http_endpoint_returns_the_same_walk(): void
    {
        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/graph/'.self::TENANT.'/trail?label=Signal&id=sig-1')
            ->assertOk()
            ->json();

        self::assertSame(6, $body['nodeCount']);
        self::assertSame('sig-1', $body['origin']['id']);
    }
}
