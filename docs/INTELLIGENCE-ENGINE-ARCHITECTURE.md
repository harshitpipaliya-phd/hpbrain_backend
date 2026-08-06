# Organization Intelligence Engine — Architecture

**Status:** Proposal, awaiting approval. No code has been changed.
**Companion to:** [ENTERPRISE-INTELLIGENCE-ARCHITECTURE.md](ENTERPRISE-INTELLIGENCE-ARCHITECTURE.md) (the data foundation)
**Scope:** Designs 1–10 as requested. Nothing implemented.

---

## 0. The central design idea

You asked for intelligence that is not hardcoded. There are two ways to attempt that, and only one of them works.

**The way that fails:** point an LLM at the database and ask it to find insights. It cannot scan 65,000 rows, it cannot compute a distribution, it will invent numbers, and it produces a different answer every run. This is the "ChatGPT understanding a database" idea taken literally, and it does not survive contact with real data.

**The way that works:**

> **Classify what each field *means* structurally, then run generic analyzers whose preconditions are expressed over those meanings — never over entity names.**

Concretely. Instead of writing:

```
"count employees grouped by department"          ← one report, hardcoded forever
```

you write once:

```
For every entity type E that has a REFERENCE attribute R pointing at entity type T:
    compute the fan-out distribution of E grouped by R
    → count, mean, median, p95, concentration, empty targets, outlier targets
```

That single analyzer produces, with **zero further code**:

- employees per department
- employees per manager (span of control)
- vehicles per location
- licenses per person
- certifications per role
- contracts per vendor
- machines per site
- documents per project
- …and every reference relationship that has not been invented yet

The intelligence does not come from the LLM. It comes from **the metadata being rich enough that generic algorithms become specific answers.** The LLM's job is narrower and much more reliable: name things, explain things, translate questions, and propose hypotheses — never count, never decide severity, never assert a fact.

Everything in this document follows from that one idea.

---

## 1. The layer stack

```
┌────────────────────────────────────────────────────────────────────────┐
│ 10  PRESENTATION      Dynamic Dashboard · NL Search · Insight Feed      │
├────────────────────────────────────────────────────────────────────────┤
│  9  LEARNING          feedback → weights → thresholds → suppression     │
├────────────────────────────────────────────────────────────────────────┤
│  8  RECOMMENDATION    insight → action → existing loop (Decision/Outcome)│
├────────────────────────────────────────────────────────────────────────┤
│  7  INSIGHT           score · dedupe · FDR gate · rank · narrate         │
├────────────────────────────────────────────────────────────────────────┤
│  6  ANALYZER          ~16 generic analyzers, precondition-matched        │
├────────────────────────────────────────────────────────────────────────┤
│  5  INSTANCE GRAPH    entities + edges (I-Graph)                         │
├────────────────────────────────────────────────────────────────────────┤
│  4  RELATIONSHIP      FK inference · inclusion dependency · confidence   │
│     DISCOVERY                                                           │
├────────────────────────────────────────────────────────────────────────┤
│  3  SEMANTIC ROLES    each attribute → structural role + confidence      │
├────────────────────────────────────────────────────────────────────────┤
│  2  METADATA GRAPH    entity types + attributes + edges (M-Graph)        │
├────────────────────────────────────────────────────────────────────────┤
│  1  FOUNDATION        attribute catalog · EntityResolver · relational    │
│                       (designed in the companion document)              │
└────────────────────────────────────────────────────────────────────────┘
```

Layers 2–4 are **derived, never authored**. That is what makes new entities self-integrating: Vehicles arrive, get profiled, get roles, get edges, and every analyzer above them fires automatically.

---

## 2. Metadata Graph (M-Graph)

### 2.1 What it is

A small graph — hundreds of nodes, not millions — describing **the shape of a tenant's world**. It is the schema of the schema.

```
                    ┌──────────────────┐
                    │  MetaEntityType  │  Person, Department, Vehicle,
                    │  ─────────────── │  License, Contract, Complaint…
                    │  key             │
                    │  label           │  from terminology engine
                    │  source          │  erp | attribute_store | operational
                    │  row_count       │
                    │  importance      │  ◀ computed, drives dashboards
                    │  volatility      │  rows changed / day
                    └────────┬─────────┘
                             │ HAS_ATTRIBUTE
                             ▼
                    ┌──────────────────┐
                    │  MetaAttribute   │
                    │  ─────────────── │
                    │  key, label      │
                    │  data_type       │  string|int|decimal|date|bool|enum
                    │  semantic_role   │  ◀ LAYER 3 — the important one
                    │  role_confidence │
                    │  fill_rate       │
                    │  distinct_count  │
                    │  cardinality_ratio
                    │  entropy         │
                    │  is_pii          │
                    │  importance      │  ◀ computed
                    └────────┬─────────┘
                             │
        ┌────────────────────┼────────────────────┐
        │ REFERENCES         │ ANCHORS_TIME       │ MEASURES
        ▼                    ▼                    ▼
  another entity        a temporal axis      a quantity
```

Plus **MetaEdge** — a typed, directed relationship between two entity types:

```
MetaEdge
  from_entity, to_entity
  relation           'belongs_to' | 'reports_to' | 'assigned_to' | 'located_at' …
  via_attribute      which attribute carries it
  cardinality        1:1 | 1:N | N:1 | N:M
  discovery_method   declared | fk_constraint | naming | inclusion | ai_proposed
  confidence         0..1
  status             proposed | confirmed | rejected
  coverage           fraction of source rows with a resolvable target
```

### 2.2 Storage

