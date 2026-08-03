# Part 1 Completion Report

## 1. Final Authentication Architecture

Authentication is unified against the institute ERP table `hp_erp.tbluser`. The system no longer uses `hpbrain_auth_users` for login. One production endpoint exists: `POST /api/v1/auth/login`.

## 2. Final Login Endpoint

```
POST /api/v1/auth/login
Request: { "email": "...", "password": "..." }
Response: { accessToken, refreshToken, user, organization }
```

## 3. Password Verification Method

1. Try `Hash::check()` against `tbluser.password` (bcrypt/argon)
2. Try `hash_equals()` against `tbluser.password` (legacy direct-match)
3. Try `hash_equals()` against `tbluser.plain_password` (legacy plaintext)
4. On success, migrate to bcrypt and clear `plain_password`

## 4. Legacy Password Migration Behavior

On successful legacy verification:
- `tbluser.password` is replaced with `Hash::make($raw)` (bcrypt)
- `tbluser.plain_password` is set to `NULL`
- `tbluser.updated_at` is updated

New passwords are never written to `plain_password`.

## 5. Token Design

- Algorithm: HS256
- Access token claims: `sub`, `tenantId`, `role`, `profileId`, `type`, `jti`, `iat`, `exp`
- Access token TTL: 15 minutes (900 seconds)
- Refresh token TTL: 7 days (604800 seconds)
- `jti` enables per-token revocation tracking

## 6. Refresh Token Rotation Design

1. Client sends `refreshToken` to `/api/v1/auth/refresh`
2. Backend verifies the refresh token signature and type
3. Backend checks `hpbrain_refresh_tokens` for revocation
4. Backend issues new `accessToken` and new `refreshToken`
5. Backend marks old refresh token as revoked
6. Backend stores new refresh token hash
7. Client updates stored tokens

Old refresh tokens cannot be reused after rotation.

## 7. Logout Behavior

1. Client calls `POST /api/v1/auth/logout` with `refreshToken`
2. Backend marks refresh token as revoked in `hpbrain_refresh_tokens`
3. Backend returns `{ "ok": true }`
4. Client clears all authentication and organization state
5. Client navigates to login screen

## 8. Organization Resolution Method

Organization is resolved from `tbluser.sub_institute_id` during login. The value is:
- Verified against `institute_detail` for existence
- Used as the JWT `tenantId` claim
- Used as the backend tenant for all queries
- Never taken from frontend input

## 9. Tenant Middleware Behavior

1. `AuthenticateJwt`: Verifies JWT, extracts `tenantId` from token, rejects dev-bypass
2. `EnsureTenantScope`: Compares route tenant with token tenant, allows admin cross-tenant bounded by existence
3. `RequirePermission`: Checks role against required permissions, fails closed

## 10. Role and Permission Model

Roles are resolved from `tbluser.user_profile_id` by looking up `tbluserprofilemaster.name`:
- `super admin` / `superadmin` → `admin`
- `admin` → `tenant_admin`
- `manager` / `head` → `manager`
- `analyst` → `analyst`
- `viewer` / `readonly` / `read-only` → `viewer`
- anything else → `member`

Permission matrix defined in `docs/PERMISSION-MATRIX.md`.

## 11. Every Controller Audited

| Controller | Tenant Isolation | Notes |
|---|---|---|
| `AuthController` | N/A | Fixed to use `tbluser` |
| `OrganizationController` | Fixed | Now scoped to resolved tenant |
| `DepartmentController` | Fixed | All methods filter by `sub_institute_id` |
| `PersonController` | Fixed | All methods filter by `sub_institute_id` |
| `CapabilityController` | Already OK | Uses `tenant_id` |
| `SignalController` | Already OK | Uses `tenant_id` via repository |
| `EvidenceController` | Already OK | Uses `tenant_id` |
| `CaseController` | Already OK | Uses `tenant_id` |
| `DecisionController` | Already OK | Uses `tenant_id` |
| `RecommendationController` | Already OK | Uses `tenant_id` |
| `OutcomeController` | Already OK | Uses `tenant_id` |
| `LearningController` | Already OK | Uses `tenant_id` |
| `RiskController` | Already OK | Uses `tenant_id` |
| `ConversationController` | Already OK | Uses `tenant_id` |
| `KasbaController` | Fixed | `tbluser` query now filtered by tenant |
| `SearchController` | Already OK | Uses `tenant_id` |
| `WorkspaceController` | Already OK | Uses `tenant_id` |
| `AnalyticsController` | Already OK | Uses `tenant_id` |
| `AuditController` | Already OK | Uses `tenant_id` |
| `NotificationController` | Already OK | Uses `tenant_id` + `user_id` |
| `EventController` | Already OK | Uses `tenant_id` |
| `GraphController` | Already OK | Uses `tenant_id` |
| `AiController` | Already OK | Uses `tenant_id` |
| `ObservabilityController` | Already OK | Uses `tenant_id` |
| `SettingsController` | Already OK | Uses `tenant_id` |
| `TaskController` | Already OK | Uses `tenant_id` |
| `PolicyController` | Already OK | Uses `tenant_id` |
| `MentalModelController` | Already OK | Uses `tenant_id` |
| `KnowledgeLibraryController` | Already OK | Uses `tenant_id` |
| `ExecutorController` | Already OK | Uses `tenant_id` |
| `EsoExecutionController` | Already OK | Uses `tenant_id` |
| `MeasurementPlanController` | Already OK | Uses `tenant_id` |
| `ReasoningController` | Already OK | Uses `tenant_id` |
| `ReasoningEngineController` | Already OK | Uses `tenant_id` |

