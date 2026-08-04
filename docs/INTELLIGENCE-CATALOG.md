# Intelligence Catalog

## Purpose

This catalog documents every intelligence rule in the HP Enterprise Brain. Each rule defines a verified data condition that produces a signal, the evidence required, the confidence calculation, and the recommended action.

Rules are evaluated against real ERP and Brain data. No rule produces a signal without traceable evidence. Missing data produces `UNKNOWN` or no signal, never a negative performance label.

---

## Rules are rows, not code

Since Phase 3 a rule is a row in `hpbrain_signal_rules`. `RuleEvaluator` loads the
active rules for a tenant, resolves the entities they name through `EntityResolver`,
builds the query from the predicate, and writes evidence from `evidence_fields`.
**Adding a rule is an INSERT.** No class, no deploy.

### Which rules a tenant gets

| Column | Meaning |
|---|---|
| `tenant_id` | `platform` for shipped rules; a tenant id for one that tenant added |
| `industry_code` | `*` for every industry, or an industry code from `hpbrain_industries` |

A tenant runs every active rule whose `industry_code` is `*` or matches its own
industry, from `platform` or from itself. **A `rule_key` defined by both resolves to
the tenant's own** — that is how an installation overrides a shipped rule without
editing it. Evaluation order is `rule_key` order, so it does not depend on insertion
order.

A tenant whose organization row has no industry gets shared rules only. A missing
industry is a configuration gap, and the safe reading of a gap is the smaller rule
set, never a guessed industry.

### The predicate grammar

Predicates are JSON, never SQL. A rule row is data an administrator writes through
the API; if any part of it reached the database as text, that administrator would own
the database and every tenant in it. So the operator set is **closed**, fields name
*universal* fields that `EntityResolver` maps to columns, and every value is bound.

**There is no `raw` operator and no escape hatch.** `PredicateTest` asserts their
absence, so adding one means deleting a test that says why it must not exist.

| Operator | Value | Notes |
|---|---|---|
| `is_null`, `is_not_null` | — | |
| `eq`, `neq` | scalar | |
| `in`, `not_in` | non-empty list | |
| `lt`, `lte`, `gt`, `gte` | scalar | |
| `before_days`, `after_days` | non-negative number | Both measure N days **back** from now and differ only in which side they take: `before_days 90` is "more than 90 days ago", `after_days 90` is "within the last 90 days". A negative count is refused — the direction belongs to the operator, and a negative would make a rule do the opposite of what it reads as. |

Compose with `all` and `any`, which nest:

```json
{"all": [
  {"field": "deletedAt", "op": "is_null"},
  {"field": "status", "op": "eq", "value": 1},
  {"any": [
    {"field": "unit", "op": "is_null"},
    {"field": "unit", "op": "eq", "value": 0}
  ]}
]}
```

An empty `any` is refused rather than silently never firing.

**Soft-delete is not assumed.** `deletedAt` is a mapped universal field and every rule
states its own position on deleted rows — four of the five shipped rules exclude them
and the fifth requires them. Hardcoding the exclusion would have made that fifth rule
inexpressible.

### evidence_fields

An object mapping **output key → source**, so a rule controls both what appears in the
evidence and what it is called:

```json
{
  "employeeNo": "externalRef",
  "name":       {"concat": ["firstName", "lastName"], "separator": " "},
  "department": {"join": "name"}
}
```

An output key whose source the tenant has not mapped is **omitted**, not emitted as
null: the source has no such column, and a key that is always null is noise in every
downstream reader.

### Thresholds

`threshold_op` + `threshold_value` suppress a rule below a count. All five shipped
rules leave both null, meaning any match fires.

### Known discrepancy, carried deliberately

`departments_without_manager` is named for a condition its predicate does not test.
The source unit table has **no manager column**, so `parent IS NULL OR parent = 0`
finds *root* units. Phase 3's gate was byte-identical signals, so the rule was
transcribed verbatim. Now that it is a row, correcting it is an `UPDATE` with its own
gate rather than a deploy — which is the point of this phase.