```
hpbrain_meta_entity_types   (tenant_id, key, label, source, row_count,
                             importance, volatility, first_seen, last_profiled)
hpbrain_meta_attributes     (tenant_id, entity_key, attribute_key, data_type,
                             semantic_role, role_confidence, stats JSON,
                             importance, is_pii, status)
hpbrain_meta_edges          (tenant_id, from_entity, to_entity, relation,
                             via_attribute, cardinality, discovery_method,
                             confidence, coverage, status)
hpbrain_meta_snapshots      (tenant_id, snapshot_date, graph_hash, graph JSON)
```

`hpbrain_meta_snapshots` is what makes **"Today's Changes"** and structural-drift detection possible: diff today's M-Graph against yesterday's and you get *"2 new entity types, 4 new attributes, 1 attribute stopped being populated"* for free, with no change-tracking code anywhere else.

### 2.3 Why the M-Graph is the single most important object in this design

Four things depend on it, and none of them is possible without it:

1. **Analyzer applicability** is a query against the M-Graph, not a config file.
2. **The LLM prompt** is built from the M-Graph — a few thousand tokens describing *the shape and statistics* of the data rather than the data itself. This is the trick that makes "an LLM that understands your database" both affordable and accurate: it reasons over a compact, true summary, never over rows.
3. **NL query grounding** — the model can only reference entities and attributes that exist in the M-Graph, so it cannot invent a field.
4. **Dashboard composition** — importance and centrality come from here.

### 2.4 Where it comes from

Three sources, merged, highest confidence winning:

| Source | Confidence | Covers |
|---|---|---|
| `hpbrain_entity_mappings` (existing) | 1.00 | ERP-backed: Person, OrganizationUnit, Position, Organization |
| `hpbrain_attribute_definitions` (companion doc) | 1.00 | Everything discovered by ingestion |
| Live profiling of `hpbrain_operational_records` datasets | 0.90 | Operational facts, per dataset |

The M-Graph is rebuilt by a scheduled job (`brain:profile`), incrementally, and after every ingestion run.

---

## 3. Semantic Role Classification (Layer 3)

This layer is why the whole thing works. A field's **structural role** is what decides which analyzers apply — not its name, not its type alone.

### 3.1 The role vocabulary

Deliberately small. Eleven roles cover essentially everything, and a small vocabulary is what keeps analyzer preconditions comprehensible.

| Role | Meaning | Typical evidence |
|---|---|---|
| `IDENTIFIER` | uniquely names a row | cardinality ratio > 0.95, low null |
| `REFERENCE` | points at another entity | value set ⊆ another entity's identifier set |
| `HIERARCHY` | points at the *same* entity | REFERENCE where target = source |
| `TEMPORAL` | a point in time | date/datetime type, or parseable ≥ 95% |
| `INTERVAL_END` | closes a temporal span | TEMPORAL, always ≥ another TEMPORAL |
| `CATEGORICAL` | a small repeated vocabulary | distinct ≤ 50 **and** distinct/rows < 0.1 |
| `STATUS` | lifecycle state | CATEGORICAL ∩ status lexicon, transitions over time |
| `QUANTITY` | a measured number | numeric, not identifier, meaningful mean |
| `LABEL` | human-readable name | text, high cardinality, short, non-unique |
| `CONTACT` | email/phone/address | format match ≥ 90% |
| `FLAG` | boolean | 2 distinct values in a boolean lexicon |
| `FREE_TEXT` | prose | long, high entropy, no structure |

### 3.2 Classification is evidence-based, and it reports its own uncertainty

Each candidate role accumulates evidence from independent signals — **name affinity is only one of five, and the weakest**:

```
score(role) = w1·statistical_shape      (cardinality, entropy, null rate, length)
            + w2·value_format           (regex families: date, email, phone, plate, IFSC…)
            + w3·inclusion_dependency   (value set containment against other entities)
            + w4·name_affinity          (lexicon + fuzzy: '_id', '_date', 'is_', 'no'…)
            + w5·co_variation           (does it move with a known TEMPORAL / STATUS?)
```

The winning role is accepted only if it clears a margin over the runner-up. Otherwise the attribute is marked `role = UNKNOWN, status = proposed` and goes to the review queue.

**This is deliberately the same fail-closed discipline as `EntityResolver`.** A misclassified role is not a cosmetic bug — it silently changes which analyzers run and therefore which insights exist. Guessing is worse than admitting ignorance, and the codebase already has the vocabulary for that: `UNDETERMINED` with named gaps.

### 3.3 Worked example — Vehicles arrive tomorrow

An ERP pushes a `vehicles` sheet nobody anticipated:

```
registration_no   MH12AB1234, MH14CD5678…   unique 100%, plate regex 99%
assigned_to       4471, 4482, 4471…          ⊆ tbluser.id (99.2% contained)
purchase_date     2019-03-14…                date-parseable 100%
insurance_expiry  2026-11-02…                date-parseable 100%, always > purchase_date
vehicle_type      Truck, Van, Bike           3 distinct / 412 rows
status            active, in_service, sold   3 distinct ∩ status lexicon
odometer_km       84213, 12004…              numeric, mean 47k, no uniqueness
depot             Zone-A, Zone-B…            7 distinct
```

Classified with no human input and no vehicle-specific code:

```
registration_no  → IDENTIFIER   0.99
assigned_to      → REFERENCE → Person  0.96   ◀ EDGE DISCOVERED
purchase_date    → TEMPORAL     0.99
insurance_expiry → INTERVAL_END 0.94        ◀ implies expiry/renewal analysis
vehicle_type     → CATEGORICAL  0.98
status           → STATUS       0.95
odometer_km      → QUANTITY     0.97
depot            → CATEGORICAL  0.93   (GEO candidate, 0.61 — proposed only)
```

And **immediately**, with zero new code, the analyzer layer produces:

