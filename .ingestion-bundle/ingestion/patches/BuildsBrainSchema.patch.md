# tests/Support/BuildsBrainSchema.php — required addition

The suite builds its own schema on SQLite rather than running migrations, so a
new table is invisible to it until it is declared here. Without this, every test
in `IngestionTest.php` fails on "no such table: hpbrain_data_sources".

## 1. Add the new table

Find the existing `hpbrain_import_logs` block (around line 858) and add
immediately after its closing `});`:

```php
        Schema::create('hpbrain_data_sources', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('source_key', 191);
            $t->string('source_type', 50);
            $t->string('display_name');
            $t->string('universal_entity', 100)->nullable();
            $t->text('field_map')->nullable();
            $t->string('checkpoint')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });
```

`field_map` is `text`, not `json`, to match how every other JSON column in this
file is declared — `BaseRepository::hydrate()` decodes it either way, and SQLite
stores both identically.

## 2. Add the three columns to the existing import jobs table

In the existing `Schema::create('hpbrain_import_jobs', ...)` block (around line
838), add these three lines before the closing `});`:

```php
            $t->string('source_id', 36)->nullable();
            $t->string('sync_type', 50)->nullable();
            $t->text('source_ref')->nullable();
```

These mirror the columns `2026_08_05_000100_ingestion.php` adds to MySQL. They
must be kept in step: a column that exists in the migration but not here is a
production failure the suite cannot see — which is exactly how the
`DECIMAL(10,0)` precision bug survived.
