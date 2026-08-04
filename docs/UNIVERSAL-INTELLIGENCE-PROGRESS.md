# Universal Intelligence — Measured Progress

Every phase appends one row. A phase that increases failures or unresolved routes is
reverted, not patched forward.

All figures below are **measured output**, not estimates. The commands are:

```
php artisan test
php tests/standalone/run.php
php tests/standalone/security.php
php artisan brain:check-routes --json
php artisan brain:status
```

---

## Baseline — Phase 0

Measured 2026-08-04 on commit `94fc8a5` (branch `main`, clean tree).

| Measure | Value |
|---|---|
| PHPUnit tests passed | 309 |
| PHPUnit assertions | 1131 |
| PHPUnit failures | 0 |
| PHPUnit duration | 40.08s |
| standalone `run.php` | 42 passed, **2 failed** |
| standalone `security.php` | 25 passed, 0 failed |
| Routes declared (`brain:check-routes`) | 364 |
| Routes that do not resolve | 0 |
| API routes in route table | 364 |
| SPA calls checked | 331 |
| **SPA calls to non-existent endpoints** | **35** |

### The two pre-existing standalone failures

These fail on the untouched baseline. They are **carried, not caused**, and no later
phase may increase the count above 2.

| Test | Expected | Actual |
|---|---|---|
| Reasoning — computed confidence: `one 0.9 at half-life` | 0.37 | 0.36 |
| Reasoning — computed confidence: `three perfect` | 0.75 | 0.72 |

Both are rounding divergences from the Node original in the confidence formula
(base 0.30, evidence_weight 0.15, ceiling 0.95, freshness_half_life_days 90).
They sit directly in the confidence path this work touches, so they are re-measured
after every phase rather than ignored.

### The 35 SPA calls with no matching route

Confirmed genuine, not a checker artifact: `RoleController` is registered with
index/store/show/update/destroy only — no `audit`, no `archive` route exists
(`routes/api.php:519-523`). `brain:check-routes` reports 0 broken because it verifies
route → controller, not SPA → route. These are the opposite direction and were
previously unmeasured.

The pattern is highly regular — `/audit` and `/archive` sub-resources on the
universal-platform CRUD modules, plus the import module:

| Shape | Count |
|---|---|
| `GET /{resource}/{tenant}/{id}/audit` | 17 |
| `POST /{resource}/{tenant}/{id}/archive` | 13 |
| import: `preview`, `start`, `active`, `logs`, `rollback` | 5 |

Affected resources: competencies, locations, onboarding, organization-types,
organization-units, person-competencies, person-roles, person-skills, positions,
readiness-checks, reporting-structures, roles, skills, template-overrides, import.

Plus two that are not audit/archive:
`GET /organization-units/{t}/{id}/children`, `GET /readiness-checks/{t}/by-org/{id}`,
`GET /reporting-structures/{t}/for-person/{id}`.

**Not fixed in Phase 0** — recording only, per plan. This is pre-existing debt and
fixing it inside a baseline phase would corrupt the baseline.

### Hardcoded ERP reference census

The stated total of **168 is exactly correct**. The stated file list is not: it names
12 files totalling 161. Two files carrying the remaining 7 refs were omitted.

| File | Refs | In plan's list? |
|---|---|---|
| `app/Http/Controllers/Api/OrganizationController.php` | 31 | yes |
| `app/Http/Controllers/Api/AnalyticsController.php` | 22 | yes |
| `app/Http/Controllers/Api/PersonController.php` | 21 | yes |
| `app/Http/Controllers/Api/AuthController.php` | 18 | yes |
| `app/Http/Controllers/Api/DepartmentController.php` | 17 | yes |
| `app/Domain/Signals/SignalGenerator.php` | 17 | yes |
| `app/Repositories/OrganizationRepository.php` | 16 | yes |
| `app/Http/Middleware/EnsureTenantScope.php` | 6 | **NO — omitted** |
| `app/Console/Commands/DbDiagnostics.php` | 6 | yes |
| `app/Http/Controllers/Api/WorkspaceController.php` | 5 | yes |
| `app/Http/Controllers/Controller.php` | 4 | yes |
| `app/Http/Controllers/Api/KasbaController.php` | 2 | yes |
| `app/Domain/Events/EventConsumer.php` | 2 | yes |
| `app/Services/ConfigurationEngine.php` | 1 | **NO — omitted** |
| **Total** | **168** | 14 files |

`EnsureTenantScope` matters more than its 6 refs suggest — it is the middleware that
derives tenant scope from the token, and it is the enforcement point the security
suite's cross-tenant tests exercise. It is folded into Phase 2 commit (5) and its
changes are held to the same behaviour-identical standard, with
`tests/standalone/security.php` as the gate.

`ConfigurationEngine` is folded into Phase 2 commit (1).

### Phase 0 gate

No code changed. Baseline is the commit under test. Gate is definitional — passed.

---

## Phase 1 — EntityResolver

