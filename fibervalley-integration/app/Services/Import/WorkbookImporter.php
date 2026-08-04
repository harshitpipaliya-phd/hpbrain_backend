<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Repositories\ImportJobRepository;
use App\Repositories\ImportLogRepository;
use App\Services\Import\Loaders\ErpRosterLoader;
use App\Services\Import\Loaders\OperationalRecordLoader;
use App\Services\Import\Loaders\RecordLoader;
use App\Support\Spreadsheet\SpreadsheetException;
use App\Support\Spreadsheet\XlsxReader;
use Illuminate\Support\Facades\DB;

/**
 * Runs one profile against one workbook, start to finish.
 *
 * Uses the EXISTING ImportJobRepository and ImportLogRepository, and writes the
 * same hpbrain_import_jobs / hpbrain_import_logs rows the API already reads. A
 * workbook imported by `php artisan brain:import` is therefore visible through
 * GET /api/v1/imports/{tenantId} and GET .../logs with no controller change,
 * and its rollback_data is in the shape ImportEngine::rollbackImport() already
 * expects.
 *
 * ERROR PHILOSOPHY
 * ----------------
 * A bad ROW is data, not an exception: it is logged against the job and the
 * import continues. A bad PROFILE or an unreadable FILE is a developer error
 * and throws, because continuing would import a partial dataset that looks
 * complete. The distinction matters — 65,268 complaint rows will always contain
 * a few with an unparseable date, and aborting on the first one would mean
 * never importing anything.
 *
 * TRANSACTION BOUNDARY
 * --------------------
 * Deliberately NOT one transaction around the whole import. A single
 * transaction holding 65,268 inserts pins the InnoDB undo log for the duration
 * and blocks other writers on a shared ERP database. Idempotency does the job a
 * transaction would: an interrupted run is resumed by simply running it again,
 * because every row is keyed and unchanged rows are skipped.
 */
final class WorkbookImporter
{
    public function __construct(
        private readonly ImportProfileRegistry $registry,
        private readonly ImportJobRepository $jobs,
        private readonly ImportLogRepository $logs,
    ) {
    }

    /**
     * @param  array{org_id?: ?string, actor?: string, dry_run?: bool, base_directory?: ?string, log_rows?: bool}  $options
     * @return array<string, mixed>
     */
    public function import(string $tenantId, ImportProfile $profile, array $options = []): array
    {
        $actor  = (string) ($options['actor'] ?? 'system');
        $dryRun = (bool) ($options['dry_run'] ?? false);

        $path = $this->registry->resolveFile($profile, $options['base_directory'] ?? null);

        if ($path === null) {
            throw new SpreadsheetException(
                "No file matching '{$profile->filePattern()}' for profile '{$profile->label()}'."
            );
        }

        $reader = new XlsxReader($path);
        $rows   = $reader->rows($profile->sheet(), $profile->headerRow() - 1);

        // THE HEADER IS CONSUMED EXPLICITLY, NOT WITH foreach + break.
        //
        // `foreach ($rows as $r) { $header = $r; break; }` looks like it takes
        // the first row and leaves the generator positioned after it. It does
        // not. Breaking on the first element never advances the generator, so a
        // second foreach re-runs rewind(), finds it still on element zero, and
        // hands the header back as the first DATA row. The symptom was a
        // complaint record whose ticket number was the literal string 'Ticket'
        // and whose engineer was 'Engineer Name' — one bogus row per sheet,
        // which is exactly the kind of thing that survives review and then
        // pollutes every count downstream.
        //
        // rewind() is called once here, and next() moves past the header, so
        // the walk below starts on genuine data.
        $rows->rewind();

        if (! $rows->valid()) {
            throw new SpreadsheetException(
                "Sheet '{$profile->sheet()}' in ".basename($path)." has no header at row {$profile->headerRow()}."
            );
        }

        $headerRow = $rows->current();
        $rows->next();

        $mapper  = new RowMapper($headerRow);
        $missing = $mapper->missingColumns($profile);

        if ($missing !== []) {
            // Fail before writing anything. A renamed column would otherwise
            // import tens of thousands of rows with a silently null field, and
            // the resulting intelligence would be confidently wrong.
            throw new ImportConfigurationException(
                "Profile '{$profile->label()}' references columns absent from "
                .basename($path)." / '{$profile->sheet()}': ".implode(', ', $missing)
                .'. Found: '.implode(', ', $mapper->headers())
            );
        }

        $loader = $this->makeLoader($profile);

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0, 'read' => 0];
        $errors = [];

        $job = null;

        if (! $dryRun) {
            $job = $this->jobs->create($tenantId, [
                'org_id'      => $options['org_id'] ?? null,
                'import_type' => 'xlsx',
                'entity_type' => $profile->loader() === 'erp_roster' ? 'person' : $profile->dataset(),
                'status'      => 'processing',
                'total_rows'  => 0,
                'started_by'  => $actor,
            ]);

            $this->registry->publishMappings($tenantId, $profile, $actor);
        }

        $context = [
            'org_id'        => $options['org_id'] ?? null,
            'source_file'   => basename($path),
            'import_job_id' => $job['id'] ?? null,
            'actor'         => $actor,
        ];

