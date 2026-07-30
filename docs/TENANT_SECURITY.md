# Tenant Security

## The rule

**Tenant identity comes from the JWT claim. Never from the URL, body, query
string or a header.**

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

## Repository enforcement

`BaseRepository::scoped(string $tenantId)` is the only query entry point, and it
always applies `where('tenant_id', ...)`. Structural enforcement beats review
discipline: a developer cannot forget the filter because there is no unscoped
builder to reach for.

One deliberate exception, documented because it looks like a bug otherwise:
`insert()` uses `DB::table()` directly rather than `scoped()`, because a WHERE
clause on an INSERT is meaningless and silently no-ops on some drivers. The
`tenant_id` column is still set explicitly on every inserted row.

## ERP-owned tables

`institute_detail`, `org_details`, `hrms_departments`, `tbluser` and
`tbluserprofilemaster` are owned by the institute ERP, keyed by
`sub_institute_id`, and are read-mostly. They do not carry `tenant_id`. Access is
scoped by institute, not by Brain tenant. Migrations never create, drop or alter
them.

## Test coverage

`php tests/standalone/security.php` — runs without Laravel. Covers the five
cases required by the brief plus fail-closed behaviour:

1. A user can read their own tenant's records
2. A user cannot read another tenant's records (403)
3. A user cannot update another tenant's records (403)
4. A user cannot delete another tenant's records (403)
5. Changing the request tenant id does not bypass the check
6. Missing or blank token tenant denies (401) rather than defaulting
7. Scope never adopts a URL-supplied value

## Known gap

These assertions test the resolution rule, not the wired HTTP stack, because
Laravel has never booted here. Re-run as Feature tests once `composer install`
succeeds. Until then this is a proven *rule* with an unproven *wiring*.