- vehicles per person, and the distribution of it (CardinalityAnalyzer)
- 14 vehicles with no assignee (ReferentialIntegrityAnalyzer)
- 3 vehicles assigned to people who no longer exist — orphans (same)
- fleet composition by type and by depot (DistributionAnalyzer)
- depot concentration: 61% of the fleet in one depot (same, concentration measure)
- odometer outliers, 2 vehicles far outside the robust range (NumericProfileAnalyzer)
- purchase trend by month, fleet ageing (TemporalTrendAnalyzer)
- **28 vehicles whose insurance expires within 30 days** (IntervalAnalyzer — this one is genuinely valuable and nobody asked for it)
- 4 duplicate registration numbers (DuplicateAnalyzer)
- status distribution, and 9 vehicles stuck in `in_service` for 200+ days (FreshnessAnalyzer)
- vehicles now appear in the knowledge graph, one hop from Person
- vehicles now appear in NL search: *"who has a vehicle with expiring insurance?"*
- vehicle tiles now compete for dashboard space on merit

That is the requirement — *"the Intelligence Engine should automatically include them"* — discharged by construction rather than by promise.

---

## 4. Relationship Discovery (Layer 4)

### 4.1 The four detectors, in confidence order

**1. Declared** (confidence 1.00) — `hpbrain_entity_mappings`, real FK constraints, profile-confirmed mappings. Free and certain.

**2. Inclusion dependency** (confidence up to 0.95) — the workhorse. If ≥ 95% of distinct non-null values of `A.x` appear in the identifier set of entity `B`, then `A.x` probably references `B`.

Computed cheaply and safely:
- Build a compact sketch of each identifier set once per entity (HyperLogLog for cardinality + a Bloom filter for membership), not a cross join.
- Test candidate pairs only where types are compatible and cardinality is plausible.
- **Reject coincidences explicitly.** Two unrelated integer columns both ranging 1–100 will show perfect containment and mean nothing. Guards: require the target's identifier cardinality to be high (> 200 distinct, configurable), require the source's distinct count to be a meaningful fraction of the target's, and penalise dense contiguous integer ranges. Without these guards, inclusion dependency generates dozens of confident nonsense edges — this is the classic failure mode of automated schema discovery and it is worth naming.

**3. Name affinity** (confidence up to 0.75, never sufficient alone) — `assigned_to`, `person_id`, `manager_ref`, `emp_no` matched against a lexicon and the alias table. Used to *boost* a weak inclusion result, never to create an edge by itself.

**4. AI-proposed** (confidence capped at 0.70, always `status = proposed`) — the LLM sees the M-Graph plus column samples and proposes semantic links a statistic cannot see: *"`supervisor_name` on complaints is probably the same population as `Person.display_name` — a name-based rather than id-based reference."* Genuinely useful, because that link is invisible to inclusion dependency (names, not ids) and to naming rules. Never auto-confirmed.

### 4.2 Edge lifecycle

```
proposed ──confirm──▶ confirmed ──▶ used by analyzers, graph, NL search
   │                      │
   └──reject──▶ rejected  └──coverage drops below floor──▶ degraded (alert)
```

Auto-confirm threshold defaults to **0.95 with coverage ≥ 0.90**, per tenant. Everything else waits for a human in the Relationship Review screen — one click, and the confirmation writes an alias so the same edge is confirmed automatically on every future tenant with the same shape.

### 4.3 Edges that are not references

Three more edge kinds, each earned differently:

- **Hierarchy** — a REFERENCE whose target is its own entity. Immediately unlocks depth, cycles, roots, span-of-control, orphan subtrees.
- **Temporal co-location** — two entity types whose activity correlates in time above chance. Weak, exploratory, always `proposed`.
- **Shared-attribute** — two entities that share a high-cardinality value space (same phone numbers, same location codes). Produces N:M bridges the schema never declared.

---

## 5. Instance Graph (I-Graph)

### 5.1 Materialize selectively — this is a real cost decision

Naively materializing every edge for 65k operational records × 6 references = ~400k edge rows per dataset, rebuilt on every import. Instead, **two edge providers behind one interface**:

```php
interface EdgeProvider {
    public function edgesFrom(string $tenantId, EntityRef $node, array $relations, int $limit): array;
    public function edgesTo(string $tenantId, EntityRef $node, array $relations, int $limit): array;
}
```

| Provider | Storage | Used for |
|---|---|---|
| `RelationalEdgeProvider` | **none** — resolved at query time from the source column | Edges backed by a real column: `person.department_id`, `vehicle.assigned_to`. The column is already indexed; storing a copy would be pure duplication that can go stale. |
| `MaterializedEdgeProvider` | `hpbrain_graph_edges` | Edges with no single backing column: discovered N:M bridges, name-based matches, derived edges (`worked_with`, `co_located`), aggregated weights. |

Traversal composes both. The consumer never knows the difference.

```
hpbrain_graph_edges
  id, tenant_id
  from_type, from_id, relation, to_type, to_id
  weight DECIMAL(10,4), confidence DECIMAL(5,4)
  derived_by VARCHAR(64), evidence JSON
  valid_from, valid_to          ◀ temporal: edges expire, history is preserved
  UNIQUE (tenant_id, from_type, from_id, relation, to_type, to_id, valid_from)
  INDEX (tenant_id, from_type, from_id, relation)
  INDEX (tenant_id, to_type,   to_id,   relation)     ◀ reverse traversal
```

`valid_from` / `valid_to` matter more than they look: *"who reported to whom last March"* is an ordinary question, and an edge table without time can only ever answer *now*.

### 5.2 Traversal

Recursive CTE (MySQL 8 / MariaDB 10.2+), **depth-capped at 4** and **fan-out-capped per level**. `ADR-008` deferred Neo4j and this design keeps that decision honest: the `EdgeProvider` interface *is* the port. If measured traversal latency ever justifies a graph database, it costs an adapter.