Adds the vocabulary layer and connects nothing to it yet. **Zero behaviour change**
is the whole claim, and `EntityMappingSeederTest` is what makes it checkable: it
asserts the resolved strings against literals read off the code being replaced, not
off the seeder.

### What was built

| File | Role |
|---|---|
| `app/Domain/Universal/EntityResolver.php` | Loads mappings, one query per tenant per request |
| `app/Domain/Universal/ResolvedSource.php` | Immutable binding: table, tenantKey, primaryKey, `field()`, `has()` |
| `app/Domain/Universal/UnsupportedEntityException.php` | Named failures: entity, field, ambiguous, incomplete |
| `app/Providers/UniversalServiceProvider.php` | `scoped()` binding — per request, never per process |
| `database/seeders/EntityMappingSeeder.php` | ERP mappings for every existing tenant |
| `tests/Unit/Universal/EntityResolverTest.php` | 18 tests, weighted toward failure paths |
| `tests/Feature/EntityMappingSeederTest.php` | 8 tests pinning resolved strings to today's literals |

### Schema bug found and fixed

`2026_08_01_000004_entity_mappings` declared `UNIQUE (tenant_id, source_system,
source_entity)` while the table stores **one row per field** — `create()` writes a row
per field and `list()` orders by `source_field`. The constraint therefore permitted
exactly **one mapped field per entity**, making Person's eleven fields impossible to
express. Never hit, because the table had zero consumers.

Fixed by `2026_08_03_000100_entity_mappings_field_unique_key`, which replaces it with
`UNIQUE (tenant_id, universal_entity, universal_field)`. Not simply the old key plus a
column: keying on `source_system` would let two systems both claim `Person.email` and
force the resolver to pick one silently. The resolver's contract is one source column
per universal field, and the database is the right place to enforce it.

The in-memory test schema declared **no unique index at all**, which is why the suite
could not have caught this. `BuildsBrainSchema` now mirrors the corrected key.

### Fields with no column behind them

Four universal fields in the plan's minimum set have no source column in this ERP,
verified against the live schema. They are left unmapped — `has()` returns false —
rather than pointed at a lookalike:

| Entity.field | Why |
|---|---|
| `OrganizationUnit.head` | `hrms_departments` has no manager column of any kind |
| `Position.unit` | `hrms_job_titles` has no unit reference |
| `Position.reportsTo` | no reporting line in the table |
| `Position.isVacant` | no vacancy flag |

**Related finding, not fixed here.** The existing rule "Departments Without Manager"
tests `parent_id IS NULL OR parent_id = 0`. Since `hrms_departments` has no manager
column, that predicate detects **root departments**, not headless ones. The rule, its
catalog entry and its severity are unchanged in Phase 1 — this is a behaviour-identical
phase — but mapping `head => parent_id` would have laundered the conflation into the
vocabulary layer and made it look deliberate, so the mapping was omitted instead.
Flagged for Phase 3, where rules become data and the predicate can be corrected as a
data change with its own gate.

### Design decisions worth stating

- **Two reserved bindings.** Every entity must map `id` and `tenantKey`. Without a
  tenant key the resolver would hand back a table with no way to scope reads, and the
  first query built from it would cross tenants. Missing either throws.
- **Seeds every tenant, not one.** All six live tenants run on the same ERP tables
  today. Seeding a single "school" tenant would leave five resolving nothing, and since
  the resolver fails closed, Phase 2 would break them the moment it stopped naming
  tables directly.
- **`transform_expression` is decoded as JSON, never as SQL.** The column is TEXT and
  tenant-supplied; treating it as an expression to interpolate would hand the query
  planner to whoever configures a tenant. Same reasoning the plan applies to predicates
  in Phase 3. Text that does not parse is returned verbatim rather than nulled.
- **`scoped()` not `singleton()`.** Mappings are configuration that changes at runtime;
  a process-lifetime cache would serve a stale table name until the worker recycled.

### Phase 1 gate

| Gate | Required | Measured | |
|---|---|---|---|
| New tests pass | — | 26 passed, 70 assertions | ok |
| Existing test count unchanged | 309 | 309 (335 − 26 new) | ok |
| Existing failures unchanged | 0 | 0 | ok |
| standalone unchanged | 42/2 | 42/2 | ok |
| security unchanged | 25/0 | 25/0 | ok |
| Routes unresolved | 0 | 0 | ok |

---

## Phase 2 — 168 hardcoded references replaced

Five commits, verified after each. `grep -rn "tbluser|hrms_departments|institute_detail|org_details" app/`
returns **nothing**. `GoldenIntelligenceFlowTest` still reports the loop turns.

| # | Files | Commit |
|---|---|---|
| 1 | OrganizationRepository, OrganizationController, ConfigurationEngine | `3c01052` |
| 2 | DepartmentController | `83c868b` |
| 3 | PersonController, AuthController | `92b245e` |
| 4 | WorkspaceController, AnalyticsController, KasbaController | `9ff934c` |
| 5 | SignalGenerator, EventConsumer, Controller, DbDiagnostics, EnsureTenantScope | `214a18b` |

