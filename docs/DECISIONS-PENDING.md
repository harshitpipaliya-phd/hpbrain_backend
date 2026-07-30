# Decisions Pending

## 1 — Viewer can trigger paid AI calls (Module 8 / Phase 3 finding)

**Question:** Can a role holding only `read` — a viewer — trigger a paid AI call?

**Answer:** Yes.

**Evidence:**

| Route | Controller method | Middleware |
|---|---|---|
| `POST /api/v1/ai/evidence/summarize` | `AiController@summarizeEvidence` | `jwt`, `tenant`, `permission:read` |
| `POST /api/v1/reasoning-engine/{tenantId}/explain` | `ReasoningEngineController@explain` | `jwt`, `tenant`, `permission:read` |
| `POST /api/v1/reasoning-engine/{tenantId}/assess` | `ReasoningEngineController@assess` | `jwt`, `tenant`, `permission:read` |

All three routes are declared inside the authenticated group at `routes/api.php:91`:

```php
Route::middleware(['jwt', 'tenant', 'permission:read'])->group(function () {
    // ...
    Route::post('reasoning-engine/{tenantId}/explain', ...);
    Route::post('reasoning-engine/{tenantId}/assess', ...);
    Route::post('ai/evidence/summarize', ...);
```

`Role::VIEWER` holds exactly one permission:

```php
self::VIEWER => [
    Permission::READ->value,
],
```

`RequirePermission` enforces the gate at the route level. A viewer therefore passes the `permission:read` gate and reaches the controller, which calls the configured AI provider. These endpoints were harmless when they returned `UNDETERMINED`; they now spend money.

**Decision needed:** elevate these three routes to `permission:create` (or a new `ai.invoke` permission), or move them behind an explicit admin/manager gate. This is a governance decision for the product owner.

## 2 — Missing foreign-key constraints (Module 4 / Phase 4 finding)

**Question:** Do the `REFERENCES` clauses in the 2026_01_01 migrations create real foreign keys?

**Answer:** No. They are decorative. InnoDB parses column-level `REFERENCES` silently and ignores them. No FK constraint is created.

**Evidence:**

Every 2026_01_01 migration uses inline column-level syntax like:

```sql
tenant_id VARCHAR(36) REFERENCES institute_detail(sub_institute_id),
decision_id VARCHAR(36) REFERENCES hpbrain_decisions(id)
```

This is valid SQL grammar and MySQL parses it without error, but InnoDB silently ignores the `REFERENCES` clause at the column level. Real foreign keys must be declared at table level via `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY ...`.

**Consequence:** every FK in the original schema is decorative. `hpbrain_evidence.signal_id` does not enforce that the signal exists. `hpbrain_decisions.recommendation_id` does not enforce referential integrity. This is why nine modules check tenant ownership by hand — the database never enforced it.

**Prerequisite before real constraints can be added:** orphaned rows must be found and resolved. Any `hpbrain_evidence` row whose `signal_id` points at a missing signal, any `hpbrain_decisions` row whose `recommendation_id` points at a missing recommendation, and so on, must be identified and either deleted or repaired before a real FK can be added. Adding a constraint to a table that already contains orphans fails.

**Decision needed:** schedule the orphan sweep as a data-migration step. Once it is confirmed clean, real `ALTER TABLE ADD CONSTRAINT` statements can be added without risk of migration failure. Do not add constraints in the same release as the orphan fix — those are separate changes with separate rollback profiles.
