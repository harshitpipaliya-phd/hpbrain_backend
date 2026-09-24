# ADR-005 — Organizational Memory

**Status:** Accepted
**Date:** 2026-07-08
**Owner:** Founder / Engineering Director
**Deciders:** Founder, Eng Director, Technical Architect, AI lead, Neo4j lead
**Decision-log ref:** D-09 (EB-DP Ch.17)

## Context / Problem
The entire thesis is that value **compounds**: the organization should get measurably
smarter with use. That only happens if every outcome is written back and becomes available
to future reasoning. Without this, the system is a clever one-shot advisor — impressive,
but not a moat and not the product described in the Manifesto. This is the most important
architectural decision in the suite.

## Decision
**Every outcome writes a Learning back into the graph, and that Learning becomes grounding
for future reasoning.** Concretely, loop stages 11–13:
- On `OutcomeRecorded`, an **idempotent** handler derives a `Learning` (with full
  provenance: which case, decision, ESO, evidence, and result produced it) and writes it
  via the **single Company Brain writer of record** (`eb-graph`), using the outbox pattern.
- `LearningWritten → MemoryUpdated`: the Learning is linked into `OrganizationalMemory` so
  the **next** verb's grounding step (ADR-004) can retrieve it.
- **Continuous Improvement** is observable: we track learnings written per week and the
  *reuse* of prior learnings in later grounding (EB-DP Ch.15 KPIs).

Memory is per-tenant, provenance-bearing, real-time, and queryable — properties a generic
model cannot provide. **This is the moat.**

## Alternatives considered
- **Stateless (no write-back)** — simplest, but the loop never closes and nothing compounds. This is the failure mode the whole architecture exists to avoid. Rejected.
- **External analytics warehouse only** — good for reporting, but not wired into grounding, so it doesn't make the *next decision* smarter in real time. Rejected as the primary memory (may complement later).
- **LLM fine-tuning as memory** — not per-tenant, not provenance-bearing, not real-time, and not auditable; conflates reasoning with memory. Rejected on principle (violates memory-first, P1).

## Consequences
**Positive:** the flywheel actually turns; the moat deepens with use; every learning is auditable back to its evidence and outcome.
**Negative / trade-offs:** write-back must be strictly idempotent (replay-safe) or memory doubles or corrupts; requires disciplined provenance on every learning.
**Follow-ups:** the golden intelligence-flow test must assert that a *subsequent* Signal grounds on a freshly written Learning (EB-FBR §3.5); memory-reuse KPI instrumented from Sprint 4.

## Supersedes / Superseded by
Implements the compounding-loop mandate (Manifesto; EB-EBP Ch.8, Ch.15). No prior ADR superseded.
