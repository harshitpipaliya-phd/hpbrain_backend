<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Events\LoopEvent;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Drains the transactional outbox. The other half of Module 4.
 *
 * Module 4 filled hpbrain_event_store and nothing emptied it: every event sat
 * at status='pending' forever, so the loop stopped at stage 10 and Invariant 8
 * ("the loop must always close") failed by omission. The dead-letter queue,
 * its retry counters and its UI have always assumed a consumer that had never
 * once run.
 *
 * DELIBERATELY A SCHEDULED COMMAND OVER A TABLE, not a queue driver. The events
 * are already durable and already transactional with their domain writes;
 * pushing them onto Redis would add a second store that can disagree with the
 * first, and the failure mode of "the queue lost it" is exactly what an outbox
 * exists to prevent.
 */
final class ProcessLoopEvents extends Command
{
    protected $signature = 'brain:process-events
        {--batch=100 : Events to claim in one pass}
        {--max-retries=3 : Attempts before an event is dead-lettered}
        {--once : Process one batch and exit (how the scheduler runs it)}';

    protected $description = 'Process pending loop events from the transactional outbox.';

    private const CONSUMER = 'loop-consumer';

    public function handle(): int
    {
        $batch      = max(1, (int) $this->option('batch'));
        $maxRetries = max(1, (int) $this->option('max-retries'));

        $processed = 0;
        $failed    = 0;

        // --once is the scheduled mode. Without it the command drains until a
        // pass finds nothing, which is what an operator wants after a backlog.
        do {
            $events = DB::table('hpbrain_event_store')
                ->where('status', 'pending')
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($batch)
                ->get();

            foreach ($events as $event) {
                if (! $this->claim($event->id)) {
                    // Another worker took it between the SELECT and here.
                    continue;
                }

                // A poison event must never stop the batch. Every failure is
                // contained to the event that caused it, which is the whole
                // point of having a dead-letter queue.
                if ($this->process($event, $maxRetries)) {
                    $processed++;
                } else {
                    $failed++;
                }
            }
        } while (! $this->option('once') && $events->isNotEmpty());

        $this->info("loop-consumer: {$processed} processed, {$failed} failed.");

        return self::SUCCESS;
    }

    /**
     * Take exclusive ownership of one event.
     *
     * The claim IS the WHERE clause. `WHERE id = ? AND status = 'pending'`
     * makes the transition atomic at the row level: two workers issuing it
     * concurrently serialise on the row lock, and exactly one sees
     * status='pending' and gets an affected-row count of 1. The loser gets 0
     * and moves on. No advisory lock, no SELECT ... FOR UPDATE, no application
     * mutex — a read-then-write would have a window between them and this does
     * not.
     */
    private function claim(string $eventId): bool
    {
        return DB::table('hpbrain_event_store')
            ->where('id', $eventId)
            ->where('status', 'pending')
            ->update([
                'status'       => 'processing',
                'processed_at' => $this->now(),
            ]) === 1;
    }

    private function process(object $event, int $maxRetries): bool
    {
        try {
            $this->dispatch($event);

            DB::table('hpbrain_event_store')->where('id', $event->id)->update([
                'status'         => 'completed',
                'completed_at'   => $this->now(),
                'failure_reason' => null,
            ]);

            $this->advanceConsumerState($event->id);

            return true;
        } catch (Throwable $e) {
            $this->recordFailure($event, $e, $maxRetries);

            return false;
        }
    }

    /**
     * Route an event to its handler.
     *
     * NO HANDLER IS NOT A FAILURE. SessionStarted and SubjectSelected exist so
     * the audit trail can reconstruct a case; they correctly have no side
     * effect. Treating an unhandled type as an error would fill the DLQ with
     * perfectly healthy events and make a real poison message impossible to
     * spot among them.
     */
    private function dispatch(object $event): void
    {
        match ($event->type) {
            LoopEvent::OUTCOME_RECORDED->value => $this->handleOutcomeRecorded($event),
            LoopEvent::LEARNING_WRITTEN->value => $this->handleLearningWritten($event),
            default => null,
        };
    }