        try {
            while ($rows->valid()) {
                $rowNumber = (int) $rows->key();
                $row = $rows->current();
                $rows->next();

                $counts['read']++;

                $records = $profile->isMatrix()
                    ? $mapper->unpivot($row, $profile)
                    : $this->tabularRecord($mapper, $row, $profile, $rowNumber, $counts, $errors);

                foreach ($records as $record) {
                    $key = $record['__key'] ?? $mapper->naturalKey($row, $profile);
                    unset($record['__key']);

                    if ($key === null) {
                        continue;
                    }

                    if ($dryRun) {
                        $counts['created']++;
                        continue;
                    }

                    try {
                        $result = $loader->load(
                            $tenantId,
                            $profile,
                            (string) $key,
                            $record,
                            $context + ['source_row' => $rowNumber]
                        );

                        $counts[$result['action']]++;

                        // Row-level logs are opt-in. 65,268 log rows per import
                        // would be several times the size of the data and are
                        // read by nobody; errors are always logged.
                        if (($options['log_rows'] ?? false) && $job !== null) {
                            $this->logs->create($tenantId, [
                                'import_job_id' => $job['id'],
                                'row_number'    => $rowNumber,
                                'action'        => $result['action'],
                                'entity_type'   => $profile->dataset(),
                                'entity_id'     => $result['entityId'],
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $counts['error']++;
                        $errors[] = ['row' => $rowNumber, 'key' => (string) $key, 'error' => $e->getMessage()];

                        if ($job !== null && count($errors) <= 500) {
                            $this->logs->create($tenantId, [
                                'import_job_id' => $job['id'],
                                'row_number'    => $rowNumber,
                                'action'        => 'error',
                                'entity_type'   => $profile->dataset(),
                                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                            ]);
                        }
                    }
                }
            }
        } finally {
            // Flush whatever is buffered even if the loop died, so a partial
            // import persists what it genuinely achieved and the reported
            // counts are true.
            if (method_exists($loader, 'flush')) {
                $loader->flush();
            }
        }

        if ($job !== null) {
            $this->jobs->update($tenantId, $job['id'], [
                'status'          => $counts['error'] > 0 ? 'completed_with_errors' : 'completed',
                'total_rows'      => $counts['read'],
                'processed_rows'  => $counts['created'] + $counts['updated'] + $counts['skipped'],
                'success_count'   => $counts['created'] + $counts['updated'],
                'error_count'     => $counts['error'],
                'duplicate_count' => $counts['skipped'],
                'error_report'    => array_slice($errors, 0, 200),
                'rollback_data'   => ['created_ids' => $loader->createdIds()],
                'completed_date'  => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ]);
        }

        return [
            'profile' => $profile->label(),
            'file'    => basename($path),
            'sheet'   => $profile->sheet(),
            'job_id'  => $job['id'] ?? null,
            'dry_run' => $dryRun,
            'counts'  => $counts,
            'errors'  => array_slice($errors, 0, 20),
        ];
    }

    /**
     * One tabular row -> zero or one record. Zero when the row is blank or
     * fails validation, which is recorded rather than thrown.
     *
     * @param  array<int, ?string>  $row
     * @return array<int, array<string, mixed>>
     */
    private function tabularRecord(
        RowMapper $mapper,
        array $row,
        ImportProfile $profile,
        int $rowNumber,
        array &$counts,
        array &$errors
    ): array {
        // Excel sheets routinely carry trailing rows that hold nothing but
        // formatting. They are not errors and must not inflate the error count.
        if ($this->isBlank($row)) {
            $counts['read']--;

            return [];
        }

        $missing = $mapper->missingRequired($row, $profile);

        if ($missing !== []) {
            $counts['error']++;
            $errors[] = [
                'row'   => $rowNumber,
                'error' => 'missing required: '.implode(', ', $missing),
            ];

            return [];
        }

        $fields = $mapper->mapRow($row, $profile);

        if ($profile->loader() === 'erp_roster') {
            $fields['__active'] = $this->isCurrentlyActive($mapper, $row, $profile);
        }

        return [$fields];
    }

    /**
     * The roster's twelve month columns hold 'True'/'False' per month. The LAST
     * one with a value is the person's current standing — reading the first, or
     * ORing them all, would keep someone who left in October on the active list
     * for the rest of the year.
     *
     * @param  array<int, ?string>  $row
     */
    private function isCurrentlyActive(RowMapper $mapper, array $row, ImportProfile $profile): bool
    {
        $latest = null;

        foreach ($profile->activeFlags() as $header) {
            $value = $mapper->value($row, $header);

            if ($value !== null) {
                $latest = $value;
            }
        }

        if ($latest === null) {
            return true;
        }

        return in_array(strtolower($latest), ['true', '1', 'yes', 'y'], true);
    }

    /**
     * @param  array<int, ?string>  $row
     */
    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function makeLoader(ImportProfile $profile): RecordLoader
    {
        return match ($profile->loader()) {
            'operational' => new OperationalRecordLoader(),
            'erp_roster'  => new ErpRosterLoader(),
            default => throw new ImportConfigurationException(
                "Profile '{$profile->label()}' names unknown loader '{$profile->loader()}'."
            ),
        };
    }
}
