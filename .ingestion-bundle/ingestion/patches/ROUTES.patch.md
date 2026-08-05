# routes/api.php — exact insertion

**Do not paste at the bottom of the file.** Everything in this application lives
inside `Route::prefix('v1')->group(...)` (opens line 113) with
`->middleware(['jwt', 'tenant', 'permission:read'])` (opens line 125). That group
closes at line 673. A route added after line 673 answers on `/api/ingestion/...`
with **no token required**, and `$this->tenantId($request)` returns `''` because
`EnsureTenantScope` never ran.

## 1. Add the import

Near the other controller imports at the top (they are alphabetical; `ImportController`
is on line 68):

```php
use App\Http\Controllers\Api\IngestionController;
```

## 2. Add the routes

Find this existing block (around line 591–599):

```php
        Route::get('imports/{tenantId}', [ImportController::class, 'index'])->middleware('permission:settings.manage');
        ...
        Route::get('imports/{tenantId}/{id}/logs', [ImportController::class, 'logs'])->middleware('permission:settings.manage');
```

Insert directly **after** the last `imports/...` line, at the same indentation
(8 spaces — it is nested two levels inside the group):

```php
        // ---- Ingestion (external upload + internal ERP read) ------------------
        // Sits with the imports routes because it writes the same
        // hpbrain_import_jobs / hpbrain_import_logs tables and is undone by the
        // same POST /imports/{tenantId}/{id}/rollback.
        Route::get('ingestion/sources/{tenantId}', [IngestionController::class, 'sources'])->middleware('permission:settings.manage');
        Route::post('ingestion/upload', [IngestionController::class, 'upload'])->middleware('permission:settings.manage');
        Route::post('ingestion/internal', [IngestionController::class, 'internal'])->middleware('permission:settings.manage');
        Route::post('ingestion/{tenantId}/{jobId}/commit', [IngestionController::class, 'commit'])->middleware('permission:settings.manage');
```

## 3. Verify placement before running anything

```bash
php artisan route:list --path=ingestion
```

Every row must show the URI as `api/v1/ingestion/...` and list `jwt`, `tenant`
and `permission:settings.manage` in the middleware column.

If you see `api/ingestion/...`, or the middleware column is empty, the lines
landed outside the group — move them and re-check. This project also ships a
route audit command that is worth running once afterwards:

```bash
php artisan brain:check-routes
```
