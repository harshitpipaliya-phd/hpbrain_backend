# Start Here — Windows / PowerShell

Your backend is Laravel. Your frontend is React. They talk over REST.

## Fastest path

```powershell
cd C:\Users\omshivay\Desktop\ADK\hp-enterprise-brain
.\setup.ps1
```

That script checks your PHP version and extensions, installs dependencies,
generates the app key, boots Laravel, resolves routes, migrates and seeds — and
stops at the first real failure instead of ploughing on.

If PowerShell blocks the script:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\setup.ps1
```

## Or run it by hand, one line at a time

PowerShell does not accept `&&`. Use one command per line.

```powershell
composer install
php artisan key:generate
php artisan route:list
php artisan migrate
php artisan db:seed
php artisan serve
```

Then in a second PowerShell window:

```powershell
cd C:\Users\omshivay\Desktop\ADK\hp-enterprise-brain\web
npm install
npm run dev
```

Open http://localhost:5173

## Your database

`.env` is already filled in for `hp_erp` on your company server. Nothing to
configure.

The Brain **shares** that database with your ERP. Every table it creates is
`hpbrain_`-prefixed, so migrations cannot collide with `institute_detail`,
`org_details`, `hrms_departments`, `tbluser` or `tbluserprofilemaster`.
Organization, Department and Person are **read** from those ERP tables — the
Brain does not own them and never writes to them destructively.

**Do not run the dev fixtures against this server.** They create stand-ins for
ERP tables that already exist there with real data. The loader refuses non-local
hosts anyway.

## Neo4j is not needed

ADR-008 defers it. MySQL is the only datastore. Login does not depend on a graph
database, so you can ignore the Neo4j credentials that were in your old Node
`.env`.

## If something fails

| Symptom | Cause | Fix |
|---|---|---|
| `php not recognized` | PHP not on PATH | Install PHP 8.2+, add to PATH, reopen PowerShell |
| `could not find driver` | `pdo_mysql` disabled | In `php.ini`, uncomment `extension=pdo_mysql`, reopen PowerShell |
| `composer not recognized` | Composer not installed | getcomposer.org → Windows installer |
| `The token '&&' is not a valid statement separator` | PowerShell | One command per line, or use `;` |
| `SQLSTATE[HY000] [2002]` | Cannot reach the DB host | Check network access to 202.47.117.220:3306 |
| `Access denied for user` | Wrong credentials | Check `DB_USERNAME` / `DB_PASSWORD` in `.env` |
| Migration fails on an existing table | Partially migrated already | `php artisan migrate:status` to see where it stopped |

Paste the exact error if you get stuck.

## What this build is

- **149 API endpoints** — all 137 frontend calls have a matching route
- **34 controllers**, 16 repositories, 39 migrations
- **JWT auth** with tenant isolation and a role/permission model
- **69 assertions passing** in `tests/standalone/` — these run without Laravel:

```powershell
php tests\standalone\run.php
php tests\standalone\security.php
```

Run those two right now if you want proof the logic works before installing
anything.

## Honest status

The code is fully Laravel — no Express, no Node backend. But `composer install`
has never run in the environment where this was built, so **no route has ever
served a request.** `php artisan route:list` is the first real proof, which is
why `setup.ps1` runs it before touching your database.

Expect some first-boot errors. That is normal and usually quick to fix.

Full detail: `docs/VERIFICATION_REPORT.md` and `docs/KNOWN_LIMITATIONS.md`.
