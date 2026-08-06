# Enterprise Brain — Architecture

**Status:** Proposal, awaiting approval. No code has been changed.
**Companion to:** [ENTERPRISE-INTELLIGENCE-ARCHITECTURE.md](ENTERPRISE-INTELLIGENCE-ARCHITECTURE.md) (data foundation) · [INTELLIGENCE-ENGINE-ARCHITECTURE.md](INTELLIGENCE-ENGINE-ARCHITECTURE.md) (insight generation)
**Scope:** The reasoning layer above the Intelligence Engine. Eleven subsystems, designed. Nothing implemented.

---

## 0. What you already have — and one correction

You asked me to design Observation → Evidence → Hypothesis → Confidence → Recommendation → Decision → Outcome → Learning.

**That chain exists.** Every table, every foreign key, every confidence column:

| Stage | Table | Notable columns already present |
|---|---|---|
| Observation | `hpbrain_signals` | `confidence`, `severity`, `classification`, `related_entity_*` |
| Evidence | `hpbrain_evidence` | `content`, **`provenance` JSON**, `confidence`, `hash`, `version`, `observed_date` |
| Case | `hpbrain_cases` | `resolved_hypothesis_id`, `hpbrain_case_evidence` join |
| Hypothesis | `hpbrain_hypotheses` | `statement`, `root_cause_family`, `confidence`, `supporting_evidence_ids`, `rejected_reason` |
| Reasoning | `hpbrain_reasoning_steps` | `step_order`, `mental_model_id`, `confidence_score` |
| Recommendation | `hpbrain_recommendations` | `confidence`, `impact`, `cost`, `risk`, `dependencies`, `expected_roi`, `urgency` |
| Decision | `hpbrain_decisions` | **`explanation`**, **`trace` JSON**, `alternatives_considered`, `approved_by/date/note` |
| Execution | `hpbrain_eso_executions` | with executors, evidence, rollback |
| Outcome | `hpbrain_outcomes` | `result`, `metrics`, `kpis`, `evidence_ids` |
| Learning | `hpbrain_learnings` | `pattern`, `domain`, `reusable`, `mental_model_id` |
| Belief | `hpbrain_mental_models` | `rules`, `confidence`, `reinforcement_count`, `version` |
| Governance | `hpbrain_policies` | `rules`, `escalation_path`, `trust_levels`, versioned |

**And the flywheel turns.** This is worth stating precisely, because your own code comments say otherwise:

- **Write half:** `ProcessLoopEvents::handleOutcomeRecorded()` derives a Learning from every Outcome, idempotently, with a UUID v5 key so replay collides with itself rather than duplicating. It refuses to overwrite on replay, on the grounds that a learning is a record of what was known at the time.
- **Read half:** `MemoryGrounding` is constructor-injected into **all four** implemented verbs — `ExplainVerb`, `AssessVerb`, `EvaluateVerb`, `RecommendVerb` — so prior learnings ground new reasoning.

`LearningService`'s docblock still says *"Neither exists yet in either implementation. Until they do, the flywheel does not turn."* **That comment is now stale.** Both halves shipped. It should be corrected, because a stale "this is broken" comment is more expensive than no comment — it causes people to rebuild what already works.

So the honest framing of this document:

> **You are not asking me to build an Enterprise Brain. You are asking me to finish the one you designed.** The loop is closed and turning. What is missing is: what the loop *remembers*, what it can *assume*, what it can *imagine*, and how it learns from being *told no*.

Six real gaps:

| # | Gap | Status |
|---|---|---|
| **G1** | **Assumptions are nowhere.** You asked "which assumptions were made" — no table, no column, no concept. | absent |
| **G2** | **`SIMULATE` and `COACH` verbs are declared in the enum with no implementation.** Scenario simulation *is* the SIMULATE verb. | seam exists, empty |
| **G3** | **Rejection is unstructured.** `rejected_reason` and `approval_note` are free text. Learning from them is impossible. | absent |
| **G4** | **Memory never forgets.** No decay, no scope, no invalidation, no contradiction detection. | absent |
| **G5** | **No prediction.** Everything is retrospective. | absent |
| **G6** | **Provenance is scattered.** The pieces exist across six tables; there is no single answerable "why". | partial |

---

## 1. Layer map