An `hpbrain_graph_paths` table caches frequently-walked paths (org → unit → person → skill) as a materialized projection, refreshed by job. This is the escape valve before Neo4j, and it is cheap.

---

## 6. The Analyzer Layer — the engine proper

### 6.1 The contract

```php
interface Analyzer {
    /** Shape this analyzer needs. Evaluated against the M-Graph — never a name. */
    public function precondition(): Precondition;

    /** Runs against one binding of that shape. Pure; no side effects. */
    public function analyze(AnalysisContext $ctx, Binding $binding): ObservationSet;

    public function cost(): Cost;   // ROWS_SCANNED estimate → scheduling
}
```

The scheduler asks the M-Graph *"which (entity, attribute, edge) tuples satisfy each precondition?"* and runs each analyzer once per binding. **No analyzer ever names an entity or a field.** That property is testable, and it should be a CI check: grep the analyzer namespace for `'Person'`, `'department'`, `'employee'` and fail the build on a hit.

### 6.2 The catalog — 16 analyzers

| # | Analyzer | Precondition | Produces |
|---|---|---|---|
| 1 | **Volume** | any entity | count, growth vs prior snapshots, rate of change |
| 2 | **Completeness** | entity + attributes | fill rate per attribute, importance-weighted completeness score, worst offenders |
| 3 | **Distribution** | `CATEGORICAL` | frequency, entropy, concentration (HHI/Gini), long tail, rare-value flags |
| 4 | **NumericProfile** | `QUANTITY` | mean/median/percentiles, robust outliers (MAD), bimodality |
| 5 | **TemporalTrend** | `TEMPORAL` | series by bucket, robust trend (Theil–Sen), seasonality, change points |
| 6 | **Interval** | `TEMPORAL` + `INTERVAL_END` | duration distribution, SLA breach, overdue, expiring-soon, unclosed |
| 7 | **Cohort/Survival** | entry + exit `TEMPORAL` | retention curves, attrition, tenure, churn by cohort |
| 8 | **ReferentialIntegrity** | any edge | dangling refs, null refs, unreachable targets, orphan rate |
| 9 | **Cardinality** | any edge | fan-out distribution, empty targets, overloaded targets, span of control |
| 10 | **Hierarchy** | `HIERARCHY` edge | depth, breadth, cycles, multiple roots, imbalance, orphan subtrees |
| 11 | **Duplicate** | `IDENTIFIER` / `CONTACT` / `LABEL` | candidate pairs with score, blocked + ranked |
| 12 | **Consistency** | discovered functional dependency | violations of A→B (one id, two emails; one dept, two names) |
| 13 | **CoOccurrence** | two `CATEGORICAL`/`REFERENCE` on one entity | association strength (lift, χ² with FDR), cross-links |
| 14 | **Freshness** | update `TEMPORAL` | staleness distribution, dormant records, dead data sources |
| 15 | **StructuralChange** | two M-Graph snapshots | new/lost entities, new/lost attributes, type drift, fill-rate collapse |
| 16 | **Centrality** | I-Graph | degree/betweenness → important entities, hubs, single points of failure |

### 6.3 Your 26 requirements, and where each comes from

Proof that none of them needs bespoke code:

| You asked for | Analyzer | Binding — derived, not written |
|---|---|---|
| Employee Count | Volume | entity=Person |
| Department Growth | Volume + TemporalTrend | entity=OrganizationUnit |
| Missing Data | Completeness | every entity |
| Duplicate People | Duplicate | Person.{email, phone, name} |
| Inactive Employees | Freshness + Distribution | Person.status, Person.updated |
| Organization Health Score | composite of 2, 8, 10, 11, 14 | see §7.5 |
| Data Completeness | Completeness | all |
| Skill Distribution | Distribution | edge Person→Skill |
| Experience Distribution | NumericProfile | QUANTITY on Person |
| Education Distribution | Distribution | CATEGORICAL on Person |
| Age Distribution | NumericProfile | derived from TEMPORAL (dob) |
| Gender Ratio | Distribution | CATEGORICAL on Person |
| Joining Trend | TemporalTrend | Person.joining_date |
| Attrition Trend | Cohort/Survival | join + exit TEMPORAL |
| Project Allocation | Cardinality | edge Person→Project *(needs the source — §11)* |
| Resource Utilisation | Cardinality + NumericProfile | same |
| Attendance Trend | TemporalTrend | operational dataset with TEMPORAL + STATUS |
| Hierarchy Graph | Hierarchy | HIERARCHY edge |
| Reporting Structure | Hierarchy | HIERARCHY edge |
| Relationship Graph | Centrality | I-Graph |
| Cross Department Links | CoOccurrence | two REFERENCEs on one entity |
| Missing Managers | ReferentialIntegrity | null-ref on HIERARCHY edge |
| Orphan Records | ReferentialIntegrity | dangling ref on any edge |
| Duplicate Departments | Duplicate | OrganizationUnit.name |
| Duplicate Organizations | Duplicate | Organization.name |
| Potential Errors | Consistency + Distribution | functional dependencies, rare values |

**26 of 26, from 16 analyzers, none of which mentions an employee.**

---

## 7. Insight Generation Engine

### 7.1 The problem nobody expects

Do the arithmetic before designing this layer, because it determines everything:

```
40 entity types × 20 attributes × 16 analyzers ≈ 12,800 analyses
each emitting 1–10 observations                ≈ 40,000 observations
```

**Generating insights is easy. The entire product is deciding which seven a human should see this morning.** A system that surfaces 4,000 findings is strictly worse than one that surfaces none, because it trains its users to ignore it — and that is unrecoverable.

So the insight layer is mostly a **suppression** layer.

### 7.2 The funnel

