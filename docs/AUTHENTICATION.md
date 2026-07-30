# Authentication and Authorization

## Token flow

```
POST /api/v1/auth/login      {tenantId, email, password}
  -> verify against hpbrain_auth_users (hashed at rest)
  -> 200 {accessToken, refreshToken, user}

Authorization: Bearer <accessToken>   on every subsequent request
POST /api/v1/auth/refresh    {refreshToken} -> new accessToken
POST /api/v1/auth/change-password          (authenticated)
```

- Algorithm HS256; access token 15 minutes, refresh token 7 days.
- Claims: `sub`, `tenantId`, `role`, `type`, `iat`, `exp`.
- `type` is checked: a refresh token cannot be used as an access token.
- Login and refresh are rate limited (`throttle:10,1`). An unlimited login
  endpoint is the cheapest attack surface a token API can expose.
- Login returns the same `invalid_credentials` response for an unknown email and
  a wrong password, so the endpoint cannot be used to enumerate accounts.
- `Jwt::secret()` throws in production when the secret is empty or the dev
  placeholder. Refusing to boot is correct — a signing key that everyone knows
  is worse than downtime.
- `POST /api/v1/auth/dev-token` is registered only when
  `APP_ENV !== 'production'`.

Identity lives in MySQL (`hpbrain_auth_users`). An earlier Node build resolved
users through Neo4j, which meant nobody could sign in without a reachable graph
database — every screen rendered empty for want of a token. Keeping identity
relational removes that failure mode.

## Authorization

Authentication answers *who are you*. Authorization answers *may you do this*.
The build previously conflated them: any authenticated user could approve
decisions, execute ESOs and rotate keys.

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

Covered by `php tests/standalone/security.php` (25 assertions).

## Not implemented

- Logout / token revocation. Access tokens are short-lived and stateless; true
  revocation needs a denylist and is not built.
- Per-record ownership checks beyond tenant scope.
- Disabled-user handling: `hpbrain_auth_users` has no active flag, so a
  deactivated user keeps a valid token until it expires.