```
┌──────────────────────────────────────────────────────────────────────┐
│                     NATURAL LANGUAGE AGENT                           │
│         asks · explains · simulates · plans · acts (governed)        │
├──────────────────────────────────────────────────────────────────────┤
│  SIMULATION  │  PREDICTION  │  PLANNING   │  ACTION TRACKING         │
│  what-if     │  what next   │  what to do │  what happened           │
├──────────────────────────────────────────────────────────────────────┤
│                        REASONING ENGINE                              │
│   Verb Pipeline: govern → ground → reason → guardrail → sufficiency  │
│   EXPLAIN · ASSESS · EVALUATE · RECOMMEND · COACH ▲ · SIMULATE ▲     │
├──────────────────────────────────────────────────────────────────────┤
│  RECOMMENDATION → DECISION → EXECUTION → OUTCOME → FEEDBACK          │
├──────────────────────────────────────────────────────────────────────┤
│                        BUSINESS MEMORY                               │
│   Episodic   │   Semantic   │   Procedural   │   Working             │
│   (events)   │  (beliefs)   │   (playbooks)  │   (open cases)        │
├──────────────────────────────────────────────────────────────────────┤
│                     PROVENANCE SPINE  ◀ NEW, cross-cutting           │
│   every node carries: derived_from · assumptions · method · version  │
├──────────────────────────────────────────────────────────────────────┤
│                    INTELLIGENCE ENGINE  (a subsystem)                │
│         M-Graph · roles · edges · 16 analyzers · insight funnel      │
├──────────────────────────────────────────────────────────────────────┤
│                       DATA FOUNDATION                                │
└──────────────────────────────────────────────────────────────────────┘
                              ▲ = declared, not built
```

The Intelligence Engine becomes exactly what you said: **one subsystem**. It is the Brain's perception. It answers *"what is true?"* The Brain answers *"what does it mean, what should we do, what would happen, and what did we learn?"*

**The bridge is one edge:** a surviving Insight becomes a Signal. Everything above already knows how to consume Signals, so the Intelligence Engine plugs in without the Brain being modified at all.

---

## 2. Business Memory

Four memory types, distinguished by what invalidates them. This distinction is not academic — it is why they need different storage, different retrieval, and different decay.

```
EPISODIC — what happened                         invalidated by: nothing, ever
  hpbrain_event_store (exists), signals, outcomes, executions
  Append-only history. An event that happened stays happened.

SEMANTIC — what we believe is true               invalidated by: contradicting evidence
  hpbrain_mental_models (exists), hpbrain_learnings (exists)
  + hpbrain_beliefs (NEW: a proposition with confidence and scope)
  Beliefs decay, get reinforced, get contradicted, get retired.

PROCEDURAL — how we act                          invalidated by: efficacy data
  hpbrain_eso_definitions (exists), action templates, executors, policies
  A playbook that stops working loses priority.

WORKING — what we are thinking about now         invalidated by: case closure
  hpbrain_cases (exists) + hpbrain_case_frames (NEW)
  The seven UODM framing questions, per open case.
```

### 2.1 The one new table — Beliefs

Learnings are *episodic*: "this outcome taught us X on this date." A **belief** is *semantic*: a standing proposition the organization currently holds, with the conditions under which it holds.

```
hpbrain_beliefs
  id, tenant_id
  statement            "departments over 40 people without a deputy accumulate
                        unresolved escalations"
  belief_type          causal | correlational | structural | normative | preference
  scope                JSON — conditions under which it applies
                       {entity: OrganizationUnit, size_range: [40, null],
                        industry: telecom, valid_from: 2026-03}
  confidence           DECIMAL(6,4)
  support_count        reinforcing learnings
  contradiction_count  contradicting learnings
  derived_from         JSON — learning ids, mental model ids
  status               active | contested | retired | undetermined
  last_tested_date, next_test_due
```

**`belief_type` is load-bearing and must not be collapsed.** A *correlational* belief and a *causal* belief are used completely differently — only the causal one may be used to predict the effect of an intervention. Conflating them is how a system starts confidently recommending actions based on coincidence, and it is the single most common way analytics products mislead people.

**`scope` is what makes memory survive growth.** A learning from when the organization had 200 people may be false at 1,200. Without scope, memory becomes a set of confidently-held claims about a company that no longer exists.

### 2.2 Retrieval

`MemoryGrounding` already does domain-filtered, confidence-ordered retrieval of reusable learnings. Extend its ranking (the existing signature and behaviour are preserved):

```
relevance = w1·scope_match        (does the current situation satisfy the scope?)
          + w2·semantic_similarity(over statement embeddings)
          + w3·recency_decay      (half-life per belief_type — structural beliefs
                                   decay slowly, preference beliefs fast)
          + w4·reinforcement      (support − contradiction, Beta-smoothed)
          − w5·contradiction_penalty
```

Retrieval returns **contested beliefs too**, flagged. A brain that hides its own internal disagreement is not being confident; it is being unreliable. If two beliefs conflict, the reasoning trace should say so.

---

## 3. The Provenance Spine

This is your seven "explain why" requirements, made structural rather than narrative.

### 3.1 One record type, on every node in the chain

