<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Cases\CaseSignalLinker;
use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * Does a model-authored recommendation reach its case on the Deliberation screen?
 *
 * THE BUG THIS PINS DOWN. deliberationOverview grouped recommendations by
 * joining hpbrain_recommendations.reasoning_step_id to a reasoning step's
 * case_id. RecommendVerb::persist writes that column as a hardcoded null — see
 * its comment, it is deliberate — so every recommendation the verb pipeline has
 * ever produced grouped under the empty key. They were counted in the summary
 * KPI and invisible against their case, which is the one place a human decides
 * anything. On the real installation that was every fee recommendation Lions
 * has.
 *
 * WHY THE FIXTURES ARE SHAPED THE WAY THEY ARE. The verb-shaped recommendation
 * below carries reasoning_step_id => null and cites evidence ids in
 * `dependencies`, exactly as RecommendVerb::persist writes them. A fixture that
 * populated the reasoning step would pass against the old code and prove
 * nothing.
 *
 * WHAT IS DELIBERATELY NOT ASSERTED. That every recommendation finds a case.
 * The resolution is allowed — required — to give up when the cited evidence
 * spans two cases, and the last test holds it to that. A fix that resolved
 * more by guessing would be worse than the bug.
 */
final class DeliberationRecommendationLinkageTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;

    /** The tenant BuildsErpFixture seeds and EntityMappingSeeder maps. */
    private const TENANT = '4';

    private const ACTOR = 'test-actor';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->seedErpFixture();
        (new EntityMappingSeeder())->run();
    }

    /* ─────────────────────────── fixture builders ─────────────────────────── */

    private function signal(string $classification = 'fee_collection_provenance'): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_signals')->insert([
            'id' => $id, 'tenant_id' => self::TENANT, 'source' => 'import.school_fee',
            'classification' => $classification, 'priority' => 'high', 'severity' => 'medium',
            'confidence' => 1.0, 'status' => 'new', 'created_by' => 'system',
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function evidence(string $signalId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_evidence')->insert([
            'id' => $id, 'tenant_id' => self::TENANT, 'signal_id' => $signalId,
            'source' => 'import.school_fee', 'evidence_type' => 'observation',
            'content' => json_encode(['source' => 'import.school_fee', 'amount' => 45260]),
            'provenance' => json_encode(['source' => 'erp', 'ts' => '2026-08-12T00:00:00Z']),
            'confidence' => 1.0, 'hash' => hash('sha256', $id), 'status' => 'active',
            'created_by' => 'system', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function openCase(string $title, string $primarySignalId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_cases')->insert([
            'id' => $id, 'tenant_id' => self::TENANT, 'signal_id' => $primarySignalId,
            'title' => $title, 'status' => 'open', 'created_by' => 'brain-open-cases',
            'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => now()->format('Y-m-d H:i:s'),
        ]);

        app(CaseSignalLinker::class)->linkPrimary(self::TENANT, $id, $primarySignalId, self::ACTOR);

        return $id;
    }

    /**
     * A recommendation shaped exactly as RecommendVerb::persist writes one —
     * null reasoning step, cited evidence in `dependencies`.
     *
     * @param  array<int, string>  $evidenceRefs
     */
    private function verbRecommendation(array $evidenceRefs, string $title = 'Multiple fee receipts lack collector information'): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_recommendations')->insert([
            'id' => $id, 'tenant_id' => self::TENANT,
            'reasoning_step_id' => null,
            'category' => 'investigate', 'title' => $title,
            'description' => 'Receipts were recorded without a collector name.',
            'priority' => 'high', 'confidence' => 0.95,
            'dependencies' => json_encode($evidenceRefs), 'status' => 'pending',
            'created_by' => 'brain-reason-signals',
            'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /** A SignalReasoner-shaped recommendation: an explicit reasoning step naming the case. */
    private function reasoningStepRecommendation(string $caseId, string $signalId): string
    {
        $stepId = Uuid::uuid4()->toString();

        DB::table('hpbrain_reasoning_steps')->insert([
            'id' => $stepId, 'tenant_id' => self::TENANT, 'case_id' => $caseId,
            'signal_id' => $signalId, 'step_order' => 1,
            'description' => 'Reasoned over the fee ledger.', 'confidence_score' => 0.7,
            'created_by' => 'signal-reasoner', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_recommendations')->insert([
            'id' => $id, 'tenant_id' => self::TENANT, 'reasoning_step_id' => $stepId,
            'category' => 'watch', 'title' => 'Keep watching the concession ledger',
            'priority' => 'medium', 'confidence' => 0.6, 'dependencies' => json_encode([]),
            'status' => 'pending', 'created_by' => 'signal-reasoner',
            'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function overview(): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => self::TENANT, 'role' => 'analyst',
        ])])->getJson('/api/v1/analytics/'.self::TENANT.'/deliberation-overview')
            ->assertStatus(200)
            ->json();
    }

    /* ───────────────────────────── the regression ───────────────────────────── */

    public function test_a_verb_written_recommendation_appears_against_its_case(): void
    {
        $signalId = $this->signal();
        $evidenceA = $this->evidence($signalId);
        $evidenceB = $this->evidence($signalId);
        $caseId = $this->openCase('fee_collection_provenance: fee_missing_collector', $signalId);

        $recommendationId = $this->verbRecommendation([$evidenceA, $evidenceB]);

        $detail = $this->overview()['cases']['detailsById'][$caseId];

        // THE ASSERTION. Before the fix this array was empty for every
        // recommendation the verb pipeline has ever written.
        self::assertCount(1, $detail['recommendations']);

        $rendered = $detail['recommendations'][0];

        self::assertSame($recommendationId, $rendered['id']);
        self::assertSame('Multiple fee receipts lack collector information', $rendered['title']);
        // Every field the Case Intelligence card puts on screen.
        self::assertSame('investigate', $rendered['category']);
        self::assertSame('high', $rendered['priority']);
        self::assertSame('pending', $rendered['status']);
        self::assertSame(0.95, $rendered['confidence']);
    }

    public function test_the_recommendation_also_reaches_the_case_timeline(): void
    {
        $signalId = $this->signal();
        $evidenceId = $this->evidence($signalId);
        $caseId = $this->openCase('fee_concession_review: fee_zero_amount_concessions', $signalId);

        $this->verbRecommendation([$evidenceId], 'Zero-amount fee receipts require review');

        $timeline = collect($this->overview()['cases']['detailsById'][$caseId]['timeline'])
            ->firstWhere('stage', 'Recommendation');

        self::assertCount(1, $timeline['items']);
        self::assertSame('Zero-amount fee receipts require review', $timeline['items'][0]['title']);
    }

    /* ──────────────────────── the existing path still wins ──────────────────────── */

    public function test_a_reasoning_step_linked_recommendation_is_unaffected(): void
    {
        $signalId = $this->signal('fee_concession_review');
        $caseId = $this->openCase('fee_concession_review: fee_zero_amount_concessions', $signalId);

        $recommendationId = $this->reasoningStepRecommendation($caseId, $signalId);

        $detail = $this->overview()['cases']['detailsById'][$caseId];

        self::assertCount(1, $detail['recommendations']);
        self::assertSame($recommendationId, $detail['recommendations'][0]['id']);
        self::assertSame('watch', $detail['recommendations'][0]['category']);
    }

    public function test_both_kinds_group_under_the_same_case(): void
    {
        $signalId = $this->signal();
        $evidenceId = $this->evidence($signalId);
        $caseId = $this->openCase('fee_collection_concentration: fee_collector_concentration', $signalId);

        $viaEvidence = $this->verbRecommendation([$evidenceId]);
        $viaStep = $this->reasoningStepRecommendation($caseId, $signalId);

        $ids = array_column($this->overview()['cases']['detailsById'][$caseId]['recommendations'], 'id');

        sort($ids);
        $expected = [$viaEvidence, $viaStep];
        sort($expected);

        self::assertSame($expected, $ids);
    }

    /* ────────────────────── what must still refuse to resolve ────────────────────── */

    public function test_evidence_spanning_two_cases_is_not_attached_to_either(): void
    {
        $signalA = $this->signal();
        $signalB = $this->signal('fee_concession_review');
        $evidenceA = $this->evidence($signalA);
        $evidenceB = $this->evidence($signalB);

        $caseA = $this->openCase('fee_collection_provenance: fee_missing_collector', $signalA);
        $caseB = $this->openCase('fee_concession_review: fee_zero_amount_concessions', $signalB);

        // Cites across both cases, so there is no honest single owner.
        $this->verbRecommendation([$evidenceA, $evidenceB]);

        $details = $this->overview()['cases']['detailsById'];

        self::assertSame([], $details[$caseA]['recommendations']);
        self::assertSame([], $details[$caseB]['recommendations']);
    }

    public function test_a_recommendation_citing_nothing_reaches_no_case(): void
    {
        $signalId = $this->signal();
        $this->evidence($signalId);
        $caseId = $this->openCase('fee_collection_provenance: fee_missing_collector', $signalId);

        $this->verbRecommendation([]);

        self::assertSame([], $this->overview()['cases']['detailsById'][$caseId]['recommendations']);
    }

    /**
     * The summary counted these all along — that is what made the bug hard to
     * see. It must keep counting them now that they are also placed.
     */
    public function test_the_pending_summary_still_counts_every_recommendation(): void
    {
        $signalId = $this->signal();
        $evidenceId = $this->evidence($signalId);
        $this->openCase('fee_collection_provenance: fee_missing_collector', $signalId);

        $this->verbRecommendation([$evidenceId]);
        $this->verbRecommendation([], 'A recommendation that reaches no case');

        self::assertSame(2, $this->overview()['summary']['pendingRecommendations']);
    }
}
