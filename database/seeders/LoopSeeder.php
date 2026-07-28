<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Evidence\EvidenceService;
use App\Domain\Learning\LearningService;
use App\Domain\Reasoning\ReasoningService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class LoopSeeder extends Seeder
{
    private const TENANT = '6';
    private const ORG_ID = 'demo-org-1';	
    private const ACTOR  = 'seed-script';

    public function run(): void
    {
        if (DB::table('hpbrain_signals')->where('tenant_id', self::TENANT)->exists()) {
            $this->command?->info('Already seeded — skipping. Safe to re-run.');
            return;
        }

        $now = now()->format('Y-m-d H:i:s');
        $id  = fn () => Uuid::uuid4()->toString();

        $this->command?->info('Seeding the complete loop...');

        // ── Signal ────────────────────────────────────────────────────────
        $signalId = $id();
        DB::table('hpbrain_signals')->insert([
            'id'             => $signalId,
            'tenant_id'      => self::TENANT,
            'org_id'         => self::ORG_ID,
            'source'         => 'fee-module',
            'classification' => 'defaulter_risk',
            'priority'       => 'high',
            'severity'       => 'medium',
            'confidence'     => 0.7,
            'status'         => 'new',
            'metadata'       => json_encode(['grade' => 9]),
            'created_by'     => self::ACTOR,
            'created_date'   => $now,
            'updated_date'   => $now,
        ]);
        $this->command?->info('  Signal: defaulter_risk');

        // ── Evidence ──────────────────────────────────────────────────────
        $evidenceId = $id();
        DB::table('hpbrain_evidence')->insert([
            'id'            => $evidenceId,
            'tenant_id'     => self::TENANT,
            'signal_id'     => $signalId,
            'source'        => 'fee-ledger',
            'evidence_type' => 'observation',
            'content'       => json_encode([
                'text' => 'Grade 9 collection is 18% below the same month last year.',
            ]),
            'provenance'    => json_encode([
                'module' => 'fee-module',
                'report' => 'monthly-reconciliation',
            ]),
            'confidence'    => 0.9,
            'hash'          => 'seed',
            'version'       => 1,
            'status'        => 'active',
            'observed_date' => $now,
            'created_by'    => self::ACTOR,
            'created_date'  => $now,
        ]);
        $this->command?->info('  Evidence collected');

        // ── Case ──────────────────────────────────────────────────────────
        $caseId = $id();
        DB::table('hpbrain_cases')->insert([
            'id'         => $caseId,
            'tenant_id'  => self::TENANT,
            'signal_id'  => $signalId,
            'title'      => 'Recurring fee shortfall — Grade 9',
            'status'     => 'investigating',
            'created_by' => self::ACTOR,
            'created_date' => $now,
        ]);
        DB::table('hpbrain_case_evidence')->insert([
            'tenant_id'   => self::TENANT,
            'case_id'     => $caseId,
            'evidence_id' => $evidenceId,
        ]);
        $this->command?->info('  Case opened');

        // ── Hypothesis ────────────────────────────────────────────────────
        $hypothesisId = $id();
        DB::table('hpbrain_hypotheses')->insert([
            'id'                    => $hypothesisId,
            'tenant_id'             => self::TENANT,
            'case_id'               => $caseId,
            'statement'             => 'The shortfall is a Motivation issue (reminder fatigue), not a Capability gap.',
            'root_cause_family'     => 'Motivation',
            'confidence'            => 0.6,
            'status'                => 'confirmed',
            'supporting_evidence_ids' => json_encode([$evidenceId]),
            'proposed_by'           => self::ACTOR,
            'created_date'          => $now,
        ]);
        $this->command?->info('  Hypothesis proposed: Motivation');

        // ── Reasoning (confidence is COMPUTED, never asserted) ────────────
        $reasoning    = new ReasoningService(new EvidenceService(90));
        $confidence   = $reasoning->computeConfidence([
            ['confidence' => 0.9, 'observedDate' => $now],
        ]);
        $reasoningId  = $id();
        DB::table('hpbrain_reasoning_steps')->insert([
            'id'               => $reasoningId,
            'tenant_id'        => self::TENANT,
            'signal_id'        => $signalId,
            'case_id'          => $caseId,
            'step_order'       => 1,
            'description'      => 'Fee shortfall correlates with reminder cadence, not ability to pay.',
            'confidence_score' => $confidence,
            'created_by'       => self::ACTOR,
            'created_date'     => $now,
        ]);
        $this->command?->info("  Reasoning step recorded (confidence: {$confidence})");

        // ── Recommendation ────────────────────────────────────────────────
        $recommendationId = $id();
        DB::table('hpbrain_recommendations')->insert([
            'id'                => $recommendationId,
            'tenant_id'         => self::TENANT,
            'reasoning_step_id' => $reasoningId,
            'category'          => 'operational',
            'title'             => 'Send targeted payment reminder to Grade 9 families',
            'priority'          => 'medium',
            'status'            => 'pending',
            'created_by'        => self::ACTOR,
            'created_date'      => $now,
        ]);
        $this->command?->info('  Recommendation generated');

        // ── Decision ──────────────────────────────────────────────────────
        $decisionId = $id();
        DB::table('hpbrain_decisions')->insert([
            'id'                => $decisionId,
            'tenant_id'         => self::TENANT,
            'recommendation_id' => $recommendationId,
            'status'            => 'approved',
            'executor_type'     => 'human',
            'approved_by'       => self::ACTOR,
            'created_by'        => self::ACTOR,
            'created_date'      => $now,
        ]);
        $this->command?->info('  Decision approved — executor: human');

        // ── ESO Execution ─────────────────────────────────────────────────
        $executionId = $id();
        DB::table('hpbrain_eso_executions')->insert([
            'id'               => $executionId,
            'tenant_id'        => self::TENANT,
            'decision_id'      => $decisionId,
            'executor_type'    => 'human',
            'status'           => 'completed',
            'measurement_plan' => 'Grade 9 collection rate, measured 14 days after the reminder.',
            'created_by'       => self::ACTOR,
            'created_date'     => $now,
        ]);
        $this->command?->info('  ESO executed and completed');

        // ── Outcome ───────────────────────────────────────────────────────
        $outcomeId = $id();
        DB::table('hpbrain_outcomes')->insert([
            'id'                => $outcomeId,
            'tenant_id'         => self::TENANT,
            'eso_execution_id'  => $executionId,
            'result'            => 'success',
            'confidence'        => 0.8,
            'description'       => 'Collection recovered to within 4% of prior year.',
            'created_by'        => self::ACTOR,
            'created_date'      => $now,
        ]);
        $this->command?->info('  Outcome captured: success');

        // ── Learning ──────────────────────────────────────────────────────
        $reusable = (new LearningService())->isReusable('success', 0.8);
        DB::table('hpbrain_learnings')->insert([
            'id'          => $id(),
            'tenant_id'   => self::TENANT,
            'outcome_id'  => $outcomeId,
            'pattern'     => 'Targeted reminders outperform blanket reminders where the root cause is Motivation.',
            'confidence'  => 0.8,
            'reusable'    => $reusable,
            'created_by'  => self::ACTOR,
            'created_date' => $now,
        ]);
        $this->command?->info('  Learning captured (reusable: ' . ($reusable ? 'true' : 'false') . ')');

        $this->command?->info('');
        $this->command?->info('Seed complete. Full loop: Signal → Evidence → Case → Hypothesis → Reasoning → Recommendation → Decision → ESO → Outcome → Learning');
    }
}