```
hpbrain_provenance
  id, tenant_id
  node_type          Signal | Evidence | Hypothesis | ReasoningStep |
                     Recommendation | Decision | Outcome | Learning | Belief |
                     Simulation | Prediction
  node_id
  ── WHY ────────────────────────────────────────────────────────────
  derived_from       JSON [{type, id, role: supports|refutes|contextualises}]
  method             analyzer | rule | llm | human | simulation | import
  method_ref         analyzer key · rule_key · prompt id · user id
  method_version     analyzer v · rule version · prompt version · model id
  ── HOW SURE ───────────────────────────────────────────────────────
  confidence, confidence_basis JSON   (§5 — the full decomposition)
  ── WHAT WAS TAKEN ON TRUST ────────────────────────────────────────
  assumption_ids     JSON  ◀ G1, the missing piece
  ── WHAT DATA ──────────────────────────────────────────────────────
  data_refs          JSON [{entity_type, entity_id}] — actual rows
  graph_nodes        JSON — M-Graph and I-Graph nodes traversed
  as_of              the data timestamp the reasoning saw
  ── REPRODUCIBILITY ────────────────────────────────────────────────
  input_hash         hash of the exact inputs
  trace_ref          full prompt/response artifact
```

Your seven questions, each answered by a column:

| You asked | Column |
|---|---|
| Why was it generated | `derived_from` — walk it backwards to the observation |
| What evidence supports it | `derived_from` filtered to `role = supports` |
| How confident | `confidence` + `confidence_basis` |
| Which data produced it | `data_refs`, at `as_of` |
| Which analyzer produced it | `method`, `method_ref`, `method_version` |
| Which graph nodes were involved | `graph_nodes` |
| **Which assumptions were made** | `assumption_ids` — §3.2 |

`method_version` matters more than it looks. When an analyzer is improved, you need to know which conclusions were drawn by the old one. Without it, you cannot tell whether a wrong recommendation reflects a bug you already fixed.

### 3.2 Assumptions as first-class objects — the best idea in your list

An assumption is **a proposition the reasoning depended on but did not verify**. Today these are invisible, which means when one turns out to be false, nothing knows what it poisoned.

```
hpbrain_assumptions
  id, tenant_id
  statement          "every active person has exactly one department"
  assumption_type    data_quality | completeness | stationarity |
                     independence | causal | scope | proxy
  source             implicit_by_method | stated_by_model | stated_by_human
  testable           boolean
  test_query         JSON — an AST that would verify it   ◀ the key field
  status             untested | holding | violated | retired
  last_tested, violation_count
```

Assumptions are **mostly generated automatically**, because each method knows what it takes for granted:

| Method | Assumption it necessarily makes |
|---|---|
| TemporalTrend | the series is stationary enough to extrapolate |
| CoOccurrence | the two variables are not both driven by a third |
| Duplicate | the matching keys are actually identifying |
| Cardinality | the reference edge has adequate coverage |
| any using a sample | the sample represents the whole |
| any using a proxy | the proxy tracks the thing it stands for |
| any LLM step | the grounding contained what mattered |

### 3.3 The invalidation cascade

This is the mechanism that turns a report generator into something that behaves like a mind.

```
1. An assumption is tested (scheduled, using test_query) → VIOLATED
        │
2. Find every provenance record referencing it
        │
3. Walk FORWARD through derived_from to every downstream conclusion
        │
4. For each affected node:
     conclusion still open      → REOPEN, flag "rested on a false assumption"
     recommendation pending     → WITHDRAW, notify, explain
     decision made, not executed→ ALERT the decider
     decision executed          → cannot unmake it; record it, and
                                  create a Learning about the assumption itself
     belief derived from it     → recompute confidence; may become contested
        │
5. Emit AssumptionViolated → existing event bus → existing audit trail
```

**Step 4's last branch is the one that makes the system trustworthy.** When the Brain discovers it advised something on a false premise, and the action was already taken, the honest behaviour is to say so — not to quietly drop the finding. That single behaviour is worth more to a manager's trust than any number of correct recommendations.

---

## 4. Reasoning Engine

### 4.1 Keep the pipeline exactly as it is

`VerbPipeline`'s four steps — governance → grounding → reasoning → guardrail+sufficiency — are correct and should not be touched. `UNDETERMINED` as a return type with named gaps is the right primitive and is already load-bearing.

Two verbs get implemented (G2). Both slot into the existing pipeline with no changes to it:

**`SIMULATE`** — §9. Read-only, so `isReadOnly()` should return true for it.
**`COACH`** — advisory guidance for a person or unit, grounded in KASBA assessments and capability gaps, both of which already have full subsystems.

### 4.2 What reasoning actually does

Reasoning is **abductive**: from an observation, generate candidate explanations, then discriminate between them with evidence. That is what `hpbrain_hypotheses` with `root_cause_family` and `rejected_reason` is already shaped for.