    /**
     * Stage (11): the outcome becomes a learning.
     *
     * Invariant 5 — write-back is mandatory. A loop that measures an outcome
     * and does not learn from it is a reporting tool; the learning row is what
     * makes the next signal be interpreted by a smarter organization.
     */
    private function handleOutcomeRecorded(object $event): void
    {
        $outcome = DB::table('hpbrain_outcomes')
            ->where('tenant_id', $event->tenant_id)
            ->where('id', $event->entity_id)
            ->first();

        if ($outcome === null) {
            // A genuine fault, not a no-op: the event says an outcome was
            // recorded and the row is not there. Throwing retries it and, if it
            // stays missing, dead-letters it for a human — silently skipping
            // would lose the learning and leave no trace that it was lost.
            throw new \RuntimeException(
                "OutcomeRecorded {$event->id} names outcome {$event->entity_id}, which does not exist in tenant {$event->tenant_id}."
            );
        }

        // Deterministic id derived from the outcome, so a replayed event
        // computes the same primary key rather than a fresh one. This is what
        // makes replay safe: the second attempt collides with itself instead of
        // writing a duplicate learning.
        $learningId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'eb:learning:'.$outcome->id)->toString();

        if (DB::table('hpbrain_learnings')->where('id', $learningId)->exists()) {
            // Return, do not update. The learning is a record of what was known
            // at the time; rewriting it on replay would edit history.
            return;
        }

        $payload = $this->payloadOf($event);

        DB::table('hpbrain_learnings')->insert([
            'id'           => $learningId,
            'tenant_id'    => $event->tenant_id,
            'outcome_id'   => $outcome->id,
            'pattern'      => $this->patternFor($outcome),
            'description'  => $outcome->feedback,
            // Absent rather than guessed. The publisher derives it by walking
            // decision -> recommendation -> reasoning step -> mental model, and
            // NULL means that chain was broken, which is a cross-domain
            // learning rather than a mislabelled one.
            'domain'       => $payload['domain'] ?? null,
            'confidence'   => $outcome->confidence,
            // ADR-005: only a success is offered back as a pattern to repeat. A
            // failure is still written — the organization must learn from it —
            // but grounding must never propose it as a thing to do again.
            'reusable'     => $outcome->result === 'success' ? 1 : 0,
            'created_by'   => $event->actor_id,
            'created_date' => $this->now(),
        ]);

