# ADR-003 — Graph Model

**Status:** Accepted
**Date:** 2026-07-08
**Owner:** Neo4j Lead
**Deciders:** Neo4j lead, Technical Architect, AI lead, Laravel lead
**Decision-log ref:** D-03 (EB-DP Ch.17) — this ADR ratifies the previously open ESO-storage question

## Context / Problem
Capability and organizational memory are the moat. They need a store whose shape matches
the questions we ask: "what capability does this person hold, at what state, evidenced by
what, and how does it connect to roles, outcomes, and learnings?" Those are graph
traversals, not table joins. Separately, the ESO is a governed nine-field contract that
also needs ACID guarantees and audit — a tension between graph expressiveness and
relational rigor we must resolve explicitly.

## Decision
- **Neo4j is the source of truth for capability + memory.** Core labels: `Person, Role,
  Industry, Knowledge, Ability, Skill, Behaviour, Attitude, Capability, Evidence, ESO,
  Executor, Outcome, Learning, OrganizationalMemory, Hypothesis, Case`.
- **KASBA is modelled as a connected cluster**, seeded once from ESCO / O*NET / Singapore
  SkillsFuture; tenant data attaches to the shared ontology.
- **Capability State** (`Unknown → Asserted → Inferred → Assessed → Demonstrated →
  Mastered`, plus `Observed` for Behaviour/Attitude) is a property on the `HOLDS`/
  `DEMONSTRATES` relationship, with `confidence`, `source`, `ts`, `evidenceRef`.
- **Provenance is mandatory** on every capability write; state advances only on evidence
  and never regresses or inflates silently (guarded `SET`, append-only evidence).
- **`tenantId` on every node and relationship**; composite index `(tenantId, businessKey)`.
- **Named, parameterized Cypher only** — no string-built queries.
- **ESO split:** the **contract-at-rest is authoritative in PostgreSQL** (governed,
  versioned, auditable); its **bindings and runtime history live in Neo4j** (executor,
  outcomes, relationships). The Postgres row is the contract; the graph holds its story.

## Alternatives considered
- **Relational-only** — loses the traversal expressiveness KASBA depends on; capability queries become join-heavy and slow. Rejected.
- **Graph-only (ESO contract in Neo4j too)** — simpler mental model, but weaker ACID/audit guarantees for a governed contract. Rejected; the split above is the compromise.
- **RDF/triplestore** — standards-friendly but heavier tooling and weaker ecosystem fit with Laravel. Rejected for the pilot.

## Consequences
**Positive:** capability and memory queries are natural and fast; provenance and honesty are enforced at the schema; the ESO gets both governance rigor and graph context.
**Negative / trade-offs:** two stores must stay consistent for ESO — handled by the outbox pattern (ADR-002); requires the reconciliation job in Ch.9 discipline.
**Follow-ups:** confirm the exact nine ESO fields against the Product Bible before freezing `eso.yaml`.

## Supersedes / Superseded by
Resolves the open item D-03 (ESO storage) in favor of the Postgres-contract / Neo4j-bindings split.
