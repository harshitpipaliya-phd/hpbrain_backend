<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ImportSchoolFeeWorkspace extends Command
{
    protected $signature = 'brain:import-school-fees
        {csv : Path to the fee intelligence CSV}
        {--tenant= : Target sub_institute_id}
        {--replace : Replace the tenant school_fee operational dataset before import}
        {--actor=artisan:brain:import-school-fees : Audit actor label}';

    protected $description = 'Import a school fee intelligence CSV into operational records and ERP-visible student/class master rows';

    private const ID_NAMESPACE = '6f9619ff-8b86-d011-b42d-00c04fc964ff';
    private const DATASET = 'school_fee';
    private const SOURCE_KEY = 'fees-intelligence-mockup-ready-dataset';

    public function handle(): int
    {
        $tenantId = (string) ($this->option('tenant') ?? '');
        $path = (string) $this->argument('csv');

        if ($tenantId === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("CSV is not readable: {$path}");

            return self::FAILURE;
        }

        if (! $this->tenantExists($tenantId)) {
            $this->error("No active organization exists in institute_detail for sub_institute_id {$tenantId}.");

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);

        if ($rows === []) {
            $this->error('CSV contains no data rows.');

            return self::FAILURE;
        }

        $validation = $this->validateRows($rows);
        if ($validation['errors'] !== []) {
            foreach (array_slice($validation['errors'], 0, 20) as $error) {
                $this->error($error);
            }

            if (count($validation['errors']) > 20) {
                $this->error('...and '.(count($validation['errors']) - 20).' more validation errors.');
            }

            return self::FAILURE;
        }

        $actor = (string) $this->option('actor');
        $replace = (bool) $this->option('replace');

        $result = DB::transaction(function () use ($tenantId, $path, $rows, $actor, $replace): array {
            if ($replace) {
                DB::table('hpbrain_operational_records')
                    ->where('tenant_id', $tenantId)
                    ->where('dataset', self::DATASET)
                    ->delete();
            }

            $sourceId = $this->upsertDataSource($tenantId, $path, $actor);
            $jobId = $this->createImportJob($tenantId, $sourceId, $path, count($rows), $actor);
            $erpActorId = $this->erpActorId($tenantId);
            $departmentIds = $this->upsertDepartments($tenantId, $rows, $erpActorId);
            $people = $this->upsertStudents($tenantId, $rows, $departmentIds, $erpActorId);
            $records = $this->insertOperationalRecords($tenantId, $jobId, $path, $rows);

            DB::table('hpbrain_import_jobs')
                ->where('tenant_id', $tenantId)
                ->where('id', $jobId)
                ->update([
                    'status' => 'completed',
                    'processed_rows' => count($rows),
                    'success_count' => $records['created'],
                    'duplicate_count' => $records['skipped'],
                    'error_count' => 0,
                    'rollback_data' => json_encode(['created_ids' => ['operational_records' => $records['ids']]]),
                    'completed_date' => now()->format('Y-m-d H:i:s'),
                ]);

            return [
                'jobId' => $jobId,
                'departments' => $departmentIds,
                'people' => $people,
                'records' => $records,
            ];
        });

        $this->info('Imported school fee workspace dataset.');
        $this->line('Rows read: '.number_format(count($rows)));
        $this->line('Operational records created: '.number_format($result['records']['created']));
        $this->line('Operational records skipped: '.number_format($result['records']['skipped']));
        $this->line('Class-section departments available: '.number_format(count($result['departments'])));
        $this->line('Student people available: '.number_format($result['people']['total']));
        $this->line('Import job: '.$result['jobId']);

        return self::SUCCESS;
    }

    private function tenantExists(string $tenantId): bool
    {
        return DB::table('institute_detail')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);

            return [];
        }

        $headers = array_map(static fn ($header): string => trim((string) $header), $headers);
        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null] || $values === false) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($values[$index] ?? ''));
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return array{errors: array<int, string>}
     */
    private function validateRows(array $rows): array
    {
        $required = [
            'student_id', 'admission_no', 'student_name', 'class', 'section',
            'invoice_id', 'fee_due_date', 'fee_period', 'net_fee_amount',
            'amount_paid', 'outstanding_amount', 'payment_status',
        ];
        $errors = [];
        $first = $rows[0] ?? [];

        foreach ($required as $column) {
            if (! array_key_exists($column, $first)) {
                $errors[] = "Missing required column: {$column}";
            }
        }

        if ($errors !== []) {
            return ['errors' => $errors];
        }

        $invoiceIds = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $invoice = $row['invoice_id'] ?? '';
            $student = $row['student_id'] ?? '';
            $net = $this->decimal($row['net_fee_amount'] ?? null);
            $paid = $this->decimal($row['amount_paid'] ?? null);
            $outstanding = $this->decimal($row['outstanding_amount'] ?? null);

            if ($invoice === '') {
                $errors[] = "Line {$line}: invoice_id is blank.";
            } elseif (isset($invoiceIds[$invoice])) {
                $errors[] = "Line {$line}: duplicate invoice_id {$invoice}.";
            }
            $invoiceIds[$invoice] = true;

            if ($student === '') {
                $errors[] = "Line {$line}: student_id is blank.";
            }
            if ($net === null || $paid === null || $outstanding === null) {
                $errors[] = "Line {$line}: amount fields must be numeric.";
            } elseif (abs(($net - $paid) - $outstanding) > 1.0) {
                $errors[] = "Line {$line}: outstanding_amount does not equal net_fee_amount minus amount_paid.";
            }

            foreach (['fee_due_date', 'payment_date', 'last_reminder_date'] as $dateColumn) {
                if (($row[$dateColumn] ?? '') !== '' && strtotime((string) $row[$dateColumn]) === false) {
                    $errors[] = "Line {$line}: {$dateColumn} is not a valid date.";
                }
            }
        }

        return ['errors' => $errors];
    }

    private function upsertDataSource(string $tenantId, string $path, string $actor): string
    {
        $now = now()->format('Y-m-d H:i:s');
        $fieldMap = [
            'external_ref' => 'invoice_id',
            'subject_ref' => 'student_id',
            'title' => 'student_name',
            'state' => 'payment_status',
            'owner' => 'recommended_action',
            'category' => 'fee_component',
            'measure' => 'net_fee_amount',
            'measure_unit' => 'INR',
            'evidence_timestamp' => 'fee_due_date',
        ];

        $existingId = DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('source_key', self::SOURCE_KEY)
            ->value('id');

        DB::table('hpbrain_data_sources')->updateOrInsert(
            ['tenant_id' => $tenantId, 'source_key' => self::SOURCE_KEY],
            [
                'id' => $existingId ?: Uuid::uuid4()->toString(),
                'source_type' => 'dataset',
                'display_name' => basename($path),
                'config' => json_encode(['dataset' => self::DATASET, 'measure_unit' => 'INR']),
                'field_map' => json_encode($fieldMap),
                'is_active' => 1,
                'last_synced_at' => $now,
                'created_by' => $actor,
                'updated_date' => $now,
            ],
        );

        return self::SOURCE_KEY;
    }

    private function createImportJob(string $tenantId, string $sourceId, string $path, int $totalRows, string $actor): string
    {
        $now = now()->format('Y-m-d H:i:s');
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_import_jobs')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'org_id' => $tenantId,
            'source_id' => $sourceId,
            'source_ref' => $path,
            'sync_type' => 'replace',
            'import_type' => 'csv',
            'entity_type' => self::DATASET,
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

    /**
     * @param array<int, array<string, string>> $rows
     * @return array<string, int>
     */
    private function erpActorId(string $tenantId): int
    {
        return (int) (DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id') ?? 0);
    }

    private function upsertDepartments(string $tenantId, array $rows, int $actorId): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $seen = [];

        foreach ($rows as $row) {
            $name = $this->departmentName($row);
            if ($name !== '') {
                $seen[$name] = true;
            }
        }

        foreach (array_keys($seen) as $name) {
            $exists = DB::table('hrms_departments')
                ->where('sub_institute_id', $tenantId)
                ->where('department', $name)
                ->whereNull('deleted_at')
                ->first();

            if ($exists !== null) {
                continue;
            }

            DB::table('hrms_departments')->insert([
                'department' => $name,
                'parent_id' => 0,
                'status' => 1,
                'is_calculated' => 0,
                'sub_institute_id' => $tenantId,
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return DB::table('hrms_departments')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereIn('department', array_keys($seen))
            ->pluck('id', 'department')
            ->mapWithKeys(static fn ($id, $name): array => [(string) $name => (int) $id])
            ->all();
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @param array<string, int> $departmentIds
     * @return array{created: int, updated: int, total: int}
     */
    private function upsertStudents(string $tenantId, array $rows, array $departmentIds, int $actorId): array
    {
        $profileId = $this->studentProfileId($tenantId);
        $now = now()->format('Y-m-d H:i:s');
        $students = [];

        foreach ($rows as $row) {
            $studentId = $row['student_id'] ?? '';
            if ($studentId === '') {
                continue;
            }

            $students[$studentId] = $row;
        }

        $created = 0;
        $updated = 0;

        foreach ($students as $studentId => $row) {
            [$firstName, $lastName] = $this->splitName($row['student_name'] ?? $studentId);
            $email = trim((string) ($row['guardian_email'] ?? '')) ?: $this->studentEmail($tenantId, $studentId);
            $departmentId = $departmentIds[$this->departmentName($row)] ?? null;
            $existing = DB::table('tbluser')
                ->where('sub_institute_id', $tenantId)
                ->where('user_name', $studentId)
                ->whereNull('deleted_at')
                ->first();

            $values = [
                'user_name' => $studentId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'mobile' => $row['guardian_phone'] ?: null,
                'user_profile_id' => $profileId,
                'department_id' => $departmentId,
                'employee_id' => $this->numericStudentId($studentId),
                'employee_no' => $studentId,
                'status' => 1,
                'updated_by' => $actorId,
                'updated_at' => $now,
            ];

            if ($existing === null) {
                DB::table('tbluser')->insert($values + [
                    'password' => hash('sha256', $studentId.'|'.$tenantId),
                    'plain_password' => null,
                    'sub_institute_id' => $tenantId,
                    'created_by' => $actorId,
                    'created_at' => $now,
                ]);
                $created++;
            } else {
                DB::table('tbluser')->where('id', $existing->id)->update($values);
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'total' => count($students)];
    }

    private function studentProfileId(string $tenantId): int
    {
        $now = now()->format('Y-m-d H:i:s');
        $existing = DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenantId)
            ->where('name', 'Student')
            ->whereNull('deleted_at')
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('tbluserprofilemaster')->insertGetId([
            'parent_id' => 0,
            'name' => 'Student',
            'description' => 'Student',
            'sort_order' => 4,
            'status' => 1,
            'sub_institute_id' => $tenantId,
            'client_id' => 15,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return array{created: int, skipped: int, ids: array<int, string>}
     */
    private function insertOperationalRecords(string $tenantId, string $jobId, string $path, array $rows): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $created = 0;
        $skipped = 0;
        $ids = [];
        $rowNumber = 1;

        foreach (array_chunk($rows, 500, true) as $chunk) {
            $records = [];

            foreach ($chunk as $row) {
                $rowNumber++;
                $id = Uuid::uuid5(self::ID_NAMESPACE, implode('|', [
                    'school-fee-workspace',
                    $tenantId,
                    self::SOURCE_KEY,
                    $row['invoice_id'],
                ]))->toString();

                $record = [
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'org_id' => $tenantId,
                    'dataset' => self::DATASET,
                    'natural_key' => $row['invoice_id'],
                    'source_file' => basename($path),
                    'source_row' => $rowNumber,
                    'occurred_at' => $this->dateTime($row['fee_due_date'] ?? null),
                    'closed_at' => $this->dateTime($row['payment_date'] ?? null),
                    'status' => $this->clip($row['payment_status'] ?? null, 64),
                    'category' => $this->clip($row['fee_component'] ?? null, 191),
                    'sub_category' => $this->clip($row['fee_plan'] ?? null, 191),
                    'owner_name' => $this->clip($row['recommended_action'] ?? null, 191),
                    'supervisor_name' => null,
                    'zone' => $this->clip($row['campus_name'] ?? null, 191),
                    'area' => $this->clip($this->departmentName($row), 191),
                    'subject_ref' => $this->clip($row['student_id'] ?? null, 191),
                    'metric_value' => $this->decimal($row['net_fee_amount'] ?? null),
                    'metric_unit' => 'INR',
                    'quantity' => null,
                    'payload' => json_encode($row, JSON_UNESCAPED_UNICODE),
                    'import_job_id' => $jobId,
                    'created_date' => $now,
                    'updated_date' => $now,
                ];

                $hashable = $record;
                unset($hashable['id'], $hashable['source_row'], $hashable['import_job_id'], $hashable['source_file'], $hashable['created_date'], $hashable['updated_date']);
                $record['row_hash'] = hash('sha256', json_encode($hashable, JSON_UNESCAPED_UNICODE) ?: '');

                $records[] = $record;
                $ids[] = $id;
            }

            $written = DB::table('hpbrain_operational_records')->insertOrIgnore($records);
            $created += $written;
            $skipped += count($records) - $written;
        }

        return ['created' => $created, 'skipped' => $skipped, 'ids' => $ids];
    }

    /** @param array<string, string> $row */
    private function departmentName(array $row): string
    {
        $class = trim((string) ($row['class'] ?? ''));
        $section = trim((string) ($row['section'] ?? ''));

        return trim('Grade '.$class.' '.$section);
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['Student', ''];
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = array_shift($parts) ?: $name;

        return [$first, implode(' ', $parts)];
    }

    private function studentEmail(string $tenantId, string $studentId): string
    {
        return strtolower($studentId).'@tenant-'.$tenantId.'.student.local';
    }

    private function numericStudentId(string $studentId): int
    {
        $digits = preg_replace('/\D+/', '', $studentId);

        return $digits === '' ? abs(crc32($studentId)) : (int) $digits;
    }

    private function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function dateTime(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    private function clip(?string $value, int $length): ?string
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