```
Signal (from an Insight)
   │
   ├─ 1. FRAME       Open a Case. Populate the seven UODM questions.
   │                 Unanswered questions are recorded as gaps, not filled in.
   │
   ├─ 2. RECALL      MemoryGrounding: prior beliefs, learnings, similar cases.
   │                 "This resembles case #4471 from March. That was a
   │                  reporting-line change, not a real gap."
   │
   ├─ 3. HYPOTHESISE Generate candidates from three sources:
   │                   • root-cause families (structural, taxonomy-driven)
   │                   • prior beliefs whose scope matches
   │                   • LLM, for the genuinely novel — capped confidence
   │                 Each hypothesis must state what would FALSIFY it.
   │                 (`what_would_falsify_it` is already a UODM question.)
   │
   ├─ 4. DISCRIMINATE For each hypothesis, seek evidence that would separate
   │                 it from its rivals. Prefer discriminating evidence over
   │                 confirming evidence — otherwise every hypothesis
   │                 accumulates support and none is ever eliminated.
   │
   ├─ 5. RESOLVE     Best-supported hypothesis wins IF it clears a margin
   │                 over the runner-up. No margin → UNDETERMINED with the
   │                 rivals named. Sets cases.resolved_hypothesis_id.
   │
   └─ 6. RECORD      Reasoning steps + provenance + assumptions.
```

**Step 4 is the difference between reasoning and rationalising.** A system that only gathers confirming evidence will confirm whatever it thought first. Requiring each hypothesis to name its falsifier, and then actively looking for it, is what makes the conclusion mean something.

---

## 5. The Confidence Calculus

This is the subtlest part of the design and the easiest to get quietly wrong.

### 5.1 The trap

Naive propagation multiplies confidence along the chain:

```
0.9 signal × 0.8 evidence × 0.7 hypothesis × 0.85 reasoning
    × 0.9 recommendation × 0.95 decision  =  0.37
```

Eight stages of *reasonable* confidence produce a number that reads as a coin flip. Teams respond by removing the multiplication, and then everything is 0.9 forever and confidence means nothing. Both failure modes are common and both destroy the number's usefulness.

### 5.2 The fix: two kinds of confidence, combined differently

**Evidential support** — how well-backed is this claim? Corroborating evidence should *increase* confidence. Combine in **log-odds** (equivalently, noisy-OR):

```
logit(P) = logit(prior) + Σ log-likelihood-ratio(eᵢ)
```

Three independent pieces of moderate evidence yield *more* confidence than one. That is correct and is what multiplication gets backwards.

**Inferential soundness** — how sound is this step given its inputs? This *does* multiply, but only along **necessary** links, and it is bounded below by re-grounding: a chain deeper than N steps must re-ground against data rather than continuing to compound.

```
confidence_basis JSON = {
  evidential: {prior, contributions: [{evidence_id, llr, independent: bool}]},
  inferential: {chain: [{step, soundness, necessary: bool}], depth},
  sample: {n, adequacy},
  method: {analyzer_reliability, model_version},
  assumptions: {count, untested_count, weakest}
}
```

### 5.3 The independence trap — say it out loud

**Correlated evidence must never be pooled as independent.** This will happen constantly and it is invisible when it does:

- The Cardinality analyzer and the ReferentialIntegrity analyzer both fire on the same null column. That is **one** finding seen twice, not two corroborating findings.
- Five rows from one import are one observation of one file, not five independent observations.
- A trend and its own change-point are the same fact.

Treating these as independent inflates a 0.6 into a 0.95 and produces exactly the kind of unearned certainty that destroys trust. So: **every evidence contribution declares its correlation group**, and contributions within a group are pooled by *maximum*, not by accumulation. When independence is unknown, assume dependence — the conservative direction.

### 5.4 Confidence has a floor and a ceiling

- **Ceiling:** no chain may exceed the confidence of its weakest *necessary* assumption. An untested assumption caps everything downstream at ~0.7. This is what makes assumption-tracking pay for itself.
- **Floor:** below a threshold, the answer is `UNDETERMINED` with gaps — never a low-confidence assertion. `VerbResult` already enforces exactly this, and its docblock puts it well: *"Code that fabricates confidence — a default score, a silent fallback, an invented root cause — is a defect, not a convenience."*

---

## 6. Recommendation Engine

Designed in the companion document (§8) as a template bridge. Two additions specific to this layer:

**Recommendations carry their own falsifier.** Every recommendation states what observable would show it was wrong, and by when. This is what makes §8.3's deferred verification possible — without a stated expectation, "did it work?" is unanswerable.

```
expected_effect   JSON {metric_key, direction, magnitude, by_date, confidence}
falsifier         "if escalations do not drop within 60 days, this was wrong"
```

**Recommendations compete.** Multiple recommendations may address one case, and some are mutually exclusive. `alternatives_considered` already exists on decisions; the recommendation engine should populate it rather than leaving it empty — a decision that names the roads not taken is far more defensible six months later.

