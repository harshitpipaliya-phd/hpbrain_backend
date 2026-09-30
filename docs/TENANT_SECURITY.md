# Tenant Security

## The rule

**Tenant identity comes from the verified JWT claim. Never from the URL, body,
query string or a header.**

`EnsureTenantScope` reads `auth.tenantId`, which `AuthenticateJwt` set from the
verified token. If the request also carries a `{tenantId}` URL segment and it
disagrees with the token, the request is refused with 403 — not silently
coerced to the token value, because a mismatch means the caller believes
something false about their own scope and should be told.

```
Authorization: Bearer <jwt>   ->  AuthenticateJwt  ->  auth.tenantId (trusted)
GET /api/v1/signals/{tenantId} ->  EnsureTenantScope -> compare, 403 on mismatch
                                                      -> request.tenantId (trusted)
```

Controllers call `$this->tenantId($request)`, which returns only the trusted
value. No controller reads a tenant from user input.

## No admin cross-tenant exception

An earlier design let an `admin` address any live organization from a token that
named another. **That exception has been retired.** `EnsureTenantScope` compares the
route `{tenantId}` with the token's tenant claim and returns 403 `tenant_mismatch`
on any difference, for every role, whether or not the named organization exists.
An operator who must work in another organization signs in to it.

The tenant is decided in the middleware and written to the request attributes, so
controllers keep reading `$this->tenantId($request)` and cannot be handed a tenant by
the client any other way. `ApiAuthorizationTest` pins this for `admin` and
`tenant_admin` (section 3b).

## ERP-owned tables

`institute_detail`, `org_details`, `hrms_departments`, `tbluser` and
`tbluserprofilemaster` are owned by the institute ERP, keyed by
`sub_institute_id`, and are read-mostly. They do not carry `tenant_id`. Access is
scoped by institute, not by Brain tenant. Migrations never create, drop or alter
them.

## Critical tenant-leak fixes

The following ERP queries were previously unscoped and have been fixed:

- `DepartmentController::index` — now filters `hrms_departments` by `sub_institute_id`
- `DepartmentController::show` — now requires both `id` AND `sub_institute_id`
- `DepartmentController::update` — now requires both `id` AND `sub_institute_id`
- `DepartmentController::archive` — now requires both `id` AND `sub_institute_id`
- `DepartmentController::twin` — now filters `tbluser` by `sub_institute_id`
- `PersonController::index` — now filters `tbluser` by `sub_institute_id`
- `PersonController::show` — now requires both `id` AND `sub_institute_id`
- `PersonController::search` — now filters `tbluser` by `sub_institute_id`
- `PersonController::update` — now requires both `id` AND `sub_institute_id`
- `PersonController::archive` — now requires both `id` AND `sub_institute_id`
- `PersonController::twin` — now filters `tbluser` by `sub_institute_id`
- `OrganizationController::index` — now returns only the resolved organization
- `OrganizationController::show` — now verifies the org belongs to the resolved tenant
- `KasbaController::heatmap` — now filters `tbluser` by `sub_institute_id`

## Repository enforcement

`BaseRepository::scoped(string $tenantId)` is the only query entry point for
Brain-owned tables, and it always applies `where('tenant_id', ...)`.

One deliberate exception: `insert()` uses `DB::table()` directly rather than
`scoped()`, because a WHERE clause on an INSERT is meaningless and silently
no-ops on some drivers. The `tenant_id` column is still set explicitly on every
inserted row.

## Test coverage

`php tests/standalone/security.php` — runs without Laravel. Covers the five
cases required by the brief plus fail-closed behaviour.

`tests/Feature/TenantIsolationMatrixTest.php` — sweeps every tenant-scoped
route, proving that no pinned role can read or write another tenant's data.

`tests/Feature/ErpLoginTest.php` — proves the unified ERP login, password
migration, role resolution, logout revocation, and rate limiting.

## Known gaps

- These assertions test the resolution rule and the wired HTTP stack through
  Feature tests. The ERP tables (`tbluser`, `hrms_departments`) are live
  production data; integration tests against them run only against the real
  database.
- Per-record ownership checks beyond tenant scope are not implemented.
