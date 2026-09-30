# Operations runbook — the intelligence loop, the scheduler, and safe verification

Written 2026-09-29 for the V1 pilot. It describes what the repository schedules, what it deliberately does
not, how to prove a **host** is actually running the schedule (the repository cannot), and how to run the
tests and a browser walkthrough without touching shared data.

> **Read this first.** `routes/console.php` *defines* a schedule. Nothing runs it unless the host calls
> `php artisan schedule:run` every minute. A schedule definition existing is not evidence that detection,
> case opening or learning is happening.

---

## 1. The loop, step by step

| # | Step | Command / route | Who triggers it | Idempotent | Spends on a model provider | Tenant scope |
|---|---|---|---|---|---|---|
| 1 | Data in | `POST /ingestion/*`, `POST /imports/*`, `brain:import*` | Person (admin) | Yes (content hash) | No | per-tenant |
| 2 | Detect signals | `brain:detect` | **Scheduler**, hourly `:10` | Yes (refreshes an open signal, never duplicates) | No | all tenants, or `--tenant=` |
| 3 | Open cases | `brain:open-cases` | **Scheduler**, hourly `:15` | Yes (a signal with a case is skipped) | No | `--tenant=`, `--limit=50` |
| 4 | Propose hypotheses | `brain:propose-hypotheses` | **Scheduler**, hourly `:20` | Yes (a case with a non-rejected hypothesis is skipped) | No | `--tenant=`, `--limit=50` |
| 5 | Warm intelligence caches | `intelligence:warm` (`:25`), `operations:warm` (`:40`) | **Scheduler** | Yes (fingerprint cached) | No | all tenants |
| 6 | Reason + recommend | `brain:reason-signals`, or a person via the Reasoning/Assistant screens | **Manual only** | **No** — a second run spends again on the same signals | **Yes** | `--tenant=`, `--signal=`, `--limit=20` |
| 7 | Propose a decision | `POST /decisions` | Person (`create`) | n/a | No | token tenant |
| 8 | Approve a decision | `POST /decisions/{t}/{id}/approve` | **Person (`decision.approve`), never the proposer** | Yes | No | token tenant |
| 9 | Measurement plan, then run | `POST /measurement-plans`, `POST /eso-executions` | Person (`eso.execute`), human executor only | Plan is required first (422 otherwise) | No | token tenant |
| 10 | Record an outcome | `POST /outcomes` | Person | validated (approved decision + cited evidence) | No | token tenant |
| 11 | Learning + memory | `brain:process-events --once` | **Scheduler**, every minute | Yes (uuid5, replay-safe) | No | all tenants |
| 12 | Snapshot metrics | `brain:snapshot` | **Scheduler**, daily `02:00` | Yes (idempotent within a day) | No | all tenants |

**Order matters.** Detection produces the signals cases are opened for; a hypothesis hangs off a case; the
warm-ups run over what those steps wrote. The `:10 → :15 → :20 → :25 → :40` spacing is intentional and is
asserted by `tests/Feature/SchedulerDefinitionTest.php`.

**Learning takes three consumer passes.** `OutcomeRecorded` → (pass 1) `LearningWritten` → (pass 2)
`MemoryUpdated` → (pass 3). With the consumer scheduled every minute, expect the learning row about one to
three minutes after an outcome is recorded — not instantly. Observed on 2026-09-29 in an isolated run.

### Deliberately NOT scheduled (pinned by `SchedulerDefinitionTest`)

| Command | Why it stays manual |
|---|---|
| `brain:reason-signals` | Buys a provider call per signal on **every** run. Paid AI is an explicit operator or user act, never a timer. |
| `brain:compute-eso-efficacy` | Appends a new `hpbrain_eso_efficacy_records` row on every run; not idempotent. |
| `brain:dedupe-signals` | Destructive under `--apply`; dry-run by default. |
| `events:process` | Legacy consumer. It marks event types it does not know — including `OutcomeRecorded` — as completed, which would **skip learning**. Never run it by hand. |
| `school:seed-v1-academy` and other seeders | Write demo data. |

### Human gates that no scheduled job bypasses

Approval requires `decision.approve` and a person other than the proposer (409 `self_approval_forbidden`).
An execution requires an approved decision **and** a measurement plan created before it (422
`measurement_plan_required`). Only a human executor is accepted; EXECUTE is dark. The AI & Intelligence
console can no longer accept a recommendation on its own: `accepted` requires an already-approved canonical
decision (409 `decision_required` otherwise).

### Paid AI is explicit

Reading a page never calls a provider. `GET /organization-intelligence/*` serves a cached interpretation or
reports `interpretation_not_generated`. Generating one is `POST /organization-intelligence/{tenant}/interpretation`
(`create`), cached per data version and single-flight. `POST /ai/evidence/summarize` needs `create` and is
idempotent for identical evidence. A Viewer cannot cause provider spend.

---

## 2. Verify the host is running the scheduler

The repository cannot do this for you. On the **server that serves production**:

**Linux**

```bash
crontab -l -u <app-user> | grep "schedule:run"
# expected:  * * * * * cd /path/to/hp-enterprise-brain && php artisan schedule:run >> /dev/null 2>&1
```

**Windows (Task Scheduler)**

```powershell
Get-ScheduledTask | Where-Object { ($_.Actions | ForEach-Object { "$($_.Execute) $($_.Arguments)" }) -match 'schedule:run' } | Format-List TaskName,State
# create one if missing (run as the app's service account):
schtasks /Create /SC MINUTE /MO 1 /TN "hp-brain-schedule" /TR "cmd /c cd /d C:\path\to\hp-enterprise-brain && php artisan schedule:run" /RU <account>
```

