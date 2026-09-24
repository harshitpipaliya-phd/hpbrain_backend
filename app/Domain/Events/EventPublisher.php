<?php

declare(strict_types=1);

namespace App\Domain\Events;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Transactional outbox producer for hpbrain_event_store (ADR-002).
 *
 * THE PROBLEM THIS SOLVES. The event store has idempotency keys, correlation
 * and causation ids, a dead-letter queue, consumer state, replay endpoints and
 * a UI — and, until Module 1, no producers at all. It was structurally empty,
 * so Invariant 8 ("the loop must always close") had no mechanism to close it
 * and the audit trail could not reconstruct a case from the log.
 *
 * WHY ONE CLASS RATHER THAN AN INSERT PER CONTROLLER. The column names are the
 * trap. The store uses `type` and `created_at`; almost every other hpbrain_
 * table uses `event_type`-style names and `created_date`, and code written from
 * habit rather than from the DDL throws SQLSTATE 42S22 at runtime — which is
 * what happened to MemoryGrounding, and would have happened twice more with
 * three hand-written copies of this insert.
 *
 * WHY THE DOMAIN WRITE LIVES INSIDE THE TRANSACTION. A domain row without its
 * event is an invisible change: it happened, nothing downstream can know, and
 * no replay will ever produce it. An event without its row is worse — a
 * consumer acts on something that does not exist. Neither is recoverable after
 * the fact, so both must be one commit.
 */
final class EventPublisher
{
    /**
     * The sanctioned way to write a loop entity: the row and its event, or
     * neither.
     *
     * $write performs the domain insert and returns
     *   ['entityId' => string, 'result' => mixed]
     * The entity id is required rather than inferred, because the event's
     * entity_id and idempotency key both depend on it and a publisher that
     * guessed would produce events pointing at nothing.
     *
     * $correlationId defaults to the entity id. That is right for the event
     * that STARTS a thread (a signal, a case) and wrong for every event after
     * it — those must pass the thread they belong to explicitly. See the
     * correlation rule in the class docblock of each caller.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable():array{entityId: string, result: mixed}  $write
     * @return mixed  The callable's `result`, or NULL when the event had
     *                already been published — see publishRow(): that case
     *                rolls the whole transaction back, so there is no row to
     *                return. Callers that generate a fresh UUID per request
     *                cannot reach it.
     */
    public function publishInTransaction(
        LoopEvent $event,
        string $tenantId,
        string $entityType,
        string $actorId,
        array $payload,
        callable $write,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): mixed {
        try {
            return DB::transaction(function () use (
                $event, $tenantId, $entityType, $actorId, $payload, $write, $correlationId, $causationId
            ) {
                $written = $write();

                $entityId = (string) ($written['entityId'] ?? '');

                if ($entityId === '') {
                    // Rolls the domain write back, which is the correct
                    // outcome: an event with no subject can never be replayed
                    // or grounded on, and a write we cannot account for is a
                    // write we do not keep.
                    throw new InvalidArgumentException(
                        "{$event->value} was published with no entity id; the domain write has been rolled back."
                    );
                }

                $this->publishRow(
                    $event, $tenantId, $entityType, $entityId, $actorId, $payload,
                    $correlationId ?? $entityId, $causationId
                );

                return $written['result'] ?? null;
            });
        } catch (AlreadyPublished) {
            // The outbox working, not failing. Thrown from publishRow() to
            // unwind the transaction, so the domain write is rolled back too:
            // this event and this entity were already committed by the request
            // this one is a replay of, and writing the row a second time under
            // a new primary key would duplicate the business fact that the
            // idempotency key exists to prevent.
            return null;
        }
    }

    /**
     * For loop events that have no domain row of their own — a session opening,
     * a subject being selected. There is nothing to write, so there is nothing
     * to keep consistent, and a transaction would be ceremony.
     *
     * DO NOT reach for this to avoid the callable. Using emit() for anything
     * that has a table reintroduces exactly the gap publishInTransaction
     * closes: the row commits, the event fails, and the change is invisible to
     * every consumer forever. If the thing you are recording has a table, it
     * belongs in publishInTransaction.
     *
     * @param  array<string, mixed>  $payload
     * @return string|null  The event id, or null if this event was already
     *                      published.
     */
    public function emit(
        LoopEvent $event,
        string $tenantId,
        string $entityType,
        string $entityId,
        string $actorId,
        array $payload = [],
        ?string $correlationId = null,
        ?string $causationId = null,
    ): ?string {
        if ($entityId === '') {
            throw new InvalidArgumentException(
                "{$event->value} cannot be emitted with no entity id."
            );
        }

        try {
            return $this->publishRow(
                $event, $tenantId, $entityType, $entityId, $actorId, $payload,
                $correlationId ?? $entityId, $causationId
            );
        } catch (AlreadyPublished) {
            return null;
        }
    }

    /**
     * The single INSERT. Every column name in this method is load-bearing and
     * was read off the DDL, not remembered:
     *
     *   `type`        — NOT event_type
     *   `created_at`  — NOT created_date
     *   `actor_id`    — VARCHAR(36) NOT NULL; there is no such thing as an
     *                   anonymous loop event
     *   `payload`     — JSON NOT NULL, so an empty payload is '{}' not NULL
     *
     * IDEMPOTENCY KEY. The column is VARCHAR(36) with a UNIQUE index, and the
     * natural key ("EvidenceRecorded:tenant-alpha:Evidence:{uuid}") is 60+
     * characters. Stored raw it would be truncated to 36 by MySQL — and since
     * the truncation point falls inside the tenant segment, two unrelated
     * events could produce the same stored key and the second would be
     * silently discarded by the unique index. md5() gives 32 stable characters
     * that fit with room to spare.
     *
     * @param  array<string, mixed>  $payload
     * @throws AlreadyPublished when the idempotency key is already present.
     */
    private function publishRow(
        LoopEvent $event,
        string $tenantId,
        string $entityType,
        string $entityId,
        string $actorId,
        array $payload,
        string $correlationId,
        ?string $causationId,
    ): string {
        $id = Uuid::uuid4()->toString();

        try {
            DB::table('hpbrain_event_store')->insert([
                'id'              => $id,
                'type'            => $event->value,
                'tenant_id'       => $tenantId,
                'entity_type'     => $entityType,
                'entity_id'       => $entityId,
                'actor_id'        => $actorId,
                'payload'         => json_encode($payload),
                'correlation_id'  => $correlationId,
                'causation_id'    => $causationId,
                'idempotency_key' => md5("{$event->value}:{$tenantId}:{$entityType}:{$entityId}"),
                // A produced event is unconsumed by definition. Nothing in this
                // module reads either column — that is Module 5.
                'status'          => 'pending',
                'retry_count'     => 0,
                'created_at'      => now()->format('Y-m-d H:i:s'),
            ]);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            throw new AlreadyPublished();
        }

        return $id;
    }

    /**
     * A duplicate must be recognised identically on both drivers. Production is
     * MySQL; the suite is pinned to in-memory SQLite (phpunit.xml). If only the
     * MySQL form were matched, every idempotency test would pass by accident
     * under SQLite while proving nothing about production — and vice versa.
     *
     * MySQL/MariaDB: SQLSTATE 23000, driver code 1062, "Duplicate entry".
     * SQLite:        SQLSTATE 23000, driver code 19, "UNIQUE constraint failed".
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        if ($driverCode === 1062 || $driverCode === 19) {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed');
    }
}
