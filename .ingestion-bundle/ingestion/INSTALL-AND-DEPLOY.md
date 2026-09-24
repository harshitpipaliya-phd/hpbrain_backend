# Ingestion — install and deploy

Repo: `hpbrain_backend`. No new Composer dependency. No `league/csv`.

---

## Part 1 — Local

### 1. Branch

```bash
cd /path/to/hpbrain_backend
git checkout -b feat/ingestion
```

### 2. Copy the files in

| From this bundle | To in the repo |
|---|---|
| `app/Domain/Ingestion/DataSource.php` | same path |
| `app/Domain/Ingestion/IngestionBatch.php` | same path |
| `app/Domain/Ingestion/FieldMap.php` | same path |
| `app/Domain/Ingestion/IngestionService.php` | same path |
| `app/Domain/Ingestion/Sources/CsvUploadSource.php` | same path |
| `app/Domain/Ingestion/Sources/ErpDataSource.php` | same path |
| `app/Repositories/DataSourceRepository.php` | same path |
| `app/Http/Controllers/Api/IngestionController.php` | same path |
| `database/migrations/2026_08_05_000100_ingestion.php` | same path |
| `tests/Feature/IngestionTest.php` | same path |

```bash
mkdir -p app/Domain/Ingestion/Sources
```

Everything else already exists.

### 3. Apply the two patches

- `patches/ROUTES.patch.md` → edits `routes/api.php`
- `patches/BuildsBrainSchema.patch.md` → edits `tests/Support/BuildsBrainSchema.php`

Both are short and must both be done. The routes patch is the security-relevant
one.

### 4. Confirm the database target before migrating

**This matters more here than in a normal Laravel project.** Your Brain shares
its database with the institute ERP — `docs/DATA-SYNCHRONIZATION-STRATEGY.md`
names `tbluser`, `hrms_departments`, `institute_detail` and `org_details` as
tables this project does not own.

```bash
grep -E '^DB_(HOST|DATABASE)=' .env
```

If that prints your production host, stop and point it at a dev database first.

### 5. Test before migrating

The suite runs on in-memory SQLite and touches no real database:

```bash
php artisan test
```

Expect your existing 259 tests plus 10 new ones, all green. If `IngestionTest`
fails on a missing table, the `BuildsBrainSchema` patch was not applied.

### 6. Migrate and verify routes

```bash
php artisan migrate
php artisan route:list --path=ingestion
```

Every ingestion row must read `api/v1/ingestion/...` with `jwt`, `tenant` and
`permission:settings.manage` present. If it reads `api/ingestion/...`, the route
lines landed outside the group — fix before going further.

### 7. Exercise it locally

```bash
php artisan serve
```

In a second terminal — note the `v1`, and note that a token is now required:

```bash
TOKEN="<a real access token for your tenant>"

# Phase 1: preview. Writes nothing.
curl -X POST http://localhost:8000/api/v1/ingestion/upload \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@Zeel_Tank.csv" \
  -F "source_id=internal-upload-zeel-tank-csv"
```

You get back a `job_id` and a `preview` containing `suggested_map`,
`unmapped_fields` and `committable`. **Read the suggested map.** It is matched
from column-name substrings and it is wrong often enough to matter.

```bash
# Phase 2: commit, with the map you actually approve.
curl -X POST http://localhost:8000/api/v1/ingestion/<tenantId>/<jobId>/commit \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "field_map": {
      "title": "Calendar Subject",
      "owner": "Calendar Assigned To",
      "state": "Calendar Status",
      "evidence_text": "Calendar Event Completion Remarks",
      "evidence_timestamp": "Calendar Start Date & Time"
    },
    "save_map": true
  }'
```

That map is the one `ingest.py` proved against this exact CSV, carried over
unchanged.

Confirm real rows landed:

