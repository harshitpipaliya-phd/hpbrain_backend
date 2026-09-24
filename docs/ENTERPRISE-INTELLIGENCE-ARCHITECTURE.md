# Enterprise Intelligence Platform — Architecture Proposal

**Status:** Proposal, awaiting approval. No code has been changed.
**Author:** Architecture review, 2026-08-05
**Scope:** Extend `hp-enterprise-brain` into a self-evolving, intelligence-generating platform without altering existing working behaviour.

---

## 0. The headline finding, before anything else

**You do not have a CRUD application.** You have a partially-built intelligence platform with a serious architectural foundation already in place, and roughly six real gaps between it and what you described.

Verified inventory:

| Layer | Count | State |
|---|---|---|
| API controllers | 60 | `app/Http/Controllers/Api/` |
| Repositories | 57 | All extend `BaseRepository`, tenant-scoped by construction |
| Migrations | 97 | `hpbrain_*` prefixed, MySQL/MariaDB raw DDL |
| API routes | 370 | `routes/api.php`, JWT + tenant + permission middleware |
| Domain services | ~70 | `app/Domain/`, `app/Services/` |
| Feature/unit tests | 60 files | `tests/Feature`, `tests/Unit`, `tests/standalone` |
| Frontend screens | ~120 components | React 18 + Vite, 31 routed views |
| ADRs | 8 | Genuine, reasoned, followed |

Things you already have that most teams building this would have to invent:

- **`EntityResolver`** ([app/Domain/Universal/EntityResolver.php](app/Domain/Universal/EntityResolver.php)) — a universal vocabulary (`Organization`, `OrganizationUnit`, `Person`, `Position`) resolved per-tenant to whatever tables that tenant actually keeps data in, driven by `hpbrain_entity_mappings` rows. It **fails closed** with no fallback. This is the single most valuable asset in the codebase and the reason "never hardcode organizations/departments/fields" is already 70% satisfied.
- **Rules-as-data signal engine** ([app/Domain/Signals/RuleEvaluator.php](app/Domain/Signals/RuleEvaluator.php)) — signal rules live in `hpbrain_signal_rules` rows, scoped by industry, with platform-level defaults that a tenant can override. Adding a rule is an `INSERT`, not a deploy. It already refreshes rather than duplicates open signals.
- **The full reasoning loop** — Signal → Evidence → Case → Hypothesis → Reasoning → Recommendation → Decision → Execution → Outcome → Learning, with tables, repositories, controllers, and an inspectable chain endpoint (`SearchController::signalChain`).
- **AI subsystem** — provider abstraction (`AiProvider`, Anthropic + Null), prompt registry, RAG/retrieval/rerank, grounding + citation verification, quotas, cost accounting, safety filters, evaluation harness. This is not a stub; it is ~30 classes and 12 test files.
- **Configuration engine** — dynamic navigation, dynamic dashboards + widgets + layouts, terminology overrides, themes/branding, feature flags, industry templates with inheritance, config versioning. Your UI is already data-driven.
- **`hpbrain_operational_records`** — a generic, tenant+dataset-keyed fact table with a JSON `payload`, idempotent on `(tenant_id, dataset, natural_key)`, with a `row_hash` skip-if-unchanged path. This is 60% of a self-evolving store already.

So the correct instruction is **not** "redesign". It is: *close six gaps, unify two competing subsystems, and fix one stubbed method.* Everything below is written to that brief.

---

## 1. Current Architecture Analysis

### 1.1 Runtime shape

```
                    React 18 + Vite (web/)
                    120 components, 31 views, AppShell + dynamic nav
                              │  fetch, JWT bearer
                              ▼
   ┌──────────────────────────────────────────────────────────┐
   │  routes/api.php  — /v1/*, 370 routes                     │
   │  middleware: jwt → tenant → permission:<perm>            │
   └──────────────────────────────────────────────────────────┘
                              ▼
   ┌──────────────────────────────────────────────────────────┐
   │  60 Api Controllers  (thin-ish; some carry logic)        │
   └──────────────────────────────────────────────────────────┘
                    ▼                          ▼
   ┌────────────────────────┐   ┌─────────────────────────────┐
   │ app/Domain/*           │   │ app/Services/*              │
   │ Signals, Verbs, Ai,    │   │ ImportEngine, Rag, Grounding│
   │ Reasoning, Kasba,      │   │ ConfigurationEngine,        │
   │ Ingestion, Universal   │   │ NavigationBuilder, ...      │
   └────────────────────────┘   └─────────────────────────────┘
                              ▼
   ┌──────────────────────────────────────────────────────────┐
   │  57 Repositories : BaseRepository                        │
   │    scoped($tenantId) — tenant filter structurally forced │
   │    hydrate()        — JSON columns decoded               │
   │    now()            — MySQL-legal DATETIME               │
   └──────────────────────────────────────────────────────────┘
                              ▼
   ┌────────────────────────┐   ┌─────────────────────────────┐
   │ ERP tables (shared DB) │   │ hpbrain_* tables (owned)    │
   │ institute_detail       │   │ 90+ tables: signals,        │
   │ hrms_departments       │   │ evidence, cases, decisions, │
   │ tbluser                │   │ operational_records, config │
   │ hrms_job_titles        │   │ dashboards, ai_*, imports   │
   │  ── read via           │   │                             │
   │     EntityResolver     │   │                             │
   └────────────────────────┘   └─────────────────────────────┘
```

### 1.2 The critical structural fact

**Master data (Organization, Department, Person, Position) is NOT owned by this application.** It lives in the institute ERP's own tables — `institute_detail`, `hrms_departments`, `tbluser`, `hrms_job_titles` — in a database this app *shares* with the ERP. `EntityMappingSeeder` binds the universal vocabulary to those tables per tenant, and `PersonController`, `DepartmentController`, `OrganizationController` all read through `EntityResolver`.

`hpbrain_people` and `hpbrain_departments` migrations exist but are effectively **dead** — `hpbrain_people` is referenced in exactly one place in the whole application (`ExecutorController.php:67`).

**This single fact determines the entire database answer in §4 and §5.** You cannot `ALTER TABLE tbluser ADD COLUMN blood_group` — it is not your table, other systems depend on its shape, and doing so would couple the Brain to the ERP's release cycle forever. Any self-evolving schema must be a **sidecar that attaches to foreign rows by identity**, never an extension of them.

### 1.3 The flow you asked me not to break

Verified end to end and confirmed intact:

```
Login (web/src/components/auth/Login.tsx)
  → POST /v1/auth/login  (AuthController, ERP-backed credential check, JWT issued)
  → App.tsx: setAuthenticated, loadSession(), api.listOrganizations(tenantId)
  → view = 'home' → CommandCenter.tsx   [7 parallel endpoint calls]
  → user selects Organization → setSelectedOrgId, saveSession
  → view = 'departments' → DepartmentApp → DepartmentController (EntityResolver → hrms_departments)
  → view = 'people'      → PersonApp     → PersonController     (EntityResolver → tbluser)
  → PersonDetails / PersonTwin
```

Every extension in this document is **additive to this path**. Nothing proposed touches `AuthController`, `App.tsx` routing, `EntityResolver`, `PersonController::index/show`, or `DepartmentController`.

---

## 2. Problems in the Current Design

Ranked by how much they block the goal. These are findings, not criticisms — most are the natural residue of building fast.

### P1 — Two competing, unconnected ingestion subsystems

