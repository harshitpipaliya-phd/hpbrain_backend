# ADR-002 — Event Bus

**Status:** Accepted
**Date:** 2026-07-08
**Owner:** Laravel Lead (loop)
**Deciders:** Eng Director, Technical Architect, Laravel lead, DevOps lead
**Decision-log ref:** D-07 (EB-DP Ch.17)

## Context / Problem
The product *is* the 13-stage Organizational Intelligence Loop (Reality → Signals →
Observation → Evidence → Knowledge → Shared Understanding → Reasoning → Decision →
Execution → Outcome → Learning → Organizational Memory → Continuous Improvement). The
value compounds only if every outcome writes back and the loop can be replayed, audited,
and reasoned over. We need a mechanism that is durable, replayable, tenant-safe, and not
locked to one broker before we know pilot scale.

## Decision
Implement the loop as a **dedicated event backbone (`eb-events`)** with these rules:
- **Transport:** Laravel events + **queued listeners on Redis** for the pilot. The event
  contract is **broker-agnostic**, so it can graduate to Kafka/RabbitMQ without changing
  producers or consumers.
- **Events are past-tense facts** (`EvidenceRecorded`, `OutcomeRecorded`), never commands.
- **Every event carries** `tenantId`, `correlationId`, `causationId`, `ts`, `producer`,
  `schemaVersion`. Schemas live in `eb-contracts/events`.
- **Handlers are idempotent** and keyed by `eventId` / natural key — the loop replays.
- **Outbox pattern** bridges relational writes and events atomically (no dual-write race).
- **Company Brain is the single writer of memory**; other services emit, only Company
  Brain writers persist to the graph of record.
- A **replay tool** and a **dead-letter queue** ship in Wave 1.

## Alternatives considered
- **Synchronous service-to-service calls** — simplest, but no replay, no natural audit, brittle under latency, and reasoning would block requests. Rejected.
- **DB triggers / CDC only** — couples logic to the database and hides the loop from the domain. Rejected.
- **Kafka from day one** — right at scale, wrong for a single-tenant pilot; operational weight not yet justified. Deferred behind the broker-agnostic contract.

## Consequences
**Positive:** the loop is durable, replayable, and auditable by construction; the audit trail *is* the data flow; broker choice stays deferrable.
**Negative / trade-offs:** idempotency and correlation discipline are now mandatory everywhere; a poison message must be dead-lettered, not allowed to stall a stage.
**Follow-ups:** loop-replay smoke test in CI (EB-DP Ch.10); dead-letter alerting in `eb-infra`.

## Supersedes / Superseded by
Refines EB-EBP Ch.8 (which located the backbone inside `eb-api`); backbone now in `eb-events` per ADR-001.
