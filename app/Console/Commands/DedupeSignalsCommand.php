<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidate ingested signals that are the same observation stored twice.
 *
 * WHY DUPLICATES EXIST AT ALL. Until the dedupe_key work, IngestionService
 * derived a signal's primary key from (tenant, source, ROW NUMBER, byte hash of
 * the row). Position in a file was therefore part of a record's identity, so the
 * same receipt arriving through a second export — or the same export re-cut with
 * an extra row near the top — was written again under a new id. The database had
 * no unique key that could object.
 *
 * WHAT COUNTS AS A DUPLICATE HERE, EXACTLY. Two rows of the SAME TENANT whose
 * dedupe_key is equal — that is, the same data source and the same external
 * reference the organization itself uses (GR number, receipt number, ticket
 * number). Nothing is matched on names, on titles, on similarity, or on
 * "looks close enough". Rows with a NULL dedupe_key are never touched: those are
 * rule-raised or hand-entered signals, or ingested rows whose source named no
 * reference, and none of them make a claim to identity.
 *
 * WHICH COPY SURVIVES. The OLDEST — earliest created_date, ties broken by id so
 * the choice is deterministic and a dry run predicts the real run exactly. The
 * oldest is the one whose id has had the longest time to be cited by a case, a
 * piece of evidence or a reasoning step, so keeping it minimises the references
 * that have to move at all.
 *
 * NOTHING IS DELETED BEFORE ITS REFERENCES MOVE. Every table that points at a
 * signal is repointed to the survivor first, inside the same transaction as the
 * delete:
 *
 *   hpbrain_evidence.signal_id         the observation's evidence
 *   hpbrain_cases.signal_id            the legacy single-signal link
 *   hpbrain_case_signals.signal_id     the many-to-many link
 *   hpbrain_reasoning_steps.signal_id  reasoning that cited it
 *
 * hpbrain_case_signals has PRIMARY KEY (case_id, signal_id), so repointing can
 * collide with a row that already links the survivor to the same case. That is
 * handled by deleting the losing link rather than failing — the case keeps its
 * link to the surviving signal either way, and no citation is lost.
 *
 * DRY RUN BY DEFAULT. It prints what it would do and changes nothing. --apply is
 * required to write, which is the only safe default for a command whose job is
 * to delete rows from a production store.
 */
final class DedupeSignalsCommand extends Command
{
    protected $signature = 'brain:dedupe-signals
        {--tenant= : Restrict to one tenant. Omit to scan every tenant.}
        {--apply : Actually consolidate. Without it, nothing is written.}
        {--chunk=200 : Duplicate groups resolved per transaction.}';

    protected $description = 'Consolidate ingested signals that share a business identity, preserving every reference.';

    /** Tables holding a foreign key to hpbrain_signals.id. */
    private const REFERENCES = [
        'hpbrain_evidence' => 'signal_id',
        'hpbrain_cases' => 'signal_id',
        'hpbrain_case_signals' => 'signal_id',
        'hpbrain_reasoning_steps' => 'signal_id',
    ];

