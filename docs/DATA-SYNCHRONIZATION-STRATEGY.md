# Data Synchronization Strategy

## Source of Truth

The institute ERP is the **source of truth** for organizational master data. The Enterprise Brain is an intelligence layer that reads from the ERP; it does not own or modify ERP master data.

**ERP tables (read-mostly):**
- `institute_detail` — Organization master
- `org_details` — Organization extended details
- `hrms_departments` — Department hierarchy
- `tbluserprofilemaster` — Role profiles
- `tbluser` — People master

**Brain tables (read-write):**
- `hpbrain_signals` — Intelligence signals
- `hpbrain_evidence` — Evidence records
- `hpbrain_cases` — Case files
- `hpbrain_recommendations` — Recommendations
- `hpbrain_decisions` — Decisions
- `hpbrain_eso_executions` — Executions
- `hpbrain_outcomes` — Outcomes
- `hpbrain_learnings` — Learnings
- `hpbrain_capabilities` — Capability definitions
- `hpbrain_capability_proficiency` — Assessment records
- `hpbrain_event_store` — Event log
- `hpbrain_notifications` — Notifications

---

## Sync Direction

| Data Type | Direction | Method |
|---|---|---|
| Organization master | ERP → Brain (read) | Direct query at runtime |
| Department master | ERP → Brain (read) | Direct query at runtime |
| People master | ERP → Brain (read) | Direct query at runtime |
| Role profiles | ERP → Brain (read) | Direct query at login |
| Signals | Brain → Brain | Event-driven |
| Evidence | Brain → Brain | Event-driven |
| Cases | Brain → Brain | Event-driven |
| Recommendations | Brain → Brain | Event-driven |
| Decisions | Brain → Brain | Event-driven |
| Executions | Brain → Brain | Event-driven |
| Outcomes | Brain → Brain | Event-driven |
| Learnings | Brain → Brain | Event-driven |

---

## Frequency

| Data Type | Frequency | Rationale |
|---|---|---|
| Organization/Department/People | Real-time | Direct SQL queries; no caching for master data |
| Brain intelligence entities | Event-driven | Published via `EventPublisher` on every write |
| Metrics/KPIs | On-demand | Computed at request time from live data |
| Reports | On-demand | Computed at request time |

---

## Incremental Key

ERP tables do not have a built-in change-data-capture mechanism. The Brain uses:

| Table | Incremental Key | Notes |
|---|---|---|
| `tbluser` | `updated_at` | Timestamp-based; last-read stored in Brain cache if needed |
| `hrms_departments` | `updated_at` | Timestamp-based |
| `institute_detail` | `updated_at` | Timestamp-based |
| `org_details` | `updated_at` | Timestamp-based |

Future: If the ERP implements CDC (e.g., MySQL binlog, triggers), the Brain can subscribe to change events instead of polling.

---

## Conflict Handling

| Scenario | Resolution |
|---|---|
| ERP data updated while Brain caches it | Invalidate cache; re-read on next request |
| ERP record deleted while Brain references it | Brain treats as soft-deleted; shows `deleted_at` |
| ERP record modified while Brain has active case | Case preserves evidence timestamp; new evidence can be added |
| Brain writes to ERP (not allowed) | NOT PERMITTED. Brain never writes to ERP tables. |

---

## Deleted Records

| ERP Table | Deletion Handling |
|---|---|
| `tbluser` | `deleted_at` timestamp checked; inactive users excluded from active counts |
| `hrms_departments` | `deleted_at` timestamp checked; inactive departments excluded |
| `institute_detail` | `deleted_at` timestamp checked; archived organizations hidden |

The Brain never hard-deletes ERP data. Soft deletes are respected.

---

## Error Handling

| Error | Handling |
|---|---|
| ERP table missing | `loadOrganization()` catches `QueryException` and returns 503 |
| ERP connection lost | HTTP 503 with `erp_unavailable` error |
| ERP query timeout | HTTP 504 with `erp_timeout` error |
| ERP returns null for required field | Frontend shows honest insufficient-data state |
| ERP schema change | Migration required; Brain tables are versioned |

---

## Retry

| Component | Retry Policy |
|---|---|
| ERP reads | No retry — fail fast; frontend shows error state |
| Event publishing | Idempotent; retries safe via `idempotency_key` |
| Event consumption | 3 retries with exponential backoff, then dead-letter |
| External AI provider | 1 retry with fallback to `UNDETERMINED` |

---

## Audit

| Event | Audit Record |
|---|---|
| ERP data read | Not audited (read-only, high volume) |
| Brain entity created | `hpbrain_audit_logs` entry with actor, action, changes |
| Brain entity updated | `hpbrain_audit_logs` entry with actor, action, changes |
| Brain entity deleted | `hpbrain_audit_logs` entry with actor, action, changes |
| Signal generated | Event in `hpbrain_event_store` |
| Recommendation approved | Event in `hpbrain_event_store` |
| Decision made | Event in `hpbrain_event_store` |

---

## Freshness

| Data Type | Freshness | Display |
|---|---|---|
| ERP master data | Live (query-time) | "ERP data: live" |
| Brain signals | Real-time | Timestamp in signal record |
| Brain metrics | On-demand | "Brain data: {timestamp}" |
| Reports | On-demand | "Generated: {timestamp}" |

**Stale data indicators:**
- ERP connection failure → "ERP data unavailable"
- ERP query timeout → "ERP data may be stale"
- No ERP data for tenant → "No ERP data found for this organization"

---

## Monitoring

| Metric | Source | Alert Threshold |
|---|---|---|
| ERP query latency | Application logs | > 2 seconds |
| ERP connection failures | Application logs | Any failure |
| Event store backlog | `hpbrain_event_store` | > 1000 pending events |
| Dead-letter queue size | `hpbrain_dead_letter_queue` | > 10 events |
| Signal generation failures | Application logs | Any failure |
| Tenant data completeness | `homeMetrics()` | Score < 70% |

---

## Future Improvements

| Improvement | Priority | Notes |
|---|---|---|
| ERP CDC (binlog/triggers) | High | Requires ERP team coordination |
| ERP webhook notifications | Medium | Push-based updates instead of polling |
| Brain-side materialized views | Medium | For expensive aggregations |
| ERP schema versioning | Low | Detect schema changes automatically |
| Cross-tenant data freshness SLA | Low | Platform-level monitoring |
