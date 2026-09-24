# ADR-001 — Repository Structure

**Status:** Accepted
**Date:** 2026-07-08
**Owner:** Engineering Director
**Deciders:** Eng Director, Technical Architect, Laravel / Neo4j / AI / DevOps leads
**Decision-log ref:** D-06 (EB-DP Ch.17)

## Context / Problem
The Three-Brain architecture (Company, Skill, Agent) must be visible in the codebase,
buildable by parallel squads, and reusable across future HP products. The Engineering
Blueprint (EB-EBP Ch.3) originally proposed `eb-cognition` for the AI service and folded
the event backbone into `eb-api`. In practice the loop and the cognitive runtime each
have distinct lifecycles, owners, scaling profiles, and test surfaces, and infrastructure
needs its own home. We must fix the topology before Sprint 1 so `eb-contracts` can be
consumed on day one and the two frontend/backend squads can work in parallel.

## Decision
Adopt **seven repositories**:

| Repo | Owns |
|------|------|
| `eb-contracts` | The versioned, logic-free seam: API contracts, event schemas, ESO 9-field, KASBA defs |
| `eb-web` | React — Workspaces, Graph Explorer, EB-DLS |
| `eb-api` | Laravel — Application API + **Agent Brain** (governance, policy, audit, executor binding) |
| `eb-graph` | Neo4j — **Company + Skill Brain** memory (schema, Cypher, KASBA seed) |
| `eb-ai` | AI services — cognitive runtime, seven-verb handlers, prompt library, grounding |
| `eb-events` | The **Organizational Intelligence Loop** as its own event bus |
| `eb-infra` | DevOps — Docker, CI/CD, IaC, observability, environments |

Loop-event **schemas** live in `eb-contracts/events`; their **runtime** lives in `eb-events`.

## Alternatives considered
- **Monorepo** — simpler tooling, but blurs Brain boundaries and couples release cadence. Rejected in favor of contract-enforced separation.
- **Events inside `eb-api`** (per EB-EBP Ch.3) — fewer repos, but the loop's replay / back-pressure lifecycle and ownership differ from the API's. Rejected.
- **Keep name `eb-cognition`** — cosmetic; `eb-ai` is clearer to a multidisciplinary team and matches founder naming. Accepted rename.

## Consequences
**Positive:** architecture legible in the tree; squads build in parallel behind one contract; loop and AI runtime scale/deploy independently; topology reusable for the next HP product.
**Negative / trade-offs:** seven repos add coordination overhead and make `eb-contracts` discipline non-optional; contract drift now hurts more, so CI must enforce `contract-diff` from PR #1.
**Follow-ups:** create `eb-contracts` first; stand up the CI gate manifest in `eb-infra`; update EB-EBP Ch.3 to reference this ADR.

## Supersedes / Superseded by
Supersedes the repository naming in EB-EBP Ch.3 (`eb-cognition`; events-in-`eb-api`).
