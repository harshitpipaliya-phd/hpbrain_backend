# Event Processing Operations

## Current State

Events are written to `hpbrain_event_store` by producers. The event store, dead-letter queue, and consumer state tables exist. However, **no consumers run**. Events accumulate as `pending` and are never processed.

## Required Architecture

```
Event created
→ pending queue
→ consumer claims event (SELECT ... FOR UPDATE SKIP LOCKED)
→ process once (idempotent)
→ mark completed
→ retry transient failure (exponential backoff)
→ dead-letter repeated failure
→ operator can inspect and replay
```

## Tables

| Table | Purpose |
|---|---|
| `hpbrain_event_store` | Outbox / append-only log |
| `hpbrain_dead_letter_queue` | Messages that exhausted retries |
| `hpbrain_consumer_state` | Per-consumer position and health |

## Consumer Requirements

- **Idempotency**: Handlers keyed by `idempotency_key`. Duplicate delivery does not duplicate business actions.
- **Locking**: `SELECT ... FOR UPDATE SKIP LOCKED` to prevent multiple workers from claiming the same event.
- **Retry limit**: Maximum 3 retries before dead-letter.
- **Backoff**: Exponential backoff between retries.
- **Dead-letter queue**: Failed events moved to `hpbrain_dead_letter_queue`.
- **Replay**: Operator can replay a dead-lettered event, which creates a new event with a fresh `idempotency_key`.
- **Tenant-aware**: Each event carries `tenant_id`. Consumers must not process events outside their tenant scope.

## Implementation Plan

1. Create `ProcessOutboxEvent` queued job.
2. Create scheduled dispatcher that claims pending events.
3. Implement retry with backoff in the job.
4. Implement dead-lettering after max retries.
5. Implement replay command that creates a new event referencing the original.
6. Update `hpbrain_consumer_state` on each dispatch cycle.
7. Add metrics: events processed, failed, dead-lettered, replayed.

## Operational Commands

```bash
# Process pending events
php artisan events:process

# Retry failed events
php artisan events:retry --all

# Replay a specific event
php artisan events:replay {eventId}

# Inspect dead-letter queue
php artisan events:dlq

# Clear dead-letter queue (requires confirmation)
php artisan events:dlq:clear
```

## Monitoring

- Alert when `hpbrain_event_store` has more than 1000 pending events older than 5 minutes.
- Alert when dead-letter queue is non-empty.
- Dashboard: events processed per minute, failure rate, dead-letter count.

## Known Gap

**No consumers run.** Events are written, queryable, replayable, and dead-letterable, but nothing processes `status = 'pending'`. The outbox fills and never drains. Closing this gap is required before the platform can be considered production-ready.