---

## Rule: People Without Department

| Field | Value |
|---|---|
| **Name** | People Without Department |
| **Purpose** | Detect employees who have no department assignment, indicating incomplete onboarding or reorganization gaps. |
| **Source tables** | `tbluser` |
| **Organization filter** | `tbluser.sub_institute_id = :tenantId` |
| **Calculation** | Count active users where `department_id IS NULL OR department_id = 0` |
| **Threshold** | Any count > 0 |
| **Severity** | Medium |
| **Priority** | Medium |
| **Evidence** | Sample of up to 5 employee records showing null/zero `department_id` |
| **Confidence** | 1.0 (direct ERP field check) |
| **Freshness** | Live — evaluated at query time |
| **Recommended action** | Assign department to affected employees |
| **Owner** | HR / Department head |
| **Screen** | Organization Intelligence Home, People module |
| **API** | `GET /api/v1/workspace/{tenantId}/home-metrics` |
| **Test** | `HomeMetricsTest::test_home_metrics_detects_people_without_department` |
| **Signal source** | `erp.data_quality` |
| **Signal classification** | `workforce` |

---

## Rule: Departments Without Manager

| Field | Value |
|---|---|
| **Name** | Departments Without Manager |
| **Purpose** | Detect departments with no assigned manager (parent_id is null or 0), indicating leadership gaps. |
| **Source tables** | `hrms_departments` |
| **Organization filter** | `hrms_departments.sub_institute_id = :tenantId` |
| **Calculation** | Count active departments where `parent_id IS NULL OR parent_id = 0` |
| **Threshold** | Any count > 0 |
| **Severity** | Medium |
| **Priority** | Medium |
| **Evidence** | Sample of up to 5 department records showing null/zero `parent_id` |
| **Confidence** | 1.0 (direct ERP field check) |
| **Freshness** | Live — evaluated at query time |
| **Recommended action** | Assign a manager to each affected department |
| **Owner** | HR / Organization admin |
| **Screen** | Organization Intelligence Home, Organization structure |
| **API** | `GET /api/v1/workspace/{tenantId}/home-metrics` |
| **Test** | `HomeMetricsTest::test_home_metrics_detects_departments_without_manager` |
| **Signal source** | `erp.data_quality` |
| **Signal classification** | `leadership` |

---

## Rule: People Without Profile

| Field | Value |
|---|---|
| **Name** | People Without Profile |
| **Purpose** | Detect employees without a role profile, meaning they cannot be assigned correct permissions or roles. |
| **Source tables** | `tbluser` |
| **Organization filter** | `tbluser.sub_institute_id = :tenantId` |
| **Calculation** | Count active users where `user_profile_id IS NULL OR user_profile_id = 0` |
| **Threshold** | Any count > 0 |
| **Severity** | Low |
| **Priority** | Low |
| **Evidence** | Sample of up to 5 employee records showing null/zero `user_profile_id` |
| **Confidence** | 1.0 (direct ERP field check) |
| **Freshness** | Live — evaluated at query time |
| **Recommended action** | Assign appropriate role profile to affected employees |
| **Owner** | HR / Admin |
| **Screen** | Organization Intelligence Home, People module |
| **API** | `GET /api/v1/workspace/{tenantId}/home-metrics` |
| **Test** | Implicit in `HomeMetricsTest` |
| **Signal source** | `erp.data_quality` |
| **Signal classification** | `access_control` |

---

## Rule: People Without Email