---

## 7. Decision Engine

`hpbrain_policies` already has `rules`, `trust_levels`, `routing_criteria`, `escalation_path`, and versioning. `hpbrain_decisions` already has `explanation`, `trace`, `alternatives_considered`, and approval columns. The Decision Engine is largely **routing and governance over what exists**:

```
Recommendation
   │
   ├─ AUTHORITY   which role may decide this? (policy rules + permission matrix)
   ├─ AUTONOMY    may an executor act, or is a human required?
   │              EXECUTE is dark per ADR-004 — keep it dark
   ├─ ROUTE       to the right person, with the full provenance attached
   ├─ CAPTURE     approve | reject | defer | delegate | modify
   │                        ▲ all five, structured — §8
   └─ RECORD      decision + rationale + alternatives + trace
```

**Deferred and modified are first-class outcomes, not variants of rejection.** "Good idea, not now" and "right problem, wrong fix" carry completely different information, and collapsing them into "rejected" destroys the signal the Learning Engine most needs.

---

## 8. Feedback Engine

### 8.1 Rejection reasons must be structured (G3)

Free text cannot be learned from. A closed taxonomy, chosen at rejection time in one click:

| Reason | What the system should learn |
|---|---|
| `wrong_facts` | the data or analysis was wrong → fix perception, lower analyzer trust |
| `wrong_diagnosis` | facts right, cause wrong → lower that hypothesis family |
| `wrong_action` | cause right, fix wrong → lower that template |
| `already_handled` | true but stale → improve freshness, not correctness |
| `not_a_problem_here` | valid elsewhere, not here → **scope** the belief, don't retire it |
| `no_capacity` | correct but unresourced → **do not lower confidence at all** |
| `wrong_owner` | routed badly → fix routing, not the recommendation |
| `disagree_with_priority` | correct, ranked wrong → adjust scoring only |
| `politically_infeasible` | correct and unactionable → keep, deprioritise |

Look at the right-hand column. **Four of these nine mean the recommendation was correct.** A system that treats all nine as "bad recommendation" learns to stop saying true things that are inconvenient — it optimises for agreeableness instead of accuracy. That is the single worst outcome available to this design, and it arrives silently, disguised as improving acceptance rates.

### 8.2 Feedback is more than a decision

```
hpbrain_feedback_events
  target_type/id, feedback_type, reason_code, actor, comment, context
```

Signals worth capturing beyond approve/reject: dismissed from feed, snoozed, pinned, shared, drilled into, marked wrong, marked already-known, acted on outside the system. **Time-to-decision is itself a signal** — a recommendation that sits untouched for three weeks is being rejected by silence.

### 8.3 Deferred verification — the mechanism that fixes the bias

When a recommendation is rejected, schedule a check at its `by_date`:

```
Rejected at T. At T + expected_effect.by_date, test the falsifier:

  problem resolved anyway    → recommendation was probably unnecessary
                               (or something else fixed it — record both)
  problem persists           → REJECTION was wrong, recommendation was right
                               ▶ positive evidence for the recommendation
                               ▶ negative evidence for that rejection pattern
  problem worsened           → strong version of the above; resurface with
                               the original date and "we flagged this on {date}"
```

This is the only honest way to learn from rejections, because it is the only way to observe *any* part of the counterfactual. Without it, the system has no way to tell a bad recommendation from an unpopular correct one — and it will always guess "bad", because that is the direction acceptance data pushes.

---

## 9. Learning Engine

### 9.1 Two kinds of learning that must never be conflated

```
WORLD LEARNING          "large departments without deputies accumulate escalations"
  validated by:         OUTCOMES
  source:               executed decisions and their measured effects
  → updates beliefs, mental models, causal claims

PREFERENCE LEARNING     "this organization does not act on training recommendations"
  validated by:         DECISIONS
  source:               accept/reject/defer patterns
  → updates ranking, routing, framing, timing — NEVER truth
```

**A recommendation can be correct and unwanted.** If preference learning is allowed to update confidence in world beliefs, the system converges on telling people what they already agree with. Keep them in separate tables, with separate update paths, and never let a preference signal touch a belief's confidence.

### 9.2 Learning is off-policy, and that is a real statistical problem

You only observe outcomes for decisions that were **approved and executed**. That is a non-random sample — approved recommendations are systematically the more palatable ones. Naively computing "our recommendations succeed 87% of the time" over that sample is survivorship bias, and it will be wrong in a flattering direction.

Three mitigations, in order of value:

1. **Deferred verification (§8.3)** recovers partial counterfactual signal from rejections. This is the main one.
2. **Propensity weighting.** Weight outcomes by how likely that recommendation type was to be approved at all. A success on a rarely-approved type is more informative than one on an always-approved type.
3. **Report the sample honestly.** Every efficacy figure publishes its `n` and its coverage — *"87% success over 23 executed of 141 recommended."* `hpbrain_metric_snapshots.sample_n` already exists for exactly this purpose, and `SnapshotWriter`'s docblock already argues the case: a rate over two cases is not the same claim as the same rate over two hundred.

