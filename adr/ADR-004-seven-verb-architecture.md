# ADR-004 — Seven-Verb Architecture

**Status:** Accepted
**Date:** 2026-07-08
**Owner:** AI Lead
**Deciders:** AI lead, Technical Architect, Eng Director, Founder
**Decision-log ref:** D-08 (EB-DP Ch.17)

## Context / Problem
KASBA is only valuable if it can *reason*, not just store. And AI features must not
degrade the product into a chat wrapper. We need a bounded, governable, testable way for
the model to operate over the graph — one that keeps the Brain (memory, structure,
evidence, governance) as the moat and the LLM as a stateless reasoning engine.

## Decision
Every KASBA node exposes a **seven-verb Capability Interface** — the *only* cognitive
operations in the system:

`EXPLAIN · ASSESS · COACH · SIMULATE · EVALUATE · RECOMMEND · EXECUTE`

Each verb, implemented in `eb-ai/verbs/`, is a fixed four-step pipeline:
1. **Governance pre-check** (Agent Brain authorizes; executor *not* chosen here).
2. **Grounding** — retrieve the relevant subgraph from `eb-graph` (evidence, capability
   state, memory, ledger). Ungrounded generation is prohibited.
3. **Reasoning** — a **versioned prompt** (prompt library) + grounding; the model is
   stateless and **model-agnostic** behind an interface.
4. **Guardrail post-check** — validate against schema; run the UODM sufficiency check.
   If evidence is insufficient, return **UNDETERMINED(gaps)** — a first-class result.

Additional rules: verbs **emit events, never write the graph directly**; every call is
logged as a loop event with grounding refs and model version; **EXECUTE ships dark in v1**
(built, governed, flag-off) because it is the only verb that changes the world outside the
Brain.

## Alternatives considered
- **Free-form LLM endpoint** — flexible but ungovernable, unauditable, and erodes the moat. Rejected.
- **RAG-only retrieval** — retrieval without the capability-state structure and governance loses provenance and the seven-verb semantics. Rejected as insufficient alone (RAG is a grounding technique *inside* the verbs).
- **Hard-coded per-feature AI calls** — fast short-term, unmaintainable and inconsistent; each feature would reinvent grounding and guardrails. Rejected.

## Consequences
**Positive:** all AI behavior is one of seven auditable operations; grounding + guardrails are uniform; UNDETERMINED is systematic; models are swappable per tenant/policy.
**Negative / trade-offs:** every verb pays the grounding + guardrail cost; a verb that cannot ground cheaply must degrade to UNDETERMINED rather than guess.
**Follow-ups:** eval suite gates every prompt change (EB-EBP Ch.7); implement EXPLAIN + ASSESS first (lowest risk), EXECUTE last.

## Supersedes / Superseded by
Implements EB-EBP Ch.7; no prior ADR superseded.
