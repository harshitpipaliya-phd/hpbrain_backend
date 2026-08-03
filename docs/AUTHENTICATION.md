# Authentication and Authorization

## Token flow

```
POST /api/v1/auth/login      {email, password}
  -> verify against hp_erp.tbluser (status=1, deleted_at IS NULL)
  -> resolve organization from sub_institute_id
  -> resolve role from tbluserprofilemaster
  -> 200 {accessToken, refreshToken, user, organization}

Authorization: Bearer <accessToken>   on every subsequent request
POST /api/v1/auth/refresh    {refreshToken} -> new accessToken
POST /api/v1/auth/logout     {refreshToken} -> revoke refresh token
POST /api/v1/auth/change-password          (authenticated)
```

- Algorithm HS256; access token 15 minutes, refresh token 7 days.
- Claims: `sub`, `tenantId`, `role`, `type`, `jti`, `iat`, `exp`.
- `type` is checked: a refresh token cannot be used as an access token.
- Login is rate limited (`throttle:10,1`). Refresh is rate limited (`throttle:20,1`).
- Login returns the same `invalid_credentials` response for an unknown email and
  a wrong password, so the endpoint cannot be used to enumerate accounts.
- `Jwt::secret()` throws in production when the secret is empty or the dev
  placeholder. Refusing to boot is correct — a signing key that everyone knows
  is worse than downtime.

## Identity source

Identity lives in the institute ERP table `tbluser`, not in `hpbrain_auth_users`.
The ERP table is the system of record for employees. On successful login:

1. The user's `sub_institute_id` becomes the JWT `tenantId`.
2. The user's `user_profile_id` is resolved against `tbluserprofilemaster` to
   determine the Brain role (`admin`, `tenant_admin`, `manager`, `analyst`,
   `viewer`, `member`).
3. The organization name and logo are read from `institute_detail` and
   `org_details`.

`hpbrain_auth_users` is no longer used for authentication. It may be removed in
a future migration once all references are cleared.

## Password migration

The ERP `tbluser.password` column may contain:
- Laravel bcrypt/argon hashes (preferred)
- Legacy direct-match values
- Plaintext values in `tbluser.plain_password`

The login controller checks all three formats for backward compatibility.
On a successful legacy verification, the password is immediately re-hashed with
bcrypt and `plain_password` is cleared. New passwords are never stored in plain
text. See `docs/PASSWORD-MIGRATION.md`.

## Authorization

Authentication answers *who are you*. Authorization answers *may you do this*.

| Role | Grants |
|---|---|
| `viewer` | read |
| `analyst` | read, create, update, evidence.curate |
| `manager` | analyst + decision.approve, eso.execute |
| `admin` / `tenant_admin` | all permissions |

The Analyst deliberately cannot approve or execute. That persona exists to keep
the Brain honest — validating diagnoses and curating evidence — and separating
scrutiny from action is the point of the role.

Applied as route middleware:

```php
Route::post('decisions/{tenantId}/{id}/approve', ...)
    ->middleware('permission:decision.approve');
```

`RequirePermission` **fails closed**: an unrecognised role or an unrecognised
permission string denies. A typo in a route must never silently grant access.

## Tenant isolation

Tenant identity comes from the verified JWT claim, never from the URL, body,
query string or a header. `EnsureTenantScope` reads `auth.tenantId` and writes
the effective tenant to the request attributes. Controllers call
`$this->tenantId($request)` and never read a tenant from user input.

A non-admin is pinned to the tenant in their token. An admin may address any
organization that exists in the ERP, bounded by `EnsureTenantScope`'s existence
check against `institute_detail`.

## Refresh token revocation

Logout revokes the refresh token by recording its `jti` in
`hpbrain_refresh_tokens` with a non-null `revoked_at`. The refresh endpoint
checks this table before issuing a new access token. Access tokens remain
short-lived and stateless.

## Not implemented

- Per-record ownership checks beyond tenant scope.
- HTTP-only cookie storage for tokens (localStorage is used for now).