### 9.3 Learning → Belief promotion

Learnings are episodes. Beliefs are generalisations. Promotion must require more than repetition:

```
≥ 3 learnings with consistent direction
  AND across ≥ 2 distinct contexts (not the same department three times)
  AND no unresolved contradiction
  AND the pattern survives a held-out check
    → propose a Belief (status = proposed, human confirms)

Contradicting learning arrives
    → belief becomes CONTESTED, not silently overwritten
    → contested beliefs are still retrieved, flagged, and reduce confidence
      in anything that depends on them
```

**Cross-context is the important condition.** Three learnings from one department is one learning observed three times — the same independence trap as §5.3, one level up.

### 9.4 Forgetting (G4)

```
DECAY         confidence decays toward the prior at a half-life set by
              belief_type. Structural beliefs decay slowly; preference
              beliefs fast, because organizations change their minds.

RE-TESTING    testable beliefs get next_test_due. Untested beliefs past
              due are marked stale and cap downstream confidence (§5.4).

SCOPE DRIFT   a belief scoped to "departments of 40+" is re-examined when
              the population it was learned from no longer resembles the
              current one.

RETIREMENT    contradicted, unsupported, or out-of-scope → retired.
              RETIRED, NEVER DELETED. The episodic record of having once
              believed it is itself organizational knowledge, and it is
              what lets you answer "why did we think that in 2026?"
```

---

## 10. Scenario Simulation and What-If

### 10.1 The honest constraint, stated first

**What-if analysis on observational data is the most dangerous feature in this entire design.** "What happens if we move five people from Ops to Finance?" is a *causal* question. All you have is *correlation*. Answering it confidently from correlational data produces authoritative-looking numbers that are simply wrong, and a chart makes a wrong answer look exactly like a right one.

So simulation is stratified into three tiers, and **the tier is displayed with the answer, always**.

### 10.2 Tier S1 — Structural (definitional, always safe)

Recompute graph and metric values under a hypothetical mutation of the graph. **This is arithmetic, not prediction.** No causal claim is made, so none can be wrong.

```
"If these 5 people move from Ops to Finance:"
  Ops headcount 34 → 29           exact
  Finance span of control 1:12 → 1:14   exact
  Ops skill coverage: loses the only 2 people with SCADA   exact  ◀ valuable
  hierarchy depth unchanged        exact
  3 open cases reassign to a manager already at 1:19  exact
```

**This tier covers most of what people actually want to ask**, and it is 100% reliable because it is definitional. It is also cheap: apply a mutation to an in-memory overlay of the I-Graph, re-run the affected analyzers, diff. Nothing is written; the overlay is discarded.

### 10.3 Tier S2 — Projection (statistical, safe with honest intervals)

Extrapolate observed trends. Legitimate, provided the intervals are real and the stationarity assumption is **recorded as an assumption** (§3.2) so it can be tested and can invalidate the projection later.

```
"At the joining and attrition rates of the last 6 months,
 headcount reaches 1,310 ± 45 by Q3 2027 (80% interval).
 Assumes: rates remain stationary; no reorganisation; sample n=182."
```

### 10.4 Tier S3 — Causal (requires an explicit model, always labelled)

Only permitted when a `causal` belief with adequate confidence and matching scope exists. It states its model, its assumptions, and its uncertainty — and it must be able to return `UNDETERMINED`.

```
"Effect of adding a deputy to Ops on escalation backlog:
   estimated −18% over 90 days (range −4% to −31%)
   BASED ON: belief #331, derived from 4 prior instances, confidence 0.62
   ASSUMES: Ops resembles those 4 (size ✓, function ✓, tenure ✗ — untested)
   THIS IS A CAUSAL ESTIMATE FROM 4 OBSERVATIONS. Treat as a hypothesis."
```

**Hard rules for S3:** never LLM-generated; never from a `correlational` belief; always states n; always falsifiable; always visually distinct in the UI. And if no qualifying causal belief exists, the correct answer is *"we don't know — here is the structural effect (S1) instead."*

### 10.5 Implementation

`SIMULATE` becomes a real verb through the **existing** `VerbPipeline` — governance, grounding, reasoning, sufficiency — with a graph overlay as its working surface. It writes a `hpbrain_simulations` record with full provenance, so a simulation can be revisited, compared against what actually happened, and turned into a Learning when reality arrives.

That last part matters: **a simulation that is never compared to the outcome teaches nothing.** Scoring past simulations against reality is what calibrates the Brain's sense of its own predictive accuracy, and it is the only honest basis for how much weight the next simulation deserves.

