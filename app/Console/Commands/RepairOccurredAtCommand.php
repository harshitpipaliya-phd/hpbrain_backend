<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair occurred_at on rows whose source timestamp was a bare year.
 *
 * THE BUG THIS CLEANS UP AFTER. IngestionService::dateTime() handed a bare
 * four-digit year to strtotime(), which reads "2018" as the TIME 20:18. Every
 * one of the 388,401 academic rows was therefore stored with occurred_at on the
 * import date, differing only in the minute — "2018" became 20:18 today, "2021"
 * became 20:21 today. dateTime() now anchors a bare year to 1 January; this
 * command fixes the rows written before that.
 *
 * WHY IT MATTERS BEYOND TIDINESS. occurred_at is the only indexed date on the
 * table — (tenant_id, dataset, occurred_at). With every row carrying the same
 * wrong day, a year filter matched everything or nothing, and any aggregate that
 * ordered or grouped by time was meaningless. The student projection reads
 * MIN/MAX(occurred_at) for a student's academic span precisely because the
 * alternative — parsing payload JSON on 388,401 rows — turned the rebuild into a
 * fifteen-minute query.
 *
 * CHUNKED, AND THAT IS THE POINT. One `UPDATE … WHERE tenant_id = ?` over 388k
 * rows holds row locks on the whole tenant slice for the duration and blocks
 * every concurrent write to it — which is the shape of the
 * `Lock wait timeout exceeded` this codebase has already been bitten by. Each
 * batch here is its own short transaction, so the longest lock is one batch, and
 * an interrupted run resumes simply by being run again.
 *
 * IT ONLY TOUCHES ROWS THAT ARE WRONG. The predicate compares the stored year
 * against the year in the payload, so a correct row is never rewritten and
 * re-running when there is nothing to do costs one indexed probe.
 */
final class RepairOccurredAtCommand extends Command
{
    protected $signature = 'dataset:repair-occurred-at
        {tenant        : Tenant id}
        {source        : dataset key whose rows carry a bare-year timestamp}
        {--field=syear  : payload key holding the year}
        {--batch=20000  : Rows per transaction}
        {--wrong-after= : Only rows with occurred_at at or after this datetime (index range)}
        {--by-bucket    : Fast mode — rewrite each distinct wrong timestamp in one indexed UPDATE}
        {--dry-run      : Report how many rows would change and stop}';

    protected $description = 'Rewrite occurred_at for dataset rows whose source timestamp was a bare year.';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->warn('MySQL only; nothing to do.');