```
40,000 observations
   │
   ├─▶ STATISTICAL GATE ───────────────────────────────── ~2,000 survive
   │     Benjamini–Hochberg FDR at q=0.05 across the whole
   │     family of tests. See §7.3 — this is non-negotiable.
   │
   ├─▶ MATERIALITY GATE ──────────────────────────────────  ~600 survive
   │     Effect size, not just significance. "3 of 12,000 rows
   │     affected" is real and irrelevant. Minimum absolute
   │     count AND minimum proportion, both configurable.
   │
   ├─▶ SUFFICIENCY GATE ──────────────────────────────────  ~500 survive
   │     Reuses the EXISTING SufficiencyCheck. An observation
   │     that cannot answer what_changed / who_is_affected /
   │     how_large_is_the_gap becomes UNDETERMINED(gaps) —
   │     visible as a data gap, never dressed up as a finding.
   │
   ├─▶ DEDUPLICATION ─────────────────────────────────────  ~200 survive
   │     Same root fact reached by four analyzers is ONE insight
   │     with four evidence rows. Keyed by (entity, attribute,
   │     population, insight_type).
   │
   ├─▶ CAUSAL SUBSUMPTION ────────────────────────────────   ~80 survive
   │     "Dept X has no manager" SUBSUMES "31 people in Dept X
   │     have no manager". Report the cause, carry the effect
   │     as its consequence. Implemented as a DAG over insight
   │     types, itself derived from the M-Graph edges.
   │
   ├─▶ LEARNED SUPPRESSION (Layer 9) ─────────────────────   ~40 survive
   │     Dismissed before, snoozed, known-and-accepted.
   │
   ├─▶ SCORING + RANKING ─────────────────────────────────   top 7 shown
   │     the rest remain browsable, never lost
   ▼
```

### 7.3 Why the FDR gate is the make-or-break decision

Run 12,800 statistical tests at α = 0.05 and you expect **640 false positives** before the data has said anything at all. A user seeing 640 confident, wrong "anomalies" on day one will never trust the system again, and no amount of later tuning recovers that.

Benjamini–Hochberg controls the *false discovery rate* across the whole family: of everything reported as significant, at most q (default 5%) is expected to be noise. It is ~20 lines of code, it is applied per analysis run across all analyzers, and it is the single highest-leverage decision in this document.

Corollary, and it must be enforced structurally: **an analyzer may not decide its own significance.** It returns an effect size, a sample size, and a p-value (or a distribution-free equivalent). The gate decides. Otherwise 16 analyzers develop 16 private notions of "important" and the FDR gate has nothing to work with.

### 7.4 Insight scoring

```
score = severity^1.5 × confidence × materiality × novelty × actionability × trust

severity      how bad, normalised per insight type
confidence    from role confidence × edge confidence × sample adequacy
materiality   effect size, log-scaled by affected population
novelty       decays if seen before; 0 if dismissed; spikes on re-emergence
actionability is there a recommendation template? does the user have permission?
trust         learned per (tenant, analyzer, insight type)  ◀ Layer 9
```

`novelty` deserves a note. Without decay, *"Finance has no manager"* is the top insight every single day forever, and the feed becomes wallpaper. With decay, it drops after acknowledgement and **returns to the top if it is still unfixed after 30 days** — because a stale, ignored problem is itself a finding.

### 7.5 The Health Score

Composite of the analyzer families, **always published with its breakdown** — the discipline `AnalyticsController` already applies to `intelligenceScore`:

```
Health = w1·Completeness      (Completeness analyzer, importance-weighted)
       + w2·Integrity         (ReferentialIntegrity: orphans, dangling refs)
       + w3·Uniqueness        (Duplicate: 1 − duplicate rate)
       + w4·Structure         (Hierarchy: cycles, orphan subtrees, headless units)
       + w5·Freshness         (Freshness: staleness distribution)
       + w6·Consistency       (Consistency: functional-dependency violations)
```

Weights come from the industry pack (extending the existing `IndustryPack`) and are tenant-overridable. Every component links to the insights that produced it, so *"92%"* is always one click from *"and here is the 8%"*.

**A score with insufficient data returns `UNDETERMINED`, not 0.** A tenant with no imported data has an unknown health score, not a terrible one — and `SnapshotWriter` already encodes exactly this distinction (`value` is nullable, and its docblock explains why at length).

### 7.6 Narration — the one place the LLM writes

Insights are computed as structured facts. The LLM converts a **batch** of them into readable English:

```
IN   {type: reference_null, entity: OrganizationUnit, attribute: head_id,
      affected: 4, total: 34, examples: ['Finance','Ops','QA','Legal'],
      severity: 0.72, trend: 'up_from_2_last_month'}

OUT  "Four departments have no assigned head — Finance, Operations, QA and
      Legal — up from two last month."
```

Hard constraints, enforced by the existing grounding path:
- Every number in the output must appear in the input. Post-generation numeric verification rejects any figure that does not — this is what `CitationVerificationResult` already does for citations.
- Failure to verify falls back to a deterministic template. **A finding is never dropped because narration failed**; it just reads more mechanically.
- The LLM never sets severity, never sets confidence, never invents a cause.

---

## 8. Recommendation Engine

### 8.1 Reuse, do not rebuild

You already have `RecommendationService`, `hpbrain_recommendations`, `DecisionController`, approval workflow, `hpbrain_outcomes`, `LearningService`, and the `RECOMMEND` verb. The recommendation engine is a **bridge into that loop**, not a new subsystem.

```
Insight ──▶ ActionTemplate matching ──▶ Recommendation ──▶ [existing loop]
                                                            Decision
                                                            Execution
                                                            Outcome
                                                            Learning ──┐
                                                                       │
            ◀──────────── template efficacy feedback ──────────────────┘
```

### 8.2 Templates are data, bound by insight *type*