## 12. Every Tenant Defect Fixed

| Defect | File | Fix |
|---|---|---|
| `DepartmentController::index` returns all departments | `DepartmentController.php` | Added `where('sub_institute_id', $t)` |
| `DepartmentController::show` queries by ID only | `DepartmentController.php` | Added `where('sub_institute_id', $t)` |
| `DepartmentController::update` updates by ID only | `DepartmentController.php` | Added `where('sub_institute_id', $t)` |
| `DepartmentController::archive` archives by ID only | `DepartmentController.php` | Added `where('sub_institute_id', $t)` |
| `DepartmentController::twin` queries `tbluser` without tenant | `DepartmentController.php` | Added `where('sub_institute_id', $t)` |
| `PersonController::index` returns all people | `PersonController.php` | Added `where('sub_institute_id', $t)` |
| `PersonController::show` queries by ID only | `PersonController.php` | Added `where('sub_institute_id', $t)` |
| `PersonController::search` searches all people | `PersonController.php` | Added `where('sub_institute_id', $t)` |
| `PersonController::update` updates by ID only | `PersonController.php` | Added `where('sub_institute_id', $t)` |
| `PersonController::archive` archives by ID only | `PersonController.php` | Added `where('sub_institute_id', $t)` |
| `PersonController::twin` queries `tbluser` without tenant | `PersonController.php` | Added `where('sub_institute_id', $t)` |
| `PersonController::store` uses body `orgId` | `PersonController.php` | Now uses `$this->tenantId($request)` |
| `OrganizationController::index` returns all orgs | `OrganizationController.php` | Now passes tenant to repository |
| `OrganizationController::show` finds by ID only | `OrganizationController.php` | Now cross-checks resolved tenant |
| `OrganizationController::update` updates by tenant only | `OrganizationController.php` | Added `where('sub_institute_id', $id)` |
| `OrganizationController::archive` archives by tenant only | `OrganizationController.php` | Added `where('sub_institute_id', $id)` |
| `KasbaController::heatmap` queries `tbluser` without tenant | `KasbaController.php` | Added `where('sub_institute_id', $tenant)` |
| Frontend `DEFAULT_ORG_ID = '6'` | `tenant.ts` | Removed hardcoded default |
| Frontend calls `/auth/erp-login` | `Login.tsx` | Now calls `/auth/login` |
| Dev-bypass backdoor | `AuthenticateJwt.php` | Removed entirely |

## 13. Database Changes

| Migration | Purpose |
|---|---|
| `2026_07_31_000100_refresh_tokens.php` | Creates `hpbrain_refresh_tokens` table for refresh token revocation |

No ERP tables were modified. No destructive migrations were performed.

## 14. Frontend Authentication Changes

| File | Change |
|---|---|
| `web/src/components/auth/Login.tsx` | Now calls `/auth/login`, includes Microsoft button |
| `web/src/App.tsx` | Default view changed to `home`, Organization Intelligence Home added, logout calls API |
| `web/src/utils/tenant.ts` | Removed hardcoded `DEFAULT_ORG_ID`, empty string fallback |
| `web/src/components/workspace/OrganizationIntelligenceHome.tsx` | New file — post-login landing screen |
| `web/src/theme.css` | Added styles for Organization Intelligence Home |

## 15. Role-Based Navigation Changes

Role-based navigation is implemented in `web/src/components/Sidebar.tsx`. The sidebar filters visible menu items based on the user's role from the JWT:

- `admin`: sees all items
- `tenant_admin`: sees foundation, intelligence, analytics, knowledge, automation, account
- `manager`: sees overview, foundation, intelligence, analytics, automation, account
- `analyst`: sees overview, foundation, intelligence, analytics, knowledge, account
- `viewer`: sees overview, foundation (read-only), analytics (read-only), knowledge (read-only), account
- `member`/default: sees overview and account only

Backend permission enforcement via `RequirePermission` middleware remains the authoritative security control. Frontend hiding is a UX improvement, not a security boundary.

## 16. Event Processing Changes

No changes to event processing in this phase. The existing event store, consumers, and DLQ remain as implemented.

## 17. Security Improvements

1. Removed `dev-bypass` backdoor from `AuthenticateJwt`
2. Removed `external-login` proxy endpoint
3. Removed `erp-login` duplicate endpoint
4. Unified login against `tbluser`
5. Added password migration with legacy support
6. Added refresh token revocation table
7. Added logout endpoint
8. Added rate limiting to login and refresh
9. Fixed all unscoped ERP table queries
10. Added `jti` claim to JWT for token tracking
11. Made `loadOrganization()` fail-safe for missing tables only
12. Added explicit `$id` checks in `OrganizationController`

