# API Functional Audit

**Date:** 2026-08-06 · **Mode:** report only, nothing fixed
**Environment:** MariaDB 10.11.9-log · PHP 8.2.12 · tenant `7` (richest dataset: 114 entity mappings, 8 signals) · role `admin`
**Method:** in-process kernel dispatch (`$kernel->handle()`) with a real `Jwt::issueAccess` token — not curl, so exceptions are captured rather than swallowed into an error page. GET only; nothing was written.

---

## 1. Coverage

| | Count |
|---|---|
| Routes registered under `/v1` | 370 |
| GET routes probed | 179 |
| → `200` | 121 |
| → `404` | 55 (53 expected: no such row; **2 genuinely broken**) |
| → `422` | 1 (correct — missing required query param) |
| → **`500`** | **2** |
| Workspace screens analysed | 25 |
| Screen → API edges verified | 73 (65 automatic + 8 manual) |
| **Frontend calls with no matching route** | **3** |

---

## 2. Findings

### 🔴 F1 — `hpbrain_organization_units` has no `sort_order` column → every listing 500s

```
GET /api/v1/organization-units/{tenantId}  →  500
SQLSTATE[42S22]: Unknown column 'sort_order' in 'order clause'
```

[app/Repositories/OrganizationUnitRepository.php:35](app/Repositories/OrganizationUnitRepository.php#L35) orders by `sort_order`:

```php
return $q->orderBy('sort_order')->orderBy('name')->get()...
```

Actual columns (verified against the live schema):
`id, tenant_id, org_id, unit_type, name, description, code, parent_unit_id, head_id, location, cost_center, status, metadata, created_by, created_date, updated_date`

There is no `sort_order`, and no migration ever adds one. **This endpoint has never worked.** Every organization-unit list — the Foundation layer of the universal org model — returns 500.

---

### 🔴 F2 — `hpbrain_conversation_sessions` has no `user_id` column → AI Workspace 500s

```
GET /api/v1/ai/workspace/sessions  →  500
SQLSTATE[42S22]: Unknown column 'user_id' in 'where clause'
```

[app/Services/AiWorkspaceService.php:35](app/Services/AiWorkspaceService.php#L35) inserts `user_id`, and [:103](app/Services/AiWorkspaceService.php#L103) filters on it.

Actual columns: `id, tenant_id, org_id, title, context_type, context_entity_id, **created_by**, created_date, updated_date, pinned, deleted_date`

The column is `created_by`. Both the read and the write path are wrong, so AI Workspace sessions can neither be listed nor created.

Note the contrast: `ConversationController` uses the same table correctly — `/conversations/sessions/{tenantId}` returns 200. So two services address one table with two different column vocabularies.

---

### 🔴 F3 — Route shadowing: `organization-units` hierarchy and tree are unreachable dead code

[routes/api.php:514–518](routes/api.php#L514-L518):

```php
514  Route::get('organization-units/{tenantId}/{id}',        ...'show');
...
517  Route::get('organization-units/{tenantId}/hierarchy',   ...'hierarchy');   // never reached
518  Route::get('organization-units/{tenantId}/tree',        ...'tree');        // never reached
```

Laravel matches first-registered-wins, so `{id}` at line 514 swallows both literals. Confirmed by asking the router directly:

```
/api/v1/organization-units/7/hierarchy  ->  OrganizationUnitController@show
/api/v1/organization-units/7/tree       ->  OrganizationUnitController@show
```

Both requests return `404 organization_unit_not_found` — the `show()` handler failing to find a unit whose id is the literal string `"hierarchy"`. `hierarchy()` and `tree()` at [OrganizationUnitController.php:84-104](app/Http/Controllers/Api/OrganizationUnitController.php#L84-L104) have never executed.

This is the same class of defect `SearchController`'s docblock records ("a shadowed route serving a different shape"). A full scan of all 366 declarations found **no other shadowed routes**.

---

### 🔴 F4 — The entire Ingestion screen is wired to routes that do not exist

`IngestionWorkspace` is a live nav item (Foundation → Ingestion). It makes three API calls. **All three 404.**

| Call | Frontend URL | Reality |
|---|---|---|
| `ingestionApi.listSources` | `GET /ingestion/sources/{tenantId}` | **no route exists** |
| `ingestionApi.upload` | `POST /ingestion/upload` | route is at **`/ai/ingestion/upload`** |
| `ingestionApi.commit` | `POST /ingestion/{tenantId}/{jobId}/commit` | **no route exists** |

The upload route is declared at [routes/api.php:672](routes/api.php#L672) *inside* the `Route::prefix('ai')` group opened at line 614, so it resolves to `/api/v1/ai/ingestion/upload` — almost certainly not intended, since every sibling ingestion concept is un-prefixed.

`IngestionUploadController` has exactly **one** public method (`upload`). There is no `listSources` and no `commit` handler anywhere. `php artisan route:list --path=ingestion` returns a single route.

**F4a — the failure is masked.** [IngestionWorkspace.tsx:59](web/src/components/workspace/IngestionWorkspace.tsx#L59):

```js
ingestionApi.listSources(tenantId).then(setSources).catch(() => setSources([]));
```

The comment says *"A tenant with no configured sources is normal on a first run, so a failure here is not worth a toast."* That reasoning is sound for an empty result — but it also swallows a **404 from a route that does not exist**. The screen renders an empty dropdown and looks merely unconfigured rather than broken, which is why this has survived.

---

### 🟡 F5 — Performance outliers

| Endpoint | Time |
|---|---|
| `GET /ai/evaluations/{tenantId}` | **4,926 ms** |
| `GET /imports/{tenantId}` | **3,588 ms** |
| `GET /analytics/{tenantId}` | **1,919 ms** |
| `GET /reasoning-engine/{tenantId}/duplicate-signals` | 990 ms |
| `GET /reasoning-engine/{tenantId}/memory-stats` | 613 ms |

`/analytics/{tenantId}` is the in-memory aggregation flagged in the architecture review — it pulls every decision, recommendation, outcome and risk row into PHP and aggregates with collections. It is on the Command Center's critical path. The top two are worse and were not previously identified.

---

## 3. Screen → API → Route table

Verified against the live router. `n/a (write)` = route exists and matches; not exercised because this audit was read-only.

| Screen | API call | Method | URL | Route exists? | Responds 200? |
|---|---|---|---|---|---|
| **AIWorkspace** | `aiApi.executions` | GET | `/ai/executions/{t}` | yes | 200 |
| | `aiApi.providers` | GET | `/ai/providers` | yes | 200 |
| **AgentMonitor** | `decisionIntelligenceApi.listExecutors` | GET | `/executors/{t}` | yes | 200 |
| **CommandCenter** | `aiApi.executions` | GET | `/ai/executions/{t}` | yes | 200 |
| | `aiApi.providers` | GET | `/ai/providers` | yes | 200 |
| | `decisionIntelligenceApi.getExecutiveSummary` | GET | `/analytics/{t}/executive-summary` | yes | 200 |
| | `notificationApi.unreadCount` | GET | `/notifications/{t}/unread-count` | yes | 200 |
| | `reasoningEngineApi.duplicateSignals` | GET | `/reasoning-engine/{t}/duplicate-signals` | yes | 200 |
| | `reasoningEngineApi.missingEvidence` | GET | `/reasoning-engine/{t}/missing-evidence` | yes | 200 |
| | `taskApi.listRegistry` | GET | `/tasks/registry` | yes | 200 |
| **ConversationWorkspace** | `conversationApi.listSessions` | GET | `/conversations/sessions/{t}` | yes | 200 |
| | `conversationApi.searchSessions` | GET | `/conversations/sessions/{t}/search` | yes | 200 |
| | `conversationApi.listPromptTemplates` | GET | `/conversations/prompt-templates/{t}` | yes | 200 |
| | `conversationApi.getMessages` | GET | `/conversations/sessions/{t}/{id}/messages` | yes | 404 (no row) |
| | `conversationApi.createSession` | POST | `/conversations/sessions` | yes | n/a (write) |
| | `conversationApi.sendMessage` | POST | `/conversations/sessions/{t}/{id}/messages` | yes | n/a (write) |
| | `conversationApi.rename` | PATCH | `/conversations/sessions/{t}/{id}/rename` | yes | n/a (write) |
| | `conversationApi.setPinned` | PATCH | `/conversations/sessions/{t}/{id}/pin` | yes | n/a (write) |
| | `conversationApi.deleteSession` | DELETE | `/conversations/sessions/{t}/{id}` | yes | n/a (write) |
| **DecisionAnalyticsPanel** | `decisionIntelligenceApi.getAnalytics` | GET | `/analytics/{t}` | yes | 200 (1.9 s) |
| | `decisionIntelligenceApi.listRisks` | GET | `/risks/{t}` | yes | 200 |
| **DecisionIntelligence** | `decisionIntelligenceApi.getDecisionIntelligence` | GET | `/analytics/{t}/decision-intelligence` | yes | 200 |
| | `raw fetch()` | GET | `/analytics/{t}/decisions/export.csv` | yes | 200 ¹ |
| **DeliberationWorkspace** | `caseApi.listCases` | GET | `/cases/{t}` | yes | 200 |
| | `caseApi.getCase` | GET | `/cases/{t}/{id}` | yes | 404 (no row) |
| | `caseApi.getLedger` | GET | `/hypotheses/{t}/case/{caseId}` | yes | 200 |
| | `caseApi.createCase` / `proposeHypothesis` / `confirmHypothesis` / `rejectHypothesis` | POST | `/cases`, `/hypotheses/…` | yes | n/a (write) |
| **DepartmentIntelligence** | `deptApi.listDepartments` | GET | `/departments/{t}` | yes | 200 |
| | `personApi.listPeople` | GET | `/people/{t}` | yes | 200 |
| | `capabilityApi.listCapabilities` | GET | `/capabilities/{t}` | yes | 200 |
| **EvidenceWorkspace** | `api.listEvidence` | GET | `/evidence/{t}` | yes | 200 |
| **ExecutionCenter** | `esoApi.*` | GET | `/eso-executions/{t}` | yes | 200 |
| **ExecutiveDashboard** | `decisionIntelligenceApi.getExecutiveSummary` | GET | `/analytics/{t}/executive-summary` | yes | 200 |
| **GlobalSearch** | `api.search` | GET | `/search/{t}` | yes | 200 |
| **GraphExplorer** | `graphApi.search` | GET | `/graph/{t}/search` | yes | 200 |
| | `graphApi.entity` / `related` | GET | `/graph/{t}/entity/{label}/{id}` | yes | 404 (no row) |
| **🔴 IngestionWorkspace** | `ingestionApi.listSources` | GET | `/ingestion/sources/{t}` | **NO** | **404** |
| | `ingestionApi.upload` | POST | `/ingestion/upload` | **NO** | **404** |
| | `ingestionApi.commit` | POST | `/ingestion/{t}/{jobId}/commit` | **NO** | **404** |
| **IntelligenceWorkspace** | `api.getWorkspace` | GET | `/workspace/{t}` | yes | 200 |
| | `api.approveRecommendation` | POST | `/decisions` | yes | n/a (write) |
| **KasbaExplorer** | `kasbaApi.heatmap` | GET | `/kasba/heatmap/{t}` | yes | 200 |
| | `kasbaApi.tasksForCapability` | GET | `/kasba/tasks/{t}/capability/{id}` | yes | 200 |
| | `capabilityApi.listCapabilities` | GET | `/capabilities/{t}` | yes | 200 |
| | `kasbaApi.createTask` | POST | `/kasba/tasks` | yes | n/a (write) |
| **KnowledgeLibrary** | `knowledgeLibraryApi.list` | GET | `/knowledge-library/{t}` | yes | 200 |
| | `knowledgeLibraryApi.create` / `markReused` | POST | `/knowledge-library…` | yes | n/a (write) |
| **MemoryScreen** | `api.listLearnings` | GET | `/learnings/{t}` | yes | 200 |
| | `reasoningEngineApi.memoryStats` | GET | `/reasoning-engine/{t}/memory-stats` | yes | 200 |
| **MentalModelBrowser** | `mentalModelApi.list` | GET | `/mental-models/{t}` | yes | 200 |
| **OrganizationIntelligenceHome** ² | `api.getHomeMetrics` | GET | `/workspace/{t}/home-metrics` | yes | 200 |
| **PersonIntelligence** | `personApi.listPeople` | GET | `/people/{t}` | yes | 200 |
| | `personApi.getTwin` | GET | `/people/{t}/{id}/twin` | yes | 404 (no row) |
| **PolicyManagement** | `policyApi.list` | GET | `/policies/{t}` | yes | 200 |
| | `policyApi.create` | POST | `/policies` | yes | n/a (write) |
| **Settings** | `settingsApi.set` | PUT | `/settings/{t}` | yes | n/a (write) |
| | `authApi.changePassword` | POST | `/auth/change-password` | yes | n/a (write) |
| **SignalDashboard** | `api.listSignals` | GET | `/signals/{t}` | yes | 200 |
| **TaskMonitor** | `taskApi.listRegistry` | GET | `/tasks/registry` | yes | 200 |
| | `taskApi.runSequence` | POST | `/tasks/run` | yes | n/a (write) |
| **EsoLibraryScreen** | *(no API calls detected)* | — | — | — | verify manually |

¹ `export.csv` returned 200 with a 0-byte body. That is a **probe artifact, not a defect** — `AnalyticsController::decisionsCsv()` returns a `StreamedResponse`, whose content is only produced when `sendContent()` runs. Not reachable through in-process dispatch.
² `OrganizationIntelligenceHome` is no longer mounted in `App.tsx` (Command Center replaced it as `home`), but the file and its calls are intact.

---

## 4. Method limitations — stated so the "0 broken" figures are not over-read

1. **404 vs. missing row.** 53 of the 55 404s are routes probed with a sentinel or guessed id because no row of that type exists for tenant `7`. Those confirm the route resolves and the handler runs; they do **not** confirm the handler returns correct data for a real id.
2. **Write routes were not exercised.** POST/PATCH/PUT/DELETE were verified to *resolve* against the router only. F1 and F2 are both write-path-adjacent schema bugs found via GET — there may be more reachable only by writing.
3. **Two extractor collisions.** `policy.ts` and `notification.ts` each export two objects sharing a `list` method; my module-keyed index kept only the last. Both were re-checked by hand and both underlying routes exist (`/policies/{t}`, `/notifications/{t}`).
4. **12 calls needed manual resolution** because they use `async` wrappers around `request()` (e.g. `listPeople`, `listDepartments`, `listCapabilities`) that the regex did not match. All 12 were checked by hand; 3 of them are F4.
5. **One tenant only.** Everything ran against tenant `7`. A tenant lacking entity mappings would fail differently — `EntityResolver` fails closed by design.

---

## 5. Incidental observations

- **MariaDB 10.11.9 confirmed.** This resolves the open question carried through all three architecture documents. It rules out MySQL 8 JSON functional indexes; the generated-column + index approach works, and recursive CTEs are available (10.2+).
- `hpbrain_organizations` holds tenant `t1` while signals, evidence and entity mappings use numeric ERP `sub_institute_id` values (`1`–`7`). Consistent with organizations being served from `institute_detail` via `EntityResolver`, and with `hpbrain_organizations` being effectively unused — but worth confirming nothing writes to it expecting to be read back.
- `hpbrain_organization_units` holds **0 rows for tenant 7**, so F1 would 500 even before reaching a row.

---

## 6. Suggested triage order (not applied)

| | Finding | Why this order |
|---|---|---|
| 1 | **F4** Ingestion screen — 3 dead endpoints | A user-reachable nav item that cannot perform its only function, and the failure is masked as "empty" |
| 2 | **F1** `sort_order` 500 | Foundation-layer endpoint, has never worked, one-line fix or one migration |
| 3 | **F2** `user_id` 500 | AI Workspace unusable; same table already used correctly elsewhere |
| 4 | **F3** shadowed hierarchy/tree | Two handlers unreachable; fix is route reordering |
| 5 | **F5** perf outliers | 4.9 s and 3.6 s endpoints; `/analytics` is on the Command Center path |

Nothing above has been changed. `routes-audit.txt` is as generated by the command specified.
