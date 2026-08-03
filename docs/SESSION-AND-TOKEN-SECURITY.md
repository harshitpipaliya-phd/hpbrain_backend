# Session and Token Security

## Token Storage

### Current Implementation
Tokens are stored in `localStorage`:
- `accessToken` — short-lived bearer token
- `refreshToken` — longer-lived refresh token
- `selectedOrgId` — selected organization ID
- `hpbrain-user` — remembered user info

### Risks of localStorage
- Accessible to any JavaScript on the page (XSS risk)
- Not automatically sent with requests (good)
- Persists across tabs
- Cleared only by explicit logout or browser clear

### Interim Mitigations
1. **Short access token lifetime**: 15 minutes limits exposure
2. **Refresh token rotation**: Old refresh tokens are invalidated after use
3. **Refresh token revocation**: Logout revokes the refresh token
4. **Content Security Policy**: Restrict script sources
5. **XSS protection**: Input encoding, output escaping
6. **No token logging**: Tokens are never written to logs
7. **Session clearing on logout**: All tokens and state are removed

### Production Recommendation
For future hardening:
- Move refresh token to `HttpOnly`, `SameSite=Strict` cookie
- Store access token in memory only (lost on tab close)
- Implement token binding to client characteristics

## Token Design

### Access Token
```json
{
  "sub": "user-id",
  "tenantId": "organization-id",
  "role": "organization_admin",
  "profileId": "2",
  "type": "access",
  "jti": "unique-token-id",
  "iat": 0,
  "exp": 0
}
```
- Lifetime: 15 minutes
- Contains no sensitive personal data
- `jti` enables revocation tracking

### Refresh Token
- Lifetime: 7 days
- Stored hashed in `hpbrain_refresh_tokens`
- Rotated on every use
- Previous token invalidated immediately
- Revoked on logout
- Revoked on password change

## Refresh Token Rotation

1. Client sends `refreshToken` to `/api/v1/auth/refresh`
2. Backend verifies the refresh token
3. Backend checks `hpbrain_refresh_tokens` for revocation
4. Backend issues new `accessToken` and new `refreshToken`
5. Backend marks old refresh token as revoked
6. Backend stores new refresh token hash
7. Client updates stored tokens

## Logout Behavior

1. Client calls `POST /api/v1/auth/logout` with `refreshToken`
2. Backend marks refresh token as revoked in `hpbrain_refresh_tokens`
3. Backend returns `{ "ok": true }`
4. Client clears `accessToken`, `refreshToken`, `selectedOrgId`, `hpbrain-user`
5. Client navigates to login screen

## Session Expiration

1. API call returns 401
2. Client attempts refresh once
3. If refresh succeeds, retry original request
4. If refresh fails, clear session and redirect to login
5. No automatic fallback to dev-bypass

## Rate Limiting

- Login: 10 requests per minute
- Refresh: 20 requests per minute
- Prevents credential stuffing and token brute-forcing
