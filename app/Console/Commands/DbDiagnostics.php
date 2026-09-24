<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only report on the tables behind the slow screens.
 *
 * STRICTLY READ-ONLY. It runs SELECTs against information_schema and COUNT(*)
 * on the tables named below, and writes nothing. It is safe to run against
 * production, and is meant to be run BEFORE the index migrations so the
 * decision is based on the real database rather than on an assumption about it.
 *
 *     php artisan brain:db-diagnostics
 *
 * What to look for:
 *   - A hot table with many rows and NO index on its filter column is the
 *     cause of a slow screen, not a symptom of one.
 *   - `hpbrain_*` rows missing an index point at the backfill migration
 *     (2026_08_02_000100) not having run yet.
 *   - A mapped SOURCE table missing an index points at the ERP index migration
 *     (2026_08_02_000200) not having run, or having skipped because the leading
 *     column was already covered.
 */
final class DbDiagnostics extends Command
{
    protected $signature = 'brain:db-diagnostics';

    protected $description = 'Read-only report of row counts and index coverage on the hot query paths';

    /**
     * table => the column the application filters on most.
     *
     * @var array<string, string>
     */
    private const HOT_PATHS = [
        // Brain tables — every read is tenant-scoped.
        'hpbrain_signals'          => 'tenant_id',
        'hpbrain_evidence'         => 'tenant_id',
        'hpbrain_cases'            => 'tenant_id',
        'hpbrain_recommendations'  => 'tenant_id',
        'hpbrain_decisions'        => 'tenant_id',
        'hpbrain_ai_executions'    => 'tenant_id',
        'hpbrain_audit_logs'       => 'tenant_id',
        'hpbrain_event_store'      => 'tenant_id',
    ];

    /**
     * The tables worth checking: every mapped source table, plus the Brain's own.
     *
     * The ERP side was a hardcoded list of five. It is now read from
     * hpbrain_entity_mappings, so a tenant on a different source system gets its
     * tables checked without this file being edited — and, more usefully, a
     * source table that nobody has mapped stops being reported as a hot path
     * when it is not one.
     *
     * Each mapped table is paired with the column that tenant filters on, which
     * is the resolved tenantKey. Two tenants mapping one table with different
     * tenant keys would be a configuration error rather than a diagnostic
     * problem; the first is reported and the disagreement surfaces as a
     * mismatched column name in the output.
     *
     * @return array<string, string> table => the column the application filters on
     */
    private function hotPaths(): array
    {
        $erp = [];

        try {
            $mappings = DB::table('hpbrain_entity_mappings')
                ->where('is_active', 1)
                ->where('universal_field', 'tenantKey')
                ->orderBy('source_entity')
                ->get(['source_entity', 'source_field']);

            foreach ($mappings as $row) {
                $erp[(string) $row->source_entity] ??= (string) $row->source_field;
            }
        } catch (\Throwable) {
            // The mapping table not existing is itself worth knowing, but this
            // command is read-only diagnostics and must still report on the
            // Brain tables it can reach.
            $this->warn('hpbrain_entity_mappings is unreadable — source tables omitted from this report.');
        }

        return $erp + self::HOT_PATHS;
    }

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->warn('Not a MySQL connection — index metadata is unavailable on '
                .DB::connection()->getDriverName().'. Run this against the real database.');

            return self::FAILURE;
        }

        $this->info('Read-only. Nothing below writes to the database.');
        $this->newLine();

        $rows = [];
        $problems = 0;

        foreach ($this->hotPaths() as $table => $column) {
            if (! Schema::hasTable($table)) {
                $rows[] = [$table, $column, '—', 'TABLE MISSING', ''];
                $problems++;

                continue;
            }

            if (! Schema::hasColumn($table, $column)) {
                $rows[] = [$table, $column, '—', 'COLUMN MISSING', ''];

                continue;
            }

            $count = (int) DB::table($table)->count();
            $leading = $this->indexesLeadingWith($table, $column);
            $indexed = $leading !== [];

            // A scan only hurts once there is something to scan. Flagging a
            // 12-row lookup table as a problem would bury the real ones.
            $isProblem = ! $indexed && $count > 1000;

            if ($isProblem) {
                $problems++;
            }

            $rows[] = [
                $table,
                $column,
                number_format($count),
                $indexed ? 'indexed' : ($count > 1000 ? 'NO INDEX — SCAN' : 'no index (small)'),
                implode(', ', $leading),
            ];
        }

        $this->table(['Table', 'Filter column', 'Rows', 'Status', 'Index(es) leading with column'], $rows);

        $this->newLine();

        if ($problems === 0) {
            $this->info('No unindexed hot paths over 1,000 rows. Slowness is likely elsewhere — '
                .'re-check with the query log enabled.');
        } else {
            $this->warn($problems.' hot path(s) need an index. Apply with:');
            $this->line('    php artisan migrate');
            $this->line('  (2026_08_02_000100 backfills Brain indexes; 2026_08_02_000200 adds ERP ones.)');
            $this->line('  CREATE INDEX consumes I/O proportional to table size — prefer a low-traffic window.');
        }

        return self::SUCCESS;
    }

    /**
     * Index names whose FIRST column is $column.
     *
     * Only the leading position matters for whether an equality filter can use
     * the index, so an index that mentions the column in third place is not
     * counted here — it would not help these queries.
     *
     * @return array<int, string>
     */
    private function indexesLeadingWith(string $table, string $column): array
    {
        return array_map(
            static fn ($r) => (string) $r->index_name,
            DB::select(
                'SELECT DISTINCT index_name FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ?
                    AND column_name = ? AND seq_in_index = 1',
                [$table, $column],
            ),
        );
    }
}