```
hpbrain_action_templates
  id, tenant_id ('platform' = shipped default)
  insight_type          'reference_null' | 'duplicate_candidate' | 'trend_break' …
  applies_when          JSON predicate over insight attributes
  title_template        "Assign a head to {label}"
  rationale_template    "{count} {entity_plural} have no {attribute_label}…"
  action_kind           navigate | form | bulk_edit | import | review | notify
  action_payload        JSON — deep link, form id, bulk operation
  priority_base, industry_code, requires_permission
```

Because templates bind to **insight type**, not entity type, one template covering `reference_null` serves *"department has no head"*, *"vehicle has no assignee"*, *"contract has no owner"*, and every future entity — automatically. The terminology engine supplies the nouns, so a hospital reads *"4 wards have no head"* and a bank reads *"4 branches"*.

### 8.3 Where the LLM legitimately adds value

For insights with **no matching template** — the genuinely novel ones — the `RECOMMEND` verb runs through the existing `VerbPipeline`: governance → grounding (the insight + its evidence + the M-Graph neighbourhood) → reasoning → sufficiency check. An LLM recommendation is marked `origin = ai`, carries lower base confidence, requires human approval, and — when acted on successfully twice — is **promoted into a template**, at which point it becomes deterministic and free.

That promotion path is the system genuinely learning to advise, and it costs one table and one job.

---

## 9. Dynamic Dashboard Generator

### 9.1 Generate configuration, not components

The key move: the generator does not render anything. **It writes rows into the dashboard tables you already have** — `hpbrain_dashboards`, `hpbrain_dashboard_widgets`, `hpbrain_dashboard_layouts` — which `DashboardBuilder.tsx` and `WidgetRegistry.ts` already render. Zero frontend rewrite; the generator is a backend job.

### 9.2 Composition algorithm

```
1. RANK ENTITIES      importance = f(row_count, centrality, edge_degree,
                                     query_frequency, insight_density,
                                     recency of change)
2. SELECT WIDGETS     for each top entity, the highest-scoring insights and
                      metrics bound to it; each analyzer output declares its
                      preferred visual form (§9.3)
3. RESOLVE SLOTS      hero → attention → trend → distribution → detail
4. APPLY ROLE FILTER  reuse the existing roleAccess.ts allow-lists
5. APPLY FEEDBACK     pinned widgets always keep their slot; hidden stay hidden
6. DIFF & PERSIST     write only what changed — a dashboard that reshuffles
                      every night is unusable; users navigate by position
```

Rule 6 matters more than it sounds. **Layout stability is a feature.** The generator proposes changes; significant reshuffles surface as *"3 new insights available — update layout?"* rather than silently rearranging the screen under someone's cursor.

### 9.3 Visual form is derived from the analyzer, not chosen per screen

| Analyzer output | Form |
|---|---|
| single value + delta | stat tile |
| categorical distribution ≤ 7 | bar |
| categorical distribution > 7 | ranked list + "other" |
| time series | line + trend band |
| two-way categorical | heatmap |
| hierarchy | tree |
| graph neighbourhood | node-link, depth-capped |
| duplicate pairs | review list |
| completeness | segmented meter |

Recharts is already a dependency and already lazy-loaded for exactly this reason.

### 9.4 A generated dashboard for the vehicles tenant

Nobody configured this:

```
┌────────────────────────────────────────────────────────────────┐
│ Health 78%  ▼4   Undetermined: 2 of 6 components               │
├──────────────┬──────────────┬──────────────┬──────────────────┤
│ People 1,247 │ Vehicles 412 │ Depts 34     │ Contracts 89     │
│ ▲18 (30d)    │ ▲6           │ ─            │ ▼3               │
├──────────────┴──────────────┴──────────────┴──────────────────┤
│ NEEDS ATTENTION                                                │
│ ⚠ 28 vehicles: insurance expires within 30 days   → review     │
│ ⚠ 4 departments have no head                      → assign     │
│ ⚠ 7 duplicate phone numbers across 14 people      → merge      │
│ ⚠ 3 vehicles assigned to people who no longer exist → reassign │
├───────────────────────────────┬────────────────────────────────┤
│ Headcount · 12 months         │ Fleet by depot                 │
│ (TemporalTrend)               │ (Distribution — 61% in Zone-A) │
└───────────────────────────────┴────────────────────────────────┘
```

The insurance tile exists because `INTERVAL_END` was inferred from a date column that is always greater than another date column. No one wrote a vehicle module.

---

## 10. Natural Language Query Engine

### 10.1 The pipeline

```
"which departments have people with AI skills but no manager?"
         │
    ┌────▼─────────────────────────────────────────────────┐
    │ 1. GROUND   Build prompt from the M-Graph: entity     │
    │    types, attributes, roles, edges, sample enum       │
    │    values. ~3k tokens. Cacheable, so it is nearly     │
    │    free after the first call in a session.            │
    ├──────────────────────────────────────────────────────┤
    │ 2. TRANSLATE  Constrained decoding → QueryAST JSON.   │
    │    The model NEVER emits SQL.                         │
    ├──────────────────────────────────────────────────────┤
    │ 3. VALIDATE   Every entity/attribute/relation in the  │
    │    AST must exist in the M-Graph. Unknown field →     │
    │    "no such field, did you mean…", never empty result.│
    ├──────────────────────────────────────────────────────┤
    │ 4. PATH-FIND  Shortest M-Graph path between named     │
    │    entities → the joins. THIS is why NL generalises   │
    │    to Vehicles the day they arrive.                   │
    ├──────────────────────────────────────────────────────┤
    │ 5. COMPILE    AST → SQL. tenant_id injected by the    │
    │    compiler from request context. Never from the model.│
    ├──────────────────────────────────────────────────────┤
    │ 6. EXECUTE + EXPLAIN  Results, plus "I read this as…" │
    └──────────────────────────────────────────────────────┘
```

