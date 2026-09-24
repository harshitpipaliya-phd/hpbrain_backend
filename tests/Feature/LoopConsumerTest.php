<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Events\LoopEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * The loop closes.
 *
 * Module 4 filled the outbox; nothing drained it, so every event sat at
 * 'pending' forever and Invariant 8 failed by omission. These tests pin the
 * four properties that make a consumer trustworthy rather than merely present:
 * write-back happens, a replay does not duplicate it, a poison event is
 * contained instead of blocking the batch, and two workers cannot both take the
 * same event.
 *
 * SCHEMA IS BUILT HERE — in-memory SQLite (phpunit.xml) cannot run the raw
 * MySQL migrations. hpbrain_learnings includes the `domain` column added by
 * 2026_07_29_000200_learning_domain.
 */
final class LoopConsumerTest extends TestCase
{
    private const TENANT = 'tenant-alpha';
    private const ACTOR  = 'user-manager';

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
            $t->timestamp('last_retry_at')->nullable();
            $t->text('failure_reason')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamp('completed_at')->nullable();
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

        Schema::create('hpbrain_dead_letter_queue', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('event_id', 36);
            $t->string('consumer_name');
            $t->text('error_message');
            $t->text('error_stack')->nullable();
            $t->integer('retry_count')->default(0);
            $t->integer('max_retries')->default(3);
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('hpbrain_consumer_state', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('consumer_name')->unique();
            $t->string('last_processed_event_id', 36)->nullable();
            $t->timestamp('last_processed_at')->nullable();
            $t->text('status')->default('active');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
    }

    /** Seeds an outcome and the OutcomeRecorded event that announces it. */
    private function seedOutcome(string $result = 'success', ?string $domain = 'finance'): array
    {
        $outcomeId  = Uuid::uuid4()->toString();
        $decisionId = Uuid::uuid4()->toString();

        DB::table('hpbrain_outcomes')->insert([
            'id'           => $outcomeId,
            'tenant_id'    => self::TENANT,
            'decision_id'  => $decisionId,
            'result'       => $result,
            'metrics'      => json_encode(['collectionRate' => 0.62]),
            'kpis'         => json_encode([]),
            'evidence_ids' => json_encode([Uuid::uuid4()->toString()]),
            'feedback'     => 'Reminder landed two weeks late in the cycle.',
            'confidence'   => 0.66,
            'created_by'   => self::ACTOR,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $eventId = $this->seedEvent(LoopEvent::OUTCOME_RECORDED, 'Outcome', $outcomeId, [
            'outcomeId'  => $outcomeId,
            'decisionId' => $decisionId,
            'result'     => $result,
            'domain'     => $domain,
        ], $decisionId);

        return [$outcomeId, $decisionId, $eventId];
    }

    /** @param array<string, mixed> $payload */
    private function seedEvent(
        LoopEvent $event,
        string $entityType,
        string $entityId,
        array $payload = [],
        ?string $correlationId = null,
    ): string {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_event_store')->insert([
            'id'              => $id,
            'type'            => $event->value,
            'tenant_id'       => self::TENANT,
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'actor_id'        => self::ACTOR,
            'payload'         => json_encode($payload),
            'correlation_id'  => $correlationId ?? $entityId,
            'idempotency_key' => md5("{$event->value}:".self::TENANT.":{$entityType}:{$entityId}"),
            'status'          => 'pending',
            'retry_count'     => 0,
            'created_at'      => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function consume(int $maxRetries = 3): void
    {
        $this->artisan('brain:process-events', ['--once' => true, '--max-retries' => $maxRetries])
            ->assertExitCode(0);
    }

    private function eventsOfType(LoopEvent $event): array
    {
        return DB::table('hpbrain_event_store')->where('type', $event->value)->get()->all();
    }

    // ---- Write-back ---------------------------------------------------------

    public function test_an_outcome_becomes_a_learning_then_memory_is_updated(): void
    {
        [$outcomeId, $decisionId, $eventId] = $this->seedOutcome();

        $this->consume();

        // Pass 1: the learning and the event announcing it.
        $learnings = DB::table('hpbrain_learnings')->get();

        self::assertCount(1, $learnings);
        self::assertSame($outcomeId, $learnings[0]->outcome_id);
        self::assertSame('finance', $learnings[0]->domain);
        self::assertSame(0.66, (float) $learnings[0]->confidence);
        self::assertSame(self::ACTOR, $learnings[0]->created_by);

        $written = $this->eventsOfType(LoopEvent::LEARNING_WRITTEN);

        self::assertCount(1, $written);
        // Inherits the outcome's thread, caused by the outcome's event.
        self::assertSame($decisionId, $written[0]->correlation_id);
        self::assertSame($eventId, $written[0]->causation_id);
        self::assertSame('pending', $written[0]->status);

        self::assertSame('completed', DB::table('hpbrain_event_store')->where('id', $eventId)->value('status'));
        self::assertCount(0, $this->eventsOfType(LoopEvent::MEMORY_UPDATED));

        // Pass 2 consumes LearningWritten and closes the loop.
        $this->consume();

        $updated = $this->eventsOfType(LoopEvent::MEMORY_UPDATED);

        self::assertCount(1, $updated);
        self::assertSame($learnings[0]->id, $updated[0]->entity_id);
        self::assertSame($decisionId, $updated[0]->correlation_id);
        self::assertSame($written[0]->id, $updated[0]->causation_id);
    }

    public function test_replaying_the_same_outcome_event_writes_one_learning(): void
    {
        [$outcomeId, , $eventId] = $this->seedOutcome();

        $this->consume();

        // A replay: the same outcome announced again under a new event id, as
        // EventController::replay() produces.
        DB::table('hpbrain_event_store')->insert([
            'id'              => Uuid::uuid4()->toString(),
            'type'            => LoopEvent::OUTCOME_RECORDED->value,
            'tenant_id'       => self::TENANT,
            'entity_type'     => 'Outcome',
            'entity_id'       => $outcomeId,
            'actor_id'        => self::ACTOR,
            'payload'         => json_encode(['outcomeId' => $outcomeId, 'domain' => 'finance']),
            'causation_id'    => $eventId,
            'idempotency_key' => md5('replay:'.$outcomeId),
            'status'          => 'pending',
            'retry_count'     => 0,
            'created_at'      => now()->format('Y-m-d H:i:s'),
        ]);

        $this->consume();

        // The uuid5 id makes the second attempt collide with itself instead of
        // writing a second learning.
        self::assertSame(1, DB::table('hpbrain_learnings')->count());
        self::assertCount(1, $this->eventsOfType(LoopEvent::LEARNING_WRITTEN));
    }

    public function test_a_failed_outcome_is_learned_from_but_not_reusable(): void
    {
        $this->seedOutcome('failure');

        $this->consume();

        $learning = DB::table('hpbrain_learnings')->first();

        // ADR-005: recorded so the organization learns from it, never offered
        // back as a pattern to repeat.
        self::assertNotNull($learning);
        self::assertSame(0, (int) $learning->reusable);
    }

    public function test_a_learning_with_no_domain_is_stored_as_null(): void
    {
        // NULL means cross-domain: it must ground every question, not none.
        $this->seedOutcome('success', null);

        $this->consume();

        self::assertNull(DB::table('hpbrain_learnings')->value('domain'));
    }

    // ---- Failure handling ---------------------------------------------------

    public function test_an_event_naming_a_missing_outcome_retries_then_dead_letters(): void
    {
        $eventId = $this->seedEvent(LoopEvent::OUTCOME_RECORDED, 'Outcome', Uuid::uuid4()->toString());

        // Attempt 1: back to pending, not retried in the same pass — a
        // transient failure needs time, and a tight loop would hammer the
        // database it is already failing to reach.
        $this->consume();

        $event = DB::table('hpbrain_event_store')->where('id', $eventId)->first();
        self::assertSame('pending', $event->status);
        self::assertSame(1, (int) $event->retry_count);
        self::assertNotNull($event->failure_reason);
        self::assertNotNull($event->last_retry_at);
        self::assertSame(0, DB::table('hpbrain_dead_letter_queue')->count());

        // Attempt 2: still pending.
        $this->consume();
        self::assertSame(2, (int) DB::table('hpbrain_event_store')->where('id', $eventId)->value('retry_count'));

        // Attempt 3 reaches max-retries and dead-letters.
        $this->consume();

        self::assertSame('failed', DB::table('hpbrain_event_store')->where('id', $eventId)->value('status'));

        $dlq = DB::table('hpbrain_dead_letter_queue')->get();

        self::assertCount(1, $dlq);
        self::assertSame($eventId, $dlq[0]->event_id);
        self::assertSame('loop-consumer', $dlq[0]->consumer_name);
        self::assertSame(3, (int) $dlq[0]->retry_count);
        self::assertStringContainsString('does not exist', $dlq[0]->error_message);

        // A failed event is not picked up again.
        $this->consume();
        self::assertCount(1, DB::table('hpbrain_dead_letter_queue')->get());
    }

    public function test_a_poison_event_does_not_block_the_rest_of_the_batch(): void
    {
        // Bad first, good second, in created_at order.
        $poison = $this->seedEvent(LoopEvent::OUTCOME_RECORDED, 'Outcome', Uuid::uuid4()->toString());
        [$outcomeId, , $good] = $this->seedOutcome();

        $this->consume(maxRetries: 1);

        self::assertSame('failed', DB::table('hpbrain_event_store')->where('id', $poison)->value('status'));
        self::assertSame('completed', DB::table('hpbrain_event_store')->where('id', $good)->value('status'));
        self::assertSame(1, DB::table('hpbrain_learnings')->where('outcome_id', $outcomeId)->count());
    }

    public function test_an_event_with_no_handler_completes_without_side_effects(): void
    {
        // SessionStarted and SubjectSelected exist to make the audit trail
        // reconstructable and correctly do nothing. Treating "no handler" as an
        // error would fill the DLQ with healthy events.
        $session = $this->seedEvent(LoopEvent::SESSION_STARTED, 'Session', Uuid::uuid4()->toString());
        $subject = $this->seedEvent(LoopEvent::SUBJECT_SELECTED, 'Case', Uuid::uuid4()->toString());

        $this->consume();

        foreach ([$session, $subject] as $id) {
            $event = DB::table('hpbrain_event_store')->where('id', $id)->first();
            self::assertSame('completed', $event->status);
            self::assertNotNull($event->completed_at);
        }

        self::assertSame(0, DB::table('hpbrain_dead_letter_queue')->count());
        self::assertSame(0, DB::table('hpbrain_learnings')->count());
        self::assertSame(2, DB::table('hpbrain_event_store')->count());
    }

    // ---- Claiming -----------------------------------------------------------

    public function test_a_claimed_event_cannot_be_claimed_again(): void
    {
        [, , $eventId] = $this->seedOutcome();

        // The claim is `WHERE id = ? AND status = 'pending'`. Simulating the
        // loser of a race is exactly this: the row is no longer pending, so the
        // conditional update matches nothing.
        $first = DB::table('hpbrain_event_store')
            ->where('id', $eventId)->where('status', 'pending')
            ->update(['status' => 'processing']);

        $second = DB::table('hpbrain_event_store')
            ->where('id', $eventId)->where('status', 'pending')
            ->update(['status' => 'processing']);

        self::assertSame(1, $first);
        self::assertSame(0, $second, 'Two workers must not both claim one event.');

        // And the consumer skips it rather than double-processing.
        $this->consume();

        self::assertSame(0, DB::table('hpbrain_learnings')->count());
        self::assertSame('processing', DB::table('hpbrain_event_store')->where('id', $eventId)->value('status'));
    }

    public function test_two_sequential_runs_do_not_process_an_event_twice(): void
    {
        [, , $eventId] = $this->seedOutcome();

        $this->consume();
        $this->consume();

        self::assertSame(1, DB::table('hpbrain_learnings')->count());
        self::assertCount(1, $this->eventsOfType(LoopEvent::LEARNING_WRITTEN));
        self::assertSame('completed', DB::table('hpbrain_event_store')->where('id', $eventId)->value('status'));
    }

    // ---- Consumer state -----------------------------------------------------

    public function test_consumer_state_advances_to_the_last_processed_event(): void
    {
        [, , $first] = $this->seedOutcome();

        $this->consume();

        $state = DB::table('hpbrain_consumer_state')->where('consumer_name', 'loop-consumer')->first();

        self::assertNotNull($state);
        self::assertSame($first, $state->last_processed_event_id);
        self::assertNotNull($state->last_processed_at);
        self::assertSame('active', $state->status);

        // Second pass consumes LearningWritten; the pointer moves, and the row
        // is upserted by consumer_name rather than duplicated.
        $this->consume();

        self::assertSame(1, DB::table('hpbrain_consumer_state')->count());
        self::assertSame(
            $this->eventsOfType(LoopEvent::LEARNING_WRITTEN)[0]->id,
            DB::table('hpbrain_consumer_state')->value('last_processed_event_id')
        );
    }
}