| Field | Value |
|---|---|
| **Name** | People Without Email |
| **Purpose** | Detect employees without an email address, which blocks login and communication. |
| **Source tables** | `tbluser` |
| **Organization filter** | `tbluser.sub_institute_id = :tenantId` |
| **Calculation** | Count active users where `email IS NULL OR email = ''` |
| **Threshold** | Any count > 0 |
| **Severity** | High |
| **Priority** | High |
| **Evidence** | Sample of up to 5 employee records showing null/empty `email` |
| **Confidence** | 1.0 (direct ERP field check) |
| **Freshness** | Live — evaluated at query time |
| **Recommended action** | Collect email addresses for affected employees |
| **Owner** | HR / IT |
| **Screen** | Organization Intelligence Home, People module |
| **API** | `GET /api/v1/workspace/{tenantId}/home-metrics` |
| **Test** | Implicit in `HomeMetricsTest` |
| **Signal source** | `erp.data_quality` |
| **Signal classification** | `data_quality` |

---

## Rule: Inactive Users in Active Departments

| Field | Value |
|---|---|
| **Name** | Inactive Users in Active Departments |
| **Purpose** | Detect users marked inactive/deleted who are still assigned to active departments. |
| **Source tables** | `tbluser`, `hrms_departments` |
| **Organization filter** | `tbluser.sub_institute_id = :tenantId AND hrms_departments.sub_institute_id = :tenantId` |
| **Calculation** | Join `tbluser` with `hrms_departments` on `department_id = hrms_departments.id`. Find users where `tbluser.status != 1 OR deleted_at IS NOT NULL` AND department is active. |
| **Threshold** | Any count > 0 |
| **Severity** | Low |
| **Priority** | Low |
| **Evidence** | Sample of up to 5 records showing inactive user + active department |
| **Confidence** | 0.8 (join-based inference) |
| **Freshness** | Live — evaluated at query time |
| **Recommended action** | Review and correct user status or department assignment |
| **Owner** | HR |
| **Screen** | Organization Intelligence Home |
| **API** | `GET /api/v1/workspace/{tenantId}/home-metrics` |
| **Test** | Implicit in `HomeMetricsTest` |
| **Signal source** | `erp.data_quality` |
| **Signal classification** | `data_quality` |

---

## Rule: High-Severity Unresolved Signal

| Field | Value |
|---|---|
| **Name** | High-Severity Unresolved Signal |
| **Purpose** | Detect high or critical severity signals that are still in `new` status, requiring immediate attention. |
| **Source tables** | `hpbrain_signals` |
| **Organization filter** | `hpbrain_signals.tenant_id = :tenantId` |
| **Calculation** | Count signals where `severity IN ('high', 'critical') AND status NOT IN ('resolved', 'closed', 'dismissed')` |
| **Threshold** | Any count > 0 |
| **Severity** | High |
| **Priority** | High |
| **Evidence** | Signal records with severity and status |
| **Confidence** | 0.9 (Brain-generated signal) |
| **Freshness** | Real-time — based on current signal state |
| **Recommended action** | Review and triage high-severity signals |
| **Owner** | Organization admin / Department head |
| **Screen** | Organization Intelligence Home, Signal Dashboard |
| **API** | `GET /api/v1/workspace/{tenantId}/home-metrics` |
| **Test** | `HomeMetricsTest::test_home_metrics_detects_high_signals` |
| **Signal source** | Brain-generated |
| **Signal classification** | Varies |

---

## Rule: Pending Recommendation

| Field | Value |
|---|---|
| **Name** | Pending Recommendation |
| **Purpose** | Detect recommendations awaiting a decision, indicating blocked action. |
| **Source tables** | `hpbrain_recommendations` |
| **Organization filter** | `hpbrain_recommendations.tenant_id = :tenantId` |
| **Calculation** | Count recommendations where `status IN ('pending', 'proposed')` |
| **Threshold** | Any count > 0 |
| **Severity** | Medium |
| **Priority** | Medium |
| **Evidence** | Recommendation records with status |
| **Confidence** | 0.95 (Brain-generated recommendation) |
| **Freshness** | Real-time |
| **Recommended action** | Review and approve or reject pending recommendations |
| **Owner** | Decision maker / Admin |
| **Screen** | Organization Intelligence Home, Intelligence Workspace |
| **API** | `GET /api/v1/workspace/{tenantId}/home-metrics` |
| **Test** | Implicit in `HomeMetricsTest` |
| **Signal source** | Brain-generated |
| **Signal classification** | `governance` |

