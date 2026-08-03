# Password Migration

## Problem

The `hp_erp.tbluser` table stores passwords in multiple formats:

1. **Preferred**: Laravel bcrypt/argon hashes in `tbluser.password`
2. **Legacy direct-match**: Plaintext or unsalted hashes stored directly in `tbluser.password`
3. **Legacy plaintext**: Plaintext passwords in `tbluser.plain_password`

The previous `verifyErpPassword()` method checked all three formats. This is
acceptable as a **temporary migration bridge only**, not as a permanent
production design.

## Strategy

The login controller implements a backward-compatible migration:

1. Try `Hash::check()` against `tbluser.password` (bcrypt/argon).
2. If that fails, try `hash_equals()` against `tbluser.password` (legacy direct-match).
3. If that fails, try `hash_equals()` against `tbluser.plain_password` (legacy plaintext).
4. On any successful verification:
   - Replace `tbluser.password` with `Hash::make($rawPassword)` (bcrypt)
   - Set `tbluser.plain_password` to `NULL`
   - Update `tbluser.updated_at`

## What must never happen

- New passwords must never be written to `plain_password`.
- Passwords must never be logged.
- Password hashes or plaintext must never be returned in API responses.
- Broad updates must not be run without `WHERE` clauses scoped to individual users.

## Rollback

If a migrated user cannot log in:

1. The old hash is unrecoverable once overwritten.
2. The user must reset their password through the organization administrator.
3. There is no automated rollback — this is intentional. A reversible migration
   would require storing the legacy value alongside the new one, which extends
   the attack surface.

## Timeline

- **Phase 1 (now)**: Login migrates on successful verification.
- **Phase 2 (after 30 days)**: Run a one-time script to identify any remaining
  rows where `plain_password IS NOT NULL` and `password` does not look like a
  bcrypt hash. Notify administrators to force resets for those accounts.
- **Phase 3 (after 60 days)**: Remove the `plain_password` column from
  `tbluser` via a migration, after confirming it is empty.

## Verification

Run `php tests/standalone/security.php` and `php artisan test` to confirm
the migration does not break authentication.
