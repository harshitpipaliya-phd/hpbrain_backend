<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies the department each operational record already names in its payload
 * into the indexed column that queries can actually use.
 *
 * NOT A DERIVATION. Every row written by OperationalRecordLoader keeps the source
 * export's own `department` value inside its payload JSON. This command moves that
 * stated value into `department_label`; it does not infer, match or guess one. A
 * payload naming no department leaves the column NULL, and the intelligence layer
 * reports that as "the source did not say" rather than scoring it zero.
 *
 * WHY A COMMAND AND NOT PART OF THE MIGRATION. The work touches every historical
 * row of every tenant — three quarters of a million on the development database —
 * and a migration that runs for an hour is a deployment that cannot be interrupted
 * safely. This is resumable and idempotent: a row that already carries a label is
 * never rewritten unless `--force` is passed, so a killed run simply picks up.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IT WALKS A CURSOR RATHER THAN REPEATING `WHERE department_label IS NULL`, AND
 * THAT IS THE WHOLE DIFFERENCE BETWEEN THIS COMPLETING AND NOT.
 *
 * The obvious loop is `UPDATE … WHERE department_label IS NULL LIMIT n`, repeated
 * until it affects nothing. There is no index on `department_label`, so every
 * iteration re-scans the tenant's slice from the beginning, stepping over all the
 * rows the previous iterations already filled: the classic quadratic pagination.
 *
 * MEASURED against the live database, one 38,000-row dataset:
 *
 *   chunk 1 ............ 30s
 *   chunk 13 .......... 90s
 *   chunk 17 .......... killed by the connection timeout, and every retry after
 *                       it re-scanned further and died sooner
 *
 * The cursor below walks `(tenant_id, dataset, natural_key)` — an index that
 * exists — so each step is an index seek of constant cost regardless of how much
 * of the table is already done. The UPDATE then addresses its rows by primary key.
 * Same statement, same result, linear instead of quadratic.
 *
 * ONE DATASET AT A TIME, and ordered, so an interrupted run resumes at a
 * predictable place and the log reads as progress rather than as noise.
 *
 * `--force` REWRITES ROWS THAT ALREADY CARRY A LABEL, for the case where a
 * re-import corrected a department in the payload after the column was first
 * populated. It is the slower path by definition and is never the default.
 */
final class BackfillRecordDepartments extends Command
{
    protected $signature = 'operations:backfill-departments
        {--tenant=* : Restrict to these tenant ids}
        {--batch=1000 : Rows per cursor step}
        {--force : Also rewrite rows whose department_label is already set}';

    protected $description = 'Populate operational_records.department_label from the department each payload already states';

    private const TABLE = 'hpbrain_operational_records';

    /** The one index this command depends on; it ships with the base table. */
    private const CURSOR_INDEX = 'idx_oprec_tenant_dataset_natural_key';

    public function handle(): int
    {
        if (! Schema::hasColumn(self::TABLE, 'department_label')) {
            $this->error('department_label is missing. Run the migrations first.');

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->warn('The JSON extraction in this command is MySQL/MariaDB specific; nothing to do on '.DB::connection()->getDriverName().'.');

            return self::SUCCESS;
        }

        $batch = max(100, (int) $this->option('batch'));
        $force = (bool) $this->option('force');
        $tenants = array_map('strval', (array) $this->option('tenant'));

        if ($tenants === []) {
            $tenants = DB::table(self::TABLE)->distinct()->pluck('tenant_id')->map(fn ($t) => (string) $t)->all();
        }

        foreach ($tenants as $tenantId) {
            $this->line("tenant {$tenantId}");
            $written = 0;

            $datasets = DB::table(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->groupBy('dataset')
                ->orderBy('dataset')
                ->pluck('dataset');

            foreach ($datasets as $dataset) {
                $written += $this->backfillDataset($tenantId, (string) $dataset, $batch, $force);
            }

            $this->info("  done — {$written} rows written");
        }

        return self::SUCCESS;
    }

    /**
     * Walk one dataset's natural keys in order, writing each page by primary key.
     */
    private function backfillDataset(string $tenantId, string $dataset, int $batch, bool $force): int
    {
        $cursor = '';
        $written = 0;

        // The hint is deliberate: the optimiser's own estimate for a predicate
        // this unselective is a table scan, which is the thing being avoided.
        $from = self::TABLE.' FORCE INDEX ('.self::CURSOR_INDEX.')';

        while (true) {
            $page = DB::table(DB::raw($from))
                ->where('tenant_id', $tenantId)
                ->where('dataset', $dataset)
                ->where('natural_key', '>', $cursor)
                ->orderBy('natural_key')
                ->limit($batch)
                ->get(['id', 'natural_key']);

            if ($page->isEmpty()) {
                break;
            }

            $cursor = (string) $page->last()->natural_key;

            $update = DB::table(self::TABLE)
                ->whereIn('id', $page->pluck('id')->all())
                ->whereNotNull('payload');

            if (! $force) {
                $update->whereNull('department_label');
            }

            $written += $update->update([
                'department_label' => DB::raw(
                    "LEFT(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.department'))), ''), 191)"
                ),
            ]);
        }

        if ($written > 0) {
            $this->line("  {$dataset}: {$written}");
        }

        return $written;
    }
}