On 2026-09-29 the developer machine used for the pilot-readiness work had **no** such task.

**Prove it is really firing** (not just configured), against the target database:

```bash
php artisan schedule:list                         # the definition: 7 entries, :10 :15 :20 :25 :40 hourly, every minute, 02:00
# an event should move pending -> completed within a couple of minutes of being written:
#   SELECT type, status, created_at FROM hpbrain_event_store ORDER BY created_at DESC LIMIT 20;
# a healthy consumer leaves no old pending rows:
#   SELECT COUNT(*) FROM hpbrain_event_store WHERE status='pending' AND created_at < NOW() - INTERVAL 10 MINUTE;
# dead letters need a human:
#   SELECT COUNT(*) FROM hpbrain_dead_letter_queue;
# detection and case opening keep pace:
#   SELECT MAX(created_date) FROM hpbrain_signals;   SELECT MAX(created_date) FROM hpbrain_cases;
```

A scheduler that stopped is invisible in the app: signals stop arriving, cases stop opening, and learning
never lands — with no error. Alert on the pending-age query.

---

## 3. Running the steps by hand

```bash
php artisan brain:detect --tenant=<id>
php artisan brain:open-cases --tenant=<id> --dry-run       # report, write nothing
php artisan brain:open-cases --tenant=<id>
php artisan brain:propose-hypotheses --tenant=<id> --dry-run
php artisan brain:propose-hypotheses --tenant=<id>
php artisan brain:process-events --once                    # or --tenant=<id> --type=OutcomeRecorded
# PAID — only deliberately, with a small limit, after checking the provider and quota:
php artisan brain:reason-signals --tenant=<id> --limit=5
```

Always pass `--tenant` when working on one organization, and `--dry-run` first where it exists.

---

## 4. Running the tests without side effects

`phpunit.xml` pins an in-memory SQLite database, so no test touches the shared MariaDB. Two things it does
**not** isolate:

1. **The `file` cache and `storage/`.** Some code uses `Cache::store('file')` and the ingestion tests write
   uploads into `storage/app/ingestion/…`. Point a run at a throwaway storage directory:

   ```bash
   mkdir -p /tmp/ts/app /tmp/ts/framework/{cache/data,sessions,views,testing} /tmp/ts/logs
   LARAVEL_STORAGE_PATH=/tmp/ts vendor/bin/phpunit
   ```

2. **The `zip` PHP extension.** XLSX import (Fiber Valley) needs it and `composer.json` does not require it;
   without it 22 `FiberValleyImportTest` cases fail with *"ext-zip is required to read .xlsx files"*.
   `php artisan test` starts a child PHP that drops `-d` flags, so run PHPUnit directly to try it without
   editing `php.ini`:

   ```bash
   php -d extension=zip vendor/phpunit/phpunit/phpunit
   ```

   To enable it permanently on the XAMPP install used here, edit `C:\xampp\php\php.ini`, change
   `;extension=zip` to `extension=zip`, and restart the web server / terminal. `php_zip.dll` is already in
   `C:\xampp\php\ext`. **This was not done** — it is a machine-level change.

CI (`.github/workflows/backend.yml`, `web/.github/workflows/frontend.yml`) installs `zip` explicitly and runs
against SQLite with no secrets.

---

## 5. An isolated browser walkthrough

Never point a walkthrough at the shared database. What worked on 2026-09-29, and the traps:

1. Create a **throwaway SQLite file**, build the schema with the test helpers (`Tests\Support\BuildsBrainSchema`,
   `BuildsAiIntelligenceSchema`, `BuildsErpFixture`, `SeedsEntityMappings`) and seed disposable users, a signal,
   its evidence, a case, and a recommendation whose `dependencies` cite the evidence id.
2. Serve the API with `php -S 127.0.0.1:8100 -t public <router.php>` where the router sets the environment
   **before** Laravel boots. Set `DB_CONNECTION=sqlite`, `DB_DATABASE=<file>`, and point `DB_HOST` at a closed port
   so an accidental fall-through to `.env` fails fast. Leave `AI_PROVIDER` and every provider key empty so no
   paid call is possible.
3. **Trap:** the PHP built-in server does not populate `$_ENV`, so `LARAVEL_STORAGE_PATH` set in the shell is
   ignored and the server silently uses the repository's own `storage/` (cache and logs). Set it in the router
   script (`$_ENV`/`$_SERVER`) instead.
4. Start the SPA with `VITE_API_URL=http://127.0.0.1:8100 npx vite --port 5173`. Drive it with Playwright
   (Chrome is installed; `playwright-core` with `executablePath` needs no browser download).
5. Run the consumer by hand (`brain:process-events --once`, three times) to stand in for the scheduler.

Result of the run (analyst, manager, viewer against seeded data) is recorded in
`docs/V1_PRODUCT_BLUEPRINT.md` §31.

---

## 6. Housekeeping the repository cannot do for you

* **Test artifacts.** Earlier test runs left three untracked files in `storage/app/ingestion/4/`
  (`3e792d4ec31e45375d0b468f748a5f68.xls`, `623dfd2cb35473027fee6a1376521398.csv`,
  `a6ec630d225b3c99c7958d96cdb15900.markdown`). They are inert; delete them when convenient
  (`git status --short storage/` lists them). Running the suite with `LARAVEL_STORAGE_PATH` (above) stops new ones.
* **Log size.** `storage/logs/laravel.log` was about 1.07 GB with `LOG_LEVEL=debug` and no rotation. Use the
  `daily` channel and a sane level in production.
* **`setup.ps1` migrates and seeds whatever `.env` points at** — the shared remote database on this project.
  Do not run it against that connection.