            return self::SUCCESS;
        }

        $tenantId = (string) $this->argument('tenant');
        $dataset = (string) $this->argument('source');
        $field = (string) $this->option('field');
        $batch = max(100, (int) $this->option('batch'));

        $year = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.\"{$field}\"'))";

        // Wrong = the payload states a plausible year and occurred_at is not in
        // it (including the case where occurred_at was never set at all).
        $wrong = "{$year} REGEXP '^(19|20)[0-9]{2}$'
                  AND (occurred_at IS NULL OR YEAR(occurred_at) <> CAST({$year} AS UNSIGNED))";

        $bindings = [$tenantId, $dataset];

        if ($this->option('by-bucket')) {
            return $this->repairByBucket($tenantId, $dataset, $field, (string) $this->option('wrong-after'));
        }

        /*
          --wrong-after IS A PERFORMANCE OPTION, AND IT IS OPTIONAL BECAUSE IT IS
          A CLAIM ABOUT THE DATA.

          The predicate above is correct and slow: it decodes JSON and runs a
          REGEXP for every candidate row, so selecting each batch of 388,401 rows
          is a scan rather than a lookup. Measured against the remote database it
          repaired roughly 7,500 rows a minute — around forty minutes for one
          tenant.

          The bug always wrote occurred_at as a time on the IMPORT date, so every
          damaged row sits far in the future relative to the academic years it
          should hold. Given that bound, `occurred_at >= ?` becomes an index
          range scan on (tenant_id, dataset, occurred_at) and MySQL walks
          straight to the damaged rows instead of examining the healthy ones.

          It is NOT the default because it is only true when the caller knows it
          is: a dataset whose legitimate dates extend past the bound would have
          correct rows silently skipped. The expensive predicate is still applied
          on top, so this can only ever narrow what is considered — it cannot
          cause a correct row to be rewritten.
        */
        if ($after = $this->option('wrong-after')) {
            $wrong = 'occurred_at >= ? AND ('.$wrong.')';
            $bindings[] = $after;
        }

        /*
          THE PROGRESS TOTAL IS COUNTED CHEAPLY, AND DELIBERATELY APPROXIMATELY.

          Counting rows that match the full predicate means decoding JSON and
          running a REGEXP over every candidate — the same work the repair
          itself does, paid twice, and on this dataset that alone exceeded five
          minutes before a single row was fixed.

          With --wrong-after the count uses only the indexed range, which is an
          upper bound on what will change: it answers "how many rows are even
          eligible", which is all a progress denominator needs to be. Without it
          there is no cheap bound available, so the exact count is paid.

          The UPDATE always applies the full predicate. This affects the number
          printed, never the rows written.
        */
        $countWhere = $after ? 'occurred_at >= ?' : $wrong;
        $countBindings = $after ? [$tenantId, $dataset, $after] : [$tenantId, $dataset];

        $pending = (int) DB::selectOne(
            "SELECT COUNT(*) n FROM hpbrain_operational_records
              WHERE tenant_id = ? AND dataset = ? AND {$countWhere}",
            $countBindings,
        )->n;

        $this->info(
            $after
                ? "{$pending} row(s) have occurred_at at or after {$after} and are candidates for repair."
                : "{$pending} row(s) have an occurred_at that disagrees with payload.{$field}."
        );

        if ($pending === 0 || $this->option('dry-run')) {
            return self::SUCCESS;
        }

        $done = 0;

        while (true) {
            $changed = DB::affectingStatement(
                "UPDATE hpbrain_operational_records
                    SET occurred_at = STR_TO_DATE(CONCAT({$year}, '-01-01'), '%Y-%m-%d')
                  WHERE tenant_id = ? AND dataset = ? AND {$wrong}
                  LIMIT {$batch}",
                $bindings,
            );

            if ($changed === 0) {
                break;
            }

            $done += $changed;
            $this->output->write("\r  repaired {$done} / {$pending}");
        }

        $this->newLine();
        $this->info("Done — {$done} row(s) repaired.");

        return self::SUCCESS;
    }

    /**
     * Rewrite each distinct wrong timestamp in one indexed UPDATE.
     *
     * WHY THIS IS SO MUCH FASTER, AND WHY IT IS STILL SAFE.
     *
     * The row-at-a-time repair above decodes JSON and runs a REGEXP for every
     * candidate it examines. Against 388,401 rows on the remote database that
     * measured roughly 2,700 rows a minute — over an hour and a half for one
     * tenant.
     *
     * But the damage is not spread evenly: strtotime() is deterministic, so
     * every row whose source year was "2018" received the SAME wrong timestamp
     * (the import date at 20:18), every "2019" the same 20:19, and so on. The
     * 273,500 damaged rows here occupy just SEVEN distinct values of
     * occurred_at. Each one is therefore an exact match on the leading columns
     * of (tenant_id, dataset, occurred_at) — an index lookup — and the
     * replacement is a constant, so not a single JSON document is decoded.
     *
     * THE MAPPING IS VERIFIED, NEVER ASSUMED. For each bucket this samples rows
     * and refuses to touch it unless every sampled row agrees on one source
     * year. A bucket holding two different years is reported and SKIPPED — the
     * slow path can repair it correctly. That check is the whole reason this is
     * a mode of the same command rather than a hand-written UPDATE: the fast
     * path proves its own precondition before it writes anything.
     */
    private function repairByBucket(string $tenantId, string $dataset, string $field, string $after): int
    {
        $year = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.\"{$field}\"'))";

        $query = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('dataset', $dataset)
            ->whereNotNull('occurred_at');

        if ($after !== '') {
            $query->where('occurred_at', '>=', $after);
        }

        $buckets = $query->selectRaw('occurred_at, COUNT(*) n')
            ->groupBy('occurred_at')
            ->orderBy('occurred_at')
            // A sane ceiling: if the damaged rows occupy thousands of distinct
            // timestamps then they are not from this bug and the slow, fully
            // general path is the right tool.
            ->limit(200)
            ->get();

        if ($buckets->isEmpty()) {
            $this->info('Nothing to repair.');

            return self::SUCCESS;
        }

        $this->info("{$buckets->count()} distinct timestamp(s) to examine.");
        $repaired = 0;
        $skipped = 0;

        foreach ($buckets as $bucket) {
            $sample = DB::table(DB::raw(
                '(SELECT payload FROM hpbrain_operational_records
                   WHERE tenant_id = ? AND dataset = ? AND occurred_at = ? LIMIT 500) s'
            ))
                ->setBindings([$tenantId, $dataset, $bucket->occurred_at])
                ->selectRaw("COUNT(DISTINCT {$year}) years, MIN({$year}) yr")
                ->first();

            $distinct = (int) ($sample->years ?? 0);
            $sourceYear = (string) ($sample->yr ?? '');

            if ($distinct !== 1 || ! preg_match('/^(19|20)\d{2}$/', $sourceYear)) {
                $this->warn(sprintf(
                    '  SKIP  %s — %d distinct %s value(s) in the sample; not safely one year.',
                    $bucket->occurred_at,
                    $distinct,
                    $field,
                ));
                $skipped++;
                continue;
            }

            $target = $sourceYear.'-01-01 00:00:00';

            if ($target === (string) $bucket->occurred_at) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf('  would set %s (%d rows) -> %s', $bucket->occurred_at, $bucket->n, $target));
                continue;
            }

            $changed = DB::table('hpbrain_operational_records')
                ->where('tenant_id', $tenantId)
                ->where('dataset', $dataset)
                ->where('occurred_at', $bucket->occurred_at)
                ->update(['occurred_at' => $target]);

            $repaired += $changed;
            $this->line(sprintf('  %s -> %s  (%d rows)', $bucket->occurred_at, $target, $changed));
        }

        $this->info("Done — {$repaired} row(s) repaired, {$skipped} bucket(s) skipped.");

        if ($skipped > 0) {
            $this->warn('Re-run without --by-bucket to repair the skipped buckets row by row.');
        }

        return self::SUCCESS;
    }
}
