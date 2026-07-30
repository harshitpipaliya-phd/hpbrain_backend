<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * The transactional outbox has producers.
 *
 * Before Module 1 nothing wrote to hpbrain_event_store at all: idempotency
 * keys, correlation ids, a dead-letter queue, replay endpoints and a UI, over
 * an empty table. These tests pin the two properties that make it an outbox
 * rather than a log — the domain row and its event commit together or not at
 * all, and a replay produces one event, not two — plus the correlation thread
 * that lets the audit trail reconstruct a case.
 *
 * SCHEMA IS BUILT HERE. The suite is pinned to in-memory SQLite (phpunit.xml)
 * and the hpbrain_ migrations are raw MySQL DDL. Every table below mirrors its
 * migration; hpbrain_eso_executions in particular mirrors the REAL columns, not
 * the ones the controller used to write.
 */
final class OutboxProducerTest extends TestCase
{
    private const TENANT = 'tenant-alpha';
    private const ANALYST = 'user-analyst';
    private const MANAGER = 'user-manager';

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
            $t->text('metadata')->nullable();
            $t->string('correlation_id', 36)->nullable();
            $t->string('causation_id', 36)->nullable();
            $t->string('idempotency_key', 36)->nullable()->unique();
            $t->string('status')->default('pending');
            $t->integer('retry_count')->default(0);
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('hpbrain_auth_users', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('email');
            $t->string('name');
            $t->string('role');
            $t->text('password_hash');
        });

        Schema::create('hpbrain_signals', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('source');
            $t->text('classification');
            $t->text('priority')->nullable();
            $t->text('severity')->nullable();
            $t->decimal('confidence', 6, 4)->nullable();
            $t->text('metadata')->nullable();
            $t->string('status')->default('new');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_cases', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('signal_id', 36)->nullable();
            $t->text('title');
            $t->string('status')->default('open');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_evidence', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('signal_id', 36)->nullable();
            $t->text('source');
            $t->string('evidence_type', 36)->default('observation');
            $t->text('content');
            $t->text('provenance');
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->text('hash');
            $t->text('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_reasoning_steps', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('signal_id', 36)->nullable();
            $t->string('case_id', 36)->nullable();
            $t->string('mental_model_id', 36)->nullable();
            $t->integer('step_order')->default(1);
            $t->text('description');
            $t->decimal('confidence_score', 6, 4)->default(0.5);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_recommendations', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('reasoning_step_id', 36)->nullable();
        });

        Schema::create('hpbrain_mental_models', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('domain');
        });

        Schema::create('hpbrain_decisions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('recommendation_id', 36)->nullable();
            $t->string('decided_by', 36);
            $t->text('executor_type')->default('human');
            $t->text('rationale');
            $t->text('alternatives_considered')->default('[]');
            $t->string('status')->default('proposed');
            $t->string('approved_by', 36)->nullable();
            $t->timestamp('approved_date')->nullable();
            $t->text('approval_note')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_audit_logs', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('entity_type');
            $t->string('entity_id', 36);
            $t->text('action');
            $t->string('actor_id', 36);
            $t->text('actor_name');
            $t->text('changes')->nullable();
            $t->text('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        // Mirrors the REAL hpbrain_eso_executions: eso_id and executed_by are
        // NOT NULL, there is no measurement_plan, no executor_id, no created_by.
        Schema::create('hpbrain_eso_executions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('eso_id', 36);
            $t->string('decision_id', 36)->nullable();
            $t->text('status')->default('queued');
            $t->text('executed_by');
            $t->text('executor_type')->default('human');
            $t->text('input');
            $t->text('output')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('started_date')->nullable();
            $t->timestamp('completed_date')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->string('eso_definition_id', 36)->nullable();
        });

        // Invariant 4 (2026_07_30_000100). An ESO run is refused without a plan
        // that pre-dates it, so the producer site cannot be reached at all
        // without this table.
        Schema::create('hpbrain_measurement_plans', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('decision_id', 36);
            $t->text('baseline_metric');
            $t->decimal('baseline_value', 18, 4)->nullable();
            $t->decimal('target_value', 18, 4)->nullable();
            $t->string('metric_unit', 50)->nullable();
            $t->integer('measurement_window_days')->default(14);
            $t->string('owner_id', 36)->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_outcomes', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('decision_id', 36)->nullable();
            $t->text('result')->default('pending');
            $t->text('metrics');
            $t->text('kpis');
            $t->text('evidence_ids');
            $t->text('feedback')->nullable();
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        DB::table('hpbrain_auth_users')->insert([
            'id' => self::ANALYST, 'tenant_id' => self::TENANT, 'email' => 'a@x.test',
            'name' => 'Ada Analyst', 'role' => 'analyst', 'password_hash' => Hash::make('correct-horse'),
        ]);
    }

    private function auth(string $role, string $id): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => $id, 'tenantId' => self::TENANT, 'role' => $role,
        ])];
    }

    /** @return array<int, object> */
    private function eventsOfType(string $type): array
    {
        return DB::table('hpbrain_event_store')->where('type', $type)->get()->all();
    }

    private function publisher(): EventPublisher
    {
        return app(EventPublisher::class);
    }

    // ---- Every producer site writes exactly one event ------------------------

    public function test_login_emits_session_started_but_refresh_does_not(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'tenantId' => self::TENANT, 'email' => 'a@x.test', 'password' => 'correct-horse',
        ]);

        $login->assertStatus(200);
        self::assertCount(1, $this->eventsOfType(LoopEvent::SESSION_STARTED->value));

        // A refreshed token continues the session it was issued under.
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $login->json('refreshToken')])
            ->assertStatus(200);

        self::assertCount(1, $this->eventsOfType(LoopEvent::SESSION_STARTED->value));
    }

    public function test_a_failed_login_writes_no_loop_event(): void
    {
        // An unauthenticated caller must not be able to write into the event
        // store; a rejected credential is an audit concern, not a loop stage.
        $this->postJson('/api/v1/auth/login', [
            'tenantId' => self::TENANT, 'email' => 'a@x.test', 'password' => 'wrong',
        ])->assertStatus(401);

        self::assertSame(0, DB::table('hpbrain_event_store')->count());
    }

    public function test_creating_a_case_emits_subject_selected(): void
    {
        $response = $this->postJson('/api/v1/cases', ['title' => 'Grade 9 fee arrears'], $this->auth('analyst', self::ANALYST));

        $response->assertStatus(201);

        $events = $this->eventsOfType(LoopEvent::SUBJECT_SELECTED->value);

        self::assertCount(1, $events);
        self::assertSame($response->json('id'), $events[0]->entity_id);
        self::assertSame($response->json('id'), $events[0]->correlation_id);
    }

    public function test_creating_a_signal_emits_observation_made(): void
    {
        $id = $this->createSignal();

        $events = $this->eventsOfType(LoopEvent::OBSERVATION_MADE->value);

        self::assertCount(1, $events);
        self::assertSame($id, $events[0]->entity_id);
        // The signal starts the thread, so it correlates to itself.
        self::assertSame($id, $events[0]->correlation_id);
    }

    public function test_reasoning_emits_deliberated_carrying_its_evidence(): void
    {
        $signalId   = $this->createSignal();
        $evidenceId = $this->createEvidence($signalId);

        $this->postJson('/api/v1/reasoning', [
            'signalId'    => $signalId,
            'description' => 'Arrears track the reminder schedule, not household income.',
        ], $this->auth('analyst', self::ANALYST))->assertStatus(201);

        $events = $this->eventsOfType(LoopEvent::DELIBERATED->value);

        self::assertCount(1, $events);
        self::assertSame($signalId, $events[0]->correlation_id);

        $payload = json_decode((string) $events[0]->payload, true);

        // Without the evidence ids the confidence in this payload is a number
        // nobody can explain after the fact.
        self::assertSame([$evidenceId], $payload['evidenceIds']);
        self::assertSame($events[0]->entity_id, $payload['reasoningStepId']);
    }

    public function test_every_producer_site_writes_exactly_one_event(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'tenantId' => self::TENANT, 'email' => 'a@x.test', 'password' => 'correct-horse',
        ])->assertStatus(200);

        $this->walkTheLoop();

        foreach ([
            LoopEvent::SESSION_STARTED,
            LoopEvent::SUBJECT_SELECTED,
            LoopEvent::OBSERVATION_MADE,
            LoopEvent::EVIDENCE_RECORDED,
            LoopEvent::DELIBERATED,
            LoopEvent::DECISION_REACHED,
            LoopEvent::EXECUTION_STARTED,
            LoopEvent::OUTCOME_RECORDED,
        ] as $event) {
            self::assertCount(
                1,
                $this->eventsOfType($event->value),
                "{$event->value} should have been produced exactly once."
            );
        }
    }

    // ---- The outbox property: both, or neither ------------------------------

    public function test_the_domain_write_is_rolled_back_when_the_event_insert_fails(): void
    {
        $id = Uuid::uuid4()->toString();

        // Pre-publish the exact event this write would produce, so the insert
        // inside the transaction hits the unique idempotency key.
        $this->publisher()->emit(
            LoopEvent::OBSERVATION_MADE, self::TENANT, 'Signal', $id, self::ANALYST
        );

        $result = $this->publisher()->publishInTransaction(
            LoopEvent::OBSERVATION_MADE,
            self::TENANT,
            'Signal',
            self::ANALYST,
            ['signalId' => $id],
            fn () => ['entityId' => $id, 'result' => DB::table('hpbrain_signals')->insert([
                'id' => $id, 'tenant_id' => self::TENANT, 'source' => 'erp',
                'classification' => 'fee', 'created_by' => self::ANALYST,
            ])],
        );

        self::assertNull($result);
        // The row must not survive an event that was never appended for it.
        self::assertSame(0, DB::table('hpbrain_signals')->where('id', $id)->count());
        self::assertCount(1, $this->eventsOfType(LoopEvent::OBSERVATION_MADE->value));
    }

    public function test_a_duplicate_idempotency_key_neither_throws_nor_duplicates(): void
    {
        $id = Uuid::uuid4()->toString();

        $first  = $this->publisher()->emit(LoopEvent::MEMORY_UPDATED, self::TENANT, 'Learning', $id, self::ANALYST);
        $second = $this->publisher()->emit(LoopEvent::MEMORY_UPDATED, self::TENANT, 'Learning', $id, self::ANALYST);

        self::assertIsString($first);
        // Null, not an exception: a duplicate is the outbox working.
        self::assertNull($second);
        self::assertCount(1, $this->eventsOfType(LoopEvent::MEMORY_UPDATED->value));
    }

    public function test_replaying_the_same_approval_yields_one_event(): void
    {
        $decisionId = $this->createApprovedDecision($this->createSignal());

        // The second approval is a replay: same decision, same event.
        $this->postJson(
            '/api/v1/decisions/'.self::TENANT."/{$decisionId}/approve",
            [], $this->auth('manager', self::MANAGER)
        )->assertStatus(200);

        self::assertCount(1, $this->eventsOfType(LoopEvent::DECISION_REACHED->value));
    }

    public function test_publishing_with_no_entity_id_throws_and_writes_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->publisher()->publishInTransaction(
                LoopEvent::OBSERVATION_MADE,
                self::TENANT,
                'Signal',
                self::ANALYST,
                [],
                fn () => ['entityId' => '', 'result' => DB::table('hpbrain_signals')->insert([
                    'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::TENANT, 'source' => 'erp',
                    'classification' => 'fee', 'created_by' => self::ANALYST,
                ])],
            );
        } finally {
            // An event with no subject can never be replayed, so the domain
            // write goes with it.
            self::assertSame(0, DB::table('hpbrain_signals')->count());
            self::assertSame(0, DB::table('hpbrain_event_store')->count());
        }
    }

    public function test_emit_rejects_an_empty_entity_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->publisher()->emit(LoopEvent::SESSION_STARTED, self::TENANT, 'Session', '', self::ANALYST);
    }

    public function test_events_are_produced_unconsumed(): void
    {
        $this->createSignal();

        $event = $this->eventsOfType(LoopEvent::OBSERVATION_MADE->value)[0];

        self::assertSame('pending', $event->status);
        self::assertSame(0, (int) $event->retry_count);
    }

    // ---- The correlation thread ---------------------------------------------

    public function test_the_correlation_thread_switches_from_signal_to_decision(): void
    {
        [$signalId, $decisionId] = $this->walkTheLoop();

        $before = [LoopEvent::OBSERVATION_MADE, LoopEvent::EVIDENCE_RECORDED, LoopEvent::DELIBERATED];
        $after  = [LoopEvent::DECISION_REACHED, LoopEvent::EXECUTION_STARTED, LoopEvent::OUTCOME_RECORDED];

        foreach ($before as $event) {
            self::assertSame(
                $signalId,
                $this->eventsOfType($event->value)[0]->correlation_id,
                "{$event->value} precedes the decision and must carry the signal id."
            );
        }

        foreach ($after as $event) {
            self::assertSame(
                $decisionId,
                $this->eventsOfType($event->value)[0]->correlation_id,
                "{$event->value} follows the decision and must carry the decision id."
            );
        }

        // Two threads, and every loop event belongs to one of them: that is
        // what makes the case reconstructable from the log alone.
        $correlations = DB::table('hpbrain_event_store')
            ->whereIn('type', array_map(fn ($e) => $e->value, array_merge($before, $after)))
            ->distinct()->pluck('correlation_id')->sort()->values()->all();

        self::assertSame(2, count($correlations));
    }

    // ---- Helpers: the golden path, driven through the real endpoints ---------

    private function createSignal(): string
    {
        return $this->postJson('/api/v1/signals', [
            'source' => 'fee_ledger', 'classification' => 'financial', 'priority' => 'high',
        ], $this->auth('analyst', self::ANALYST))->assertStatus(201)->json('id');
    }

    private function createEvidence(string $signalId): string
    {
        return $this->postJson('/api/v1/evidence', [
            'tenantId'   => self::TENANT,
            'signalId'   => $signalId,
            'source'     => 'fee_ledger_export',
            'content'    => ['note' => 'Arrears concentrated in 14 families.'],
            'provenance' => ['source' => 'ERP nightly export', 'ts' => '2026-07-20T09:00:00Z', 'confidence' => 0.82],
        ], $this->auth('analyst', self::ANALYST))->assertStatus(201)->json('id');
    }

    /**
     * A decision proposed by the analyst and approved by the manager, hung off
     * the reasoning chain so OutcomeController can derive a domain.
     */
    private function createApprovedDecision(string $signalId): string
    {
        $modelId = Uuid::uuid4()->toString();
        $stepId  = Uuid::uuid4()->toString();
        $recId   = Uuid::uuid4()->toString();

        DB::table('hpbrain_mental_models')->insert(['id' => $modelId, 'tenant_id' => self::TENANT, 'domain' => 'finance']);
        DB::table('hpbrain_reasoning_steps')->insert([
            'id' => $stepId, 'tenant_id' => self::TENANT, 'signal_id' => $signalId,
            'mental_model_id' => $modelId, 'description' => 'seeded', 'created_by' => self::ANALYST,
        ]);
        DB::table('hpbrain_recommendations')->insert([
            'id' => $recId, 'tenant_id' => self::TENANT, 'reasoning_step_id' => $stepId,
        ]);

        $decisionId = $this->postJson('/api/v1/decisions', [
            'tenantId'         => self::TENANT,
            'recommendationId' => $recId,
            'rationale'        => 'Cadence change is proportionate to the measured shortfall.',
        ], $this->auth('analyst', self::ANALYST))->assertStatus(201)->json('id');

        $this->postJson(
            '/api/v1/decisions/'.self::TENANT."/{$decisionId}/approve",
            ['note' => 'Reviewed.'], $this->auth('manager', self::MANAGER)
        )->assertStatus(200);

        return $decisionId;
    }

    /**
     * Case -> signal -> evidence -> reasoning -> decision -> execution ->
     * outcome, every step through its real endpoint.
     *
     * @return array{0: string, 1: string} [signalId, decisionId]
     */
    private function walkTheLoop(): array
    {
        $this->postJson('/api/v1/cases', ['title' => 'Grade 9 fee arrears'], $this->auth('analyst', self::ANALYST))
            ->assertStatus(201);

        $signalId   = $this->createSignal();
        $evidenceId = $this->createEvidence($signalId);

        $this->postJson('/api/v1/reasoning', [
            'signalId' => $signalId, 'description' => 'Cadence, not capacity.',
        ], $this->auth('analyst', self::ANALYST))->assertStatus(201);

        $decisionId = $this->createApprovedDecision($signalId);

        $this->postJson('/api/v1/measurement-plans', [
            'decisionId'            => $decisionId,
            'baselineMetric'        => 'Grade 9 collection rate, 14 days after the reminder.',
            'measurementWindowDays' => 14,
        ], $this->auth('manager', self::MANAGER))->assertStatus(201);

        $this->postJson('/api/v1/eso-executions', [
            'decisionId'      => $decisionId,
            'esoDefinitionId' => Uuid::uuid4()->toString(),
            'executorType'    => 'human',
        ], $this->auth('manager', self::MANAGER))->assertStatus(201);

        $this->postJson('/api/v1/outcomes', [
            'tenantId'    => self::TENANT,
            'decisionId'  => $decisionId,
            'result'      => 'partial',
            'metrics'     => ['collectionRate' => 0.62],
            'evidenceIds' => [$evidenceId],
            'confidence'  => 0.66,
        ], $this->auth('manager', self::MANAGER))->assertStatus(201);

        return [$signalId, $decisionId];
    }
}
