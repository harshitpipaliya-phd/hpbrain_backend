# Laravel Port — Status

**Decisions:** [ADR-006](adr/ADR-006-relational-store-mysql.md) MySQL ·
[ADR-007](adr/ADR-007-laravel-runtime.md) Laravel, full cutover, Query Builder ·
[ADR-008](adr/ADR-008-defer-neo4j.md) Neo4j deferred

Read this before writing code, so you know what has been *verified* and what has
only been *written*.

---

## The constraint on this build

`repo.packagist.org` is unreachable from the environment this was produced in,
so **`composer install` has never run and Laravel is not installed here.**

**Verified:**
- `php -l` clean on all 99 PHP files (PHP 8.3.6)
- Domain logic: **26/26 assertions pass** via `php tests/standalone/run.php`,
  which needs no framework
- Confidence and freshness outputs are **byte-identical to the TypeScript
  original**

**Not verified:** that the app boots, that routes resolve, that `artisan
migrate` runs, that middleware binds, that controllers return the right shapes
against a real database. All of that needs `composer install` on a machine with
packagist access, then a live-database run.

---

## Built

| Area | Files | State |
|---|---|---|
| Plumbing | `artisan`, `bootstrap/app.php`, `public/index.php` | Written |
| Config | app, database, cors, cache, queue, logging, **brain** | Written |
| Migrations | **38** | Converted, syntax-clean, DDL verbatim |
| Domain services | Evidence, Reasoning, Recommendation, Case, Learning, Policy, KASBA | **Unit-verified** |
| Repositories | 16 (Query Builder, tenant-scoped) | Written |
| Controllers | 24 | Written |
| Routes | 77 endpoints | Written |
| Auth | JWT + tenant-scope middleware | Written |
| Seeder | `LoopSeeder` — walks all 13 stages | Written |
| Tests | `tests/Unit` (PHPUnit) + `tests/standalone` (no framework) | **44 passing** |
| Carried over | `contracts/`, `web/` React SPA | Unchanged |

### Equivalence proof

```
confidence   TS  [0.3, 0.43, 0.37, 0.35, 0.75, 0.42, 0.95]
             PHP [0.3, 0.43, 0.37, 0.35, 0.75, 0.42, 0.95]
freshness    TS  [1, 0.707107, 0.5, 0.25, 0.060139]
             PHP [1, 0.707107, 0.5, 0.25, 0.060139]
```

`0.43` is the value the live Node system produced during its verified seed run.

### Bugs the harness caught during the port

1. `PolicyService` used Laravel's `data_get()`, which made a domain rule
   untestable without booting the framework. Replaced with a hand-rolled path
   resolver — a policy engine that authorizes execution should not depend on a
   helper whose semantics can shift under it.
2. The generated repositories called `scoped()` on INSERT, which appends a
   WHERE clause to an insert. Corrected to `DB::table()`.

---

## Not yet built

**~26 of 45 Express route groups have no Laravel equivalent.** Missing:
`accreditation`, `ai`, `analytics`, `api-keys`, `audit`, `career`,
`context-library`, `conversations`, `eso-efficacy`, `eso-library`, `events`,
`graph`, `guardians`, `knowledge-library`, `mental-models`, `notifications`,
`observability`, `placement`, `process-library`, `reasoning-engine`,
`reasoning-pattern-library`, `risks`, `settings`, `tasks`, `telemetry`,
`tenants`.

Also outstanding:
- **Event backbone** — outbox, dead-letter queue, consumer state. Laravel queues
  map well, but idempotency keys must carry over.
- **Feature tests** — the 266 Node assertions covered service wiring. Only
  domain logic is covered here so far.
- **Live-database verification.** The seven defects in the Node build's
  `VERIFICATION_REPORT.md` were *all* found by running against real MySQL, and
  none were visible to a passing test suite. Passing in PHP proves nothing until
  the same run happens here.

---

## Suggested order from here

1. `composer install`, `php artisan migrate` against a real MySQL — expect
   defects, that is the point
2. `php artisan db:seed`, then curl the endpoints and diff the JSON against the
   Node build's responses
3. Point `web/` at the Laravel host and confirm every screen still renders
4. Port the remaining route groups, highest-traffic first
5. Event backbone
6. Re-issue `VERIFICATION_REPORT.md` against Laravel

## Architecture invariants — now closed

All four were absent from the Node build and from the first Laravel scaffold.

- **`UNDETERMINED`** — `app/Domain/Undetermined/VerbResult.php` and
  `SufficiencyCheck.php`. A first-class return type carrying named gaps,
  returned at HTTP 200 because an honest "I don't know" is a successful
  response. 9 assertions passing.
- **Six-state capability model** — `app/Domain/Capability/CapabilityState.php`
  plus a backfill migration. Advance requires an evidence reference; silent
  regression throws; Behaviour/Attitude use Observed. Legacy 0–5 levels are
  mapped conservatively, never deleted. 9 assertions passing.
- **Seven-verb Capability Interface** — `app/Domain/Verbs/Verb.php` and
  `VerbPipeline.php`. The fixed four-step pipeline: governance → grounding →
  versioned reasoning → guardrail. EXECUTE is dark.
- **Memory grounding** — `app/Domain/Learning/MemoryGrounding.php`.
  `retrieveFor()`, `recordGrounding()` and `compoundingStats()` exist.
  **Still to do:** call `retrieveFor()` from the grounding step so the flywheel
  actually turns. That last wire is the highest-value remaining work.