        // The learning inherits the outcome's thread, and is caused by this
        // specific event — that pair is what lets the audit trail say not just
        // "these belong together" but "this one produced that one".
        $this->emitFollowOn(
            LoopEvent::LEARNING_WRITTEN,
            $event,
            'Learning',
            $learningId,
            [
                'learningId' => $learningId,
                'outcomeId'  => $outcome->id,
                'domain'     => $payload['domain'] ?? null,
                'reusable'   => $outcome->result === 'success',
            ],
        );
    }

    /**
     * Stages (12–13): organizational memory absorbs the learning.
     *
     * MemoryUpdated is the event that closes the loop — the last link in the
     * chain that started with a signal.
     */
    private function handleLearningWritten(object $event): void
    {
        $payload = $this->payloadOf($event);

        $this->emitFollowOn(
            LoopEvent::MEMORY_UPDATED,
            $event,
            'Learning',
            $event->entity_id,
            [
                'learningId' => $event->entity_id,
                'outcomeId'  => $payload['outcomeId'] ?? null,
                'domain'     => $payload['domain'] ?? null,
            ],
        );
    }

    /**
     * A consumer-produced event.
     *
     * This duplicates EventPublisher's insert rather than calling it, on
     * purpose: publishInTransaction() opens its own transaction around a domain
     * write, and emit() is documented for events with no domain row. Neither
     * fits a consumer that is already inside a claimed-event lifecycle and must
     * carry a causation_id pointing at the event it is reacting to. The rules it
     * must obey are the same and are restated here so they cannot drift:
     *
     *   - `type` and `created_at`, never event_type / created_date
     *   - actor_id is NOT NULL; the consumer inherits the original actor rather
     *     than inventing a 'system' identity, so the audit trail still names
     *     the human the chain started from
     *   - idempotency_key is VARCHAR(36) UNIQUE, so the natural key is md5()'d
     *     to 32 characters — raw it would be truncated into collisions between
     *     unrelated events
     *   - a duplicate is SUCCESS: this event was already produced by an earlier
     *     pass over the same source event
     *
     * @param array<string, mixed> $payload
     */
    private function emitFollowOn(
        LoopEvent $event,
        object $source,
        string $entityType,
        string $entityId,
        array $payload,
    ): void {
        try {
            DB::table('hpbrain_event_store')->insert([
                'id'              => Uuid::uuid4()->toString(),
                'type'            => $event->value,
                'tenant_id'       => $source->tenant_id,
                'entity_type'     => $entityType,
                'entity_id'       => $entityId,
                'actor_id'        => $source->actor_id,
                'payload'         => json_encode($payload),
                'correlation_id'  => $source->correlation_id,
                'causation_id'    => $source->id,
                'idempotency_key' => md5("{$event->value}:{$source->tenant_id}:{$entityType}:{$entityId}"),
                'status'          => 'pending',
                'retry_count'     => 0,
                'created_at'      => $this->now(),
            ]);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }
        }
    }

    /**
     * Below max-retries the event goes back to 'pending' and is NOT retried in
     * this pass. That delay is the point: a transient dependency failure needs
     * time to clear, and a tight retry loop turns one bad event into a denial
     * of service against the database it is already failing to reach.
     */
    private function recordFailure(object $event, Throwable $e, int $maxRetries): void
    {
        $attempts = (int) ($event->retry_count ?? 0) + 1;

        if ($attempts >= $maxRetries) {
            DB::table('hpbrain_dead_letter_queue')->insert([
                'id'            => Uuid::uuid4()->toString(),
                'event_id'      => $event->id,
                'consumer_name' => self::CONSUMER,
                'error_message' => $this->truncate($e->getMessage()),
                'error_stack'   => $this->truncate($e->getTraceAsString(), 5000),
                'retry_count'   => $attempts,
                'max_retries'   => $maxRetries,
                'created_at'    => $this->now(),
            ]);

            DB::table('hpbrain_event_store')->where('id', $event->id)->update([
                'status'         => 'failed',
                'retry_count'    => $attempts,
                'failure_reason' => $this->truncate($e->getMessage()),
                'last_retry_at'  => $this->now(),
            ]);

            $this->warn("dead-lettered {$event->type} {$event->id}: {$e->getMessage()}");

            return;
        }

        DB::table('hpbrain_event_store')->where('id', $event->id)->update([
            'status'         => 'pending',
            'retry_count'    => $attempts,
            'failure_reason' => $this->truncate($e->getMessage()),
            'last_retry_at'  => $this->now(),
        ]);
    }

    /**
     * hpbrain_consumer_state has a UNIQUE consumer_name, so this is an upsert
     * by name rather than by id. It records how far the consumer has read — the
     * question "is the loop draining?" is answered from here, not by counting
     * pending rows.
     */
    private function advanceConsumerState(string $eventId): void
    {
        $now = $this->now();

        $updated = DB::table('hpbrain_consumer_state')
            ->where('consumer_name', self::CONSUMER)
            ->update([
                'last_processed_event_id' => $eventId,
                'last_processed_at'       => $now,
                'status'                  => 'active',
                'updated_at'              => $now,
            ]);

        if ($updated === 0) {
            DB::table('hpbrain_consumer_state')->insert([
                'id'                      => Uuid::uuid4()->toString(),
                'consumer_name'           => self::CONSUMER,
                'last_processed_event_id' => $eventId,
                'last_processed_at'       => $now,
                'status'                  => 'active',
                'created_at'              => $now,
                'updated_at'              => $now,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payloadOf(object $event): array
    {
        $decoded = json_decode((string) ($event->payload ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The one-line statement of what happened, which is what a learning IS.
     * Kept mechanical rather than clever: an LLM-written summary here would be
     * unreproducible on replay and unattributable in the audit trail.
     */
    private function patternFor(object $outcome): string
    {
        return sprintf(
            'Decision %s produced a %s outcome.',
            (string) ($outcome->decision_id ?? 'unknown'),
            (string) $outcome->result
        );
    }

    private function truncate(string $value, int $length = 1000): string
    {
        return mb_substr($value, 0, $length);
    }

    private function now(): string
    {
        return now()->format('Y-m-d H:i:s');
    }

    /**
     * Production is MySQL; the suite is pinned to in-memory SQLite
     * (phpunit.xml). Both forms are matched, or the idempotency behaviour under
     * test would not be the behaviour that ships.
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        if ($driverCode === 1062 || $driverCode === 19) {
            return true;
        }

        return str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
