# HP Enterprise Brain — Laravel

Organizational Intelligence & Execution System.
**Laravel 11 · PHP 8.2+ · MySQL 8.** No Node, no PostgreSQL, no Neo4j.

Decisions: [ADR-006](adr/ADR-006-relational-store-mysql.md) MySQL ·
[ADR-007](adr/ADR-007-laravel-runtime.md) Laravel ·
[ADR-008](adr/ADR-008-defer-neo4j.md) Neo4j deferred

**Read [PORT_STATUS.md](PORT_STATUS.md) before writing code** — it states exactly
what has been verified and what has only been written.

## Where the data comes from

The Brain is an intelligence layer **above** the institute's systems of record.
It does not own its people data:

| Entity | Source table | Owner |
|---|---|---|
| Organization | `institute_detail`, `org_details` | Institute ERP |
| Department | `hrms_departments` | Institute ERP |
| Person | `tbluser`, `tbluserprofilemaster` | Institute ERP |

Everything the Brain reasons *with* — signals, evidence, cases, hypotheses,
reasoning, recommendations, decisions, ESOs, outcomes, learnings, capability
proficiency — lives in `hpbrain_*` tables owned by `database/migrations/`.

Migrations only ever create `hpbrain_`-prefixed tables, so they cannot collide
with existing ERP tables.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# set DB_* and JWT_SECRET
php artisan migrate
php artisan db:seed          # walks the full loop with real data
php artisan serve            # :8000
```

Frontend (unchanged React SPA):

```bash
cd web && npm install && npm run dev    # :5173
```

## Verify the domain logic

```bash
php tests/standalone/run.php     # no framework needed — 26 assertions
php artisan test                 # after composer install
```

## Four rules that must not regress

Each cost real debugging in the Node build:

1. **Tables are `hpbrain_`-prefixed.** The Brain shares a database with the ERP.
2. **Never `NUMERIC` without precision.** MySQL reads it as `DECIMAL(10,0)` and
   rounds every confidence score to an integer.
3. **Never send RFC-3339 to a DATETIME column.** MySQL rejects `...T...Z`
   (error 1292). Use `BaseRepository::now()`.
4. **Never judge "already seeded" on organizations, departments or people.**
   They come from the ERP and are always present. Use Brain-owned data.

## Layout

```
app/Domain/<Context>/     business rules, framework-independent, unit-tested
app/Repositories/         Query Builder data access, tenant-scoped
app/Http/Controllers/Api/ REST layer — shapes must match web/src/api/*.ts
adr/                      architecture decision records
contracts/                ESO + taxonomy schemas — the source of truth
database/migrations/      38 migrations, MySQL DDL preserved verbatim
web/                      React SPA, carried over unchanged
```

## Running the stack

Backend (Laravel):

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve            # :8000
php artisan queue:work       # event/queue processing
php artisan test
```

Frontend (React + TypeScript + Vite — **unchanged, do not convert to Blade**):

```bash
cd web
npm install
npm run dev                  # :5173
```

Laravel is the backend. React is the frontend. They communicate over REST at
`/api/v1`. The React SPA is the official frontend of this application and must
not be replaced with Blade, Livewire, Inertia, Vue or Angular.

## Production deployment

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Set `APP_DEBUG=false`, a freshly generated `JWT_SECRET`
(`openssl rand -hex 32`), and a real `CORS_ORIGIN`. The dev-token route is
registered only when `APP_ENV !== 'production'`.

## Documentation

| File | Contents |
|---|---|
| `docs/BACKEND_AUDIT.md` | Full inventory and risk register |
| `docs/FRONTEND_BACKEND_API_MATRIX.md` | Every frontend call vs its Laravel route |
| `docs/VERIFICATION_REPORT.md` | What is tested vs merely written |
| `docs/KNOWN_LIMITATIONS.md` | Ranked list of what is missing |
| `MIGRATION_CHECKLIST.md` | Live status per group and per invariant |
| `docs/postman/` | Collection + environment template (no secrets) |
| `adr/` | ADR-001…008 |
