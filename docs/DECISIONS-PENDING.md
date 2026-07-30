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