| | Stack A | Stack B |
|---|---|---|
| Entry | `ImportEngine` → `WorkbookImporter` | `Domain\Ingestion\DataSource` |
| Config | `config/import_profiles.php` (PHP file) | `data_sources` table (`config` JSON) |
| Formats | XLSX + CSV, tabular + matrix/pivot | CSV only |
| Targets | `hpbrain_operational_records`, ERP roster | *nothing — pipeline ends at `IngestionBatch`* |
| Tables | `hpbrain_import_jobs`, `hpbrain_import_logs` | `data_sources`, `ingestion_runs` |
| Tenancy | `tenant_id` scoped | **no `tenant_id` column at all** |
| Naming | `hpbrain_` prefixed | **unprefixed** — collides with the shared ERP DB |
| Maturity | Real, tested (`FiberValleyImportTest`) | Interface + one CSV source |

Stack B is the *better designed* seam (`DataSource` is a clean one-method port, in the same spirit as `AiProvider`). Stack A is the one that actually works. They must be merged, not raced.

**Stack B's two table defects are release-blocking as written:** `data_sources` and `ingestion_runs` are unprefixed in a database shared with the institute ERP — the exact collision every `hpbrain_` prefix exists to prevent — and neither carries `tenant_id`, so one tenant's sources are visible to all.

### P2 — `ImportEngine::processImport()` does nothing