### 10.2 The AST

```json
{
  "select": {"entity": "OrganizationUnit"},
  "where": {
    "and": [
      {"exists": {
         "path": ["OrganizationUnit","<-belongs_to-","Person","-has_skill->","Skill"],
         "where": {"attribute":"Skill.name","op":"in","value":["ai","machine learning"]}}},
      {"null": {"attribute": "OrganizationUnit.head_id"}}
    ]
  },
  "limit": 50
}
```

### 10.3 Why an AST rather than generated SQL — three reasons, in order

1. **Tenant isolation.** An LLM emitting SQL against a database shared with the institute ERP is a cross-tenant leak waiting to happen. The compiler injects `tenant_id`; the model has no way to omit it. This is the same fail-closed reasoning that produced `EntityResolver`.
2. **Interpretation is visible.** The AST renders back as *"Departments where at least one person has an AI-related skill, and the department has no head."* A misreading is caught by the user in one second instead of becoming a wrong decision.
3. **It can say it doesn't know.** Validation against the M-Graph turns *"show me everyone with a blood_type"* into *"no such field — did you mean `blood_group`?"*. Generated SQL returns an empty set, which looks exactly like a real answer of "nobody".

### 10.4 Three tiers of query, and most cost nothing

| Tier | Path | Cost | Coverage |
|---|---|---|---|
| Pattern | regex/keyword → AST directly | 0 | ~40% of real queries |
| Grammar | `skill:python unit:finance manager:null` | 0 | power users |
| LLM | translate → AST | 1 small call | the long tail |

Translations are cached by normalised question + M-Graph hash. The same question asked twice costs nothing the second time, and the M-Graph hash means the cache invalidates correctly when the schema evolves.

---

## 11. Learning Layer

Five distinct things learn, on different timescales. Each is small; together they are what stops the system being static.

**1. Insight trust** — per `(tenant, analyzer, insight_type)`, a Beta posterior over acted/dismissed, not a raw counter. Beta handles low counts honestly: 2 dismissals out of 2 is suggestive, not proof, and a naive ratio would suppress the analyzer outright. Feeds `trust` in the score.

**2. Threshold calibration** — an analyzer dismissed 90% of the time in one tenant has its materiality threshold raised *for that tenant*. Bounded, logged, and always reversible from the UI.

**3. Schema learning** — confirmed field mappings write aliases; confirmed relationships write edge patterns; corrected roles write role hints. Every human confirmation is a permanent reduction in future human work. This is the compounding loop.

**4. Template efficacy** — reuses the existing `hpbrain_learning_efficacy` and `LearningService`. Recommendations whose outcomes were successful gain priority; those repeatedly ignored lose it. AI recommendations that succeed twice get promoted to deterministic templates.

**5. Cross-tenant priors** — **statistics only, never values.** *"In telecom tenants, `zone` is a GEO attribute 94% of the time"* is a shipped prior. *"FiberValley's Zone-A has 61% of the fleet"* is never shared. The boundary is: aggregate distributions over metadata may cross tenants; no instance data ever does. This must be an explicit, auditable allow-list of prior types, defaulting to off, because it is the one place in this design where a mistake becomes a data-protection incident rather than a bug.

```
hpbrain_insight_feedback   (tenant, insight_id, user, action, reason, created)
hpbrain_analyzer_trust     (tenant, analyzer, insight_type, alpha, beta, threshold)
hpbrain_learned_patterns   (scope, pattern_type, pattern JSON, confidence, hits)
```

---

## 12. Where this fits the existing architecture

The best evidence that this design is right for *your* codebase rather than a generic one: it lands almost entirely on seams that already exist.

| New layer | Existing seam it uses | Change to existing code |
|---|---|---|
| M-Graph | `EntityResolver`, `hpbrain_entity_mappings` | none — read only |
| Roles | attribute catalog (companion doc) | none |
| Relationship discovery | `hpbrain_entity_mappings` | none |
| I-Graph | `GraphController` = `GraphQueryPort` (ADR-008) | extend `LABELS`, add traverse |
| Analyzers | new | none |
| Insights → Signals | `RuleEvaluator`, `hpbrain_signal_rules` | new **rows**, not code |
| Insight → loop | Signal→Evidence→Case→Recommendation | none — same loop |
| Sufficiency | `SufficiencyCheck`, `VerbResult::undetermined` | none |
| Narration / NL / AI recs | `VerbPipeline`, `AiGateway`, `PromptRegistry`, `GroundingService` | new registry rows |
| Metrics over time | `hpbrain_metric_snapshots`, `SnapshotWriter` | none |
| Dashboard | `hpbrain_dashboards/_widgets/_layouts`, `WidgetRegistry` | none — generator writes rows |
| Terminology | `IndustryPack`, `hpbrain_terminology` | extend packs with weights |
| Background work | `config/queue.php` | **`app/Jobs/` must be created** |

**ADR-004 already says the thing you are asking for**, and I want to quote it because it means this is not a new direction — it is the stated direction, finally reaching the data layer:

> *"Every product feature is, underneath, a composition of these seven [verbs] over the graph — which is what lets one engine serve every domain: only the nodes change, the verbs do not."*

The Intelligence Engine is how *"only the nodes change"* becomes literally true: the M-Graph is what makes new nodes appear without new code.

---

## 13. Honest limitations

Stated plainly, because a design that only lists its strengths is a sales document.

**1. Generic analysis finds generic insights.** *"4 departments have no head"* is genuine and useful. *"Our Q3 churn is driven by the pricing change"* requires domain knowledge this engine does not have. Expect strong coverage of structural, quality, distributional and trend intelligence; expect **nothing** on causal business intelligence. Causal claims will need domain rules — which the existing `hpbrain_signal_rules` table already accommodates as data.