---

## Rule: Open Decision

| Field | Value |
|---|---|
| **Name** | Open Decision |
| **Purpose** | Detect decisions that have been proposed but not yet approved or rejected. |
| **Source tables** | `hpbrain_decisions` |
| **Organization filter** | `hpbrain_decisions.tenant_id = :tenantId` |
| **Calculation** | Count decisions where `status IN ('pending', 'proposed')` |
| **Threshold** | Any count > 0 |
| **Severity** | Low |
| **Priority** | Low |
| **Evidence** | Decision records with status |
| **Confidence** | 0.9 (Brain-generated decision) |
| **Freshness** | Real-time |
| **Recommended action** | Complete pending decisions |
| **Owner** | Decision maker / Admin |
| **Screen** | Organization Intelligence Home, Decision Intelligence |
| **API** | `GET /api/v1/workspace/{tenantId}/home-metrics` |
| **Test** | Implicit in `HomeMetricsTest` |
| **Signal source** | Brain-generated |
| **Signal classification** | `governance` |

---

## Future Rules (Not Yet Implemented)

| Rule Name | Purpose | Source Tables | Status |
|---|---|---|---|
| Critical Skill Gap | Detect roles where required skills exceed verified supply | `hpbrain_capabilities`, `hpbrain_capability_proficiency` | BLOCKED — no ERP skill tables |
| Stale Assessment | Detect capability assessments older than threshold | `hpbrain_capability_proficiency` | BLOCKED — no assessment date tracking |
| Role Without Backup | Detect critical roles with no identified backup person | `tbluser`, `hpbrain_capabilities` | BLOCKED — no succession data |
| Overdue Execution | Detect executions past due date | `hpbrain_eso_executions` | PARTIAL — needs due date field |
| Outcome Not Measured | Detect completed executions without outcome measurement | `hpbrain_eso_executions`, `hpbrain_outcomes` | PARTIAL — needs outcome tracking |
| Capability Supply Below Demand | Detect capabilities where supply < demand | `hpbrain_capabilities` | PARTIAL — needs demand calculation |
| Low Evidence Confidence | Detect evidence with confidence below threshold | `hpbrain_evidence` | COMPLETE — rule exists, needs signal generation |
| Stale ERP Sync | Detect ERP data older than freshness threshold | ERP tables | MISSING — no sync metadata |

---

## Rule Evaluation Schedule

| Frequency | Rules |
|---|---|
| Real-time (on login) | Home metrics via `homeMetrics()` endpoint |
| On-demand | `POST /api/v1/signals/generate` |
| Scheduled (recommended) | All ERP-derived rules via cron |
| Event-driven (future) | PersonCreated, DepartmentCreated, AssessmentCompleted |

---

## Confidence Scale

| Value | Meaning |
|---|---|
| 1.0 | Direct field check from authoritative source |
| 0.9 | Brain-generated signal with clear evidence |
| 0.8 | Join-based inference with reasonable assumptions |
| 0.5 | Inferred from indirect indicators |
| < 0.5 | Speculative — should not generate signal without human review |

---

## Severity Scale

| Severity | Meaning | Response Time |
|---|---|---|
| Critical | Organization-blocking issue | Immediate |
| High | Significant impact, requires attention within 24h | 24 hours |
| Medium | Moderate impact, requires attention within 1 week | 1 week |
| Low | Minor issue, requires attention within 1 month | 1 month |

---

## Status Lifecycle

```
NEW → TRIAGED → INVESTIGATING → RESOLVED
                ↓
            DISMISSED
```

| Status | Meaning |
|---|---|
| `new` | Signal just created, not yet reviewed |
| `triaged` | Signal reviewed, evidence gathered |
| `investigating` | Active investigation in progress |
| `resolved` | Issue resolved, signal closed |
| `dismissed` | Signal deemed invalid or not actionable |
