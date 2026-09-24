<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Cases\CaseSignalLinker;
use App\Domain\Events\LoopEvent;
use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

final class ExecutionOutcomeLearningFlowTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;

    private const TENANT = '4';
    private const ACTOR = 'manager@school';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->seedErpFixture();
        (new EntityMappingSeeder())->run();
    }

    private function auth(string $role = 'manager'): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => self::ACTOR,
            'tenantId' => self::TENANT,
            'role' => $role,
        ])];
    }

    /** @return array{signal: string, evidence: string, case: string} */
    private function caseWithEvidence(string $title): array
    {
        $signalId = Uuid::uuid4()->toString();
        $evidenceId = Uuid::uuid4()->toString();
        $caseId = Uuid::uuid4()->toString();

        DB::table('hpbrain_signals')->insert([
            'id' => $signalId,
            'tenant_id' => self::TENANT,
            'source' => 'import.school_fee',
            'classification' => 'fee_collection_provenance',
            'priority' => 'high',
            'severity' => 'medium',
            'confidence' => 1.0,
            'status' => 'new',
            'created_by' => 'system',
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        DB::table('hpbrain_evidence')->insert([
            'id' => $evidenceId,
            'tenant_id' => self::TENANT,
            'signal_id' => $signalId,
            'source' => 'import.school_fee',
            'evidence_type' => 'observation',
            'content' => json_encode(['source' => 'import.school_fee', 'amount' => 45260]),
            'provenance' => json_encode(['source' => 'erp', 'ts' => '2026-08-12T00:00:00Z']),
            'confidence' => 1.0,
            'hash' => hash('sha256', $evidenceId),
            'status' => 'active',
            'created_by' => 'system',
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        DB::table('hpbrain_cases')->insert([
            'id' => $caseId,
            'tenant_id' => self::TENANT,
            'signal_id' => $signalId,
            'title' => $title,
            'status' => 'open',
            'created_by' => 'brain-open-cases',
            'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => now()->format('Y-m-d H:i:s'),
        ]);

        app(CaseSignalLinker::class)->linkPrimary(self::TENANT, $caseId, $signalId, self::ACTOR);

        return ['signal' => $signalId, 'evidence' => $evidenceId, 'case' => $caseId];
    }

    /** @param array<int, string> $evidenceIds */
    private function recommendation(array $evidenceIds, ?string $reasoningStepId = null, ?string $esoId = null): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_recommendations')->insert([
            'id' => $id,
            'tenant_id' => self::TENANT,
            'reasoning_step_id' => $reasoningStepId,
            'category' => 'intervene',
            'title' => 'Run targeted fee collection follow-up',
            'description' => 'Approved action from cited fee-ledger evidence.',
            'priority' => 'high',
            'confidence' => 0.91,
            'impact' => 'collection rate +0.12',
            'dependencies' => json_encode($evidenceIds),
            'status' => 'approved',
            'eso_id' => $esoId,
            'created_by' => 'brain-recommend',
            'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function approvedDecision(string $recommendationId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_decisions')->insert([
            'id' => $id,
            'tenant_id' => self::TENANT,
            'recommendation_id' => $recommendationId,
            'decided_by' => 'analyst@school',
            'executor_type' => 'human',
            'rationale' => 'Approved after reviewing the cited fee evidence.',
            'alternatives_considered' => json_encode([]),
            'status' => 'approved',
            'approved_by' => self::ACTOR,
            'approved_date' => now()->format('Y-m-d H:i:s'),
            'approval_note' => 'Proceed with measured execution.',
            'confidence' => 0.9,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function esoDefinition(): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_eso_definitions')->insert([
            'id' => $id,
            'tenant_id' => self::TENANT,
            'eso_code' => 'ESO-FEE-FOLLOWUP',
            'name' => 'Targeted fee collection follow-up',
            // A published ESO. The column defaults to 'draft', which
            // EsoPreflight refuses to run.
            'status' => 'active',
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function overview(): array
    {
        return $this->getJson('/api/v1/analytics/'.self::TENANT.'/execution-overview?status=all', $this->auth())
            ->assertOk()
            ->json();
    }

    public function test_approved_recommendation_flows_through_execution_outcome_and_learning(): void
    {
        $fixture = $this->caseWithEvidence('fee_collection_provenance: missing collector');
        $esoId = $this->esoDefinition();
        $recommendationId = $this->recommendation([$fixture['evidence']], esoId: $esoId);
        $decisionId = $this->approvedDecision($recommendationId);

        $queued = collect($this->overview()['executionQueue']['items'])->firstWhere('id', $decisionId);

        self::assertNotNull($queued);
        self::assertSame($fixture['case'], $queued['caseId']);
        self::assertSame($recommendationId, $queued['recommendationId']);
        self::assertSame([$fixture['evidence']], $queued['recommendation']['citationEvidenceIds']);

        $this->postJson('/api/v1/measurement-plans', [
            'decisionId' => $decisionId,
            'baselineMetric' => 'collection_rate',
            'baselineValue' => 0.51,
            'targetValue' => 0.7,
            'metricUnit' => 'ratio',
            'measurementWindowDays' => 14,
        ], $this->auth())->assertCreated();

        $executionId = $this->postJson('/api/v1/eso-executions', [
            'decisionId' => $decisionId,
            'esoDefinitionId' => $esoId,
            'executorType' => 'human',
        ], $this->auth())->assertCreated()->json('id');

        $this->patchJson('/api/v1/eso-executions/'.self::TENANT.'/'.$executionId.'/transition', [
            'status' => 'completed',
        ], $this->auth())->assertOk();

        $outcomeId = $this->postJson('/api/v1/outcomes', [
            'tenantId' => self::TENANT,
            'decisionId' => $decisionId,
            'result' => 'success',
            'metrics' => ['collection_rate' => 0.74],
            'evidenceIds' => [$fixture['evidence']],
            'feedback' => 'Follow-up improved collection above the target window.',
            'confidence' => 0.82,
        ], $this->auth())->assertCreated()->json('id');

        $this->artisan('brain:process-events', ['--once' => true])->assertExitCode(0);
        $this->artisan('brain:process-events', ['--once' => true])->assertExitCode(0);

        $overview = $this->overview();
        $execution = collect($overview['activeExecutions']['items'])->firstWhere('id', $executionId);
        $loop = collect($overview['outcomeLoop'])->firstWhere('executionId', $executionId);

        self::assertSame('completed', $execution['status']);
        self::assertSame('success', $execution['outcome']['result']);
        self::assertSame(['collection_rate' => 0.74], $execution['outcome']['metrics']);
        self::assertSame('success', $loop['outcome']);
        self::assertSame(1, $loop['learningCount']);
        self::assertSame(1, $loop['reusableLearningCount']);

        self::assertDatabaseHas('hpbrain_learnings', [
            'tenant_id' => self::TENANT,
            'outcome_id' => $outcomeId,
            'reusable' => 1,
        ]);
        self::assertSame(1, DB::table('hpbrain_event_store')
            ->where('type', LoopEvent::MEMORY_UPDATED->value)
            ->where('correlation_id', $decisionId)
            ->count());
    }

    public function test_reasoning_step_decision_case_linkage_still_wins_in_execution_queue(): void
    {
        $dependencyCase = $this->caseWithEvidence('case reached by cited evidence');
        $reasoningCase = $this->caseWithEvidence('case explicitly named by reasoning step');

        $stepId = Uuid::uuid4()->toString();
        DB::table('hpbrain_reasoning_steps')->insert([
            'id' => $stepId,
            'tenant_id' => self::TENANT,
            'case_id' => $reasoningCase['case'],
            'signal_id' => $reasoningCase['signal'],
            'step_order' => 1,
            'description' => 'Explicit reasoning-step linkage.',
            'confidence_score' => 0.8,
            'created_by' => 'signal-reasoner',
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $decisionId = $this->approvedDecision($this->recommendation([$dependencyCase['evidence']], $stepId));

        $queued = collect($this->overview()['executionQueue']['items'])->firstWhere('id', $decisionId);

        self::assertSame($reasoningCase['case'], $queued['caseId']);
        self::assertNotSame($dependencyCase['case'], $queued['caseId']);
    }
}
