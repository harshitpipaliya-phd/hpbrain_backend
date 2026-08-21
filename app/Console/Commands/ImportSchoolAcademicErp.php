<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Sources\SchoolAcademicErpDataSource;
use App\Domain\School\AcademicIntelligenceService;
use App\Domain\School\StudentProjectionBuilder;
use App\Domain\Universal\EntityResolver;
use App\Repositories\AcademicRecordRepository;
use App\Services\Import\ImportProfile;
use App\Services\Import\Loaders\OperationalRecordLoader;
use App\Services\TenantScopedCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ImportSchoolAcademicErp extends Command
{
    protected $signature = 'brain:import-erp-school-academics
        {--tenant= : Tenant/sub_institute_id to import}
        {--source=erp-academic-results : hpbrain_data_sources source_key}
        {--dataset=erp-academic-results : hpbrain_operational_records dataset key}
        {--actor=artisan:brain:import-erp-school-academics : Audit actor label}
        {--limit= : Import only the first N result rows}
        {--no-rebuild : Skip rebuilding hpbrain_students after import}
        {--no-warm : Skip warming derived academic caches}';

    protected $description = 'Import school ERP result marks into the existing Brain academic dataset pipeline.';

    /**
     * @return array<string, string>
     */
    public static function fieldMap(): array
    {
        return [
            'external_ref' => 'external_ref',
            'subject_ref' => 'subject_ref',
            'measure' => 'measure',
            'quantity' => 'quantity',
            'category' => 'category',
            'sub_category' => 'sub_category',
            'state' => 'state',
            'evidence_timestamp' => 'evidence_timestamp',
            'title' => 'title',
            'measure_unit' => 'measure_unit',
        ];
    }

    public function handle(
        EntityResolver $resolver,
        StudentProjectionBuilder $students,
        AcademicIntelligenceService $intelligence,
        AcademicRecordRepository $records,
        TenantScopedCache $cache,
    ): int {
        $tenantId = (string) ($this->option('tenant') ?? '');

        if ($tenantId === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }

        if (! $this->organizationExists($resolver, $tenantId)) {
            $this->error("No active mapped organization found for tenant {$tenantId}.");

            return self::FAILURE;
        }

        $sourceKey = (string) $this->option('source');
        $dataset = (string) $this->option('dataset');
        $actor = (string) $this->option('actor');
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));

        $this->upsertDatasetSource($tenantId, $sourceKey, $dataset, $actor);

        if (DB::connection()->getDriverName() === 'mysql' && $limit === null) {
            $total = $this->countMysqlRows($resolver, $tenantId);

            if ($total === 0) {
                $this->warn("No ERP result marks found for tenant {$tenantId}.");

                return self::SUCCESS;
            }

            $this->info("Importing {$total} ERP academic result rows for tenant {$tenantId}.");

            $jobId = $this->createImportJob($tenantId, $sourceKey, $dataset, $total, $actor);

            try {
                $result = $this->bulkMysqlOperationalRecords($resolver, $tenantId, $dataset, $jobId, $total);
            } catch (\Throwable $e) {
                $this->failImportJob($tenantId, $jobId, $e->getMessage());
                $this->error('ERP academic import failed: '.$e->getMessage());

                return self::FAILURE;
            }

            $this->line('  operational records written  '.$result['created']);
            $this->line('  operational records skipped  '.$result['skipped']);
            $this->line('  operational record errors    0');
            $this->line('  import job                   '.$jobId);

            if (! $this->option('no-rebuild')) {
                $this->rebuildStudents($tenantId, $dataset, $students, $intelligence, $records, $cache);
            }

            return self::SUCCESS;
        }

        $batch = (new SchoolAcademicErpDataSource(
            resolver: $resolver,
            tenantId: $tenantId,
            sourceKey: $sourceKey,
            dataset: $dataset,
            limit: $limit,
        ))->fetch();

        if ($batch->count() === 0) {
            $this->warn("No ERP result marks found for tenant {$tenantId}.");

            return self::SUCCESS;
        }

        $this->info("Importing {$batch->count()} ERP academic result rows for tenant {$tenantId}.");

        $jobId = $this->createImportJob($tenantId, $sourceKey, $dataset, $batch->count(), $actor);
        $result = $this->loadOperationalRecords($tenantId, $dataset, $batch, $jobId, $actor);

        $this->line('  operational records written  '.($result['created'] + $result['updated']));
        $this->line('  operational records created  '.$result['created']);
        $this->line('  operational records updated  '.$result['updated']);
        $this->line('  operational records skipped  '.$result['skipped']);
        $this->line('  operational record errors    '.$result['errors']);
        $this->line('  import job                   '.$jobId);

        if (! $this->option('no-rebuild')) {
            $this->rebuildStudents($tenantId, $dataset, $students, $intelligence, $records, $cache);
        }

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function organizationExists(EntityResolver $resolver, string $tenantId): bool
    {
        try {
            $organization = $resolver->resolve($tenantId, 'Organization');

            $query = DB::table($organization->table)
                ->where($organization->tenantKey, $tenantId)
                ->where($organization->primaryKey, $tenantId);

            if ($organization->has('deletedAt')) {
                $query->whereNull($organization->field('deletedAt'));
            }

            return $query->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function upsertDatasetSource(string $tenantId, string $sourceKey, string $dataset, string $actor): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $existingId = DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('source_key', $sourceKey)
            ->value('id');

        DB::table('hpbrain_data_sources')->updateOrInsert(
            ['tenant_id' => $tenantId, 'source_key' => $sourceKey],
            [
                'id' => $existingId ?: Uuid::uuid4()->toString(),
                'source_type' => 'dataset',
                'display_name' => 'ERP Academic Results',
                'config' => json_encode([
                    'dataset' => $dataset,
                    'dataset_role' => 'academic',
                    'source' => 'school_erp_result_marks',
                ], JSON_UNESCAPED_SLASHES),
                'field_map' => json_encode(self::fieldMap(), JSON_UNESCAPED_SLASHES),
                'is_active' => 1,
                'created_by' => $actor,
                'updated_date' => $now,
                'created_date' => $now,
            ],
        );
    }

    private function createImportJob(string $tenantId, string $sourceKey, string $dataset, int $totalRows, string $actor): string
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_import_jobs')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'org_id' => $tenantId,
            'source_id' => $sourceKey,
            'source_ref' => 'SchoolAcademicErp@'.$dataset,
            'sync_type' => 'one_time_historical_import',
            'import_type' => 'internal_erp',
            'entity_type' => $dataset,
            'status' => 'processing',
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'duplicate_count' => 0,
            'started_by' => $actor,
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        return $id;
    }

    private function failImportJob(string $tenantId, string $jobId, string $message): void
    {
        DB::table('hpbrain_import_jobs')
            ->where('tenant_id', $tenantId)
            ->where('id', $jobId)
            ->update([
                'status' => 'failed',
                'error_count' => 1,
                'completed_date' => gmdate('Y-m-d H:i:s'),
                'updated_date' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    private function countMysqlRows(EntityResolver $resolver, string $tenantId): int
    {
        $prefix = $this->sourcePrefix($resolver, $tenantId);

        $row = DB::selectOne(
            "SELECT COUNT(*) AS n
               FROM {$prefix}result_marks
              WHERE sub_institute_id = ?",
            [$tenantId],
        );

        return (int) ($row->n ?? 0);
    }

    /**
     * @return array{created: int, skipped: int}
     */
    private function bulkMysqlOperationalRecords(
        EntityResolver $resolver,
        string $tenantId,
        string $dataset,
        string $jobId,
        int $totalRows,
    ): array {
        $prefix = $this->sourcePrefix($resolver, $tenantId);
        $now = gmdate('Y-m-d H:i:s');

        return $this->bulkMysqlResultMarksOnly($prefix, $tenantId, $dataset, $jobId, $totalRows, $now);

        $idHash = "SHA2(CONCAT('erp-academic-results|', rm.sub_institute_id, '|', rm.id), 256)";
        $studentRef = "COALESCE(NULLIF(student.enrollment_no, ''), CAST(rm.student_id AS CHAR))";
        $studentName = "TRIM(CONCAT_WS(' ', NULLIF(student.first_name, ''), NULLIF(student.middle_name, ''), NULLIF(student.last_name, '')))";
        $standard = "NULLIF(TRIM(COALESCE(rm.standard_name, standard.name, '')), '')";
        $subject = "NULLIF(TRIM(COALESCE(rm.subject_name, subject.subject_name, '')), '')";
        $exam = "NULLIF(TRIM(COALESCE(rm.exam_title, exam.title, '')), '')";
        $year = 'COALESCE(exam.syear, YEAR(rm.created_at), YEAR(rm.updated_at))';

        $sql = <<<SQL
        INSERT IGNORE INTO hpbrain_operational_records (
            id, tenant_id, org_id, dataset, natural_key, source_file, source_row,
            occurred_at, closed_at, status, category, sub_category, owner_name,
            supervisor_name, zone, area, subject_ref, metric_value, metric_unit,
            quantity, payload, row_hash, import_job_id, created_date, updated_date
        )
        SELECT
            LOWER(CONCAT_WS('-',
                SUBSTR({$idHash}, 1, 8), SUBSTR({$idHash}, 9, 4), SUBSTR({$idHash}, 13, 4),
                SUBSTR({$idHash}, 17, 4), SUBSTR({$idHash}, 21, 12)
            )) AS id,
            CAST(rm.sub_institute_id AS CHAR) AS tenant_id,
            CAST(rm.sub_institute_id AS CHAR) AS org_id,
            ? AS dataset,
            CAST(rm.id AS CHAR) AS natural_key,
            'school_erp.result_marks' AS source_file,
            rm.id AS source_row,
            CASE
                WHEN {$year} IS NULL THEN NULL
                ELSE STR_TO_DATE(CONCAT({$year}, '-01-01 00:00:00'), '%Y-%m-%d %H:%i:%s')
            END AS occurred_at,
            NULL AS closed_at,
            {$standard} AS status,
            {$subject} AS category,
            {$exam} AS sub_category,
            NULL AS owner_name,
            NULL AS supervisor_name,
            NULL AS zone,
            NULL AS area,
            {$studentRef} AS subject_ref,
            CAST(NULLIF(rm.points, '') AS DECIMAL(14,4)) AS metric_value,
            'marks' AS metric_unit,
            CAST(ROUND(COALESCE(exam.points, exam.con_point)) AS SIGNED) AS quantity,
            JSON_OBJECT(
                'external_ref', CAST(rm.id AS CHAR),
                'subject_ref', {$studentRef},
                'measure', CAST(rm.points AS CHAR),
                'quantity', CAST(COALESCE(exam.points, exam.con_point) AS CHAR),
                'category', {$subject},
                'sub_category', {$exam},
                'state', {$standard},
                'evidence_timestamp', CAST({$year} AS CHAR),
                'title', COALESCE(NULLIF({$studentName}, ''), {$studentRef}),
                'student_name', COALESCE(NULLIF({$studentName}, ''), {$studentRef}),
                'measure_unit', 'marks',
                'student_id', CAST(rm.student_id AS CHAR),
                'enrollment_no', {$studentRef},
                'standard', {$standard},
                'subject', {$subject},
                'exam', {$exam},
                'grade', rm.grade,
                'percentage', CAST(rm.per AS CHAR),
                'comment', rm.comment,
                'is_absent', rm.is_absent,
                'source_dataset', ?
            ) AS payload,
            SHA2(CONCAT_WS('|',
                rm.sub_institute_id, ?, rm.id, {$studentRef}, rm.points,
                COALESCE(exam.points, exam.con_point), {$standard}, {$subject}, {$exam}, {$year}
            ), 256) AS row_hash,
            ? AS import_job_id,
            ? AS created_date,
            ? AS updated_date
        FROM {$prefix}result_marks rm
        JOIN {$prefix}tblstudent student
          ON student.id = rm.student_id
         AND student.sub_institute_id = rm.sub_institute_id
        LEFT JOIN {$prefix}result_create_exam exam
          ON exam.id = rm.exam_id
         AND exam.sub_institute_id = rm.sub_institute_id
        LEFT JOIN {$prefix}standard standard
          ON standard.id = exam.standard_id
         AND standard.sub_institute_id = rm.sub_institute_id
        LEFT JOIN {$prefix}subject subject
          ON subject.id = exam.subject_id
         AND subject.sub_institute_id = rm.sub_institute_id
        WHERE rm.sub_institute_id = ?
          AND (student.status IS NULL OR student.status = 1)
          AND rm.id > ?
          AND rm.id <= ?
        SQL;

        $bounds = DB::selectOne(
            "SELECT MIN(rm.id) AS min_id, MAX(rm.id) AS max_id
               FROM {$prefix}result_marks rm
               JOIN {$prefix}tblstudent student
                 ON student.id = rm.student_id
                AND student.sub_institute_id = rm.sub_institute_id
              WHERE rm.sub_institute_id = ?
                AND (student.status IS NULL OR student.status = 1)",
            [$tenantId],
        );

        $minId = (int) ($bounds->min_id ?? 0);
        $maxId = (int) ($bounds->max_id ?? 0);
        $chunkSize = 5000;
        $created = 0;
        $processed = 0;

        for ($from = max(0, $minId - 1); $from < $maxId; $from += $chunkSize) {
            $to = min($maxId, $from + $chunkSize);

            $written = DB::affectingStatement($sql, [
                $dataset,
                $dataset,
                $dataset,
                $jobId,
                $now,
                $now,
                $tenantId,
                $from,
                $to,
            ]);

            $created += $written;

            $row = DB::selectOne(
                "SELECT COUNT(*) AS n
                   FROM {$prefix}result_marks rm
                   JOIN {$prefix}tblstudent student
                     ON student.id = rm.student_id
                    AND student.sub_institute_id = rm.sub_institute_id
                  WHERE rm.sub_institute_id = ?
                    AND (student.status IS NULL OR student.status = 1)
                    AND rm.id > ?
                    AND rm.id <= ?",
                [$tenantId, $from, $to],
            );

            $processed += (int) ($row->n ?? 0);

            DB::table('hpbrain_import_jobs')
                ->where('tenant_id', $tenantId)
                ->where('id', $jobId)
                ->update([
                    'processed_rows' => $processed,
                    'success_count' => $created,
                    'duplicate_count' => max(0, $processed - $created),
                    'updated_date' => gmdate('Y-m-d H:i:s'),
                ]);
        }

        $skipped = max(0, $processed - $created);

        DB::table('hpbrain_import_jobs')
            ->where('tenant_id', $tenantId)
            ->where('id', $jobId)
            ->update([
                'status' => 'completed',
                'processed_rows' => $processed,
                'success_count' => $created,
                'duplicate_count' => $skipped,
                'error_count' => 0,
                'rollback_data' => json_encode(['created_ids' => ['operational_records' => []]], JSON_UNESCAPED_SLASHES),
                'completed_date' => $now,
                'updated_date' => $now,
            ]);

        DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('source_key', (string) $this->option('source'))
            ->update([
                'last_synced_at' => $now,
                'updated_date' => $now,
            ]);

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Fast path for live ERP backfills. It avoids slow joins on the remote ERP
     * and projects the result row itself into the existing operational dataset.
     *
     * @return array{created: int, skipped: int}
     */
    private function bulkMysqlResultMarksOnly(
        string $prefix,
        string $tenantId,
        string $dataset,
        string $jobId,
        int $totalRows,
        string $now,
    ): array {
        $bounds = DB::selectOne(
            "SELECT MIN(id) AS min_id, MAX(id) AS max_id
               FROM {$prefix}result_marks
              WHERE sub_institute_id = ?",
            [$tenantId],
        );

        $minId = (int) ($bounds->min_id ?? 0);
        $maxId = (int) ($bounds->max_id ?? 0);
        $chunkSize = 1000;
        $created = 0;
        $processed = 0;
        $idHash = "SHA2(CONCAT('erp-academic-results|', rm.sub_institute_id, '|', rm.id), 256)";

        $sql = <<<SQL
        INSERT IGNORE INTO hpbrain_operational_records (
            id, tenant_id, org_id, dataset, natural_key, source_file, source_row,
            occurred_at, status, category, sub_category, subject_ref, metric_value,
            metric_unit, quantity, payload, row_hash, import_job_id, created_date, updated_date
        )
        SELECT
            LOWER(CONCAT_WS('-',
                SUBSTR({$idHash}, 1, 8), SUBSTR({$idHash}, 9, 4), SUBSTR({$idHash}, 13, 4),
                SUBSTR({$idHash}, 17, 4), SUBSTR({$idHash}, 21, 12)
            )),
            CAST(rm.sub_institute_id AS CHAR),
            CAST(rm.sub_institute_id AS CHAR),
            ?,
            CAST(rm.id AS CHAR),
            'school_erp.result_marks',
            rm.id,
            COALESCE(rm.created_at, rm.updated_at),
            NULLIF(rm.standard_name, ''),
            NULLIF(rm.subject_name, ''),
            NULLIF(rm.exam_title, ''),
            CAST(rm.student_id AS CHAR),
            CAST(NULLIF(rm.points, '') AS DECIMAL(14,4)),
            'marks',
            NULL,
            JSON_OBJECT(
                'external_ref', CAST(rm.id AS CHAR),
                'subject_ref', CAST(rm.student_id AS CHAR),
                'measure', CAST(rm.points AS CHAR),
                'category', rm.subject_name,
                'sub_category', rm.exam_title,
                'state', rm.standard_name,
                'evidence_timestamp', CAST(COALESCE(rm.created_at, rm.updated_at) AS CHAR),
                'title', CAST(rm.student_id AS CHAR),
                'student_name', CAST(rm.student_id AS CHAR),
                'measure_unit', 'marks',
                'student_id', CAST(rm.student_id AS CHAR),
                'grade', rm.grade,
                'percentage', CAST(rm.per AS CHAR),
                'comment', rm.comment,
                'is_absent', rm.is_absent,
                'source_dataset', ?
            ),
            SHA2(CONCAT_WS('|', rm.sub_institute_id, ?, rm.id, rm.student_id, rm.points, rm.per, rm.grade), 256),
            ?,
            ?,
            ?
        FROM {$prefix}result_marks rm
        WHERE rm.sub_institute_id = ?
          AND rm.id > ?
          AND rm.id <= ?
        SQL;

        for ($from = max(0, $minId - 1); $from < $maxId; $from += $chunkSize) {
            $to = min($maxId, $from + $chunkSize);

            $written = DB::affectingStatement($sql, [
                $dataset,
                $dataset,
                $dataset,
                $jobId,
                $now,
                $now,
                $tenantId,
                $from,
                $to,
            ]);

            $created += $written;

            $row = DB::selectOne(
                "SELECT COUNT(*) AS n
                   FROM {$prefix}result_marks
                  WHERE sub_institute_id = ?
                    AND id > ?
                    AND id <= ?",
                [$tenantId, $from, $to],
            );

            $processed += (int) ($row->n ?? 0);

            DB::table('hpbrain_import_jobs')
                ->where('tenant_id', $tenantId)
                ->where('id', $jobId)
                ->update([
                    'processed_rows' => $processed,
                    'success_count' => $created,
                    'duplicate_count' => max(0, $processed - $created),
                    'updated_date' => gmdate('Y-m-d H:i:s'),
                ]);
        }

        $skipped = max(0, $processed - $created);

        DB::table('hpbrain_import_jobs')
            ->where('tenant_id', $tenantId)
            ->where('id', $jobId)
            ->update([
                'status' => 'completed',
                'processed_rows' => $processed,
                'success_count' => $created,
                'duplicate_count' => $skipped,
                'error_count' => 0,
                'rollback_data' => json_encode(['created_ids' => ['operational_records' => []]], JSON_UNESCAPED_SLASHES),
                'completed_date' => $now,
                'updated_date' => $now,
            ]);

        DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('source_key', (string) $this->option('source'))
            ->update([
                'last_synced_at' => $now,
                'updated_date' => $now,
            ]);

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function sourcePrefix(EntityResolver $resolver, string $tenantId): string
    {
        $organization = $resolver->resolve($tenantId, 'Organization');
        $table = $organization->table;
        $dot = strrpos($table, '.');

        if ($dot === false) {
            return '';
        }

        $database = substr($table, 0, $dot);

        if (! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new \RuntimeException('Mapped organization source database name is not safe to use in an ERP import.');
        }

        return "`{$database}`.";
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: int}
     */
    private function loadOperationalRecords(
        string $tenantId,
        string $dataset,
        \App\Domain\Ingestion\IngestionBatch $batch,
        string $jobId,
        string $actor,
    ): array {
        $profile = ImportProfile::fromConfig('school_erp', $dataset, [
            'file' => 'internal_erp',
            'sheet' => 'result_marks',
            'loader' => 'operational',
            'dataset' => $dataset,
            'key' => ['external_ref'],
        ]);

        $loader = new OperationalRecordLoader();
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $processed = 0;

        try {
            foreach ($batch->chunks(1000) as $chunk) {
                foreach ($chunk as $row) {
                    $processed++;
                    $naturalKey = trim((string) ($row['external_ref'] ?? ''));

                    if ($naturalKey === '') {
                        $counts['errors']++;
                        $this->logImportError($tenantId, $jobId, $processed, 'ERP result row has no external_ref.');

                        continue;
                    }

                    try {
                        $result = $loader->load(
                            $tenantId,
                            $profile,
                            $naturalKey,
                            $this->operationalFields($row),
                            [
                                'org_id' => $tenantId,
                                'source_file' => 'school_erp.result_marks',
                                'source_row' => $processed,
                                'import_job_id' => $jobId,
                                'actor' => $actor,
                            ],
                        );

                        $counts[$result['action'] === 'error' ? 'errors' : $result['action']]++;
                    } catch (\Throwable $e) {
                        $counts['errors']++;
                        $this->logImportError($tenantId, $jobId, $processed, $e->getMessage());
                    }
                }

                DB::table('hpbrain_import_jobs')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $jobId)
                    ->update([
                        'processed_rows' => $processed,
                        'success_count' => $counts['created'] + $counts['updated'],
                        'duplicate_count' => $counts['skipped'],
                        'error_count' => $counts['errors'],
                        'updated_date' => gmdate('Y-m-d H:i:s'),
                    ]);
            }
        } finally {
            $loader->flush();
        }

        DB::table('hpbrain_import_jobs')
            ->where('tenant_id', $tenantId)
            ->where('id', $jobId)
            ->update([
                'status' => $counts['errors'] > 0 ? 'completed_with_errors' : 'completed',
                'processed_rows' => $processed,
                'success_count' => $counts['created'] + $counts['updated'],
                'duplicate_count' => $counts['skipped'],
                'error_count' => $counts['errors'],
                'rollback_data' => json_encode(['created_ids' => $loader->createdIds()], JSON_UNESCAPED_SLASHES),
                'completed_date' => gmdate('Y-m-d H:i:s'),
                'updated_date' => gmdate('Y-m-d H:i:s'),
            ]);

        DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('source_key', (string) $this->option('source'))
            ->update([
                'last_synced_at' => gmdate('Y-m-d H:i:s'),
                'updated_date' => gmdate('Y-m-d H:i:s'),
            ]);

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function operationalFields(array $row): array
    {
        return [
            'occurred_at' => $this->dateTime($row['evidence_timestamp'] ?? null),
            'status' => $this->clip($row['state'] ?? null, 64),
            'category' => $this->clip($row['category'] ?? null, 191),
            'sub_category' => $this->clip($row['sub_category'] ?? null, 191),
            'subject_ref' => $this->clip($row['subject_ref'] ?? null, 191),
            'metric_value' => $this->decimal($row['measure'] ?? null),
            'metric_unit' => $this->clip($row['measure_unit'] ?? null, 20),
            'quantity' => $this->wholeNumber($row['quantity'] ?? null),
            'payload' => $row,
        ];
    }

    private function logImportError(string $tenantId, string $jobId, int $rowNumber, string $message): void
    {
        DB::table('hpbrain_import_logs')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => $tenantId,
            'import_job_id' => $jobId,
            'row_number' => $rowNumber,
            'action' => 'error',
            'entity_type' => 'erp-academic-results',
            'error_message' => mb_substr($message, 0, 1000),
            'created_date' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? round((float) $value, 4) : null;
    }

    private function wholeNumber(mixed $value): ?int
    {
        $decimal = $this->decimal($value);

        return $decimal === null ? null : (int) round($decimal);
    }

    private function dateTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^(19|20)\d{2}$/', $value)) {
            return $value.'-01-01 00:00:00';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function clip(mixed $value, int $length): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    private function warmCaches(
        string $tenantId,
        AcademicIntelligenceService $intelligence,
        AcademicRecordRepository $records,
        TenantScopedCache $cache,
    ): void {
        try {
            $version = $intelligence->dataVersion($tenantId);

            $cache->remember(
                $tenantId,
                "hpbrain:school:structure:v1:{$tenantId}:{$version}",
                21600,
                fn (): array => $records->structure($tenantId),
            );

            $intelligence->forTenant($tenantId, true);
        } catch (\Throwable $e) {
            $this->warn('Could not warm academic caches: '.$e->getMessage());
        }
    }

    private function rebuildStudents(
        string $tenantId,
        string $dataset,
        StudentProjectionBuilder $students,
        AcademicIntelligenceService $intelligence,
        AcademicRecordRepository $records,
        TenantScopedCache $cache,
    ): void {
        $projection = $students->rebuild($tenantId, $dataset, null);

        if ($projection['skipped'] !== null) {
            $this->warn('Student projection skipped: '.$projection['skipped']);
        } else {
            $this->line('  students in projection       '.$projection['students']);
        }

        if (! $this->option('no-warm') && $projection['skipped'] === null) {
            $this->warmCaches($tenantId, $intelligence, $records, $cache);
        }
    }
}
