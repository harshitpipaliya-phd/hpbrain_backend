# Security Hardening Checklist

## Authentication
- [x] Single production login endpoint (`POST /api/v1/auth/login`)
- [x] Login against `hp_erp.tbluser`
- [x] Dev Token removed
- [x] Dev-bypass backdoor removed
- [x] External HP login proxy removed
- [x] Duplicate ERP login endpoint removed
- [x] Organization derived from `sub_institute_id`
- [x] Role resolved from `tbluserprofilemaster`
- [x] Login rate limiting (`throttle:10,1`)
- [x] Refresh rate limiting (`throttle:20,1`)
- [x] Password migration on login
- [x] No plain-text password creation
- [x] No password logging
- [x] No password fields in responses

## Tenant Isolation
- [x] Tenant from verified JWT only
- [x] Frontend-supplied tenant IDs not trusted
- [x] ERP queries filtered by `sub_institute_id`
- [x] Brain queries filtered by `tenant_id`
- [x] Cross-tenant returns 403
- [x] Admin cross-tenant bounded by existence check
- [x] Normal users pinned to their tenant
- [x] Tenant mismatch audited

## Authorization
- [x] Permission middleware on routes
- [x] Fail-closed on unknown roles/permissions
- [x] Audit denials
- [x] Role-based navigation
- [x] Organization admin ≠ platform super-admin

## Token Security
- [x] HS256 algorithm
- [x] Short access token lifetime (15 min)
- [x] Refresh token rotation
- [x] Refresh token revocation
- [x] Logout revokes refresh token
- [x] Token type checking (access vs refresh)
- [x] No sensitive data in tokens

## Input Validation
- [x] Email validation
- [x] Password required
- [x] Type validation
- [x] Length validation
- [x] Enum validation where applicable
- [x] Ownership validation
- [x] Tenant relationship validation

## Output Safety
- [x] No stack traces in production
- [x] No SQL queries in errors
- [x] No `.env` values in errors
- [x] No database credentials in errors
- [x] No passwords in responses
- [x] No password hashes in responses
- [x] Consistent error format

## Headers and CSP
- [ ] Content Security Policy (documented, not enforced)
- [ ] X-Frame-Options
- [ ] X-Content-Type-Options
- [ ] Strict-Transport-Security
- [ ] CORS restrictions configured

## Database Safety
- [x] No destructive migrations without rollback
- [x] ERP tables not dropped or renamed
- [x] Soft deletes used where appropriate
- [x] Transactions for multi-step writes
- [ ] Foreign key constraints reviewed
- [ ] Indexes for tenant queries

## Logging and Auditing
- [x] Login success audited
- [x] Login failure audited
- [x] Logout audited
- [x] Cross-tenant attempts audited
- [x] Permission denials audited
- [x] No password logging
- [x] No raw token logging

## Frontend Security
- [x] No dev-bypass
- [x] No automatic login
- [x] Session clearing on logout
- [x] Organization state clearing on logout
- [x] Error boundaries
- [x] Safe empty states

## Prepared for Later
- [ ] MFA
- [ ] Google SSO
- [ ] Microsoft SSO
- [ ] Suspicious login detection
- [ ] IP/device risk scoring
- [ ] Enterprise identity providers
- [ ] Passwordless login
- [ ] HttpOnly cookie storage for refresh tokens