**2. Cold start is real.** Trend, anomaly and change analyzers need history. A new tenant on day one gets structure, completeness, duplicates and distributions — roughly 60% of the catalog. Trends need ~14 daily snapshots; seasonality needs ~90. The correct behaviour in the meantime is `UNDETERMINED` with a named gap (*"needs 11 more days of history"*), which the existing `VerbResult` type already expresses. **Do not fill the gap with a plausible-looking flat line.**

**3. Role misclassification propagates.** A `QUANTITY` misread as `IDENTIFIER` silently removes an entire branch of analysis. Mitigations: confidence margins, human review below threshold, and a periodic re-profiling job that revises roles as data grows — a column looks unique at 50 rows and stops looking unique at 50,000.

**4. Relationship discovery produces false edges.** Inclusion dependency on small integer ranges is the classic trap (§4.1). The guards help; the confirmation queue is the real answer. Expect meaningful human review effort on the first import of a genuinely new schema, decreasing sharply afterwards as aliases and patterns accumulate.

**5. Cost is not zero.** Full profiling of 40 entity types with 65k+ rows each is minutes of database work, not seconds. Mitigations: sampling for profiling, incremental recomputation keyed on `row_hash`, analyzer cost classes with a scheduling budget, and nightly rather than on-demand execution. Dashboards read snapshots; they never trigger analysis.

**6. The LLM is a genuine dependency for the long tail of NL queries.** Pattern and grammar tiers cover the common cases at zero cost, but the tail needs a model call. Existing quota and cost-accounting machinery applies. Provider outage degrades NL search to the grammar tier — it must not degrade anything else, and that isolation should be tested.

**7. Multiple comparisons will bite if §7.3 is skipped.** I want to be blunt about this because it is the difference between a system people trust and one they mute in week two. If only one thing from this document survives review, make it the FDR gate.

---

## 14. Build order

Each phase is independently valuable and independently revertable. Nothing here modifies existing behaviour; every phase adds.

| Phase | Delivers | Depends on | Proves |
|---|---|---|---|
| **A** | **M-Graph + role classification.** Profile every entity, classify roles, render a Schema Map screen. Read-only. | attribute catalog | the system can *see* itself |
| **B** | **Relationship discovery + review queue.** Edges proposed with confidence; humans confirm. | A | the graph builds itself |
| **C** | **Analyzers 1,2,3,8,9 + insight funnel + FDR gate.** The five highest-value analyzers end to end, with suppression working. | A, B | the funnel works before scaling it |
| **D** | **Insight feed + narration + health score.** First user-visible intelligence. | C | it is readable and trusted |
| **E** | **Analyzers 4–7, 10–16.** The rest of the catalog. Mechanical once C exists. | C | breadth |
| **F** | **I-Graph + traversal + graph explorer.** | B | connectedness |
| **G** | **Recommendation bridge into the existing loop.** | D | insight → action → outcome |
| **H** | **NL query: pattern → grammar → LLM.** In that order. | A, F | questions in plain English |
| **I** | **Dashboard generator.** | D, E | composition without configuration |
| **J** | **Learning layer.** | D, G | it improves without being edited |

**Phase C is the one that decides whether this works.** Building all 16 analyzers before the insight funnel exists would produce 40,000 findings and no way to face them. Five analyzers plus a working funnel is the honest proof; the other eleven are then straightforward.

---

## 15. Open decisions

Carried forward from the companion document, plus what this design adds. The first four block Phase A.

1. **MySQL 8 or MariaDB?** Recursive CTEs, generated columns and JSON functions differ. *(carried forward — still unanswered)*
2. **Auto-confirm thresholds** for roles (default 0.90) and edges (default 0.95 + coverage 0.90). Recommend starting strict; loosening is easy, unwinding a bad auto-confirmed edge is not.
3. **Where does non-ERP Person data live?** `hpbrain_people` is dead but present. *(carried forward)*
4. **Cross-tenant priors: on or off by default?** Recommend **off**, with an explicit per-tenant opt-in and an auditable allow-list of prior types.
5. **Insight volume target.** How many should the feed show daily? Recommend 5–10, everything else browsable. This number calibrates the whole funnel.
6. **Projects, assets, documents.** Named in your list, absent from the schema. They need a data source before allocation and utilisation are more than placeholders — though note that once the source exists, *no analytics code is required*: the analyzers already cover them.
7. **Health score weights.** Per industry, per tenant, or global default? Recommend industry defaults via `IndustryPack`, tenant-overridable, since `IndustryPack` already establishes exactly this pattern.

---

## 16. What I am not proposing

- **No Neo4j.** ADR-008 stands. `EdgeProvider` is the port; revisit only on a measurement.
- **No new AI framework.** `AiGateway`, `PromptRegistry`, `GroundingService`, `VerbPipeline` are used as they are; new capabilities are new registry rows.
- **No replacement of `RuleEvaluator`.** Analyzers *emit into* the existing rule/signal machinery. Domain-specific rules stay as rows — that path is correct and should remain.
- **No change to the existing loop.** Signal → Evidence → Case → Recommendation → Decision → Outcome → Learning is untouched. Insights enter at Signal.
- **No LLM in the counting path.** Ever. Narration and translation only, with numeric verification.
- **No change to login → Command Center → Organization → Department → People → Person.** Every screen described here is new or additive.

---

## Recommendation

Start with **Phase A**. It is read-only, cannot break anything, and its deliverable — a screen showing every entity the system has found, every attribute, its inferred role, its confidence, and its statistics — is the moment it becomes obvious whether the rest of this will work. If role classification is accurate on your real data, everything above it follows. If it is not, we find out in phase one, having changed nothing.
