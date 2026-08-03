# Password Security and Migration

## Overview

The institute ERP table `hp_erp.tbluser` stores passwords in multiple formats. This document describes the verification path, migration behavior, and removal plan.

## Supported Formats

| Format | Column | Detection | Status |
|---|---|---|---|
| Laravel bcrypt/argon | `tbluser.password` | `Hash::check()` succeeds | Current |
| Legacy direct-match | `tbluser.password` | `hash_equals()` matches raw input | Temporary |
| Legacy plaintext | `tbluser.plain_password` | `hash_equals()` matches raw input | Temporary |

## Verification Order

1. `Hash::check($raw, $tbluser.password)` — modern Laravel hash.
2. `hash_equals($tbluser.password, $raw)` — legacy unsalted hash or plaintext stored in the password column.
3. `hash_equals($tbluser.plain_password, $raw)` — legacy plaintext column.

## Migration Behavior

On successful verification of any format:

- `tbluser.password` is replaced with `Hash::make($raw)` (bcrypt).
- `tbluser.plain_password` is set to `NULL`.
- `tbluser.updated_at` is updated.

Migration happens during login, not in bulk. This limits blast radius: only active users who successfully authenticate are migrated.

## What Is Forbidden

- New passwords must never be written to `plain_password`.
- Passwords must never be logged.
- Password hashes or raw passwords must never appear in API responses.
- Bulk updates must include a `WHERE id = ?` clause scoped to the individual user.

## Rollback

Once a legacy password is overwritten with a bcrypt hash, the old value is unrecoverable. There is no automated rollback. A user who cannot log in after migration must reset their password through their organization administrator.

## Removal Plan

| Phase | Action | Timing |
|---|---|---|
| 1 | Login migrates on successful verification | Now |
| 2 | Identify accounts where `plain_password IS NOT NULL` and `password` does not start with `$2y$` or `$argon` | After 30 days |
| 3 | Force-reset remaining legacy accounts | After 45 days |
| 4 | Drop `plain_password` column via migration | After 60 days, after confirming column is empty |

## Audit

Run the following to verify migration health:

```bash
php artisan test --filter=ErpLoginTest
php tests/standalone/security.php
```

## Code Location

- Verification: `app/Http/Controllers/Api/AuthController.php` — `verifyErpPassword()`
- Migration: same method, updates `tbluser.password` and `tbluser.plain_password`
- Tests: `tests/Feature/ErpLoginTest.php`