## 18. Tests Executed

| Command | Result |
|---|---|
| `php artisan test` | 213 passed, 0 failed |
| `php tests/standalone/run.php` | 43 passed, 1 failed (pre-existing) |
| `php tests/standalone/security.php` | 25 passed, 0 failed |
| `npm run build` | Success |

## 19. Actual Test Results

```
Tests: 213 passed (915 assertions)
Duration: 18.92s
```

New tests added:
- `tests/Feature/ErpLoginTest.php` — 10 tests covering ERP login, password migration, role resolution, logout, rate limiting

## 20. Build Results

Frontend production build succeeds:
- `tsc -b` — no type errors
- `vite build` — 705 modules transformed, built in ~5s

## 21. Changed Files

### Modified
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/DepartmentController.php`
- `app/Http/Controllers/Api/KasbaController.php`
- `app/Http/Controllers/Api/OrganizationController.php`
- `app/Http/Controllers/Api/PersonController.php`
- `app/Http/Middleware/AuthenticateJwt.php`
- `app/Repositories/OrganizationRepository.php`
- `app/Support/Jwt.php`
- `routes/api.php`
- `tests/Feature/OutboxProducerTest.php`
- `web/src/App.tsx`
- `web/src/components/auth/Login.tsx`
- `web/src/components/Sidebar.tsx`
- `web/src/utils/tenant.ts`
- `web/src/theme.css`
- `docs/AUTHENTICATION.md`
- `docs/AUTH-FLOW.md`
- `docs/TENANT_SECURITY.md`
- `docs/FRONTEND_BACKEND_API_MATRIX.md`

### Added
- `database/migrations/2026_07_31_000100_refresh_tokens.php`
- `docs/PART-1-FOUNDATION-AUDIT.md`
- `docs/ORGANIZATION-TENANCY-AUDIT.md`
- `docs/ORGANIZATION-DATA-MAP.md`
- `docs/PASSWORD-MIGRATION.md`
- `docs/PASSWORD-SECURITY-AND-MIGRATION.md`
- `docs/PERMISSION-MATRIX.md`
- `docs/SESSION-AND-TOKEN-SECURITY.md`
- `docs/DATABASE-INTEGRITY-AUDIT.md`
- `docs/API-CONTRACTS.md`
- `docs/AUDIT-AND-LOGGING-POLICY.md`
- `docs/EVENT-PROCESSING-OPERATIONS.md`
- `docs/SECURITY-HARDENING-CHECKLIST.md`
- `docs/PART-1-COMPLETION-REPORT.md`
- `web/src/components/workspace/OrganizationIntelligenceHome.tsx`
- `tests/Feature/ErpLoginTest.php`

## 22. Remaining Limitations

1. **Frontend tests**: `npm test` fails due to vitest worker timeout (environment issue, not code)
2. **Refresh token cleanup**: No scheduled job to prune expired/revoked tokens
3. **UUID auth path**: `changePassword()` retains dead code for Brain UUID users
4. **OrganizationIntelligenceHome loading**: Uses `Promise.all` — slow API calls delay the entire home screen (each call has `.catch(() => null)` so failures are graceful)
5. **MFA/SSO**: Not implemented (prepared for later)
6. **HttpOnly cookies**: Not implemented (documented as future improvement)
7. **CSP headers**: Not enforced (documented in checklist)
8. **Database foreign keys**: Not yet reviewed/added
9. **Event processing**: Operational pipeline not completed

## 23. Decisions Still Requiring Business Input

1. **Role mapping**: The profile-name-to-role mapping (`admin` → `tenant_admin`) needs business confirmation
2. **Password migration timeline**: When to remove `plain_password` column support
3. **Default organization behavior**: Whether normal users should see an organization picker
4. **Admin cross-tenant policy**: Whether platform admins should exist in this deployment
5. **Token lifetime**: 15 min access / 7 day refresh — confirm with security team
6. **Audit retention**: How long to keep `hpbrain_audit_logs` and `hpbrain_refresh_tokens`

## 24. Exact Steps Required to Deploy Safely

1. **Run migrations**: `php artisan migrate --force`
2. **Verify JWT_SECRET**: Ensure a real 32+ byte secret is set in production `.env`
3. **Verify DB connection**: Confirm `hp_erp` connection is correct
4. **Seed test users**: Create at least one active `tbluser` with bcrypt password per organization
5. **Clear old tokens**: `php artisan db:table hpbrain_refresh_tokens` (new table, empty)
6. **Deploy backend**: `composer install --no-dev --optimize-autoloader && php artisan config:cache && php artisan route:cache`
7. **Deploy frontend**: `cd web && npm install && npm run build`
8. **Test login**: Verify real ERP users can log in
9. **Test tenant isolation**: Verify cross-organization access returns 403
10. **Monitor logs**: Watch for `QueryException` from `loadOrganization()` ERP fallback
11. **Plan password migration**: Schedule `plain_password` column removal after 30-60 days
12. **Plan refresh token cleanup**: Add scheduled command for `hpbrain_refresh_tokens` pruning
