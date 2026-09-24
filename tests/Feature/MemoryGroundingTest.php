<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Events\LoopEvent;
use App\Domain\Learning\MemoryGrounding;
use App\Domain\Verbs\AssessVerb;
use App\Domain\Verbs\ExplainVerb;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\TestCase;

/**
 * The flywheel turns: a learning written by one loop is read back into the
 * reasoning of the next, and the fact that it was is recorded.
 *
 * Half of these are regression tests for a class that had never executed.
 * MemoryGrounding was documented as "implemented and correct" while disagreeing
 * with the schema on every path — so the assertions here are deliberately about
 * stored column values, not about return shapes: a return shape would have
 * looked fine against the broken version too.
 *
 * SCHEMA IS BUILT HERE — in-memory SQLite (phpunit.xml) cannot run the raw
 * MySQL migrations. hpbrain_learnings carries the `domain` column from
 * 2026_07_29_000200_learning_domain.
 */
final class MemoryGroundingTest extends TestCase
{
    private const TENANT = 'tenant-alpha';
    private const ACTOR  = 'user-analyst';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('hpbrain_event_store', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('type');
            $t->string('tenant_id', 36);
            $t->string('entity_type');
            $t->string('entity_id', 36);
            $t->string('actor_id', 36);
            $t->text('payload');
            $t->string('correlation_id', 36)->nullable();
            $t->string('causation_id', 36)->nullable();
            $t->string('idempotency_key', 36)->nullable()->unique();
            $t->string('status')->default('pending');
            $t->integer('retry_count')->default(0);
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('hpbrain_learnings', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('outcome_id', 36)->nullable();
            $t->string('mental_model_id', 36)->nullable();
            $t->text('pattern');
            $t->text('description')->nullable();
            $t->string('domain', 64)->nullable();
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->boolean('reusable')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_signals', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('department_id', 36)->nullable();
            $t->text('source');
            $t->text('classification');
            $t->text('priority')->nullable();
            $t->text('severity')->nullable();
            $t->decimal('confidence', 6, 4)->nullable();
            $t->text('related_entity_type')->nullable();
            $t->string('related_entity_id', 36)->nullable();
            $t->string('status')->default('new');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_evidence', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('signal_id', 36)->nullable();
            $t->text('source');
            $t->text('content');
            $t->text('provenance');
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->text('hash');
            $t->timestamp('observed_date')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_cases', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('signal_id', 36)->nullable();
            $t->text('title');
            $t->string('status')->default('open');
        });

