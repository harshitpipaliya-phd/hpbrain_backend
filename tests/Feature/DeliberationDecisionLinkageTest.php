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
 * Does a decision on a model-authored recommendation reach its case?
 *
 * THE SAME GAP AS DeliberationRecommendationLinkageTest, ONE HOP FURTHER ALONG.
 * A decision reached its case by d.recommendation_id -> r.reasoning_step_id ->
 * rs.case_id. That middle column is the hardcoded null RecommendVerb::persist
 * writes, so the moment anybody recorded a decision on a model-authored
 * recommendation it was orphaned exactly as the recommendation had been. It had
 * simply never bitten: until Lions approved a fee recommendation, every decision
 * in the installation hung off a SignalReasoner recommendation whose reasoning
 * step was populated.
 *
 * THE QUEUE TEST IS THE ONE THAT WOULD NOT HAVE BEEN WRITTEN BY ACCIDENT.
 * Three of the consumers read the grouped collection and were fixed by
 * correcting the grouping alone. The decision queue read
 * $row->linked_case_id directly, so a partial fix produced a screen that placed
 * a decision under a case in the timeline while the queue beneath it reported
 * no case for the same decision. test_the_decision_queue_agrees_with_the_grouping
 * exists to keep those two answers identical.
 */
final class DeliberationDecisionLinkageTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;

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
            'content' => json_encode(['source' => 'import.school_fee']),
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
     * A recommendation shaped as RecommendVerb::persist writes one — null
     * reasoning step, cited evidence in `dependencies`.
     *
     * @param  array<int, string>  $evidenceRefs
     */
    private function verbRecommendation(array $evidenceRefs, string $title = 'Multiple fee receipts lack collector information'): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_recommendations')->insert([
            'id' => $id, 'tenant_id' => self::TENANT, 'reasoning_step_id' => null,
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

    /** Shaped as DecisionController::store writes one: status 'proposed', no approver yet. */
    private function decision(?string $recommendationId, string $status = 'proposed'): string
    {
        $id = Uuid::uuid4()->toString();

        $row = [
            'id' => $id, 'tenant_id' => self::TENANT,
            'recommendation_id' => $recommendationId,
            'decided_by' => 'analyst@school', 'executor_type' => 'human',
            'rationale' => 'Accepting the fee-provenance finding and investigating at source.',
            'alternatives_considered' => json_encode([]), 'status' => $status,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ];

        if ($status === 'approved') {
            $row['approved_by'] = 'manager@school';
            $row['approved_date'] = now()->format('Y-m-d H:i:s');
            $row['approval_note'] = 'Approved after reviewing the cited fee evidence.';
        }

        DB::table('hpbrain_decisions')->insert($row);

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

    public function test_a_decision_on_a_verb_recommendation_reaches_its_case(): void
    {
        $signalId = $this->signal();
        $evidenceId = $this->evidence($signalId);
        $caseId = $this->openCase('fee_collection_provenance: fee_missing_collector', $signalId);

        $recommendationId = $this->verbRecommendation([$evidenceId]);
        $decisionId = $this->decision($recommendationId);

        $detail = $this->overview()['cases']['detailsById'][$caseId];

        // caseDetails['decisions'] — empty for every such decision before the fix.
        self::assertCount(1, $detail['decisions']);
        self::assertSame($decisionId, $detail['decisions'][0]['id']);
        self::assertSame('Multiple fee receipts lack collector information', $detail['decisions'][0]['recommendationTitle']);
        self::assertSame('proposed', $detail['decisions'][0]['status']);
    }

    public function test_the_decision_reaches_the_case_timeline(): void
    {
        $signalId = $this->signal();
        $evidenceId = $this->evidence($signalId);
        $caseId = $this->openCase('fee_collection_provenance: fee_missing_collector', $signalId);

        $this->decision($this->verbRecommendation([$evidenceId]));

        $stage = collect($this->overview()['cases']['detailsById'][$caseId]['timeline'])
            ->firstWhere('stage', 'Decision');

        self::assertCount(1, $stage['items']);
        // The Decision stage titles itself from the recommendation it approves.
        self::assertSame('Multiple fee receipts lack collector information', $stage['items'][0]['title']);
        self::assertSame(0.95, $stage['items'][0]['confidence']);
    }

    public function test_an_approved_decision_on_a_verb_recommendation_keeps_the_resolved_case(): void
    {
        $signalId = $this->signal();
        $evidenceId = $this->evidence($signalId);
        $caseId = $this->openCase('fee_collection_provenance: fee_missing_collector', $signalId);

        $recommendationId = $this->verbRecommendation([$evidenceId]);
        $decisionId = $this->decision($recommendationId, 'approved');

        $body = $this->overview();
        $detail = $body['cases']['detailsById'][$caseId];
        $stage = collect($detail['timeline'])->firstWhere('stage', 'Decision');

        self::assertSame($decisionId, $detail['decisions'][0]['id']);
        self::assertSame($recommendationId, $detail['decisions'][0]['recommendationId']);
        self::assertSame('approved', $detail['decisions'][0]['status']);
        self::assertSame($decisionId, $stage['items'][0]['id']);

        self::assertSame([], $body['decisionQueue']['items']);
    }

    public function test_the_case_list_knows_the_case_now_awaits_a_decision(): void
    {
        $signalId = $this->signal();
        $evidenceId = $this->evidence($signalId);
        $caseId = $this->openCase('fee_collection_provenance: fee_missing_collector', $signalId);

        $this->decision($this->verbRecommendation([$evidenceId]));

        $item = collect($this->overview()['cases']['items'])->firstWhere('id', $caseId);

        // Before the fix the decision was invisible here, so a case with a
        // decision waiting still told the reader to review the recommendation.
        self::assertSame('Review decision', $item['nextAction']);
    }

    /**
     * The consumer that reads linked_case_id directly rather than the grouping.
     */
    public function test_the_decision_queue_agrees_with_the_grouping(): void
    {
        $signalId = $this->signal();
        $evidenceId = $this->evidence($signalId);
        $caseId = $this->openCase('fee_collection_provenance: fee_missing_collector', $signalId);

        $decisionId = $this->decision($this->verbRecommendation([$evidenceId]));

        $body = $this->overview();
        $queued = collect($body['decisionQueue']['items'])->firstWhere('id', $decisionId);

        self::assertNotNull($queued, 'A proposed decision belongs in the queue.');
        self::assertSame($caseId, $queued['caseId']);

        // The two answers to "which case is this decision about" must be one
        // answer. This is the assertion a grouping-only fix would fail.
        $groupedUnder = collect($body['cases']['detailsById'][$caseId]['decisions'])->pluck('id');
        self::assertContains($queued['id'], $groupedUnder->all());
    }

    /* ──────────────────── the existing path is untouched ──────────────────── */

    public function test_a_decision_on_a_reasoning_step_recommendation_is_unaffected(): void
    {
        $signalId = $this->signal('fee_concession_review');
        $caseId = $this->openCase('fee_concession_review: fee_zero_amount_concessions', $signalId);

        $decisionId = $this->decision($this->reasoningStepRecommendation($caseId, $signalId));

        $detail = $this->overview()['cases']['detailsById'][$caseId];

        self::assertCount(1, $detail['decisions']);
        self::assertSame($decisionId, $detail['decisions'][0]['id']);
        self::assertSame('Keep watching the concession ledger', $detail['decisions'][0]['recommendationTitle']);
    }

    /* ────────────────────── what must still refuse to resolve ────────────────────── */

    public function test_a_decision_whose_evidence_spans_two_cases_attaches_to_neither(): void
    {
        $signalA = $this->signal();
        $signalB = $this->signal('fee_concession_review');
        $evidenceA = $this->evidence($signalA);
        $evidenceB = $this->evidence($signalB);

        $caseA = $this->openCase('fee_collection_provenance: fee_missing_collector', $signalA);
        $caseB = $this->openCase('fee_concession_review: fee_zero_amount_concessions', $signalB);

        $this->decision($this->verbRecommendation([$evidenceA, $evidenceB]));

        $body = $this->overview();

        self::assertSame([], $body['cases']['detailsById'][$caseA]['decisions']);
        self::assertSame([], $body['cases']['detailsById'][$caseB]['decisions']);
        // And the queue reports no case rather than picking one.
        self::assertNull($body['decisionQueue']['items'][0]['caseId']);
    }

    public function test_a_decision_with_no_recommendation_reaches_no_case(): void
    {
        $signalId = $this->signal();
        $this->evidence($signalId);
        $caseId = $this->openCase('fee_collection_provenance: fee_missing_collector', $signalId);

        // recommendation_id is nullable, and such a decision has no citation to
        // resolve through. It must not reach a case, and must not error.
        $decisionId = $this->decision(null);

        $body = $this->overview();

        self::assertSame([], $body['cases']['detailsById'][$caseId]['decisions']);

        $queued = collect($body['decisionQueue']['items'])->firstWhere('id', $decisionId);
        self::assertNull($queued['caseId']);
    }
}