[app/Services/ImportEngine.php:169-215](app/Services/ImportEngine.php#L169-L215):

```php
for ($i = 0; $i < $job['total_rows']; $i++) {
    try {
        $this->jobRepository->update(..., ['processed_rows' => $i + 1]);
        $results['success']++;          // ← nothing was written
    } catch (\Throwable $e) { ... }
}
$this->jobRepository->update(..., ['status' => 'completed', ...]);
```

It increments a counter `total_rows` times, writes no entity, and marks the job `completed` with a full success count. The `POST /v1/imports/{tenantId}/{id}/process` route is live and reports success for an import that imported nothing. It also issues one `UPDATE` per row — 65,268 round trips for a real workbook.

This is the single highest-severity defect found.

### P3 — Import profiles are code, not data

`config/import_profiles.php` is a PHP file keyed by organization slug (`'fibervalley' => [...]`). Its own docblock claims "A profile is data, not code" — but adding a customer's workbook requires editing a file in the repository and deploying. There is also no runtime path to *create* a profile, so an admin cannot onboard a new file shape without an engineer. And `--only`/slug lookups still key on an organization name, which is the hardcoding you explicitly ruled out.

### P4 — No schema evolution anywhere

Search across `app/`, `database/`, `config/` for attribute registries, custom fields, or dynamic columns: **zero results**. The only extension points are untyped, unregistered JSON blobs:

- `hpbrain_operational_records.payload` — dataset-scoped, keys never registered, never validated, never searchable, never surfaced in the UI.
- `metadata JSON` on 8 tables — same, and mostly unused.

Nothing discovers a new column, nothing records that it appeared, nothing decides its type, nothing makes it queryable. Your `blood_group` / `passport_no` scenario currently ends with the column being silently discarded by `RowMapper` (it only copies headers a profile explicitly names).

### P5 — Search covers the wrong half of the system

`SearchController::SEARCHABLE` lists six tables: `signals`, `evidence`, `cases`, `recommendations`, `learnings`, `capabilities`. It does **not** search people, departments, organizations, skills, positions, roles, or operational records — i.e. none of your examples ("show all Java developers", "who knows React?", "everyone under Finance") can be answered. Matching is `LIKE %term%` on 1–2 columns per table with a hard `limit(25)` per table and no relevance ranking. There is no query grammar and no natural-language path.

### P6 — Analytics measures the loop, not the organization

`AnalyticsController` (639 lines, well built) computes decision acceptance, recommendation accuracy, evidence quality, risk coverage, mental-model confidence. All of it is about *the Brain's own reasoning performance*.

Of the 26 organization-intelligence items you listed, the number currently computed is approximately **three** (via ad-hoc rules: missing managers, orphan records, duplicate signals). Headcount, department growth, data completeness, skill/experience/education/age distribution, gender ratio, joining and attrition trends, project allocation, utilisation, attendance trend, duplicate people, duplicate departments — none exist.

### P7 — Nothing runs in the background

`config/queue.php` sets `QUEUE_CONNECTION=database`. **`app/Jobs/` does not exist.** No class in the codebase implements `ShouldQueue`. Every import, every detection sweep, every analytics computation runs synchronously — inside an HTTP request, or inside an artisan command. A 65k-row workbook import is a single blocking call.

Three scheduled commands do exist and are correct (`brain:process-events` every minute, `brain:snapshot` daily, `brain:detect` hourly) — but they depend on a host cron entry that the docs correctly flag as easy to forget.

### P8 — Caching is nominal

`CACHE_STORE=database`. `TenantConfigCache` exists and is used for configuration. Nothing else caches. Every Command Center load fires 7 endpoints, several of which do full-table `->get()` into PHP and aggregate in memory (`AnalyticsController::statistics()` pulls every decision, recommendation, outcome and risk row on every call).

### P9 — Duplicate detection is a placeholder

`ImportEngine::detectDuplicates()` compares `md5(code . email)` **within the uploaded file only**. It never queries the database, so it cannot detect that a row duplicates an existing person. There is no fuzzy matching, no phone/name normalisation, no cross-entity duplicate detection.

### P10 — Controllers carry domain logic

`AnalyticsController` is 639 lines of aggregation SQL. `PersonController` is 444. This is survivable today but means intelligence logic cannot be reused by a background job, a scheduled snapshot, or the AI layer without going through HTTP.

---

## 3. Scalability Issues

Concrete, with the numbers that make them real. The FiberValley dataset is 65,268 complaint rows — that is the working scale.

**S1 — In-memory aggregation.** `AnalyticsController::statistics()` executes `DB::table(...)->get()` on four tables and aggregates with PHP collections. At 65k+ rows per table this is O(n) memory per request, per user, uncached. Must become `GROUP BY` in SQL, then snapshotted.

**S2 — Row-at-a-time writes.** `ImportEngine::processImport()` issues one `UPDATE` per row. `WorkbookImporter` is better (it streams) but still writes per record. Needs chunked `upsert()` at 500–1000 rows.

**S3 — Synchronous imports.** No queue means the 65k-row import owns a PHP process for its whole duration. With `max_execution_time` and any HTTP timeout in front, a large import through the API cannot complete.

**S4 — JSON is unindexable as used.** `operational_records.payload` and every `metadata JSON` column can only be filtered by full scan. The migration comment for `operational_records` gets this exactly right — *"JSON extraction cannot use an index in MySQL 8 without a generated column"* — and promotes 15 hot columns for precisely that reason. Any new dynamic-attribute design must inherit this discipline, not ignore it.

**S5 — `LIKE %term%` search.** Leading wildcard defeats every B-tree index. Six tables × full scan per keystroke. At 65k+ rows this degrades immediately.

**S6 — No pagination on list endpoints.** `PersonController::index()` selects every active person for the tenant with no `LIMIT`. `search()` caps at 50; `index()` caps at nothing.

**S7 — Per-request resolver cache only.** `EntityResolver` caches per-request by design (correct — mappings are live configuration). But that is 1 query per tenant per request minimum, on a hot path, against a table with no covering index for `(tenant_id, is_active)`.

**S8 — Unknown JSON engine.** Migration comments reference *both* "a live MySQL 8 server" and "a real MariaDB 10.11 instance". These behave differently: MySQL 8's `JSON` is a binary type supporting functional indexes on JSON paths; MariaDB's `JSON` is a `LONGTEXT` alias with a `json_valid` check and **no** functional index on JSON paths. **This must be resolved before implementation** — see §12, Step 0. The design in §5 is deliberately built to work identically on both.

---

## 4. Database Redesign

### 4.1 Principle

**Nothing existing is altered.** No `ALTER TABLE` on any `hpbrain_*` table, and absolutely nothing on any ERP table. Every change is a new, additive table. This is what makes "do not break current functionality" a structural guarantee rather than a promise.

### 4.2 The four-zone model

The database splits cleanly into four zones with different ownership and different rules. Naming this explicitly is most of the redesign.

```
ZONE 1 — MASTER DATA (foreign, read-mostly)
  institute_detail, hrms_departments, tbluser, hrms_job_titles
  Owner: the ERP.  Access: EntityResolver only.  DDL: never.

ZONE 2 — CORE (owned, relational, stable)
  hpbrain_signals, evidence, cases, decisions, outcomes, learnings,
  capabilities, org_units, skills, roles, positions, locations …
  Owner: Brain.  Shape changes rarely.  Migrations as today.

ZONE 3 — FACT (owned, high-volume, semi-structured)
  hpbrain_operational_records  (+ payload JSON, promoted hot columns)
  Owner: Brain.  Grows to millions.  Never ALTERed per customer.

ZONE 4 — DYNAMIC  ◀── NEW
  hpbrain_attribute_definitions      the catalog: what fields exist
  hpbrain_attribute_aliases          how sources name them
  hpbrain_entity_attributes          the values: one JSON doc per entity
  hpbrain_attribute_index            the searchable projection (selective)
  Owner: Brain.  Shape is DATA.  Zero DDL per new field, ever.
```

### 4.3 Zone 4, in full

**`hpbrain_attribute_definitions`** — the metadata catalog. One row per (tenant, entity type, attribute).

```
id                   VARCHAR(36) PK
tenant_id            VARCHAR(36) NOT NULL
entity_type          VARCHAR(64) NOT NULL   -- 'Person' | 'OrganizationUnit' | dataset name
attribute_key        VARCHAR(128) NOT NULL  -- canonical snake_case: 'blood_group'
label                VARCHAR(255) NOT NULL  -- human: 'Blood Group'
data_type            VARCHAR(32) NOT NULL   -- string|integer|decimal|boolean|date|datetime|enum|json|reference
semantic_type        VARCHAR(64) NULL       -- email|phone|url|currency|percentage|identifier|name
enum_values          JSON NULL              -- observed distinct values when cardinality is low
validation           JSON NULL              -- {required, min, max, pattern, unique}
unit                 VARCHAR(32) NULL       -- 'INR' | 'hours' | 'kg'
is_pii               BOOLEAN DEFAULT FALSE  -- drives masking + audit
is_searchable        BOOLEAN DEFAULT FALSE  -- projected into hpbrain_attribute_index
is_promoted          BOOLEAN DEFAULT FALSE  -- has a generated column (see 5.4)
status               VARCHAR(24) DEFAULT 'proposed'  -- proposed|active|deprecated|rejected
origin               VARCHAR(32) NOT NULL   -- 'discovered' | 'manual' | 'template'
confidence           DECIMAL(5,4) NULL      -- type-inference confidence, 0..1
observed_count       INT DEFAULT 0
null_count           INT DEFAULT 0
distinct_count       INT DEFAULT 0
sample_values        JSON NULL              -- up to 10, PII-redacted
first_seen_job_id    VARCHAR(36) NULL
first_seen_date / last_seen_date  TIMESTAMP
created_by           VARCHAR(255) NOT NULL
UNIQUE (tenant_id, entity_type, attribute_key)
INDEX (tenant_id, entity_type, status)
INDEX (tenant_id, is_searchable)
```

**`hpbrain_attribute_aliases`** — the learned vocabulary. This is what makes the second import of a differently-headed file map itself.

```
id, tenant_id, attribute_definition_id
alias           VARCHAR(255)   -- 'Blood Grp', 'BG', 'blood-group', 'रक्त समूह'
normalized      VARCHAR(255)   -- lowercased, punctuation-stripped: 'bloodgrp'
source_system   VARCHAR(100) NULL
confirmed_by    VARCHAR(255) NULL  -- NULL = machine-proposed, set = human-confirmed
hit_count       INT DEFAULT 0
UNIQUE (tenant_id, normalized, source_system)
```

**`hpbrain_entity_attributes`** — the value store. **One row per entity, not per attribute.**

```
id            VARCHAR(36) PK
tenant_id     VARCHAR(36) NOT NULL
entity_type   VARCHAR(64) NOT NULL
entity_id     VARCHAR(191) NOT NULL   -- FK-by-value; ERP ids are ints-as-strings
values        JSON NOT NULL           -- {"blood_group":"O+","passport_no":"…"}
version       INT DEFAULT 1
updated_by    VARCHAR(255)
created_date / updated_date
UNIQUE (tenant_id, entity_type, entity_id)
```

`entity_id` is a **value reference, not a foreign key** — deliberately. It has to attach to `tbluser.id`, a row in a table the Brain does not own and must not constrain. A real FK would make every ERP delete fail.

**`hpbrain_attribute_index`** — the searchable projection. Written only for attributes with `is_searchable = 1`.

```
id, tenant_id, attribute_definition_id, entity_type, entity_id
value_string  VARCHAR(255) NULL   -- indexed
value_number  DECIMAL(20,6) NULL  -- indexed
value_date    DATETIME NULL       -- indexed
value_text    TEXT NULL           -- FULLTEXT
UNIQUE (tenant_id, attribute_definition_id, entity_id)
INDEX (tenant_id, attribute_definition_id, value_string)
INDEX (tenant_id, attribute_definition_id, value_number)
INDEX (tenant_id, attribute_definition_id, value_date)
FULLTEXT (value_text)
```

### 4.4 Supporting tables

```
hpbrain_ingestion_profiles     runtime-editable replacement for config/import_profiles.php
hpbrain_ingestion_field_maps   per-profile column → target/attribute binding, with confidence
hpbrain_metric_definitions     metrics as data (formula, dimension, entity, industry scope)
hpbrain_entity_duplicates      duplicate candidate pairs: score, method, status, reviewer
hpbrain_search_documents       denormalised search index over every entity type
hpbrain_insights               generated insights: type, severity, entity, evidence, dismissed_at
```

Plus **fixes to Stack B**: rename `data_sources` → `hpbrain_data_sources` and `ingestion_runs` → `hpbrain_ingestion_runs`, and add `tenant_id` to both. Done as a new migration that creates the corrected tables and copies rows; the old tables are dropped only after verification.

---

## 5. Dynamic Data Architecture — and why this shape

You asked me to choose and justify. Here is the comparison against **your** constraints, not in the abstract.

### 5.1 The options, scored

| | Physical `ALTER TABLE` | Pure EAV | Pure JSON blob | **Hybrid (proposed)** |
|---|---|---|---|---|
| Works on ERP-owned `tbluser` | ❌ impossible | ✅ | ✅ | ✅ |
| New field needs zero DDL | ❌ | ✅ | ✅ | ✅ |
| Field is typed & validated | ✅ | ⚠️ per-row | ❌ none | ✅ catalog |
| Field is discoverable by UI | ✅ | ✅ | ❌ | ✅ catalog |
| Filter one field | ✅ index | ⚠️ join+index | ❌ full scan | ✅ index table |
| Filter five fields at once | ✅ | ❌ 5 self-joins | ❌ | ✅ 5 index hits |
| `GROUP BY` a field | ✅ | ⚠️ slow | ❌ | ✅ |
| Read all fields of one entity | ✅ 1 row | ❌ N rows | ✅ 1 row | ✅ 1 row |
| Storage at 65k × 40 fields | baseline | 2.6M rows | compact | compact + selective index |
| Works on MySQL 8 **and** MariaDB | ✅ | ✅ | ⚠️ differs | ✅ **identical** |

### 5.2 The decision

> **Hybrid: relational core + JSON document sidecar + metadata catalog + selective inverted index.**

Not pure EAV, not pure JSONB. Concretely:

1. **Stable, universal fields stay relational.** `first_name`, `email`, `department_id` remain real columns in the ERP and in Zone 2 tables. They are queried constantly, joined constantly, and their shape is not in question. Moving them into a dynamic store would be a strict downgrade.

2. **Every dynamic value lives in one JSON document per entity** (`hpbrain_entity_attributes.values`). Reading a person's 40 custom fields is one row, one decode — not 40 EAV rows joined. Writing them is one upsert.

3. **Every dynamic value's *meaning* lives in the catalog** (`hpbrain_attribute_definitions`), never inferred at read time. This is what pure JSON lacks and what makes the difference between a blob and a schema. The catalog is what the form builder renders from, what validation reads, what the AI layer is told about, and what makes `blood_group` a *field* rather than a string that happens to be in a JSON object.

4. **Only fields that need to be searched get projected into a narrow index table** (`hpbrain_attribute_index`) — a *selective, deliberate* EAV used purely as an inverted index, never as the store of record. If 5 of 40 attributes are searchable, that is 5 index rows per entity, not 40. And because the projection is a plain table with plain B-tree indexes, it performs identically on MySQL 8, MariaDB, and SQLite (which your test suite uses).

5. **Very hot attributes can be promoted to a generated column** on the sidecar table:
   ```sql
   ALTER TABLE hpbrain_entity_attributes
     ADD COLUMN attr_salary_grade VARCHAR(32)
       GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`values`,'$.salary_grade'))) STORED,
     ADD INDEX idx_attr_salary_grade (attr_salary_grade);
   ```
   This is `ALTER TABLE` — but on a **Brain-owned sidecar**, triggered by an operator decision, and it is an *optimisation*, never a correctness requirement. Both MySQL 8 and MariaDB 10.2+ support stored generated columns and indexes on them. This is the same technique `hpbrain_operational_records` already uses by promoting 15 hot columns — the pattern is proven in this codebase.

### 5.3 Why not pure EAV

The failure is arithmetic, not taste. 65,268 operational records × 40 attributes = **2.6 million value rows**. "Show Java developers in Finance who joined after 2024" becomes three self-joins on a 2.6M-row table plus a type-cast on a `VARCHAR` value column. Aggregations (`AVG(salary)`, age distribution) require casting text to number across millions of rows with no usable index. And every read of a single person's profile becomes a 40-row fetch that must be pivoted in PHP. EAV solves the schema problem by making every other problem worse.

### 5.4 Why not pure JSON

MySQL cannot index a JSON path without a generated column; MariaDB cannot do it at all. "Find employees whose `passport_no` is set" becomes a full table scan. And nothing in the database records that `passport_no` *exists*, so no form can render it, no validation can guard it, no search can offer it as a facet, and no AI prompt can be told about it. A blob is storage, not a schema.

### 5.5 The evolution sequence, concretely

Your exact scenario, step by step:

```
Import file arrives with columns:
  employee_id, name, email, blood_group, passport_no, vehicle_number, salary_grade

1. PROFILE     SchemaProfiler reads 200 sample rows per column:
                 blood_group    → 8 distinct values, 0.4% null → enum, conf 0.95
                 passport_no    → 98% match ^[A-Z]\d{7}$      → string/identifier, PII
                 vehicle_number → 96% match plate pattern      → string/identifier
                 salary_grade   → 12 distinct, all "L1".."L12" → enum, conf 0.92

2. MAP         AutoMapper resolves each header, in order:
                 employee_id → exact alias   → Person.externalRef   (conf 1.00)
                 name        → alias table   → Person.displayName   (conf 0.94)
                 email       → exact         → Person.email         (conf 1.00)
                 blood_group → no match      → UNMAPPED
                 …
               Anything below the auto-accept threshold is queued for the
               review screen. Nothing is written on a guess.

3. EVOLVE      SchemaEvolver, for each UNMAPPED column:
                 INSERT hpbrain_attribute_definitions
                   (entity_type='Person', attribute_key='blood_group',
                    data_type='enum', enum_values=['O+','A+',…],
                    origin='discovered', status='proposed', confidence=0.95)
                 INSERT hpbrain_attribute_aliases ('Blood Group' → that id)
               Policy decides whether status starts 'proposed' (admin confirms
               in the Schema Review screen) or 'active' (auto-accept, per tenant).

4. NORMALIZE   'o+' → 'O+';  '30-04-2025' → 2025-04-30;  Excel serial 45777 → date

5. RESOLVE     Match row to an existing Person by employee_id, then email,
               then fuzzy(name + phone). Unmatched → new, or → duplicate queue.

6. VALIDATE    Against validation JSON on each definition.

7. PERSIST     UPSERT hpbrain_entity_attributes
                 (tenant, 'Person', <tbluser.id>, {"blood_group":"O+", …})
               UPSERT hpbrain_attribute_index for is_searchable attributes.
               tbluser is NOT touched. Old data is NOT touched.

8. REPORT      Import report: rows in/out, new attributes discovered,
               mappings applied, duplicates found, validation failures,
               per-row log in hpbrain_import_logs.
```

Next month the same file arrives with the header spelled `Blood Grp`. Step 2 hits the alias table, maps it with confidence 1.0, and **no human is involved**. That is the system learning its own schema.

---

## 6. AI / Intelligence Architecture

### 6.1 Three tiers, strictly separated

The separation matters because it decides what can be trusted. Your existing `GroundingService` / `CitationVerification` already encodes this instinct; this makes it structural.

```
TIER 1 — DETERMINISTIC METRICS          (SQL. Always correct. Never an LLM.)
  Headcount, growth %, completeness %, gender ratio, age/experience
  distribution, joining & attrition trend, allocation, utilisation.
  → MetricProvider registry; definitions in hpbrain_metric_definitions
  → snapshotted daily into hpbrain_metric_snapshots  (table already exists)

TIER 2 — RULES                          (Data. Explainable. Auditable.)
  "Department has no manager", "3 employees missing email",
  "duplicate phone numbers", "IT grew 40% this quarter".
  → EXISTING RuleEvaluator + hpbrain_signal_rules.  New rules are INSERTs.
  → Emits Signals → Evidence → the existing loop, unchanged.

TIER 3 — LANGUAGE                       (LLM. Grounded. Never authoritative.)
  Natural-language → structured query.  Narrative summaries of Tier 1+2.
  Field-mapping suggestions.  Anomaly explanation.
  → EXISTING AiGateway + PromptRegistry + GroundingService + RAG
  → HARD RULE: Tier 3 never produces a number and never produces SQL.
    It translates and narrates. Every figure it utters is cited to a
    Tier 1 metric id or a Tier 2 signal id, verified by the existing
    CitationVerificationResult path.
```

**Why the hard rule.** An LLM that emits SQL against a multi-tenant database is a tenant-isolation vulnerability, full stop — no amount of prompt engineering makes it safe. An LLM that emits a *validated query AST* which the application then compiles to SQL, with `tenant_id` injected by the compiler and not by the model, is safe by construction. That is the design in §7.3.

### 6.2 Organization Intelligence — the 26 items, mapped

Every item you listed, with its tier and where it lands:

| Intelligence | Tier | Mechanism |
|---|---|---|
| Employee count, department growth | 1 | `HeadcountMetric`, `GrowthMetric` → snapshot |
| Missing data, data completeness | 1 | `CompletenessMetric` — computed *from the attribute catalog*, so newly discovered fields automatically join the score |
| Organization health score | 1 | Composite of completeness + structure + freshness + duplicate rate; breakdown always published beside the score (the pattern `AnalyticsController` already uses) |
| Duplicate people / departments / orgs | 1+2 | `DuplicateDetector` → `hpbrain_entity_duplicates` → rule raises a signal |
| Inactive employees | 1 | `ActivityMetric` over status + last operational-record touch |
| Skill / experience / education / age distribution | 1 | `DistributionMetric` over relational skills + dynamic attributes |
| Gender ratio | 1 | `RatioMetric` |
| Joining / attrition trend | 1 | `TrendMetric`, monthly buckets, snapshotted |
| Project allocation, resource utilisation | 1 | `AllocationMetric` (needs a project/assignment source — flagged as a dependency) |
| Attendance trend | 1 | Over `hpbrain_operational_records` where dataset = attendance (the FiberValley matrix importer already produces this shape) |
| Hierarchy / reporting / relationship graph | 1 | `GraphController` extended with `Person`, `OrganizationUnit` labels + recursive CTE for ancestor/descendant |
| Cross-department links | 1 | Derived from reporting + project edges |
| Missing managers, orphan records | 2 | Rules (two already exist) |
| Potential errors | 2+3 | Rules detect; Tier 3 narrates |
| Recommendations ("Finance has duplicate employees") | 2→3 | Rule fires → `RecommendationService` (exists) → Tier 3 phrases it |

### 6.3 Knowledge graph

`ADR-008` defers Neo4j and `GraphController` is written as the `GraphQueryPort` seam for exactly that. Keep that decision. Extend the seam:

- Add `Person`, `OrganizationUnit`, `Skill`, `Position`, `Location`, `Project` to `GraphController::LABELS`, resolved through `EntityResolver` so ERP-backed nodes work.
- Add typed edges via a new `hpbrain_entity_relationships` table `(tenant_id, from_type, from_id, relation, to_type, to_id, weight, source, confidence)` — derived, rebuildable, never hand-maintained.
- Multi-hop traversal via MySQL 8 / MariaDB 10.2 recursive CTE, depth-capped at 4. If traversal depth becomes the bottleneck, *that* is the trigger to revisit ADR-008 — and the port means it costs an adapter, not a rewrite.

---

## 7. Injection Engine Design

### 7.1 Unify the two stacks

`DataSource` (Stack B) is the right port. Everything becomes a `DataSource`:

```
                    ┌─────────────────────────────────┐
                    │  interface DataSource           │
                    │    fetch(?checkpoint): Batch    │
                    └─────────────────────────────────┘
                                   ▲
   ┌──────────┬──────────┬─────────┼─────────┬──────────┬───────────┐
CsvUpload  Workbook   Database   RestApi   Webhook   ErpSync    Manual
 (exists)  (wrap the  (JDBC-ish  (paged,  (inbound, (existing  (form
           existing    read-only  cursor)  signed)  resolver)  submit)
           XlsxReader)  connection)
```

`WorkbookImporter` is **wrapped, not rewritten**. It already handles XLSX streaming, matrix unpivoting, Excel epoch correction, short rows, and header normalisation — all hard-won. A `WorkbookSource` adapter calls it and yields `IngestionBatch`.

### 7.2 The eight-stage pipeline

```
┌─ 1 ACQUIRE ──────────────────────────────────────────────────────┐
│  DataSource::fetch() → IngestionBatch (raw rows + provenance)    │
│  Throws on connection failure — never returns empty silently.    │
└──────────────────────────────────────────────────────────────────┘
┌─ 2 PROFILE ──────────────────────────────────────────────────────┐
│  SchemaProfiler: per column — inferred type, null rate,          │
│  distinct count, format regex, sample values, PII heuristics.    │
│  Reads a bounded sample (default 500 rows), never the whole set. │
└──────────────────────────────────────────────────────────────────┘
┌─ 3 MAP ──────────────────────────────────────────────────────────┐
│  FieldMapper, in strict order, first hit wins:                   │
│    a. saved profile (hpbrain_ingestion_field_maps)      conf 1.00│
│    b. exact alias   (hpbrain_attribute_aliases)         conf 1.00│
│    c. normalised alias (case/space/punct-insensitive)   conf 0.95│
│    d. universal-vocabulary synonyms (built-in lexicon)  conf 0.90│
│    e. fuzzy (Levenshtein ≤2 + token overlap)            conf 0.70│
│    f. AI suggestion (Tier 3, header + samples + catalog)conf ≤0.85│
│    g. UNMAPPED                                                   │
│  ≥ auto_accept_threshold (default 0.90) → applied.               │
│  Below → Mapping Review screen. NOTHING is written on a guess.   │
│  Human confirmation writes an alias → the system has learned.    │
└──────────────────────────────────────────────────────────────────┘
┌─ 4 EVOLVE ───────────────────────────────────────────────────────┐
│  SchemaEvolver: each UNMAPPED column → attribute_definition      │
│  (status per tenant policy: 'proposed' or 'active') + alias.     │
│  Idempotent: re-import of the same file creates nothing new.     │
└──────────────────────────────────────────────────────────────────┘
┌─ 5 NORMALIZE ────────────────────────────────────────────────────┐
│  ValueNormalizer, reusing RowMapper's proven casts:              │
│  Excel serial→date, phone→E.164, enum canonicalisation,          │
│  whitespace/case, currency, boolean synonyms (Y/yes/1/true).     │
└──────────────────────────────────────────────────────────────────┘
┌─ 6 RESOLVE ──────────────────────────────────────────────────────┐
│  EntityMatcher: deterministic key → unique-field → fuzzy.        │
│  Outcome per row: MATCHED | NEW | AMBIGUOUS.                     │
│  AMBIGUOUS never auto-merges — it goes to hpbrain_entity_duplicates.│
└──────────────────────────────────────────────────────────────────┘
┌─ 7 VALIDATE ─────────────────────────────────────────────────────┐
│  Rules from attribute_definitions.validation + entity invariants.│
│  Failures are per-row and non-fatal: the row is logged and       │
│  skipped, the import continues. A bad row must never lose a batch.│
└──────────────────────────────────────────────────────────────────┘
┌─ 8 PERSIST + REPORT ─────────────────────────────────────────────┐
│  Chunked upsert (1000). Idempotent by natural key + row_hash.    │
│  Writes: relational targets, entity_attributes, attribute_index, │
│          import_logs, rollback manifest.                         │
│  Emits: ingestion.completed event → existing event bus →         │
│          triggers detection + snapshot refresh.                  │
│  Import report: counts, new attributes, mappings, duplicates,    │
│  validation failures, timing — persisted, not just returned.     │
└──────────────────────────────────────────────────────────────────┘
```

Every stage is a class with one public method, individually testable, individually replaceable. Stages 2–7 are pure functions of their input — no I/O except catalog reads — which is what makes the pipeline testable without a database.

### 7.3 Fixing P2

`ImportEngine::processImport()` is rewritten to delegate to the pipeline and to run as a queued job. Its HTTP signature and response shape are preserved so `ImportCenter.tsx` keeps working; the route now returns `202` with a job id it can poll, which is what the existing `ImportProgress.tsx` component is already built to display.

---

## 8. Smart Search Design

### 8.1 Three layers

```
LAYER 3   NATURAL LANGUAGE      "show me experienced Python developers"
             │  AiGateway, constrained decoding → QueryAST JSON.
             │  The model NEVER emits SQL. It emits an AST, or it fails.
             ▼
LAYER 2   QUERY GRAMMAR         skill:python AND experience:>5
             │  Also typeable directly by power users.
             │  Parsed → QueryAST → validated against the attribute
             │  catalog (unknown field = explicit error, not empty result).
             ▼
LAYER 1   EXECUTION             QueryCompiler → SQL
             │  tenant_id injected by the compiler, from the request
             │  context — never from the AST, never from the model.
             │  Targets: hpbrain_search_documents (FULLTEXT)
             │         + hpbrain_attribute_index  (facets)
             │         + relational joins for structural predicates
             ▼
          RESULTS + FACETS + "interpreted as: …" echo of the AST
```

### 8.2 Your examples, resolved

| Query | Layer | Compiles to |
|---|---|---|
| "Show all Java Developers" | 3→2 | `skill:java` → join `person_skills` |
| "Show people with AI skills" | 3→2 | `skill:ai OR skill:"machine learning"` (synonym expansion from the skill taxonomy) |
| "Everyone working under Finance" | 3→2 | `unit:finance` + recursive CTE over `parent_unit_id` — *under*, not *in* |
| "Who knows React?" | 3→2 | `skill:react` |
| "Experienced Python developers" | 3→2 | `skill:python AND experience_years:>=5` (threshold from config, echoed back to the user) |
| "Joined after 2024" | 3→2 | `joining_date:>2024-01-01` |
| "Employees without managers" | 2 | `manager:null` — structural predicate |
| "Duplicate phone numbers" | 1 | Not a search: routed to `DuplicateDetector`, returns pairs |

### 8.3 Why the AST, not SQL generation

Three reasons, in order of severity: **(1)** an LLM emitting SQL against a shared multi-tenant database is a tenant-leak waiting to happen, and `EntityResolver`'s entire fail-closed design exists to prevent exactly that class of bug; **(2)** an AST can be echoed back to the user as "I interpreted this as…", which makes a wrong interpretation visible instead of silently wrong; **(3)** an AST validates against the attribute catalog, so "show me everyone with a `blood_type`" returns *"no such field — did you mean `blood_group`?"* rather than an empty result set that looks like an answer.

`hpbrain_search_documents` is maintained by the same event bus that already exists: entity written → event → indexer updates the document. Import completion triggers a bulk rebuild for the affected entity type.

---

## 9. Command Center / Dashboard Design

The dashboard infrastructure is **already dynamic** — `hpbrain_dashboards`, `_widgets`, `_layouts`, `DashboardBuilder`, `WidgetRegistry.ts`, drag-and-drop `DashboardBuilder.tsx`. What is missing is not the frame; it is the intelligence behind the tiles.

**Keep** the existing Command Center exactly as it is, as the top band. **Add** below it:

```
┌─ COMMAND CENTER ─────────────────────────────────────────────────┐
│                                                                  │
│  ── existing band, unchanged ──────────────────────────────────  │
│  Intelligence Score · Open Decisions · Top Risks · AI activity   │
│                                                                  │
│  ── NEW: ORGANIZATION HEALTH ────────────────────────────────── │
│  ┌────────────┬────────────┬────────────┬────────────┐          │
│  │ Health 92% │ People 1,247│ Depts 34  │ Quality 87%│          │
│  │ ▲2 vs last │ ▲18 (30d)  │ ▲2        │ ▼3         │          │
│  └────────────┴────────────┴────────────┴────────────┘          │
│  Score is always expandable into its four components — never a  │
│  number nobody can decompose. (Same rule AnalyticsController     │
│  already applies to intelligenceScore.)                          │
│                                                                  │
│  ── NEW: TODAY ─────────────────  ── NEW: NEEDS ATTENTION ────── │
│  +18 people                        ⚠ Finance has no manager     │
│  +2 departments                    ⚠ 3 people missing email     │
│  1 import (5,790 rows)             ⚠ 7 duplicate phone numbers  │
│  4 new attributes discovered       ⚠ 12 records unmatched       │
│                                    → each links to the fix      │
│                                                                  │
│  ── NEW: TRENDS ────────────────  ── NEW: RECENT IMPORTS ────── │
│  Headcount · 12 months             fibervalley.complaints  ✓     │
│  Joiners vs leavers                  65,268 rows · 4 new fields  │
│  Growth by department                12 errors → report          │
└──────────────────────────────────────────────────────────────────┘
```

Every new tile is a **widget registered in the existing `WidgetRegistry`**, backed by a metric id, so it is composable, reorderable, and role-filterable by machinery that already works. No tile hardcodes an organization, a department, or a field name — every one resolves through `EntityResolver` or the attribute catalog.

Two rules carried forward from the current implementation because they are correct: shape-normalise every response (a failed endpoint yields a zero tile, never a white screen), and never encode status in colour alone.

---

## 10. API Structure

Additive only. Existing 370 routes untouched. New routes follow the established `/v1/...` + `jwt` + `tenant` + `permission:` convention.

```
── SCHEMA / ATTRIBUTES ─────────────────────────────────────────────
GET    /v1/attributes/{tenantId}                    ?entity_type=&status=
POST   /v1/attributes                               manual definition
PATCH  /v1/attributes/{tenantId}/{id}               approve|reject|edit|promote
GET    /v1/attributes/{tenantId}/proposed           the review queue
POST   /v1/attributes/{tenantId}/{id}/searchable    project into the index
GET    /v1/attributes/{tenantId}/aliases
POST   /v1/attributes/{tenantId}/aliases

GET    /v1/entities/{tenantId}/{type}/{id}/attributes
PUT    /v1/entities/{tenantId}/{type}/{id}/attributes

── INGESTION ───────────────────────────────────────────────────────
GET    /v1/ingestion/sources/{tenantId}
POST   /v1/ingestion/sources
POST   /v1/ingestion/sources/{tenantId}/{id}/test         connectivity
POST   /v1/ingestion/upload                               (exists — kept)
POST   /v1/ingestion/{tenantId}/profile                   stages 1-2 → column profile
POST   /v1/ingestion/{tenantId}/map                       stage 3 → proposed mapping
POST   /v1/ingestion/{tenantId}/map/confirm               human confirmation, learns aliases
POST   /v1/ingestion/{tenantId}/dry-run                   full pipeline, writes nothing
POST   /v1/ingestion/{tenantId}/execute                   → 202 + job id
GET    /v1/ingestion/{tenantId}/runs
GET    /v1/ingestion/{tenantId}/runs/{id}                 live progress
GET    /v1/ingestion/{tenantId}/runs/{id}/report
POST   /v1/ingestion/{tenantId}/runs/{id}/rollback

GET    /v1/ingestion/profiles/{tenantId}                  runtime-editable profiles
POST   /v1/ingestion/profiles

── ORGANIZATION INTELLIGENCE ───────────────────────────────────────
GET    /v1/intelligence/{tenantId}/health                 score + breakdown
GET    /v1/intelligence/{tenantId}/metrics                ?keys=&dimension=&period=
GET    /v1/intelligence/{tenantId}/metrics/{key}/trend
GET    /v1/intelligence/{tenantId}/completeness           ?entity_type=
GET    /v1/intelligence/{tenantId}/distribution/{field}
GET    /v1/intelligence/{tenantId}/duplicates             ?entity_type=&status=
POST   /v1/intelligence/{tenantId}/duplicates/{id}/resolve
GET    /v1/intelligence/{tenantId}/insights               ?severity=&dismissed=
POST   /v1/intelligence/{tenantId}/insights/{id}/dismiss
GET    /v1/intelligence/{tenantId}/today                  the Today tile

── SEARCH ──────────────────────────────────────────────────────────
GET    /v1/search                                         (exists — kept verbatim)
POST   /v1/search/query                                   grammar or AST
POST   /v1/search/natural                                 NL → AST → results + interpretation
GET    /v1/search/{tenantId}/facets                       ?entity_type=
GET    /v1/search/{tenantId}/suggest                      ?q=  typeahead

── GRAPH (extends the existing seam) ───────────────────────────────
GET    /v1/graph/{tenantId}/{label}/{id}                  (exists)
GET    /v1/graph/{tenantId}/{label}/{id}/related          (exists)
GET    /v1/graph/{tenantId}/{label}/{id}/traverse         ?depth=&relations=   NEW
GET    /v1/graph/{tenantId}/hierarchy/{unitId}            NEW, recursive CTE
```

**Response conventions**, matching what the codebase already does well: never invent a value (absent ⇒ `null` or `[]`, never a plausible default); every computed score publishes its components; every rate publishes the `total` it was computed over.

---

## 11. Folder Structure

Additive. Follows the existing `Domain` / `Services` / `Repositories` split exactly — no new conventions introduced.

```
app/
├── Domain/
│   ├── Attributes/                          ◀ NEW
│   │   ├── AttributeDefinition.php               value object
│   │   ├── AttributeCatalog.php                  read model, per-request cached
│   │   ├── AttributeRegistrar.php                create/approve/deprecate
│   │   ├── AttributeValueStore.php               read/write the JSON sidecar
│   │   ├── AttributeIndexer.php                  project → attribute_index
│   │   ├── AttributePromoter.php                 generated column + index
│   │   ├── TypeInferencer.php                    values → data_type + confidence
│   │   ├── PiiDetector.php
│   │   └── AliasResolver.php
│   │
│   ├── Ingestion/                           ◀ EXTENDED (existing port kept)
│   │   ├── DataSource.php                        (exists, unchanged)
│   │   ├── IngestionBatch.php                    (exists, unchanged)
│   │   ├── Sources/
│   │   │   ├── CsvUploadSource.php               (exists)
│   │   │   ├── WorkbookSource.php                ◀ wraps WorkbookImporter
│   │   │   ├── DatabaseSource.php                ◀ NEW
│   │   │   ├── RestApiSource.php                 ◀ NEW
│   │   │   └── WebhookSource.php                 ◀ NEW
│   │   └── Pipeline/                        ◀ NEW — the 8 stages
│   │       ├── IngestionPipeline.php             orchestrator
│   │       ├── SchemaProfiler.php                 stage 2
│   │       ├── FieldMapper.php                    stage 3
│   │       ├── MappingStrategy/                   the 6 strategies, ordered
│   │       ├── SchemaEvolver.php                  stage 4
│   │       ├── ValueNormalizer.php                stage 5
│   │       ├── EntityMatcher.php                  stage 6
│   │       ├── RecordValidator.php                stage 7
│   │       ├── BatchPersister.php                 stage 8
│   │       └── IngestionReport.php
│   │
│   ├── Intelligence/                        ◀ NEW
│   │   ├── MetricRegistry.php
│   │   ├── MetricProvider.php                    interface — one method
│   │   ├── Providers/                            Headcount, Growth, Completeness,
│   │   │                                         Distribution, Ratio, Trend,
│   │   │                                         Allocation, Activity
│   │   ├── HealthScorer.php                      composite + breakdown
│   │   ├── DuplicateDetector.php
│   │   ├── MatchingStrategy/                     Exact, Normalized, Fuzzy, Phonetic
│   │   ├── InsightGenerator.php                  Tier 2 → hpbrain_insights
│   │   └── ChangeDigest.php                      the "Today" tile
│   │
│   ├── Search/                              ◀ NEW
│   │   ├── QueryAst.php
│   │   ├── GrammarParser.php                     layer 2
│   │   ├── NaturalLanguageTranslator.php         layer 3 (uses AiGateway)
│   │   ├── QueryValidator.php                    against the catalog
│   │   ├── QueryCompiler.php                     AST → SQL, injects tenant_id
│   │   ├── SearchIndexer.php
│   │   └── FacetBuilder.php
│   │
│   ├── Universal/     (exists — EntityResolver, untouched)
│   ├── Signals/       (exists — extended with a new rule pack, as data)
│   ├── Ai/            (exists — untouched; new prompts are registry rows)
│   └── …              (all other existing domains untouched)
│
├── Jobs/                                    ◀ NEW — the directory does not exist today
│   ├── RunIngestionPipeline.php
│   ├── RebuildSearchIndex.php
│   ├── ComputeMetricSnapshot.php
│   ├── DetectDuplicates.php
│   └── ProjectAttributeIndex.php
│
├── Repositories/                            ◀ 6 added, all extend BaseRepository
│   ├── AttributeDefinitionRepository.php
│   ├── AttributeAliasRepository.php
│   ├── EntityAttributeRepository.php
│   ├── AttributeIndexRepository.php
│   ├── EntityDuplicateRepository.php
│   ├── InsightRepository.php
│   └── … (57 existing, untouched)
│
├── Http/Controllers/Api/                    ◀ 5 added
│   ├── AttributeController.php
│   ├── EntityAttributeController.php
│   ├── IngestionPipelineController.php
│   ├── OrganizationIntelligenceController.php
│   ├── SmartSearchController.php
│   └── … (60 existing, untouched)
│
└── Services/          (existing; ImportEngine::processImport rewritten to delegate)

web/src/
├── components/
│   ├── schema/                              ◀ NEW
│   │   ├── AttributeCatalog.tsx                  browse/approve discovered fields
│   │   ├── AttributeReviewQueue.tsx
│   │   ├── DynamicFieldRenderer.tsx              renders any attribute by data_type
│   │   └── DynamicForm.tsx                       form generated from the catalog
│   ├── ingestion/                           ◀ NEW (joins existing import/)
│   │   ├── SourceManager.tsx
│   │   ├── ColumnProfiler.tsx                    stage-2 output
│   │   ├── MappingReview.tsx                     stage-3 confirmation
│   │   └── IngestionReport.tsx
│   ├── intelligence/                        ◀ NEW
│   │   ├── HealthScoreCard.tsx
│   │   ├── CompletenessPanel.tsx
│   │   ├── DistributionChart.tsx
│   │   ├── DuplicateReview.tsx
│   │   └── InsightFeed.tsx
│   ├── search/                              ◀ NEW
│   │   ├── SmartSearchBar.tsx
│   │   ├── QueryInterpretation.tsx               "I read this as…"
│   │   └── FacetPanel.tsx
│   └── …  (all existing components untouched)
```

---

## 12. Migration Plan

**Guarantees.** Zero `ALTER` on any existing table. Zero change to any ERP table. Zero change to the login → command center → org → department → people → person path. Every step independently deployable and independently revertable. All 60 existing test files must stay green at every step — that is the acceptance gate, not a nice-to-have.

### Step 0 — Establish facts (before any code)

1. Confirm the actual database server: `SELECT VERSION()`. MySQL 8.x and MariaDB 10.x differ on JSON type and functional indexes (§3, S8). The design works on both, but the *promotion* mechanism (§5.2 item 5) needs the right syntax.
2. Confirm `hpbrain_people` / `hpbrain_departments` are genuinely dead (one reference found; verify against production data volume before treating them as such).
3. Capture a baseline: `php artisan test`, record pass/fail; time the Command Center load; count rows per major table.

### Step 1 — Schema (2 migrations, additive, reversible)

`2026_08_XX_000100_attribute_registry.php` — definitions, aliases, entity_attributes, attribute_index.
`2026_08_XX_000200_intelligence_and_ingestion.php` — ingestion_profiles, ingestion_field_maps, metric_definitions, entity_duplicates, search_documents, insights, entity_relationships, plus the corrected `hpbrain_data_sources` / `hpbrain_ingestion_runs`.

Both follow the established raw-DDL + `hpbrain_` prefix + `VARCHAR(36)` id conventions, with `down()` dropping cleanly. Nothing reads them yet — this step cannot break anything.

### Step 2 — Attribute layer, dark

Repositories + `AttributeCatalog` + `AttributeValueStore` + tests. Not wired into any request path. Existing behaviour provably unchanged because no existing file is edited.

### Step 3 — Read path, additive

`GET /entities/{type}/{id}/attributes` and the `PersonDetails` tab that renders it. If the attribute store is empty, the tab renders "no additional fields" — which is the honest answer, and is exactly what today's behaviour looks like.

### Step 4 — Pipeline, behind a flag

Build stages 2–7 as pure classes with unit tests. Wire `POST /ingestion/{tenantId}/dry-run` — runs everything, writes nothing, returns the full report. **This is the safest possible way to validate the whole engine against the real 65,268-row FiberValley workbook**: run it, compare the proposed mapping against `config/import_profiles.php`'s hand-written one, and require them to agree before the write path is enabled.

### Step 5 — Write path + jobs

`app/Jobs/RunIngestionPipeline`. `ImportEngine::processImport()` delegates to it (P2 fixed). Existing profile-driven imports keep working — `WorkbookSource` wraps the existing importer, and `FiberValleyImportTest` is the regression gate.

### Step 6 — Intelligence

Metric providers + health scorer + duplicate detector, computed on demand first, then snapshotted into the existing `hpbrain_metric_snapshots` by a scheduled job. New signal rules land as `hpbrain_signal_rules` **rows** in a seeder, not as code.

### Step 7 — Search

Indexer, grammar, compiler. New endpoints alongside the existing `/v1/search`, which is not touched. NL translation added last, once the grammar is proven.

### Step 8 — Dashboard

New widgets registered in the existing registry. Command Center's top band is not modified; the new bands are appended.

### Rollback

Each step is one migration + a set of new files. Reverting = `migrate:rollback` one step + revert the commit. Steps 3–8 additionally sit behind feature flags in the **existing** `hpbrain_feature_flags` table, so a bad step can be disabled without a deploy.

---

## 13. Implementation Roadmap

Ordered by dependency and by risk-reduction — each module is independently valuable, so stopping after any of them leaves the system better than before, never half-migrated.

| # | Module | Delivers | Depends on | Risk |
|---|---|---|---|---|
| **0** | Baseline & facts | DB version confirmed, test baseline, perf baseline | — | none |
| **1** | Attribute registry | Zone-4 schema + repositories + catalog, fully tested, unwired | 0 | **none** — nothing reads it |
| **2** | Dynamic read/write | Attributes visible on Person/Department; dynamic form renderer | 1 | low — new tab only |
| **3** | Profiler + mapper | Upload a file → see inferred types + proposed mapping. **Dry-run only.** | 1 | **none** — writes nothing |
| **4** | Schema evolution | Unmapped columns become registered attributes; review queue; alias learning | 3 | low — gated by review |
| **5** | Pipeline write path | Full 8 stages, queued, idempotent, rollback-able. **Fixes P2.** | 4 | medium — regression-gated by `FiberValleyImportTest` |
| **6** | Source unification | Workbook/DB/API/webhook sources on one port; profiles become table rows (P3) | 5 | medium |
| **7** | Metrics & health | Metric registry, health score + breakdown, completeness, distributions, trends, snapshots | 1 | low — all new endpoints |
| **8** | Duplicates & insights | Detector, review queue, insight feed, new signal rules as data | 7 | low |
| **9** | Smart search | Index, grammar, compiler, facets, then NL translation | 1, 7 | medium — NL layer last |
| **10** | Graph extension | Person/Unit/Skill nodes, relationship table, depth-capped traversal, hierarchy | 7 | low |
| **11** | Command Center v2 | New widgets on the existing dashboard machinery | 7, 8, 9 | low |
| **12** | Performance | Pagination, SQL aggregation, caching, index review | 5, 7 | low |

**Suggested order if you want value earliest:** 0 → 1 → 3 → 7 → 2 → 4 → 5 → 8 → 9 → 11 → 6 → 10 → 12.
Module 3 gives a visible, impressive result (upload a file, watch the system understand it) with literally zero write risk. Module 7 lights up the Command Center with real organizational intelligence before any ingestion change ships.

---

## 14. Risks and open questions

**Must be answered before Module 5:**

1. **MySQL 8 or MariaDB?** (§3, S8) Determines the promotion syntax and whether JSON functional indexes are available at all.
2. **Auto-accept or human-confirm for discovered attributes?** Per-tenant policy, but a platform default is needed. Recommendation: `proposed` (human confirms) by default; auto-accept as an explicit opt-in per source, because a mis-typed attribute that silently becomes `active` is very hard to notice and moderately painful to unwind.
3. **Where does Person data of record live going forward?** Discovered attributes attach to `tbluser.id`. If a customer without the institute ERP onboards, `EntityResolver` must map `Person` to something. `hpbrain_people` exists and is dead — reviving it as the fallback source for non-ERP tenants is the obvious move, but it is a decision, not an implementation detail.
4. **Projects / allocation / attendance sources.** You listed "Project Allocation", "Resource Utilisation", "Attendance Trend". There is no project or assignment table today, and attendance exists only as FiberValley operational records. Those three metrics need a data source before they can be more than a placeholder.

**Flagged, lower urgency:**

5. **PII in discovered attributes.** `passport_no` is a passport number. The attribute catalog carries `is_pii`, but masking, audit-on-read, and export restriction need a deliberate policy — the existing `AuditRepository` and `PERMISSION-MATRIX.md` are the right places to hang it.
6. **`web/` is a nested git repository.** `web/.git` exists inside the outer repo. Worth resolving (submodule or merge) before multi-module work spans both.
7. **Host cron.** Three scheduled commands are inert without the `schedule:run` cron entry. Adding queue workers makes this more consequential — a supervised worker process becomes a deployment requirement.

---

## 15. What I am explicitly NOT proposing

Stated so the scope is unambiguous:

- **No rewrite.** ~10,000 lines of PHP stay as they are.
- **No change to `EntityResolver`.** It is the best-designed thing in the codebase.
- **No change to the auth or navigation flow.** Login → Command Center → Organization → Department → People → Person is untouched.
- **No new framework, ORM, or language.** Laravel query builder + repositories, as today.
- **No Neo4j.** ADR-008 stands; the port is extended instead.
- **No Elasticsearch.** MySQL FULLTEXT + the attribute index will carry this to millions of rows. Revisit only with a measurement that says otherwise.
- **No replacement of the AI subsystem.** New capabilities are new prompt-registry rows and new callers of the existing `AiGateway`.
- **No dropping of `config/import_profiles.php`.** Profiles migrate to table rows; the config file remains readable as a seed source until every profile is migrated and verified.

---

## Approval

If this direction is right, the first thing I would build is **Module 0 + Module 1** — the fact-finding pass plus the attribute registry schema and repositories, fully tested and wired to nothing. It cannot break a single existing behaviour, and it is the foundation everything else stands on.

If you want the most visible result first instead, **Module 3** (upload a file, watch the system profile and map it, write nothing) is the demo, and it is equally risk-free.