    public function handle(): int
    {
        if (! Schema::hasColumn('hpbrain_signals', 'dedupe_key')) {
            $this->error('hpbrain_signals has no dedupe_key column. Run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $tenant = $this->option('tenant');
        $apply = (bool) $this->option('apply');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $groups = $this->duplicateGroups($tenant === null ? null : (string) $tenant);

        if ($groups->isEmpty()) {
            $this->info('No duplicate ingested signals found.');

            return self::SUCCESS;
        }

        $extraRows = $groups->sum(fn ($g) => (int) $g->n - 1);

        $this->line(sprintf(
            '%s duplicate group(s) covering %s redundant row(s).',
            number_format($groups->count()),
            number_format($extraRows),
        ));

        foreach ($groups->groupBy('tenant_id') as $tenantId => $rows) {
            $this->line(sprintf(
                '  tenant %s: %s group(s), %s redundant row(s)',
                $tenantId,
                number_format($rows->count()),
                number_format($rows->sum(fn ($g) => (int) $g->n - 1)),
            ));
        }

        if (! $apply) {
            $this->warn('Dry run. Nothing was written. Re-run with --apply to consolidate.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $repointed = 0;

        foreach ($groups->chunk($chunkSize) as $batch) {
            [$batchDeleted, $batchRepointed] = $this->consolidate($batch);
            $deleted += $batchDeleted;
            $repointed += $batchRepointed;
            $this->output->write('.');
        }

        $this->newLine();
        $this->info(sprintf(
            'Consolidated: %s row(s) removed, %s reference(s) repointed.',
            number_format($deleted),
            number_format($repointed),
        ));

        $remaining = $this->duplicateGroups($tenant === null ? null : (string) $tenant)->count();

        if ($remaining > 0) {
            $this->warn("{$remaining} group(s) still duplicated — re-run to continue.");

            return self::FAILURE;
        }

        $this->info('No duplicate groups remain. `php artisan migrate` will now add the UNIQUE index.');

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function duplicateGroups(?string $tenant)
    {
        $query = DB::table('hpbrain_signals')
            ->selectRaw('tenant_id, dedupe_key, COUNT(*) AS n')
            ->whereNotNull('dedupe_key')
            ->groupBy('tenant_id', 'dedupe_key')
            ->havingRaw('COUNT(*) > 1');

        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }

        return $query->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $groups
     * @return array{0: int, 1: int}
     */
    private function consolidate($groups): array
    {
        $deleted = 0;
        $repointed = 0;

        DB::transaction(function () use ($groups, &$deleted, &$repointed): void {
            foreach ($groups as $group) {
                // Ordered so the survivor is the oldest row, deterministically.
                $rows = DB::table('hpbrain_signals')
                    ->where('tenant_id', $group->tenant_id)
                    ->where('dedupe_key', $group->dedupe_key)
                    ->orderBy('created_date')
                    ->orderBy('id')
                    ->get(['id']);

                if ($rows->count() < 2) {
                    continue;
                }

                $survivor = (string) $rows->first()->id;
                $losers = $rows->slice(1)->pluck('id')->map(fn ($id) => (string) $id)->all();

                foreach (self::REFERENCES as $table => $column) {
                    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                        continue;
                    }

                    if ($table === 'hpbrain_case_signals') {
                        $repointed += $this->repointCaseSignals($survivor, $losers);

                        continue;
                    }

                    $repointed += DB::table($table)
                        ->whereIn($column, $losers)
                        ->update([$column => $survivor]);
                }

                $deleted += DB::table('hpbrain_signals')->whereIn('id', $losers)->delete();
            }
        });

        return [$deleted, $repointed];
    }

    /**
     * Repoint the case↔signal link table, whose PRIMARY KEY (case_id,
     * signal_id) makes a blind UPDATE a duplicate-key error whenever the case
     * already links the survivor.
     *
     * Insert-then-delete rather than update: insertOrIgnore adds the link where
     * it is missing and silently accepts it where it is already there, and the
     * losing rows are then removed. The case ends up linked to the surviving
     * signal exactly once, which is the only outcome that preserves the
     * citation.
     *
     * @param  array<int, string>  $losers
     */
    private function repointCaseSignals(string $survivor, array $losers): int
    {
        $links = DB::table('hpbrain_case_signals')->whereIn('signal_id', $losers)->get();

        if ($links->isEmpty()) {
            return 0;
        }

        $columns = Schema::getColumnListing('hpbrain_case_signals');

        $rows = $links->map(function ($link) use ($survivor, $columns) {
            $row = (array) $link;
            $row['signal_id'] = $survivor;

            return array_intersect_key($row, array_flip($columns));
        })->all();

        DB::table('hpbrain_case_signals')->insertOrIgnore($rows);

        return DB::table('hpbrain_case_signals')->whereIn('signal_id', $losers)->delete();
    }
}
