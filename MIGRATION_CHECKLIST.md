# Migration Checklist

`NOT STARTED` · `IN PROGRESS` · `IMPLEMENTED` · `TESTED` · `LIVE DB VERIFIED` · `BLOCKED`

## Environment

| Item | Status | Note |
|---|---|---|
| `composer install` | **BLOCKED** | All packagist hosts return 403 from this environment. 54 of 71 packages resolvable from GitHub; 17 are not, so VCS-only resolution also fails. |
| `php artisan about` | **BLOCKED** | requires `vendor/` |
| `php artisan route:list` | **BLOCKED** | requires `vendor/` |
| `php artisan migrate:fresh --seed` | **BLOCKED** | requires `vendor/` |
| `php artisan test` | **BLOCKED** | requires `vendor/` |
| `php -l` on every file | **IMPLEMENTED** | 118 files, 0 syntax errors, PHP 8.3.6 |
| Domain harness | **TESTED** | `tests/standalone/run.php` — 44 assertions |
| Security harness | **TESTED** | `tests/standalone/security.php` — 25 assertions |

## Frontend contract

| Item | Status |
|---|---|
| Frontend API calls discovered | 137 |
| Laravel endpoints declared | 149 |
| **Matched** | **IMPLEMENTED — 137 / 137** |
| Would 404 | 0 |
| HTTP method mismatches found and fixed | 2 (`cases` and `eso-executions` transitions were POST, frontend uses PATCH) |
| React frontend modified | **No** — unchanged |

## Architecture invariants

| Invariant | Source | Status |
|---|---|---|
| `UNDETERMINED` first-class result | Invariant 3 / ADR-004 | **TESTED** |
| Six-state capability model | Invariant 6 | **TESTED** |
| Confidence computed, never asserted | Product Bible | **TESTED** — identical to the TypeScript original |
| Seven-verb Capability Interface | ADR-004 | IMPLEMENTED — EXECUTE dark |
| Memory grounding / learning loop | ADR-005 | IMPLEMENTED — not yet called from reasoning |
| Measurement plan before execution | Invariant 4 | IMPLEMENTED |
| Evidence provenance on capability writes | ADR-003 | IMPLEMENTED — advance requires `evidenceRef` |

## Security

| Item | Status |
|---|---|
| JWT auth (access + refresh, type-checked) | IMPLEMENTED |
| Auth rate limiting | IMPLEMENTED — `throttle:10,1` |
| Dev-token disabled in production | IMPLEMENTED |
| Tenant scope from token only | **TESTED** — 10 assertions |
| Role/permission model | **TESTED** — 15 assertions |
| Permission guards on sensitive routes | IMPLEMENTED — 10 routes |
| Feature-level (HTTP) security tests | **NOT STARTED** — needs a booted Laravel |

## Remaining work

| Item | Status |
|---|---|
| Event consumers (outbox drain) | **NOT STARTED** — events written but never processed |
| Wire `MemoryGrounding::retrieveFor()` into reasoning | **NOT STARTED** — last mile of ADR-005 |
| AI provider clients | **NOT STARTED** — endpoints return `UNDETERMINED`, never fabricate |
| Form Request classes | **NOT STARTED** — validation is inline |
| Feature/HTTP test suite | **BLOCKED** on `composer install` |
| Live MySQL verification | **BLOCKED** on `composer install` |
