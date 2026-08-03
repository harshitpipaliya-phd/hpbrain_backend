<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ImportJobRepository;
use App\Repositories\ImportLogRepository;
use Illuminate\Support\Facades\DB;

final class ImportEngine
{
    public function __construct(
        private readonly ImportJobRepository $jobRepository,
        private readonly ImportLogRepository $logRepository,
    ) {
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

    public function rollbackImport(string $jobId): bool
    {
        $job = $this->jobRepository->find('platform', $jobId);

        if (!$job) {
            return false;
        }

        $rollbackData = $job['rollback_data'] ?? null;

        if (is_string($rollbackData)) {
            $rollbackData = json_decode($rollbackData, true);
        }

        if (!empty($rollbackData['created_ids'])) {
            foreach ($rollbackData['created_ids'] as $entityType => $ids) {
                foreach ($ids as $id) {
                    DB::table("hpbrain_{$entityType}")->where('id', $id)->delete();
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