---

## 11. Predictive Planning

### 11.1 Prediction

Same three tiers, same discipline. Predictions are **written down before the fact and scored after** — a prediction not recorded in advance is a story told afterwards.

```
hpbrain_predictions
  statement, metric_key, predicted_value, interval_low/high, horizon_date
  method, method_version, assumption_ids, provenance_id
  actual_value, error, scored_date, status
```

**A running calibration score** — over all resolved predictions, how often did the 80% interval contain the truth? — is published on the dashboard. If the answer is 45%, the Brain is overconfident and says so. A system that reports its own calibration is dramatically more trustworthy than one that reports only its successes, and it is the single most convincing artifact you can show a skeptical executive.

### 11.2 Planning

A plan is a **DAG of recommendations** with dependencies, sequencing, and a shared expected effect. `hpbrain_recommendations.dependencies` already exists.

```
hpbrain_plans        goal, horizon, status, owner
hpbrain_plan_steps   plan_id, recommendation_id, sequence, depends_on,
                     expected_effect, actual_effect, status
```

Planning composes existing pieces: recommendations to sequence, S1 simulation to check each step's structural feasibility, policies to check authority, capacity data to check realism. **The Brain proposes plans; humans approve them.** `EXECUTE` stays dark per ADR-004, and nothing here changes that.

---

## 12. Action Tracking

`hpbrain_eso_executions` (with evidence and rollback), executors, and the event store already cover the mechanics. What is missing is the **closing of the loop back to the expectation**:

```
Decision → Execution → [wait for expected_effect.by_date] → Measure
                                                              │
   ┌──────────────────────────────────────────────────────────┘
   ▼
Compare actual against expected_effect
   as predicted    → reinforce belief, template, analyzer trust
   no effect       → contradict; was the diagnosis wrong or the action?
   opposite effect → strong contradiction; open a case about the case
   not measurable  → UNDETERMINED — record the measurement gap as a finding
        │
        └──▶ Outcome (existing) ──▶ Learning (existing, automatic) ──▶ Belief
```

The fourth branch is not a failure state. *"We acted and cannot tell whether it worked"* is a genuine and common result, and it points at a real deficiency — the missing measurement — which is itself worth surfacing. Recording it as UNDETERMINED rather than as a neutral success is the difference between an honest system and a flattering one.

---

## 13. Natural Language Agent

The conversational surface over everything above. `hpbrain_conversations`, `AiWorkspace`, `ConversationController` and `PromptRegistry` already exist; this makes the agent **verb-invoking** rather than merely retrieval-based.

```
User: "why is Ops struggling?"
  → EXPLAIN verb → case + hypotheses + evidence, with citations

User: "what should we do about it?"
  → RECOMMEND verb → ranked options with confidence and assumptions

User: "what if we added a deputy?"
  → SIMULATE verb → S1 structural (exact) + S3 causal (labelled, if a
                    qualifying belief exists; otherwise says so)

User: "do it"
  → EXECUTE is dark. The agent creates a Decision for approval,
    routed by policy. It does not act.

User: "you told us this in March and we said no"
  → walks provenance + feedback history and answers honestly,
    including what happened after the rejection (§8.3)
```

Four non-negotiable constraints, all enforced by machinery that already exists:

1. **Every claim carries a citation** to a provenance record. `CitationVerificationResult` already implements this check.
2. **The agent never invents a number.** Numbers come from Tier-1 metrics or the provenance chain, and are verified post-generation.
3. **The agent may say `UNDETERMINED`** and does so with named gaps. `VerbResult` already provides the type.
4. **The agent never executes.** It proposes; governance disposes.

---

## 14. What breaks this

The failure modes, ordered by how likely they are to actually happen.

**1. Confidence theatre.** Numbers that look precise and mean nothing. Mitigation: §5's decomposition, published; a confidence with no `confidence_basis` should be a validation error, not a default.

**2. Learning to be agreeable.** The system optimises for acceptance and stops saying inconvenient true things. This is the most dangerous failure because it looks like success on every metric you'd naturally track. Mitigation: §9.1's separation, §8.1's taxonomy, §8.3's deferred verification. Watch for it directly: if acceptance rate climbs while outcome success rate is flat, that is the signature.

**3. Causal overreach.** S3 answers presented with S1's confidence. Mitigation: tiering, visual distinction, `belief_type` as a hard gate.

**4. Memory rot.** Stale beliefs applied to a changed organization. Mitigation: §9.4's decay, scope, and re-testing.

**5. Provenance bloat.** A provenance record per node per stage, at insight volume, is a lot of rows. Mitigation: retention tiers — full detail for 90 days, then compact to a summary; keep the chain, drop the intermediate prompt artifacts.