### Deviation from the plan, and why

The gate says test counts stay at the Phase 0 figure. **17 tests were added** (8 in commit 1,
9 in commit 2). The organization and department endpoints — 48 of the 168 references, the two
largest files in the change set — had **no test at all**. "Behaviour-identical" would have been
an unverifiable claim: those files could have been rewritten into anything and the suite would
still have gone green. `OrganizationResolverParityTest` and `DepartmentResolverParityTest` pin
the responses to the values the pre-Phase-2 code produced.

Existing test count and failures are unchanged throughout. Commits 3, 4 and 5 added no tests.

### Signature changes that are consequences, not tidying

- `OrganizationRepository::list()` takes a required `tenantId`. It was nullable, where null meant
  "every organization in the ERP" — a question with no answer once mappings are per tenant. No
  caller passed null.
- `create()` takes the mapping tenant as its own parameter, not a key in `$input`, because
  `$input` is echoed back in the response.
- `resolveRole()` takes a string tenant id rather than an int.
- `verifyErpPassword()` takes the resolved source.

### Login: the one operation with no tenant to resolve against

A caller offers an email and nothing else; the tenant is what the lookup is trying to establish.
`findPersonByEmail` searches every tenant that maps Person, **grouped by source shape**, so an
installation on one ERP issues a single query with the tenant keys as an `IN` list — exactly what
the hardcoded version cost. A second source system adds one query.

Ordering is tenant order, first match wins. Only observable if one address exists in two tenants;
**verified against the live database that zero addresses do**, and the previous implementation had
no defined ordering for that case either.

A designated "identity tenant" was rejected: it reintroduces the hardcoded source and silently
locks out any tenant not on it.

### Three pre-existing defects found

| What | Where | Action |
|---|---|---|
| `inactive` count's unparenthesised `orWhereNull` binds across the tenant filter, counting **every tenant's** status-null rows | `AnalyticsController::peopleReport` | Carried forward unchanged, marked in code. A cross-tenant count must not move silently inside a commit that promises no behaviour change. Logged for Phase 3. |
| "Departments Without Manager" detects **root** departments — the source has no manager column | `SignalGenerator`, `INTELLIGENCE-CATALOG.md` | Annotated at the rule. Unchanged in Phase 2. |
| `config('brain.erp_tables')` — hardcoded map of five ERP tables, **zero readers** | `config/brain.php` | Removed. A second, now-stale answer to a question `EntityResolver` owns. |

### One defect introduced and caught

A missing `use` in `EnsureTenantScope` made `app(EntityResolver::class)` resolve to the middleware
namespace, and the deliberately broad `catch (\Throwable)` reported the binding error as
"organization does not exist" — a 403. Fail-closed behaved exactly as designed and turned a
programming error into an authorization answer. Import fixed; the catch is left as-is, because
failing closed on any throwable is the correct choice. Worth remembering as the cost of that choice.

### Still not universal

`deleted_at`, `created_at`, `updated_at`, `created_by`, `password` and `plain_password` are still
written literally. They are soft-delete, audit and credential conventions rather than entity
fields, so they sit outside the universal field set. A source system spelling them differently
would still need code. Recorded rather than papered over with a fallback — a fallback is the one
thing the resolver must never do.

### Fixtures that now declare their source

`ErpLoginTest`, `OutboxProducerTest`, `HomeMetricsTest`, `ApiAuthorizationTest` and
`TenantIsolationMatrixTest` install entity mappings via `tests/Support/SeedsEntityMappings`.
A test without mappings is a tenant without mappings, and the resolver fails closed for both.
`OutboxProducerTest` needed two tenants: its Brain tables are keyed `tenant-alpha` while its ERP
row carries `sub_institute_id` 1 — a mismatch the old tenant-blind login never surfaced.

---

## Phase log

| Phase | Tests | Assertions | Fails | standalone | security | Routes | Unresolved | Commit |
|---|---|---|---|---|---|---|---|---|
| 0 baseline | 309 | 1131 | 0 | 42/2 | 25/0 | 364 | 0 | `94fc8a5` |
| 1 EntityResolver | 335 | 1201 | 0 | 42/2 | 25/0 | 364 | 0 | `a6b6a4a` |
| 2.1 organizations | 343 | 1242 | 0 | 42/2 | 25/0 | 364 | 0 | `3c01052` |
| 2.2 departments | 352 | 1273 | 0 | 42/2 | 25/0 | 364 | 0 | `83c868b` |
| 2.3 people + auth | 352 | 1273 | 0 | 42/2 | 25/0 | 364 | 0 | `92b245e` |
| 2.4 workspace/analytics/kasba | 352 | 1273 | 0 | 42/2 | 25/0 | 364 | 0 | `9ff934c` |
| 2.5 signals/events/tenancy | 352 | 1273 | 0 | 42/2 | 25/0 | 364 | 0 | `214a18b` |

A commit cannot record its own hash, so each phase's hash is written into the next
phase's commit. The row above is filled in once the commit exists.
