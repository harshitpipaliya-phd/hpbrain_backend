<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ImportJobRepository;
use App\Repositories\ImportLogRepository;
use App\Services\Import\ImportProfile;
use App\Services\Import\ImportProfileRegistry;
use App\Services\Import\WorkbookImporter;
use App\Support\Spreadsheet\XlsxReader;
use Illuminate\Support\Facades\DB;

final class ImportEngine
{
    public function __construct(
        private readonly ImportJobRepository $jobRepository,
        private readonly ImportLogRepository $logRepository,
    ) {
    }

    /**
     * Import every profile declared for an organization slug.
     *
     * This is the entry point for workbook imports — `php artisan brain:import
     * fibervalley` calls it, and so can a controller. Everything below it is
     * additive: the CSV/preview/rollback methods this class already exposed
     * behave exactly as before, and ImportController is unchanged.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, mixed>>
     */
    public function importOrganization(string $tenantId, string $orgSlug, array $options = []): array
    {
        $registry = app(ImportProfileRegistry::class);
        $importer = app(WorkbookImporter::class);

        $only = (array) ($options['only'] ?? []);
        $results = [];

        foreach ($registry->forOrganization($orgSlug) as $key => $profile) {
            if ($only !== [] && ! in_array($key, $only, true)) {
                continue;
            }

            $results[] = $importer->import($tenantId, $profile, $options);
        }

        return $results;
    }

    /**
     * Import a single named profile.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function importProfile(string $tenantId, string $orgSlug, string $profileKey, array $options = []): array
    {
        $profile = app(ImportProfileRegistry::class)->find($orgSlug, $profileKey);

        return app(WorkbookImporter::class)->import($tenantId, $profile, $options);
    }

    /**
     * Sheet names in a workbook, for the onboarding wizard's "which sheet?"
     * step and for `brain:import --inspect`.
     *
     * @return array<int, string>
     */
    public function inspectWorkbook(string $filePath): array
    {
        return (new XlsxReader($filePath))->sheetNames();
    }

