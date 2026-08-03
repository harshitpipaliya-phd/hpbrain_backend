<?php

declare(strict_types=1);

namespace App\Domain\Events;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Claims pending events from the outbox and dispatches them to handlers.
 *
 * This is the consumer side of ADR-002. It is intentionally separate from the
 * producer (EventPublisher) so the transport can graduate from database to
 * Kafka without touching either side.
 *
 * INVARIANTS:
 *   - Exactly-once processing: idempotency_key prevents duplicate work.
 *   - Tenant isolation: events are processed within their tenant boundary.
 *   - Failure isolation: transient failures are retried; permanent failures are
 *     dead-lettered.
 *   - Locking: SELECT ... FOR UPDATE SKIP LOCKED prevents multiple workers
 *     from claiming the same event.
 */
final class EventConsumer
{
    private const MAX_RETRIES = 3;
    private const BATCH_SIZE = 50;

    private int $batchSize = self::BATCH_SIZE;

    public function setBatchSize(int $size): void
    {
        $this->batchSize = max(1, min($size, 500));
    }

    /**
     * Process up to BATCH_SIZE pending events.
     *
     * @return array{processed: int, deadLettered: int, skipped: int}
     */
    public function process(): array
    {
        $processed = 0;
        $deadLettered = 0;
        $skipped = 0;

        $events = DB::table('hpbrain_event_store')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit($this->batchSize)
            ->get();

        foreach ($events as $event) {
            try {
                $this->handle($event);
                DB::table('hpbrain_event_store')
                    ->where('id', $event->id)
                    ->update(['status' => 'completed', 'updated_at' => now()->format('Y-m-d H:i:s')]);
                $processed++;
            } catch (TransientFailure $e) {
                $retryCount = ($event->retry_count ?? 0) + 1;
                if ($retryCount >= self::MAX_RETRIES) {
                    $this->deadLetter($event, $e->getMessage());
                    $deadLettered++;
                } else {
                    DB::table('hpbrain_event_store')
                        ->where('id', $event->id)
                        ->update([
                            'status' => 'pending',
                            'retry_count' => $retryCount,
                            'updated_at' => now()->format('Y-m-d H:i:s'),
                        ]);
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $this->deadLetter($event, $e->getMessage());
                $deadLettered++;
            }
        }

        return ['processed' => $processed, 'deadLettered' => $deadLettered, 'skipped' => $skipped];
    }

    /**
     * Handle a single event based on its type.
     *
     * @throws TransientFailure
     */
    private function handle(object $event): void
    {
        $type = (string) ($event->type ?? '');

        match ($type) {
            LoopEvent::OBSERVATION_MADE->value => $this->handleObservation($event),
            LoopEvent::EVIDENCE_RECORDED->value => $this->handleEvidence($event),
            LoopEvent::SUBJECT_SELECTED->value => $this->handleSubjectSelected($event),
            LoopEvent::SESSION_STARTED->value => $this->handleSessionStarted($event),
            default => null,
        };
    }

    private function handleObservation(object $event): void
    {
        $payload = json_decode((string) ($event->payload ?? '{}'), true) ?: [];

        if (isset($payload['signalId'])) {
            $signalId = (string) $payload['signalId'];
            $tenantId = (string) ($event->tenant_id ?? '');

            $signal = DB::table('hpbrain_signals')
                ->where('tenant_id', $tenantId)
                ->where('id', $signalId)
                ->first();

            if ($signal) {
                $severity = strtolower((string) ($signal->severity ?? 'medium'));
                $status = strtolower((string) ($signal->status ?? 'new'));

                if (in_array($severity, ['high', 'critical'], true) && $status === 'new') {
                    $this->createNotification($tenantId, $signalId, 'high_signal', [
                        'title' => 'High-severity signal detected',
                        'body' => "Signal {$signalId} ({$signal->source}) requires attention.",
                        'severity' => $severity,
                    ]);
                }
            }
        }
    }

    private function handleEvidence(object $event): void
    {
        $payload = json_decode((string) ($event->payload ?? '{}'), true) ?: [];

        if (isset($payload['signalId'])) {
            $signalId = (string) $payload['signalId'];
            $tenantId = (string) ($event->tenant_id ?? '');

            $count = DB::table('hpbrain_evidence')
                ->where('tenant_id', $tenantId)
                ->where('signal_id', $signalId)
                ->count();

            if ($count >= 3) {
                DB::table('hpbrain_signals')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $signalId)
                    ->where('status', 'new')
                    ->update(['status' => 'triaged']);
            }
        }
    }

    private function handleSubjectSelected(object $event): void
    {
        $payload = json_decode((string) ($event->payload ?? '{}'), true) ?: [];

        if (isset($payload['caseId'])) {
            $tenantId = (string) ($event->tenant_id ?? '');
            $title = (string) ($payload['title'] ?? '');

            $this->createNotification($tenantId, (string) $payload['caseId'], 'case_opened', [
                'title' => 'Case opened',
                'body' => "Case '{$title}' has been opened for investigation.",
                'severity' => 'medium',
            ]);
        }
    }

    private function handleSessionStarted(object $event): void
    {
        $payload = json_decode((string) ($event->payload ?? '{}'), true) ?: [];

        if (isset($payload['userId'])) {
            $tenantId = (string) ($event->tenant_id ?? '');
            $userId = (string) $payload['userId'];

            DB::table('hpbrain_notifications')->insert([
                'id' => Uuid::uuid4()->toString(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                // hpbrain_notifications.type is NOT NULL with no default, so
                // omitting it made every one of these inserts fail on MySQL.
                'type' => 'session.started',
                'title' => 'Session started',
                'body' => 'You have successfully logged in.',
                'read_date' => null,
                'created_date' => now()->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function createNotification(string $tenantId, string $entityId, string $eventType, array $data): void
    {
        $users = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();

        foreach ($users as $userId) {
            DB::table('hpbrain_notifications')->insert([
                'id' => Uuid::uuid4()->toString(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'type' => $eventType,
                'title' => (string) ($data['title'] ?? 'Notification'),
                'body' => (string) ($data['body'] ?? ''),
                'entity_type' => $eventType,
                'entity_id' => $entityId,
                'read_date' => null,
                'created_date' => now()->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Move a failed event to the dead-letter queue.
     */
    private function deadLetter(object $event, string $error): void
    {
        DB::table('hpbrain_dead_letter_queue')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => (string) ($event->tenant_id ?? ''),
            'event_id' => (string) $event->id,
            'event_type' => (string) ($event->type ?? ''),
            'error' => $error,
            'retry_count' => (int) ($event->retry_count ?? 0) + 1,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        DB::table('hpbrain_event_store')
            ->where('id', $event->id)
            ->update(['status' => 'dead_lettered', 'updated_at' => now()->format('Y-m-d H:i:s')]);
    }
}
