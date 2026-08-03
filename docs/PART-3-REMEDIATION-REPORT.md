# Part 3 Remediation Report

**Date:** 2026-08-02
**Branch:** `module-9-contract-ci`
**Scope:** verification and repair of Prompts 3.1–3.3. **No work was done on Prompts 3.4–3.10.**

---

## 1. Why this document exists

The instruction for Part 3 is *"Complete and verify each phase before moving to the next."*

Prompts 3.1, 3.2 and 3.3 had already been implemented in this working tree and each had a
completion report claiming success. `docs/PART-3-3-COMPLETION-REPORT.md` stated:

> All new AI tests must pass alongside existing 259 tests.
> …
> All existing tests pass.

That claim was not true and could not have been true. The application did not boot. The first
command run against this tree was:

```
Fatal error: Cannot redeclare App\Domain\Ai\AiGateway::estimateCost()
  in app/Domain/Ai/AiGateway.php on line 196
```

A PHP fatal error at class-load time means **zero** tests executed — not "most passed". The
completion reports for 3.1–3.3 were written without running the suite they describe.

Verifying phase 3 therefore had to start by making phases 1–3 actually run. This report records
what was wrong and what was changed. It supersedes the test-results sections of
`PART-3-1-COMPLETION-REPORT.md`, `PART-3-2-COMPLETION-REPORT.md` and
`PART-3-3-COMPLETION-REPORT.md`.

---

## 2. Verified state after remediation

Every gate below was executed and its exit code captured. These are real results, not intentions.

| Gate | Command | Result |
| --- | --- | --- |
| Backend tests | `php vendor/bin/phpunit` | **307 passed**, 1126 assertions, exit 0 |
| Route resolution | `php artisan brain:check-routes` | **363 routes**, all resolve, exit 0 |
| Frontend typecheck | `npx tsc -b` | **0 errors**, exit 0 |
| Frontend build | `npm run build` | **built**, exit 0 |
| Frontend tests | `npm test` | **70 passed** (16 files), exit 0 |
| OpenAPI contract | `php artisan brain:openapi` + `npm run generate` | regenerated, **idempotent** on re-run |

Starting point for comparison: the suite could not load at all. Once it loaded, 14 tests were
failing (9 errors, 5 failures).

---

## 3. Defects found and fixed

### 3.1 Fatal: duplicate method declaration

`AiGateway` had two `estimateCost()` methods — the Part 2 original (`private`, nullable, returns
`null` for unpriced models) and a Part 3.3 addition (`public`, returns `0.0`). PHP refuses to load
the class.

The 3.3 version had **no callers anywhere in the codebase**; it was dead code. Removed it and kept
the original, whose null-vs-zero distinction the cost dashboard depends on: `0.0` asserts a call
was free, which understates spend, while `null` says the model is not priced.

- `app/Domain/Ai/AiGateway.php`

### 3.2 Production blocker: `DB::unprepaired()` — 28 occurrences in 13 migrations

`unprepaired` is not a method on the `DB` facade. Every one of these throws `BadMethodCallException`
when reached.

Two of them are `CREATE TABLE` statements:

- `2026_01_01_003200_reasoning_pattern_library.php` → `hpbrain_reasoning_patterns`
- `2026_01_01_003300_eso_definition_library.php` → `hpbrain_eso_definitions`

**Those two tables would never be created on a fresh database.** The rest are `CREATE INDEX` and
`ALTER TABLE` statements, so a fresh migration run would have aborted partway through regardless.
This means the full migration set had never been run successfully end-to-end against an empty MySQL
schema.

Fixed all 28 (`unprepaired` → `unprepared`) across 13 files.

### 3.3 Six route groups unreachable — missing imports

`routes/api.php` referenced `AiProviderController`, `AiPromptTemplateController`,
`AiEvaluationController`, `AiFeedbackController`, `AiQuotaController` and `AiWorkspaceController`
without `use` statements. With no namespace declaration in the file, `Foo::class` resolves to the
global `\Foo`, so all **35 Part 3.3 AI endpoints** pointed at classes that do not exist and would
have been fatal on first request.

`RouteResolutionTest` caught this — the test was doing its job; the result was simply never looked at.

