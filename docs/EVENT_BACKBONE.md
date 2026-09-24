# Event Backbone

Implements ADR-002. The Organizational Intelligence Loop is the product, so the
event log is not telemetry — it *is* the data flow, and the audit trail is a
by-product of it rather than a parallel system.

## Rules

- **Events are past-tense facts** (`OutcomeRecorded`, `EvidenceRecorded`), never
  commands.
- **Every event carries** `tenant_id`, `correlation_id`, `causation_id`,
  `idempotency_key`, `status`, `created_date`.
- **Handlers are idempotent**, keyed by `idempotency_key`. The loop replays;
  duplicate delivery must not duplicate business actions.
- **Outbox pattern** — events are written in the same transaction as the
  business row, so there is no dual-write race.
- **Poison messages are dead-lettered**, never allowed to stall a stage.

## Tables

| Table | Purpose |
|---|---|
| `hpbrain_event_store` | The outbox / append-only log |
| `hpbrain_dead_letter_queue` | Messages that exhausted retries |
| `hpbrain_consumer_state` | Per-consumer position and health |

## Replay semantics — read before changing

Replaying an event writes a **new** event carrying the original payload, a fresh
`idempotency_key`, and `causation_id` pointing at the original. It does not
re-dispatch the original row.

This is deliberate. Re-dispatching would either be swallowed by the idempotency
check (achieving nothing) or bypass it (duplicating business actions). A replay
is a new fact that references an old one, and the lineage stays inspectable.

## Endpoints

```
GET    /api/v1/events/stats/summary
GET    /api/v1/events/{id}
POST   /api/v1/events/{id}/replay          permission: events.manage
POST   /api/v1/events/retry/failed         permission: events.manage
GET    /api/v1/events/dlq
POST   /api/v1/events/dlq/{id}/retry       permission: events.manage
DELETE /api/v1/events/dlq/{id}             permission: events.manage
GET    /api/v1/events/consumers
```

## Known gap — the important one

**No consumers run.** Events are written, queryable, replayable and
dead-letterable, but nothing processes `status = 'pending'`. The outbox fills
and never drains.

Closing this means: a `ProcessOutboxEvent` job, a scheduled dispatcher claiming
pending rows with `SELECT ... FOR UPDATE SKIP LOCKED`, retry with backoff,
dead-lettering after N attempts, and `hpbrain_consumer_state` updates. Laravel's
database queue driver is the intended vehicle — ADR-002 keeps the transport
broker-agnostic precisely so this can graduate to Kafka later without touching
producers or consumers.