```sql
SELECT COUNT(*) FROM hpbrain_signals  WHERE source = 'internal-upload-zeel-tank-csv';
SELECT COUNT(*) FROM hpbrain_evidence WHERE source = 'internal-upload-zeel-tank-csv';
SELECT COUNT(*) FROM hpbrain_event_store WHERE type = 'ObservationMade';
```

If the first is 0 while the API reported successes, stop and send me the output
of `GET /api/v1/imports/{tenantId}/{jobId}/logs`.

### 8. Internal ingestion

No file. This reads the ERP through `EntityResolver`:

```bash
curl -X POST http://localhost:8000/api/v1/ingestion/internal \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"source_id":"erp-person","universal_entity":"Person"}'
```

A `422 entity_not_mapped` means that tenant has no mapping row — run
`EntityMappingSeeder` for it rather than adding a fallback.

---

## Part 2 — Deploy (SSH + git pull + restart)

### 9. Push

```bash
git add -A
git commit -m "Add ingestion: external CSV upload and internal ERP read"
git push -u origin feat/ingestion
```

Merge to your deploy branch through a PR if that is your habit; otherwise merge
locally and push that branch.

### 10. Back up first — this is not optional here

The migration only ever CREATEs and ADDs, and `down()` deliberately does not
drop the added columns. But it runs against a database another application
writes to.

```bash
ssh user@your-server
cd /path/to/hpbrain_backend

mysqldump -u <user> -p <database> \
  hpbrain_import_jobs hpbrain_data_sources \
  > ~/pre-ingestion-$(date +%F).sql
```

(`hpbrain_data_sources` will not exist yet — mysqldump will say so, which is
fine and is itself a useful confirmation you are on the right database.)

### 11. Deploy

```bash
php artisan down --render="errors::503"

git pull origin <your-deploy-branch>

# No composer install is needed for this change — nothing was added to
# composer.json. Run it only if your normal deploy does.

php artisan migrate --force

php artisan config:clear
php artisan route:clear
php artisan cache:clear

php artisan up
```

### 12. Restart the workers

Easy to forget, and the symptom is confusing: PHP-FPM serves new code while the
queue and scheduler keep running the old.

```bash
# Whichever applies to your setup:
sudo systemctl reload php8.2-fpm     # or php8.3-fpm
sudo supervisorctl restart all       # if queue workers run under supervisor
php artisan queue:restart
```

Confirm the scheduler cron is still present — without it `brain:process-events`
never drains the outbox, and ingestion events sit unprocessed:

```bash
crontab -l | grep schedule:run
```

### 13. Verify live

```bash
php artisan route:list --path=ingestion   # v1 + jwt + tenant, as locally
```

Then the same two-phase curl from step 7 against your real domain. Check the
same three SQL counts.

### 14. Rollback if needed

```bash
php artisan down
git reset --hard <previous-commit-sha>
php artisan config:clear && php artisan route:clear
php artisan up
```

The migration does not need reversing — the new table is unused by old code and
the three added columns are nullable. To undo an *ingestion run* rather than the
deploy, use the route that already exists:

```
POST /api/v1/imports/{tenantId}/{jobId}/rollback
```

That works because `commit()` populates `rollback_data` in exactly the shape
`ImportEngine::rollbackImport()` reads.

---

## Still open, deliberately

**`ImportEngine::processImport()` is still the fake loop.** I did not touch it —
it is a separate change with its own blast radius, and it has live routes in
front of it. It still reports success while writing nothing. Worth either
implementing or returning `501` from `ImportController::process()` in a
follow-up PR.

**No upload UI.** There is no ingestion screen in `web/src/`. These are API
endpoints only for now.

**Internal incremental sync is insert-only.** `Person` as mapped today has no
`updatedAt` column, so an incremental ERP read catches new rows and misses
edits. `ErpDataSource::watermarkField()` picks up a real modification timestamp
automatically if one is ever mapped. Stated here rather than hidden, because a
sync that silently misses updates is worse than one known to.
