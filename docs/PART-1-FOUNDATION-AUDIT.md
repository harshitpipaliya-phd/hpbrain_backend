# Part 1 Foundation Audit

## Current Authentication Methods

| Method | Endpoint | Status | Issues |
|---|---|---|---|
| `POST /api/v1/auth/login` | `AuthController::login` | Uses `hpbrain_auth_users` | Requires `tenantId` in body |
| `POST /api/v1/auth/external-login` | `AuthController::externalLogin` | Proxies to external HP API | Should be removed |
| `POST /api/v1/auth/erp-login` | `AuthController::erpLogin` | Uses `tbluser` | Current frontend uses this |
| `POST /api/v1/auth/dev-token` | `AuthController::devToken` | Dev only | Backdoor, must be removed |
| `POST /api/v1/auth/refresh` | `AuthController::refresh` | Token-based | OK |
| `POST /api/v1/auth/change-password` | `AuthController::changePassword` | Authenticated | OK |

## Current Login Endpoints

| Endpoint | Method | Controller | Tenant Scoped | Issues |
|---|---|---|---|---|
| `/api/v1/auth/login` | POST | AuthController | N/A | Uses `hpbrain_auth_users` |
| `/api/v1/auth/refresh` | POST | AuthController | N/A | Token-based, OK |
| `/api/v1/auth/external-login` | POST | AuthController | N/A | External proxy |
| `/api/v1/auth/erp-login` | POST | AuthController | N/A | Uses `tbluser` |
| `/api/v1/auth/dev-token` | POST | AuthController | N/A | Dev only |

## Current Token Implementation

- Algorithm: HS256
- Access token TTL: 15 minutes (900 seconds)
- Refresh token TTL: 7 days (604800 seconds)
- Claims: `sub`, `tenantId`, `role`, `type`, `iat`, `exp`
- No `jti` claim (no unique token ID)
- No refresh token revocation mechanism
- No refresh token rotation

## Current User Source Tables

| Table | Purpose | Issues |
|---|---|---|
| `hpbrain_auth_users` | Brain authentication | Parallel to `tbluser`, creates identity divergence |
| `tbluser` | ERP user table | Real system of record, contains password columns |
| `tbluserprofilemaster` | User profiles | Used for role resolution |

## Current Organization Source

| Table | Purpose | Issues |
|---|---|---|
| `institute_detail` | ERP organizations | Read-only for Brain |
| `org_details` | ERP organization details | Read-only for Brain |
| `hpbrain_organizations` | Brain organization metadata | Duplicates ERP data |

## Current Role and Permission System

- Roles defined in `app/Domain/Authorization/Role.php` enum
- Permissions defined in `app/Domain/Authorization/Permission.php` enum
- Role resolution: currently hardcoded from `hpbrain_auth_users.role`
- No connection to ERP `tbluserprofilemaster`
- Middleware: `RequirePermission`

## Current Tenant Middleware

| Middleware | Purpose | Issues |
|---|---|---|
| `AuthenticateJwt` | JWT verification | Contains `dev-bypass` backdoor |
| `EnsureTenantScope` | Tenant isolation | Allows admin cross-tenant |
| `RequirePermission` | Authorization | Works correctly |

## Controllers Missing Tenant Isolation

| Controller | Method | Issue |
|---|---|---|
| `DepartmentController` | `index` | No `sub_institute_id` filter |
| `DepartmentController` | `show` | No `sub_institute_id` filter |
| `DepartmentController` | `update` | No `sub_institute_id` filter |
| `DepartmentController` | `archive` | No `sub_institute_id` filter |
| `PersonController` | `index` | No `sub_institute_id` filter |
| `PersonController` | `show` | No `sub_institute_id` filter |
| `PersonController` | `search` | No `sub_institute_id` filter |
| `PersonController` | `update` | No `sub_institute_id` filter |
| `PersonController` | `archive` | No `sub_institute_id` filter |
| `OrganizationController` | `index` | Returns ALL organizations |
| `OrganizationController` | `show` | No tenant verification |

## Frontend Authentication Flow

- `web/src/components/auth/Login.tsx` — calls `/auth/erp-login`
- `web/src/App.tsx` — auth gate, decides between Login and Dashboard
- `web/src/api/client.ts` — axios instance with token refresh
- `web/src/utils/tenant.ts` — tenant helpers, hardcoded `DEFAULT_ORG_ID = '6'`

## Frontend Organization State

- `selectedOrgId` in localStorage
- `hpbrain-user` in localStorage
- No clearing between different user logins
- Stale org IDs can survive into another user's session

## Existing Test Coverage

| Test File | Purpose | Count |
|---|---|---|
| `tests/Feature/ApiAuthorizationTest.php` | Auth, tenant, permission gates | 42 tests |
| `tests/Feature/TenantIsolationMatrixTest.php` | Cross-tenant isolation sweep | 9 tests |
| `tests/Feature/SecurityMatrixTest.php` | Permission matrix over routes | 27 tests |
| `tests/standalone/security.php` | Domain logic security | 25 assertions |
| `tests/standalone/run.php` | Domain logic | 43 assertions |

## Security Risks

1. **Dev-bypass backdoor**: `AuthenticateJwt` accepts `dev-bypass` token in non-production
2. **Unscoped ERP queries**: Department and Person controllers return all records
3. **Plaintext passwords**: `tbluser.plain_password` is checked during login
4. **No logout**: No token revocation mechanism
5. **No refresh rotation**: Refresh tokens can be reused indefinitely
6. **Frontend trusts org IDs**: URL tenant segments are not verified against token
7. **Hardcoded default org**: `DEFAULT_ORG_ID = '6'` in frontend
8. **Three login endpoints**: Credential, ERP, and external proxy
9. **No password migration**: Legacy formats are checked but never upgraded
10. **Parallel auth tables**: `hpbrain_auth_users` and `tbluser` can diverge

## Duplicate or Obsolete Code

1. `AuthController::login()` — uses `hpbrain_auth_users`, should be replaced
2. `AuthController::externalLogin()` — proxies to external API, obsolete
3. `AuthController::erpLogin()` — uses `tbluser`, should become the unified login
4. `AuthController::devToken()` — dev backdoor, must be removed
5. `AuthenticateJwt::dev-bypass` handling — security backdoor
6. `hpbrain_auth_users` table — parallel identity store

## Database Assumptions Requiring Verification

1. `tbluser.password` contains bcrypt hashes, legacy hashes, or plaintext
2. `tbluser.plain_password` may contain plaintext passwords
3. `tbluser.sub_institute_id` is the organization identifier
4. `tbluser.user_profile_id` maps to `tbluserprofilemaster.id`
5. `tbluserprofilemaster.name` contains role-like strings
6. `institute_detail.sub_institute_id` is the ERP organization key
7. `org_details.sub_institute_id` links to `institute_detail`
8. `hrms_departments.sub_institute_id` links to organization
9. ERP tables have `deleted_at` for soft deletes
10. ERP tables have `status` field (1 = active)
