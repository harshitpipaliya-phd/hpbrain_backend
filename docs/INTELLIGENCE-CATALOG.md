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
---

# Rules over imported operational data

The rules below read `hpbrain_operational_records` rather than the ERP tables.
They are attached to a tenant by **which datasets that tenant holds**, never by
organization name (`App\Domain\Signals\SignalRuleRegistry`), so any organization
importing a `complaint` dataset inherits the complaint rules with no code
change. A tenant with no imported data evaluates exactly the five ERP rules
above and nothing else.

Thresholds live in `config('brain.operational_signals')`. Every default was set
against the FiberValley FY2025-26 distribution so that each rule fires on a
genuine outlier rather than on ordinary operation.

---

## Rule: Complaint SLA Breach

| Field | Value |
|---|---|
| **Name** | Complaint SLA Breach |
| **Purpose** | Detect complaints resolved outside the service target, indicating field capacity or escalation failure. |
| **Source** | `hpbrain_operational_records` where `dataset = 'complaint'` |
| **Organization filter** | `tenant_id = :tenantId` |
| **Calculation** | Count records in the latest month where `metric_value > complaint_sla_hours`. NULL durations are excluded, never counted as zero. |
| **Threshold** | `complaint_sla_hours` (24), minimum `complaint_sla_minimum` (25) breaches |
| **Severity** | High at ≥15% of the month's tickets, medium at ≥8%, else low — share, not raw count, so a larger operation is not penalised for its size |
| **Evidence** | Up to 5 tickets with ticket number, zone, engineer, TL and actual hours |
| **Confidence** | 1.0 (direct field comparison) |
| **Observed** | Mar-2026: 1,266 of 4,479 tickets breached (28.3%) |
| **Recommended action** | Review field capacity and escalation path in the affected zones |
| **Signal source** | `import.complaint` |
| **Signal classification** | `service_quality` |
| **Test** | `FiberValleyImportTest::test_imported_data_produces_signals_with_linked_evidence` |

---

## Rule: Complaint Root Cause Unrecorded

| Field | Value |
|---|---|
| **Name** | Complaint Root Cause Unrecorded |
| **Purpose** | Detect closed complaints with no root cause, which makes failure-mode analysis impossible and hides recurring physical faults. |
| **Source** | `dataset = 'complaint'`, `status = 'closed'`, `sub_category IS NULL` |
| **Calculation** | Share of closed tickets in the latest month with no Final Solution recorded |
| **Threshold** | `root_cause_blank_share` (0.25) |
| **Severity** | High at ≥50%, else medium |
| **Evidence** | Up to 5 closed tickets showing the missing field |
| **Confidence** | 1.0 (direct field check) |
| **Observed** | FY2025-26: 44,500 of 65,268 tickets (68%) have no Final Solution |
| **Recommended action** | Make root cause mandatory at ticket closure |
| **Signal source** | `import.complaint` |
| **Signal classification** | `data_quality` |

---

## Rule: Repeat Complaint Subscribers

| Field | Value |
|---|---|
| **Name** | Repeat Complaint Subscribers |
| **Purpose** | Distinguish "tickets closed quickly" from "problems actually fixed". A subscriber calling repeatedly in one month is one unresolved fault, not several incidents — and the two are indistinguishable in an SLA report. |
| **Source** | `dataset = 'complaint'` grouped by `subject_ref` |
| **Calculation** | Count of subscribers with ≥ `repeat_complaint_threshold` tickets in the latest month. Derived by counting rows, not by reading the workbook's own frozen "Complains more then 1 time" formula. |
| **Threshold** | 4 complaints per subscriber; at least 5 such subscribers |
| **Severity** | High when the worst case is ≥8, else medium |
| **Evidence** | Up to 5 subscribers with their ticket counts and zone |
| **Confidence** | 0.9 |
| **Observed** | Mar-2026: 25 subscribers at ≥4 complaints; worst case 11 |
| **Recommended action** | Physical inspection at the affected premises rather than another remote reset |
| **Signal source** | `import.complaint` |
| **Signal classification** | `recurring_fault` |

---

## Rule: Complaint Zone Concentration

| Field | Value |
|---|---|
| **Name** | Complaint Zone Concentration |
| **Purpose** | Surface a network hotspot: one zone carrying a disproportionate share of complaints. |
| **Source** | `dataset = 'complaint'` grouped by `zone` |
| **Calculation** | Top zone's share of the latest month against an even split across observed zones |
| **Threshold** | `zone_concentration_multiple` (4×) the even share |
| **Severity** | High at ≥8× expected, else medium |
| **Evidence** | Up to 5 tickets from the over-represented zone |
| **Confidence** | 0.75 — concentration is consistent with a network fault, but also with the zone simply having more subscribers. The Brain does not hold subscriber counts per zone, so it must not claim certainty. |
| **Observed** | Mar-2026: Katargam 676 of 4,425 (15.3%) against a 2.0% even share |
| **Recommended action** | Inspect fibre plant and OLT capacity in the named zone |
| **Signal source** | `import.complaint` |
| **Signal classification** | `network_hotspot` |

---

## Rule: Work Order Stalled

| Field | Value |
|---|---|
| **Name** | Work Order Stalled |
| **Purpose** | Detect provisioning job orders pending well past target — each one a customer who has signed and is not yet connected. |
| **Source** | `dataset = 'work_order'`, `quantity` (pending days) |
| **Threshold** | `work_order_pending_days` (15), minimum `work_order_minimum` (5) orders |
| **Severity** | High at ≥5% of all orders, else medium |
| **Evidence** | Up to 5 job orders with pending days, hold status, zone, technician and TL |
| **Confidence** | 1.0 |
| **Observed** | 64 orders pending beyond 15 days; 213 exceeded 15 days across the year |
| **Recommended action** | Clear the hold reason blocking each order |
| **Signal source** | `import.work_order` |
| **Signal classification** | `provisioning_delay` |

---

## Rule: Work Order Cancellation Rate

| Field | Value |
|---|---|
| **Name** | Work Order Cancellation Rate |
| **Purpose** | Quantify revenue lost between signature and connection. |
| **Source** | `dataset = 'work_order'`, `status = 'Cancel'` |
| **Threshold** | `cancellation_share` (0.04) |
| **Severity** | High at ≥8%, else medium |
| **Evidence** | Up to 5 cancelled orders with cancellation reason and feasibility |
| **Confidence** | 0.85 |
| **Observed** | 353 of 5,790 orders cancelled (6.1%); leading reasons cable laying (116) and permission (49) — both operational rather than customer choice |
| **Recommended action** | Address the dominant operational cancellation reason |
| **Signal source** | `import.work_order` |
| **Signal classification** | `revenue_leakage` |

---

## Rule: Help Desk Call Drop Rate

| Field | Value |
|---|---|
| **Name** | Help Desk Call Drop Rate |
| **Purpose** | Detect subscribers unable to reach support. This rule also explains why complaint volume understates the true fault load: a subscriber who cannot get through raises no ticket at all. |
| **Source** | `dataset = 'helpdesk_month'` — `metric_value` (offered) against `payload['Total Drop']` |
| **Threshold** | `call_drop_rate` (0.20) |
| **Severity** | Critical at ≥40%, high at ≥25%, else medium |
| **Evidence** | The month's offered, answered, dropped and agent-count figures |
| **Confidence** | 1.0 |
| **Observed** | Jun-2025: 30,538 of 50,359 offered calls dropped (61%). Mar-2026: 25%. |
| **Recommended action** | Review help-desk staffing against offered-call volume |
| **Signal source** | `import.helpdesk_month` |
| **Signal classification** | `service_accessibility` |