        Schema::create('hpbrain_hypotheses', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('case_id', 36);
            $t->text('statement');
            $t->text('root_cause_family');
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->text('status')->default('proposed');
            $t->text('supporting_evidence_ids')->default('[]');
        });

        Schema::create('hpbrain_capability_assignments', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('capability_id', 36);
            $t->string('target_type');
            $t->string('target_id', 36);
            $t->text('assigned_by');
            $t->text('status')->default('active');
        });

        Schema::create('hpbrain_capabilities', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('capability_code');
            $t->text('name');
            $t->string('category')->default('general');
            $t->text('knowledge')->nullable();
            $t->text('ability')->nullable();
            $t->text('skill')->nullable();
            $t->text('behaviour')->nullable();
            $t->text('attitude')->nullable();
        });

        Schema::create('hpbrain_capability_proficiency', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('assignment_id', 36);
            $t->decimal('knowledge_level', 12, 4)->nullable();
            $t->decimal('ability_level', 12, 4)->nullable();
            $t->decimal('skill_level', 12, 4)->nullable();
            $t->decimal('behaviour_level', 12, 4)->nullable();
            $t->decimal('attitude_level', 12, 4)->nullable();
            $t->decimal('evidence_confidence', 6, 4)->nullable();
            $t->text('assessed_by')->nullable();
            $t->timestamp('assessed_date')->nullable();
            $t->timestamp('created_date')->nullable();
        });
    }

    private function memory(): MemoryGrounding
    {
        return app(MemoryGrounding::class);
    }

    private function seedLearning(
        ?string $domain,
        float $confidence = 0.8,
        bool $reusable = true,
        string $pattern = 'Reminder cadence drives collection.',
    ): string {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_learnings')->insert([
            'id'           => $id,
            'tenant_id'    => self::TENANT,
            'pattern'      => $pattern,
            'domain'       => $domain,
            'confidence'   => $confidence,
            'reusable'     => $reusable ? 1 : 0,
            'created_by'   => self::ACTOR,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function groundingEvents(): array
    {
        return DB::table('hpbrain_event_store')
            ->where('type', LoopEvent::LEARNING_GROUNDED->value)->get()->all();
    }

    // ---- retrieveFor ---------------------------------------------------------

    public function test_a_domain_query_also_returns_cross_domain_learnings(): void
    {
        $inDomain    = $this->seedLearning('finance', 0.9);
        $crossDomain = $this->seedLearning(null, 0.7);
        $otherDomain = $this->seedLearning('pedagogy', 0.95);

        $ids = array_column($this->memory()->retrieveFor(self::TENANT, 'finance'), 'id');

        // A learning with no domain applies everywhere. A bare `domain = ?`
        // would have hidden it — which is exactly when a general lesson is most
        // worth having.
        self::assertContains($inDomain, $ids);
        self::assertContains($crossDomain, $ids);
        self::assertNotContains($otherDomain, $ids);
    }

    public function test_non_reusable_learnings_are_never_retrieved(): void
    {
        $good = $this->seedLearning('finance', 0.5);
        $bad  = $this->seedLearning('finance', 0.99, reusable: false);

        $ids = array_column($this->memory()->retrieveFor(self::TENANT, 'finance'), 'id');

        // ADR-005: a failed outcome is recorded so the organization learns from
        // it, and is never offered back as a pattern to repeat — not even at
        // high confidence.
        self::assertSame([$good], $ids);
        self::assertNotContains($bad, $ids);
    }

    public function test_learnings_come_back_most_confident_first(): void
    {
        $low  = $this->seedLearning('finance', 0.30);
        $high = $this->seedLearning('finance', 0.95);
        $mid  = $this->seedLearning('finance', 0.60);

        self::assertSame(
            [$high, $mid, $low],
            array_column($this->memory()->retrieveFor(self::TENANT, 'finance'), 'id')
        );
    }

    public function test_another_tenants_learnings_are_not_retrieved(): void
    {
        $mine = $this->seedLearning('finance');

        DB::table('hpbrain_learnings')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => 'tenant-beta',
            'pattern' => 'theirs', 'domain' => 'finance', 'confidence' => 0.99,
            'reusable' => 1, 'created_by' => 'x', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        self::assertSame([$mine], array_column($this->memory()->retrieveFor(self::TENANT, 'finance'), 'id'));
    }

    // ---- recordGrounding: the three schema bugs ------------------------------

    public function test_recording_a_grounding_writes_a_schema_correct_event(): void
    {
        $learningId = $this->seedLearning('finance');
        $signalId   = Uuid::uuid4()->toString();

        $this->memory()->recordGrounding(self::TENANT, $learningId, 'Signal', $signalId, self::ACTOR);

        $events = $this->groundingEvents();

        self::assertCount(1, $events);

        // Bug 2, in three parts. The old code wrote `event_type` and
        // `created_date` — columns that do not exist — and omitted actor_id,
        // which is NOT NULL. Each of these assertions fails against it.
        self::assertSame('LearningGrounded', $events[0]->type);
        self::assertNotNull($events[0]->created_at);
        self::assertSame(self::ACTOR, $events[0]->actor_id);

        // And the key fits VARCHAR(36). The old one was 60+ characters, so
        // MySQL would have truncated unrelated groundings into collisions.
        self::assertLessThanOrEqual(36, strlen((string) $events[0]->idempotency_key));

        $payload = json_decode((string) $events[0]->payload, true);

        self::assertSame($learningId, $payload['learningId']);
        self::assertSame($signalId, $payload['groundedEntityId']);
        // The grounded entity is the thread this grounding belongs to.
        self::assertSame($signalId, $events[0]->correlation_id);
    }

    // ---- compoundingStats ----------------------------------------------------

    public function test_reuse_rate_is_null_when_nothing_is_reusable(): void
    {
        $this->seedLearning('finance', 0.8, reusable: false);

        $stats = $this->memory()->compoundingStats(self::TENANT);

        self::assertSame(1, $stats['learningsWritten']);
        self::assertSame(0, $stats['learningsReusable']);
        // No denominator is not the same claim as a zero reuse rate: 0.0 would
        // report a failure that has not happened.
        self::assertNull($stats['reuseRate']);
    }

    public function test_reuse_rate_is_a_number_once_memory_has_been_used(): void
    {
        $used   = $this->seedLearning('finance');
        $unused = $this->seedLearning('finance');

        $this->memory()->recordGrounding(self::TENANT, $used, 'Signal', Uuid::uuid4()->toString(), self::ACTOR);

        $stats = $this->memory()->compoundingStats(self::TENANT);

        self::assertSame(2, $stats['learningsWritten']);
        self::assertSame(2, $stats['learningsReusable']);
        self::assertSame(1, $stats['groundingEvents']);
        self::assertSame(0.5, $stats['reuseRate']);
        self::assertNotSame($used, $unused);
    }

    // ---- EXPLAIN -------------------------------------------------------------

    /** A signal with everything the UODM frame needs, and nothing more. */
    private function seedExplainableSignal(): string
    {
        $signalId = Uuid::uuid4()->toString();
        $caseId   = Uuid::uuid4()->toString();

        DB::table('hpbrain_signals')->insert([
            'id' => $signalId, 'tenant_id' => self::TENANT, 'org_id' => 'org-1',
            'source' => 'fee_ledger', 'classification' => 'finance', 'priority' => 'high',
            'severity' => 'high', 'confidence' => 0.8,
            'related_entity_type' => 'Department', 'related_entity_id' => 'dept-9',
            'created_by' => self::ACTOR, 'created_date' => '2026-07-25 09:00:00',
        ]);

        DB::table('hpbrain_evidence')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::TENANT, 'signal_id' => $signalId,
            'source' => 'fee_ledger_export', 'content' => json_encode(['note' => 'arrears']),
            'provenance' => json_encode(['source' => 'ERP', 'ts' => '2026-07-20T09:00:00Z', 'confidence' => 0.82]),
            'confidence' => 0.82, 'hash' => str_repeat('a', 64),
            'created_date' => '2026-07-25 09:00:00',
        ]);

        DB::table('hpbrain_cases')->insert([
            'id' => $caseId, 'tenant_id' => self::TENANT, 'signal_id' => $signalId,
            'title' => 'Grade 9 arrears', 'status' => 'open',
        ]);

        DB::table('hpbrain_hypotheses')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::TENANT, 'case_id' => $caseId,
            'statement' => 'Reminder cadence, not ability to pay.',
            'root_cause_family' => 'process_design', 'confidence' => 0.72,
            'status' => 'supported', 'supporting_evidence_ids' => json_encode([]),
        ]);

        return $signalId;
    }

    private function explain(string $signalId, string $role = 'analyst')
    {
        return app(ExplainVerb::class)->run(self::TENANT, $signalId, self::ACTOR, $role);
    }

    public function test_explain_with_nothing_to_ground_on_is_undetermined(): void
    {
        $signalId = Uuid::uuid4()->toString();

        DB::table('hpbrain_signals')->insert([
            'id' => $signalId, 'tenant_id' => self::TENANT, 'org_id' => 'org-1',
            'source' => 'fee_ledger', 'classification' => 'finance',
            'created_by' => self::ACTOR, 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $result = $this->explain($signalId);

        // No evidence and no memory: the pipeline refuses to generate from
        // nothing rather than producing a confident-sounding guess.
        self::assertTrue($result->isUndetermined());
        self::assertContains('no_grounding_evidence', $result->gaps);
    }

    public function test_explain_with_evidence_decides_and_names_what_it_used(): void
    {
        $signalId = $this->seedExplainableSignal();

        $result = $this->explain($signalId);

        self::assertFalse($result->isUndetermined(), 'gaps: '.implode(', ', $result->gaps));
        self::assertNotEmpty($result->evidenceRefs);
        self::assertSame('process_design', $result->value['rootCause']);
        self::assertSame(0.72, $result->confidence);

        // Invariant 2 / Principle 1: the answer carries the path to why.
        self::assertArrayHasKey('evidenceRefs', $result->jsonSerialize());
    }

    public function test_explain_without_a_hypothesis_names_the_missing_questions(): void
    {
        $signalId = $this->seedExplainableSignal();
        DB::table('hpbrain_hypotheses')->delete();

        $result = $this->explain($signalId);

        // Grounding exists, so this is not no_grounding_evidence — it is the
        // UODM gate naming exactly which framing questions are unanswered. An
        // explanation nobody has stated cannot be falsified.
        self::assertTrue($result->isUndetermined());
        self::assertContains('what_would_falsify_it', $result->gaps);
        self::assertContains('what_is_the_root_cause_family', $result->gaps);
        self::assertNotContains('no_grounding_evidence', $result->gaps);
        // Still says what it DID have.
        self::assertNotEmpty($result->evidenceRefs);
    }

    public function test_explain_emits_one_grounding_event_per_learning_used(): void
    {
        $signalId = $this->seedExplainableSignal();

        $a = $this->seedLearning('finance', 0.9);
        $b = $this->seedLearning(null, 0.6);
        $this->seedLearning('pedagogy', 0.99);          // different wedge
        $this->seedLearning('finance', 0.99, reusable: false); // not reusable

        $result = $this->explain($signalId);

        $events = $this->groundingEvents();

        self::assertCount(2, $events, 'One event per learning actually used, and only those.');
        self::assertEqualsCanonicalizing([$a, $b], array_column($events, 'entity_id'));
        self::assertSame([$signalId, $signalId], array_column($events, 'correlation_id'));

        // The learnings are part of the answer's grounding, so they appear in
        // evidenceRefs alongside the evidence rows.
        self::assertContains($a, $result->evidenceRefs);
        self::assertSame([$a, $b], $result->value['groundedOnLearnings']);
    }

    public function test_governance_denial_happens_before_any_grounding_query(): void
    {
        $signalId = $this->seedExplainableSignal();
        $this->seedLearning('finance');

        try {
            $this->explain($signalId, role: 'superuser');
            self::fail('An unrecognised role must be refused.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('governance_denied', $e->getMessage());
            self::assertStringContainsString('unknown_role', $e->getMessage());
        }

        // The order in VerbPipeline is the contract: nothing is retrieved, and
        // nothing is recorded, for a caller who was never allowed to ask.
        self::assertSame([], $this->groundingEvents());
    }

    // ---- ASSESS --------------------------------------------------------------

    /** @return array{0: string, 1: string} [assignmentId, capabilityId] */
    private function seedAssessable(bool $withProficiency = true): array
    {
        $assignmentId = Uuid::uuid4()->toString();
        $capabilityId = Uuid::uuid4()->toString();

        DB::table('hpbrain_capabilities')->insert([
            'id' => $capabilityId, 'tenant_id' => self::TENANT, 'org_id' => 'org-1',
            'capability_code' => 'FEE-RECOVERY', 'name' => 'Fee recovery', 'category' => 'finance',
            'knowledge' => json_encode(['targetLevel' => 4]),
            'skill'     => json_encode(['targetLevel' => 4]),
        ]);

        DB::table('hpbrain_capability_assignments')->insert([
            'id' => $assignmentId, 'tenant_id' => self::TENANT, 'capability_id' => $capabilityId,
            'target_type' => 'Person', 'target_id' => 'person-1', 'assigned_by' => self::ACTOR,
            'status' => 'active',
        ]);

        if ($withProficiency) {
            DB::table('hpbrain_capability_proficiency')->insert([
                'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::TENANT,
                'assignment_id' => $assignmentId, 'knowledge_level' => 2, 'skill_level' => 3,
                'evidence_confidence' => 0.7, 'assessed_by' => self::ACTOR,
                'assessed_date' => '2026-07-01 09:00:00', 'created_date' => '2026-07-01 09:00:00',
            ]);
        }

        return [$assignmentId, $capabilityId];
    }

    private function assess(string $assignmentId, string $capabilityId, string $role = 'analyst')
    {
        return app(AssessVerb::class)->run(self::TENANT, $assignmentId, $capabilityId, self::ACTOR, $role);
    }

    public function test_assess_with_nothing_to_ground_on_is_undetermined(): void
    {
        [$assignmentId, $capabilityId] = $this->seedAssessable(withProficiency: false);

        $result = $this->assess($assignmentId, $capabilityId);

        // Never assessed and no memory: null is not zero, so the verb declines
        // to state a level rather than defaulting one.
        self::assertTrue($result->isUndetermined());
        self::assertContains('no_grounding_evidence', $result->gaps);
    }

    public function test_assess_with_proficiency_decides_and_names_the_gap(): void
    {
        [$assignmentId, $capabilityId] = $this->seedAssessable();

        $result = $this->assess($assignmentId, $capabilityId);

        self::assertFalse($result->isUndetermined(), 'gaps: '.implode(', ', $result->gaps));
        self::assertNotEmpty($result->evidenceRefs);
        // knowledge 2 against a target of 4 is the larger shortfall, so it
        // leads the gap list and names the root-cause family.
        self::assertSame('knowledge', $result->value['gaps'][0]['dimension']);
        self::assertSame(2.0, $result->value['gaps'][0]['gap']);
        self::assertSame('skill', $result->value['gaps'][1]['dimension']);
        self::assertSame(0.7, $result->confidence);
        // An unassessed dimension stays null; it is not reported as zero.
        self::assertNull($result->value['scores']['attitude']);
    }

    public function test_assess_emits_one_grounding_event_per_learning_used(): void
    {
        [$assignmentId, $capabilityId] = $this->seedAssessable();

        $a = $this->seedLearning('finance', 0.9);
        $b = $this->seedLearning(null, 0.5);
        $this->seedLearning('pedagogy', 0.99);

        $this->assess($assignmentId, $capabilityId);

        $events = $this->groundingEvents();

        self::assertCount(2, $events);
        self::assertEqualsCanonicalizing([$a, $b], array_column($events, 'entity_id'));
        self::assertSame([$assignmentId, $assignmentId], array_column($events, 'correlation_id'));
    }

    public function test_assess_governance_denial_happens_before_any_grounding_query(): void
    {
        [$assignmentId, $capabilityId] = $this->seedAssessable();
        $this->seedLearning('finance');

        try {
            $this->assess($assignmentId, $capabilityId, role: 'superuser');
            self::fail('An unrecognised role must be refused.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('governance_denied', $e->getMessage());
        }

        self::assertSame([], $this->groundingEvents());
    }

    // ---- Repeated grounding --------------------------------------------------

    public function test_asking_the_same_question_twice_does_not_inflate_the_reuse_metric(): void
    {
        $signalId = $this->seedExplainableSignal();
        $this->seedLearning('finance');

        $this->explain($signalId);
        $this->explain($signalId);

        // The event store's idempotency key makes the second ask a no-op, so a
        // user refreshing a screen cannot manufacture evidence that memory is
        // compounding.
        self::assertCount(1, $this->groundingEvents());
        self::assertSame(1.0, $this->memory()->compoundingStats(self::TENANT)['reuseRate']);
    }
}