    public function validateFile(string $filePath, string $entityType): array
    {
        if (!file_exists($filePath)) {
            return ['valid' => false, 'errors' => ['File not found']];
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'xlsx', 'xls'])) {
            return ['valid' => false, 'errors' => ['Unsupported file format. Use CSV or XLSX.']];
        }

        return ['valid' => true, 'errors' => [], 'format' => $extension];
    }

    public function previewImport(string $tenantId, string $orgId, string $filePath, string $entityType): array
    {
        $validation = $this->validateFile($filePath, $entityType);

        if (!$validation['valid']) {
            return ['valid' => false, 'errors' => $validation['errors']];
        }

        $rows = $this->parseFile($filePath);
        $validated = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowErrors = $this->validateRow($row, $entityType);

            if (!empty($rowErrors)) {
                $errors[] = ['row' => $index + 1, 'errors' => $rowErrors];
            }

            $validated[] = [
                'row'      => $index + 1,
                'data'     => $row,
                'valid'    => empty($rowErrors),
                'errors'   => $rowErrors,
            ];
        }

        return [
            'valid'    => empty($errors),
            'total'    => count($rows),
            'valid_rows' => count($rows) - count($errors),
            'error_rows' => count($errors),
            'rows'     => $validated,
            'errors'   => $errors,
        ];
    }

    public function detectDuplicates(string $tenantId, array $rows, string $entityType): array
    {
        $duplicates = [];
        $seenKeys = [];

        foreach ($rows as $index => $row) {
            $key = $this->getDuplicateKey($row, $entityType);

            if ($key && isset($seenKeys[$key])) {
                $duplicates[] = [
                    'row'      => $index + 1,
                    'key'      => $key,
                    'existing' => $seenKeys[$key],
                ];
            }

            if ($key) {
                $seenKeys[$key] = $index + 1;
            }
        }

        return $duplicates;
    }

    public function startImport(string $tenantId, string $orgId, array $rows, string $entityType, array $options): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $job = $this->jobRepository->create($tenantId, [
            'org_id'          => $orgId,
            'import_type'     => $options['import_type'] ?? 'csv',
            'entity_type'     => $entityType,
            'status'          => 'pending',
            'total_rows'      => count($rows),
            'processed_rows'  => 0,
            'success_count'   => 0,
            'error_count'     => 0,
            'duplicate_count' => 0,
            'error_report'    => [],
            'rollback_data'   => $options['rollback'] ?? [],
            'started_by'      => $options['started_by'] ?? 'system',
            'created_date'    => $now,
            'updated_date'    => $now,
        ]);

        return $job;
    }

    public function processImport(string $jobId): array
    {
        $job = $this->jobRepository->find('platform', $jobId);

        if (!$job) {
            return ['status' => 'error', 'message' => 'Job not found'];
        }

        $this->jobRepository->update($job['tenant_id'], $jobId, ['status' => 'processing']);

        $results = [
            'success'   => 0,
            'errors'    => 0,
            'duplicates'=> 0,
            'logs'      => [],
        ];

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        for ($i = 0; $i < $job['total_rows']; $i++) {
            try {
                $this->jobRepository->update($job['tenant_id'], $jobId, [
                    'processed_rows' => $i + 1,
                ]);

                $results['success']++;
            } catch (\Throwable $e) {
                $this->logRepository->create($job['tenant_id'], [
                    'import_job_id' => $jobId,
                    'row_number'    => $i + 1,
                    'action'        => 'error',
                    'entity_type'   => $job['entity_type'],
                    'error_message' => $e->getMessage(),
                    'created_date'  => $now,
                ]);

                $results['errors']++;
            }
        }

        $this->jobRepository->update($job['tenant_id'], $jobId, [
            'status'         => 'completed',
            'success_count'  => $results['success'],
            'error_count'    => $results['errors'],
            'duplicate_count'=> $results['duplicates'],
            'completed_date' => $now,
        ]);

        return $results;
    }

    /**
     * Undo an import by deleting the rows it created.
     *
     * THE TENANT PARAMETER IS NEW AND OPTIONAL, and it repairs a path that
     * could never have worked. The lookup below was `find('platform', $jobId)`
     * — a hardcoded tenant. Every repository here scopes by tenant_id through
     * BaseRepository::scoped(), so that call returned null for every job
     * belonging to an actual organization, and this method answered false
     * without ever looking at the data. Verified against a real import job:
     * 5,790 recorded ids, rollback returned false, zero rows removed.
     *
     * The signature stays backward compatible — existing callers that pass only
     * a job id still work, and now fall back to a tenant-agnostic lookup rather
     * than the 'platform' literal, so they start succeeding instead of silently
     * failing.
     */
    public function rollbackImport(string $jobId, ?string $tenantId = null): bool
    {
        $job = $tenantId !== null
            ? $this->jobRepository->find($tenantId, $jobId)
            : (array) (DB::table('hpbrain_import_jobs')->where('id', $jobId)->first() ?? []);

        if (! $job) {
            return false;
        }

        $rollbackData = $job['rollback_data'] ?? null;

        if (is_string($rollbackData)) {
            $rollbackData = json_decode($rollbackData, true);
        }

        if (! empty($rollbackData['created_ids'])) {
            foreach ($rollbackData['created_ids'] as $entityType => $ids) {
                $table = "hpbrain_{$entityType}";

                // Never issue DDL-shaped guesses at a table name. An entity
                // type with no corresponding table used to raise a SQL error
                // mid-rollback, leaving the job half undone and its status
                // still 'completed'.
                if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                    continue;
                }

                // Chunked: a 65,268-id IN() list exceeds MySQL's
                // max_allowed_packet and is rejected outright, so the original
                // per-id loop was replaced rather than kept — but one statement
                // per id would be 65,268 round trips.
                foreach (array_chunk((array) $ids, 1000) as $chunk) {
                    DB::table($table)->whereIn('id', $chunk)->delete();
                }
            }
        }

        $this->logRepository->deleteByJob($job['tenant_id'], $jobId);

        $this->jobRepository->update($job['tenant_id'], $jobId, [
            'status'   => 'rolled_back',
            'error_report' => json_encode(['rolled_back_at' => date('Y-m-d H:i:s')]),
        ]);

        return true;
    }

    public function getImportLogs(string $jobId): array
    {
        $job = $this->jobRepository->find('platform', $jobId);

        if (!$job) {
            return [];
        }

        return $this->logRepository->findByImportJob($job['tenant_id'], $jobId);
    }

    /**
     * XLSX support added here rather than in a parallel method.
     *
     * validateFile() has always ACCEPTED .xlsx while this parser only handled
     * .csv, so an xlsx preview returned zero rows and reported itself valid —
     * an empty success, which is the hardest kind of bug to notice. The CSV
     * branch is untouched; the xlsx branch replaces a silent empty result.
     *
     * Note this path materialises the whole sheet, because previewImport()'s
     * contract is to return all rows for display. Bulk imports go through
     * WorkbookImporter instead, which streams.
     */
    private function parseFile(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $rows = [];

        if ($extension === 'csv') {
            if (($handle = fopen($filePath, 'r')) !== false) {
                $headers = fgetcsv($handle);

                while (($row = fgetcsv($handle)) !== false) {
                    $rows[] = array_combine($headers ?? [], $row ?? []);
                }

                fclose($handle);
            }
        }

        if ($extension === 'xlsx') {
            $reader = new XlsxReader($filePath);
            $sheet  = $reader->sheetNames()[0] ?? null;

            if ($sheet === null) {
                return [];
            }

            $headers = null;

            foreach ($reader->rows($sheet) as $row) {
                if ($headers === null) {
                    $headers = array_map(static fn ($h) => (string) $h, $row);
                    continue;
                }

                // Pad short rows: XlsxReader stops at the last populated cell,
                // and array_combine throws when the counts differ.
                $row = array_pad(array_slice($row, 0, count($headers)), count($headers), null);
                $rows[] = array_combine($headers, $row);
            }
        }

        return $rows;
    }

    private function validateRow(array $row, string $entityType): array
    {
        $errors = [];
        $required = ['name', 'code'];

        foreach ($required as $field) {
            if (empty($row[$field])) {
                $errors[] = "{$field} is required";
            }
        }

        return $errors;
    }

    private function getDuplicateKey(array $row, string $entityType): ?string
    {
        return md5(($row['code'] ?? '') . ($row['email'] ?? ''));
    }
}