**6. Reasoning cost.** Every hypothesis discriminated by an LLM call gets expensive. Mitigation: structural hypotheses from root-cause families are free and cover most cases; LLM only for the novel tail; cache by input hash. Existing quota and cost-accounting machinery applies.

**7. Non-reproducibility.** LLM steps are not deterministic. Mitigation: **the trace is the artifact of record, not something to regenerate.** Store prompt version, model id, parameters, inputs, and outputs. "Replay" means re-reading the trace, not re-running the model.

**8. Nobody uses it.** The most likely failure of all. Ten well-founded recommendations nobody opens is worth less than one that changed a decision. Mitigation: measure decision influence, not insight volume — and be willing to conclude a subsystem is not earning its place.

---

## 15. Build order

Each phase is additive and independently revertable. Phases B1–B3 change no existing behaviour at all.

| Phase | Delivers | Why here |
|---|---|---|
| **B0** | Correct the stale `LearningService` docblock. | 5 minutes; prevents someone rebuilding a working flywheel |
| **B1** | **Provenance spine.** Table + writers on existing nodes. Nothing reads it yet. | Everything else needs it; zero risk |
| **B2** | **Assumptions + invalidation cascade.** Auto-generated per method; scheduled testing. | Highest value per line of code in this document |
| **B3** | **"Why?" surface.** One screen walking any conclusion back to its data. | First visible payoff; makes B1–B2 real |
| **B4** | **Confidence calculus.** Replace ad-hoc confidence with the decomposition. | Before anything depends on the numbers |
| **B5** | **Beliefs + memory hygiene.** Promotion, decay, scope, contradiction. | Semantic memory proper |
| **B6** | **Structured feedback + rejection taxonomy.** | Must precede B7 or learning is biased from day one |
| **B7** | **Deferred verification.** Scheduled falsifier checks on rejections. | The bias fix |
| **B8** | **Learning Engine v2.** World/preference split, propensity weighting, promotion. | Needs B5–B7 |
| **B9** | **`SIMULATE` verb, tier S1 only.** Graph overlay, structural what-if. | Exact, safe, immediately useful |
| **B10** | **S2 projection + predictions + calibration scoring.** | Honest forecasting |
| **B11** | **`COACH` verb.** | Reuses KASBA + capability subsystems |
| **B12** | **Planning (DAG of recommendations).** | Needs B9 |
| **B13** | **NL Agent → verb-invoking.** | Needs everything |
| **B14** | **S3 causal simulation.** | **Last, deliberately** — only once enough validated causal beliefs exist to make it honest |

**B14 is last on purpose.** Causal what-if is the most impressive demo and the most dangerous feature. It should ship only when the belief store has enough outcome-validated causal beliefs to answer with real numbers — and until then, S1 plus an honest "we don't know" is the better product.

---

## 16. Open decisions

Carried forward and new. The first two block B1.

1. **MySQL 8 or MariaDB?** *(open since the first document — still the gating question)*
2. **Provenance retention.** Full detail for how long before compaction? Recommend 90 days full, then chain-only.
3. **Who may confirm a Belief?** Promotion from learning to belief changes how the system reasons. Recommend a named role, not any admin.
4. **Confidence floor for `UNDETERMINED`.** Recommend 0.45, tenant-tunable — but pick it deliberately, because it decides how often the Brain declines to answer.
5. **Deferred verification horizon.** Recommend per-recommendation via `expected_effect.by_date`, defaulting to 60 days.
6. **May preference learning affect ranking?** Recommend yes for ranking and framing, never for confidence or truth. This boundary should be enforced in code, not convention.
7. **Does `EXECUTE` stay dark?** Recommend yes, unchanged, until B12 has run for at least one full plan cycle with human approval throughout.

---

## 17. What I am not proposing

- **No change to `VerbPipeline`, `SufficiencyCheck`, or `VerbResult`.** They are correct.
- **No change to the existing chain tables.** Everything new is a new table plus a provenance record.
- **No un-darkening of `EXECUTE`.** ADR-004 stands.
- **No replacement of the Intelligence Engine.** It becomes the perception subsystem, unmodified, connected by one edge: Insight → Signal.
- **No LLM in confidence, severity, or counting.** Narration, hypothesis generation, and translation only — every one of them capped, cited, and verifiable.
- **No autonomous action.** The Brain reasons, recommends, simulates and plans. Humans decide.
- **No change to login → Command Center → Organization → Department → People → Person.**

---

## Recommendation

Start with **B1 → B2 → B3**: the provenance spine, assumptions with the invalidation cascade, and one "Why?" screen that walks any conclusion back to the data that produced it.

Three reasons. It changes no existing behaviour. It delivers the seven explainability requirements you listed, in full, as the first visible result. And the assumption-invalidation cascade — the Brain noticing that something it told you rested on a premise that turned out false, and saying so unprompted — is the moment this stops feeling like analytics and starts feeling like a colleague.