- `routes/api.php`

### 3.4 Test fixture missing 20 tables

`tests/Support/BuildsBrainSchema.php` hand-mirrors the MySQL migrations onto SQLite (the migrations
are raw MySQL DDL and cannot run on SQLite — see that file's header). Twenty tables referenced by
`app/` were never added to it, including `hpbrain_ai_executions` — the execution ledger `AiGateway`
writes on *every* call.

The Part 3.3 services ground answers against people, capabilities and policies rather than the loop
tables the original fixture covered, so they errored with `no such table` the moment a test touched
them.

Added all 20, columns copied from their migrations: people, capabilities (+versions, assignments,
tasks, proficiency), metrics, policies, executors, risks, conversation sessions/messages, prompt
templates, notifications, settings, ai_executions, knowledge_assets, job_role_capability_requirements,
guardians, refresh_tokens.

Also removed the duplicate local copies of `hpbrain_ai_executions` and `hpbrain_prompt_templates`
from `AiProviderTest`, which now conflicted with the shared trait. The trait is the single source of
truth by design.

- `tests/Support/BuildsBrainSchema.php`, `tests/Feature/AiProviderTest.php`

### 3.5 `DB::raw('UUID()')` with `insertGetId()` — 11 sites, 9 files

Part 3.3 generated primary keys with MySQL's `UUID()` function. Two independent bugs:

1. **`UUID()` does not exist on SQLite**, so no test could reach any of this code.
2. **`insertGetId()` returns the auto-increment value.** These keys are `VARCHAR(36)`, so there is no
   auto-increment — every "created" response returned id **`"0"`**. `EvaluationService::createDataset()`
   handed back id `0`, and `runEvaluation()` could then never find the dataset it had just written.

Replaced all with `Ramsey\Uuid\Uuid::uuid4()` generated in PHP, matching what `AiGateway` already did,
and returned the real id.

- `AiWorkspaceService`, `AiFeedbackService`, `AiAuditService`, `EvaluationService`, `EventConsumer`,
  `AiEvaluationController`, `AiPromptTemplateController`, `AiProviderController`, `AiQuotaController`

### 3.6 Tenant isolation hole in the AI Workspace

`AiWorkspaceService` was the worst of the Part 3.3 code:

- `sendMessage()` **never wrote `tenant_id`** (a `NOT NULL` column).
- `getConversationHistory()` filtered on `session_id` only, with **no tenant filter at all** —
  anyone holding a session UUID could read another tenant's transcript.
- The controller's `messages`/`send`/`history` took a `$tenantId` parameter that the routes
  (`workspace/sessions/{sessionId}/...`) never supply, so Laravel bound the session id to
  `$tenantId` and left `$sessionId` unfilled. Every method failed on dispatch.
- `AiWorkspaceController::store` was routed but did not exist.

Rewrote both. The tenant now comes from the authenticated token, never the URL; every query filters
on it; a session belonging to another tenant returns 404 rather than 403, so a response cannot
confirm that an id exists elsewhere. Added `store`. Added regression tests for cross-tenant append,
cross-tenant read, and real-UUID session ids.

- `app/Services/AiWorkspaceService.php`, `app/Http/Controllers/Api/AiWorkspaceController.php`,
  `tests/Feature/AiWorkspaceTest.php`

### 3.7 Prompt-injection detector defeated by one word

`SafetyService::checkPromptInjection()` used `str_contains()` against fixed phrases. It caught
`"ignore previous instructions"` but not `"ignore all previous instructions"` — any filler word
between verb and object defeated the entire check.

Replaced with anchored regexes tolerant of intervening words, keeping stable canonical pattern names
so logs and dashboards can group occurrences. Added a `reveal system prompt` pattern.

This remains a cheap lexical screen and is documented as such: it is not a substitute for the
structural defences (untrusted content in a separate role, retrieved text never concatenated into
the system prompt, tool calls permission- and tenant-checked independently).

- `app/Services/SafetyService.php`

### 3.8 AI spend silently unmetered

`QuotaService::recordUsage()` incremented a row that had to already exist. No such row exists until
an admin visits the quota screen — so consumption on every unconfigured feature (i.e. all of them,
for every new tenant) hit **zero rows and vanished**. Spend that is never written down cannot be
governed later.

`recordUsage()` now creates a metering row on first use. `limit_value = 0` means "meter, do not cap",
and `check()` honours that distinction — reading 0 as a hard ceiling would have made the first call
to any feature also the last.

`$cost` is accepted but deliberately not persisted here; `hpbrain_ai_quotas` has no cost column and
the authoritative per-call spend already lands in `hpbrain_ai_executions.estimated_cost_usd`. A second
independently-maintained total would drift from the first.

- `app/Services/QuotaService.php`

### 3.9 Frontend: 19 API modules bypassed authentication entirely

`web/src/api/client.ts` is documented as the single HTTP entry point — *"Every api/\*.ts module goes
through request(), so headers and auth are configured in exactly one place."*

**All 19 API modules added in Parts 3.1–3.3 ignored it** and called `fetch()` directly. Consequences:

- **No `Authorization` header** — every request 401s against the real API.
- Hardcoded same-origin `/api/v1`, ignoring `VITE_API_URL` — broken in any split-origin deployment.
- No 401 token refresh, no error unwrapping, no snake→camel aliasing.

Every Part 3.1–3.3 admin and AI screen was non-functional against a live backend. Migrated all 19
onto `request()`. `AiWorkspace.tsx` had the same defect inline and was rewritten; it also opened a
new session per message, so the thread on screen was never the thread on the server.

### 3.10 Frontend typecheck: 37 errors

The build was red. Fixed:

- `NavigationItem` type imported by `NavigationItem.tsx` was never declared — added it to
  `api/navigation.ts`.
- `StateRenderer` spread optional prop bags into components with required props; added honest
  defaults (an empty state with no message, or a stale banner whose Refresh does nothing, is a dead
  end for the user).
- `ConfidenceIndicator` rendered `bg-{color}-500` as a **literal string** — the bar never changed
  colour. Replaced with whole class names (Tailwind cannot see runtime-assembled classes) and added
  `role="meter"` with ARIA values.
- 26 vestigial `tenantId` parameters marked `_`-unused rather than removed, to avoid breaking every
  call site; 5 unused destructured props dropped.

### 3.11 Stale frontend tests (11 failures, pre-existing)

Both failing files asserted behaviour the product had deliberately changed, and neither was caused by
this work:

- `sidebar.component.test.tsx` (5) — `Sidebar` gained role-based filtering and a required `userRole`
  prop. Omitting it fell through to the `member` role, which sees only two items, so assertions
  failed with a confusing "element not found". Tests now pass the role explicitly, plus a new case
  proving the filter actually filters.
- `tenant.test.ts` (6) — asserted a hardcoded fallback tenant of `'6'` that `tenant.ts` had removed.
  **The removal is correct and was kept.** `'6'` is a real tenant (Scholar Clone); falling back to it
  means an unauthenticated or malformed-token request quietly addresses a real organization's data.
  An empty tenant produces a visibly failed request. Tests updated to the safer contract, with the
  reasoning recorded so nobody "fixes" it back.

### 3.12 Other latent bugs fixed in passing

- `EventConsumer` inserted into `hpbrain_notifications` without `type`, a `NOT NULL` column with no
  default — both insert paths would fail on MySQL. Added it.
- `EvaluationService::runEvaluation()` updated by id with no tenant filter (the read was scoped, the
  write was not). Scoped it.
- Composer classmap was stale (`optimize-autoloader` is on), hiding newly added classes.
  `composer dump-autoload` run.

---

## 4. Contract regeneration

`contracts/openapi/hpbrain.openapi.yaml` was stale against `routes/api.php` — the CI `contracts` job
(`npm run generate && git diff --exit-code`) was **already failing on this branch** before any change
here, because Parts 3.1–3.3 added routes and never regenerated.

Regenerated. The diff is overwhelmingly additive (the 3.1–3.3 endpoints); `/auth/dev-token` is
correctly dropped, as that route no longer exists. Generation was verified **idempotent** — running
it twice produces byte-identical output — so the gate will pass once these artifacts are committed.

---

## 5. Known issues NOT fixed

Stated plainly rather than left to be discovered:

1. **`web/` is not in version control.** `.gitignore:67` excludes `/web`, and `git ls-files web/`
   returns nothing. The CI workflow already documents it as *"an orphan nested git repo pointing at a
   different remote"* and skips the frontend job on a normal checkout. **All frontend work in this
   session — 19 API modules, 7 components, 2 test files — is therefore untracked and unbacked-up.**
   This is the single highest-risk item in the repository and needs a decision before more frontend
   work happens.

2. **Laravel Pint fails on 305 files.** Pre-existing; Pint has evidently never been run. The dominant
   fixers are `binary_operator_spaces` (194), `line_ending` (100) and `single_line_empty_body` (96).
   CI does **not** run Pint, so this is not a gate. Not auto-fixed here: rewriting 305 files,
   including line endings, would bury the substantive changes above in an unreviewable diff.

3. **83 PHPUnit deprecations.** Mostly doc-comment metadata (`/** @test */`) rather than attributes.
   Not failing; will matter at PHPUnit 12.

4. **Part 3.3 functionality remains shallow**, as its own report admitted. `regenerate`, `explain`
   and follow-up-question generation are not wired to the model pipeline. They now return an explicit
   `not_implemented` / empty result instead of the previous canned strings
   (`"This response was generated based on the available context."`, `"Can you elaborate on that?"`),
   which were indistinguishable from real model output to the UI and to a user.

5. **Evaluation is simulated.** `EvaluationService::runEvaluation()` marks every case `passed` with
   status `simulated`. It measures nothing. Left as-is and flagged: this is a Prompt 3.3 deliverable
   that is not really delivered.

---

## 6. Status of Prompts 3.1–3.10

| Prompt | Claimed | Actual |
| --- | --- | --- |
| 3.1 Universal Platform Foundation | complete | **implemented, now verified.** Tables, configs, terminology, flags, navigation, dashboards, versioning present; tests pass. Frontend was non-functional until §3.9. |
| 3.2 Universal Organization Engine | complete | **implemented, now verified.** Onboarding, templates, inheritance, import present; tests pass. Import rollback and async processing are shallow. |
| 3.3 Universal AI Brain | complete | **partially implemented.** Structure is real; several services were non-functional (§3.5–3.8) and are now fixed. Evaluation is simulated; regenerate/explain/follow-up are stubs. RAG lacks a document-ingestion pipeline. |
| 3.4 Universal Knowledge Graph | — | **not started** |
| 3.5 Human Productivity Intelligence | — | **not started** |
| 3.6 Agentic AI Platform | — | **not started** |
| 3.7 Universal Workflow and Automation | — | **not started** |
| 3.8 Universal Analytics Platform | — | **not started** |
| 3.9 Enterprise SaaS / Marketplace | — | **not started** |
| 3.10 Global Enterprise Production Platform | — | **not started** |

Production readiness is **not** claimed for any phase. Per the Part 3.10 instruction — *"Do not claim
production readiness unless every mandatory test and operational check was actually completed"* — the
mandatory operational checks (load tests, DR drills, restore testing, penetration-test readiness,
golden flows across five industries) have not been run.

---

## 7. Recommended sequencing

1. **Resolve `web/` version control first.** Everything else is at risk until the SPA is tracked.
2. **Run the migrations against a clean MySQL 8 schema.** §3.2 proves this has never succeeded. Until
   it does, no statement about the production schema is trustworthy — and the SQLite fixture is a
   hand-maintained model of a database nobody has built.
3. **Decide whether Prompt 3.3 is done.** Simulated evaluation and stubbed regenerate/explain do not
   meet its own definition of done ("grounded, cited, auditable"). Either finish it or record the gap
   explicitly before building 3.4–3.6 on top of it.
4. Only then proceed to 3.4.

Each of Prompts 3.4–3.10 is a substantial programme in its own right — a knowledge graph with
ontology and sync, an agent platform with approvals and budgets, a workflow engine with a visual
builder, a metadata-driven analytics platform, a licensing and plugin marketplace, and a full
production/DR/compliance programme. Attempting them in sequence without the above resolved would
repeat the pattern this report documents: code that exists, reports that claim success, and a suite
that was never run.
