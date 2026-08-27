<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Intelligence\IntelligenceEngine;
use App\Domain\Organization\OrganizationSignupService;
use App\Domain\Signals\OperationalSignalWriter;
use App\Domain\Signals\RuleEvaluator;
use App\Domain\Signals\SignalRuleRegistry;
use App\Domain\Tenancy\TenantOwnedTables;
use App\Domain\Tenancy\TenantPurgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ActivateFiberValleyLongFormat extends Command
{
    protected $signature = 'fibervalley:activate-longformat
        {--file=* : Long-format CSV file path}
        {--email=fibervellay@gmail.com : Admin login email}
        {--password=admin1234 : Admin login password}
        {--replace-old : Permanently purge existing Fiber Valley tenants before creating the clean tenant}
        {--force-existing= : Import into an existing tenant id instead of creating one}';

    protected $description = 'Create Fiber Valley and import long-format department CSV exports through the existing tenant/operational architecture.';

    private const FILES = [
        'C:\\Users\\omshivay\\Desktop\\ADK\\FiberValley_Departments_LongFormat\\FiberValley_1_SALES.csv',
        'C:\\Users\\omshivay\\Desktop\\ADK\\FiberValley_Departments_LongFormat\\FiberValley_2_CST_SPLICING_CABLEPULLING.csv',
        'C:\\Users\\omshivay\\Desktop\\ADK\\FiberValley_Departments_LongFormat\\FiberValley_3_HELPDESK_MANAGEMENT_SHARED.csv',
    ];

    private const ID_NAMESPACE = '6f9619ff-8b86-d011-b42d-00c04fc964ff';

    public function handle(
        OrganizationSignupService $signup,
        RuleEvaluator $evaluator,
        IntelligenceEngine $intelligence,
        TenantPurgeService $purge,
        TenantOwnedTables $tenantTables,
    ): int {
        $files = (array) ($this->option('file') ?: self::FILES);
        foreach ($files as $file) {
            if (! is_file((string) $file)) {
                $this->error('Missing CSV: '.$file);

                return self::FAILURE;
            }
        }

        $existingTenants = $this->fiberValleyTenants((string) $this->option('email'));
        $this->info('Existing active Fiber Valley tenant rows found: '.count($existingTenants));
        $verification = $this->verifyOldFiberValley((string) $this->option('email'), $existingTenants);
        $this->table(['Area', 'Matches'], $verification);

        if ($existingTenants !== []) {
            $this->newLine();
            $this->table(
                ['Tenant', 'Organization', 'Email', 'Deleted At'],
                array_map(static fn (array $row): array => [
                    $row['tenant_id'],
                    $row['name'],
                    $row['email'],
                    $row['deleted_at'] ?? '',
                ], $existingTenants),
            );
        }

        $tenantId = (string) ($this->option('force-existing') ?: '');
        if ($tenantId === '') {
            if ($existingTenants !== []) {
                if (! (bool) $this->option('replace-old')) {
                    $requestedEmailIsFree = ! DB::table('tbluser')
                        ->where('email', (string) $this->option('email'))
                        ->exists();

                    if (! $requestedEmailIsFree) {
                        $this->error('The requested login email already exists. Re-run with --replace-old only after approving old-tenant deletion.');

                        return self::FAILURE;
                    }

                    $this->warn('Old Fiber Valley tenant rows exist, but the requested login email is free. Creating a clean new tenant without deleting the orphaned old tenant.');
                } else {
                    foreach ($existingTenants as $existing) {
                        $oldTenant = (string) $existing['tenant_id'];
                        $oldName = (string) $existing['name'];
                        if ($this->hasTenantRoot($oldTenant)) {
                            $this->warn("Purging old Fiber Valley tenant {$oldTenant} ({$oldName})...");
                            $result = $purge->purge($oldTenant, $oldName, true, 'artisan:fibervalley:activate-longformat');
                            $this->line("  Removed {$result['rows']} rows across {$result['tables']} tables.");
                        } else {
                            $this->warn("Cleaning orphaned old Fiber Valley tenant {$oldTenant} ({$oldName})...");
                            $result = $this->purgeOrphanTenant($oldTenant, $tenantTables);
                            $this->line("  Removed {$result['rows']} rows across {$result['tables']} tables.");
                        }
                    }
                }
            }

            $created = $signup->provision([
                'organizationName' => 'Fiber Valley',
                'organizationEmail' => (string) $this->option('email'),
                'password' => (string) $this->option('password'),
                'industry' => 'telecom',
                'legalName' => 'Fiber Valley',
            ]);
            $tenantId = (string) $created['tenantId'];
            $this->info('Created Fiber Valley tenant '.$tenantId);
        } else {
            $this->warn('Using existing tenant '.$tenantId.' because --force-existing was supplied.');
        }

        $rows = [];
        foreach ($files as $file) {
            $rows[] = $this->importFile($tenantId, (string) $file);
        }

        $this->newLine();
        $this->table(
            ['CSV', 'Source rows', 'Records created', 'Records updated', 'Skipped', 'Departments', 'People'],
            $rows
        );

        $this->newLine();
        $this->info('Evaluating signals...');
        $ruleResult = $evaluator->evaluate($tenantId);
        $operational = $this->operationalRules($tenantId);
        $this->line('  Signals created: '.(($ruleResult['created'] ?? 0) + $operational['created']));
        $this->line('  Signals refreshed: '.(($ruleResult['refreshed'] ?? 0) + $operational['refreshed']));
        $this->line('  Rules not triggered: '.(($ruleResult['skipped'] ?? 0) + $operational['skipped']));

        $this->newLine();
        $this->info('Warming intelligence...');
        $warm = $intelligence->forOrganization($tenantId, true);
        $this->line('  Version: '.($warm['dataVersion'] ?? '?'));

        $this->newLine();
        $this->info('Final tenant id: '.$tenantId);

        return self::SUCCESS;
    }

    private function hasTenantRoot(string $tenantId): bool
    {
        return DB::table('school_setup')->where('id', $tenantId)->exists();
    }

    /**
     * @return array{tables: int, rows: int, deleted: array<string, int>, dissociated: array<string, int>}
     */
    private function purgeOrphanTenant(string $tenantId, TenantOwnedTables $tables): array
    {
        $classified = $tables->classify($tenantId);
        $ordered = $tables->inDeletionOrder($classified);
        $dependents = $tables->dependentRows($ordered);

        return DB::transaction(function () use ($tenantId, $tables, $ordered, $dependents): array {
            $deleted = [];
            $dissociated = [];

            foreach ($dependents as $dependent) {
                $query = $tables->scopedDependentQuery($tenantId, $dependent);
                if ($dependent->dissociates()) {
                    $n = $query->update([$dependent->column => null]);
                    if ($n > 0) {
                        $dissociated[$dependent->table.'.'.$dependent->column] = $n;
                        $this->line("  dissociated {$n} {$dependent->table}.{$dependent->column}");
                    }
                    continue;
                }

                $n = $query->delete();
                if ($n > 0) {
                    $deleted[$dependent->table] = ($deleted[$dependent->table] ?? 0) + $n;
                    $this->line("  deleted {$n} {$dependent->table}");
                }
            }

            foreach ($ordered as $table) {
                if ($table->selfReferenceColumn !== null) {
                    DB::table($table->table)
                        ->where($table->tenantColumn, $tenantId)
                        ->whereNotNull($table->selfReferenceColumn)
                        ->update([$table->selfReferenceColumn => null]);
                }

                $n = DB::table($table->table)
                    ->where($table->tenantColumn, $tenantId)
                    ->delete();

                if ($n > 0) {
                    $deleted[$table->table] = ($deleted[$table->table] ?? 0) + $n;
                    $this->line("  deleted {$n} {$table->table}");
                }
            }

            return [
                'tables' => count($deleted),
                'rows' => array_sum($deleted),
                'deleted' => $deleted,
                'dissociated' => $dissociated,
            ];
        });
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    /**
     * @return list<array{tenant_id: string, name: string, email: string, deleted_at: ?string}>
     */
    private function fiberValleyTenants(string $email): array
    {
        $rows = DB::table('institute_detail')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($email): void {
                $q->where('organization_name', 'like', '%Fiber%Valley%')
                    ->orWhere('organization_code', 'like', '%FIBER%')
                    ->orWhere('organization_email', $email);
            })
            ->orderBy('sub_institute_id')
            ->get();

        return $rows->map(static fn ($row): array => [
            'tenant_id' => (string) $row->sub_institute_id,
            'name' => (string) $row->organization_name,
            'email' => (string) ($row->organization_email ?? ''),
            'deleted_at' => $row->deleted_at === null ? null : (string) $row->deleted_at,
        ])->all();
    }

    /**
     * @param list<array{tenant_id: string, name: string, email: string, deleted_at: ?string}> $tenants
     * @return list<array{0: string, 1: int}>
     */
    private function verifyOldFiberValley(string $email, array $tenants): array
    {
        $like = '%Fiber%Valley%';
        $tenantIds = array_map(static fn (array $row): string => $row['tenant_id'], $tenants);

        return [
            ['institute_detail', DB::table('institute_detail')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($like, $email) {
                    $q->where('organization_name', 'like', $like)
                        ->orWhere('organization_code', 'like', '%FIBER%')
                        ->orWhere('organization_email', $email);
                })->count()],
            ['school_setup', DB::table('school_setup')
                ->where(function ($q) use ($like, $email) {
                    $q->where('SchoolName', 'like', $like)->orWhere('Email', $email);
                })->count()],
            ['tblclient', DB::table('tblclient')
                ->where(function ($q) use ($like, $email) {
                    $q->where('client_name', 'like', $like)->orWhere('email', $email);
                })->count()],
            ['tbluser login email', DB::table('tbluser')->where('email', $email)->count()],
            ['hpbrain_data_sources', DB::table('hpbrain_data_sources')
                ->where(function ($q) use ($like, $tenantIds) {
                    $q->where('display_name', 'like', $like)->orWhere('source_key', 'like', '%fibervalley%');
                    if ($tenantIds !== []) {
                        $q->orWhereIn('tenant_id', $tenantIds);
                    }
                })->count()],
            ['tbluser tenant rows', $tenantIds === [] ? 0 : DB::table('tbluser')->whereIn('sub_institute_id', $tenantIds)->count()],
            ['hrms_departments tenant rows', $tenantIds === [] ? 0 : DB::table('hrms_departments')->whereIn('sub_institute_id', $tenantIds)->count()],
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int}
     */
    private function importFile(string $tenantId, string $path): array
    {
        $fileName = basename($path);
        $sourceKey = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', pathinfo($fileName, PATHINFO_FILENAME)));
        $sourceKey = trim($sourceKey, '_');
        $now = gmdate('Y-m-d H:i:s');

        DB::table('hpbrain_data_sources')->updateOrInsert(
            ['tenant_id' => $tenantId, 'source_key' => $sourceKey],
            [
                'id' => $this->dataSourceId($tenantId, $sourceKey),
                'display_name' => $fileName,
                'source_type' => 'long_format_csv',
                'config' => json_encode(['format' => 'long_entity_fields', 'dataset' => 'by_entity_type']),
                'field_map' => json_encode([
                    'entity_type' => 'entity_type',
                    'external_ref' => 'record_id',
                    'field_name' => 'field_name',
                    'field_value' => 'field_value',
                ]),
                'is_active' => 1,
                'last_synced_at' => $now,
                'created_by' => 'artisan:fibervalley:activate-longformat',
                'updated_date' => $now,
            ],
        );

        $jobId = Uuid::uuid4()->toString();
        DB::table('hpbrain_import_jobs')->insert([
            'id' => $jobId,
            'tenant_id' => $tenantId,
            'org_id' => $tenantId,
            'source_id' => $sourceKey,
            'source_ref' => $path,
            'sync_type' => 'manual',
            'import_type' => 'long_format_csv',
            'entity_type' => 'operational_record',
            'status' => 'processing',
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'duplicate_count' => 0,
            'started_by' => 'artisan:fibervalley:activate-longformat',
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        // Reruns are idempotent through deterministic ids plus insertOrIgnore.
        // Avoid a per-record SELECT on resumes; against the remote hp_erp
        // database that turns recovery into the slowest possible path.
        $checkExisting = false;

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open '.$path);
        }

        $headerLine = fgets($handle);
        if ($headerLine === false) {
            fclose($handle);
            throw new \RuntimeException('Empty CSV: '.$path);
        }

        $header = str_getcsv(rtrim($headerLine, "\r\n"));
        $columns = array_flip($header);
        foreach (['department', 'entity_type', 'record_id', 'field_name', 'field_value', 'source_file'] as $required) {
            if (! array_key_exists($required, $columns)) {
                fclose($handle);
                throw new \RuntimeException("CSV {$fileName} is missing {$required}");
            }
        }

        $sourceRows = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $departments = 0;
        $people = 0;
        $buffer = [];
        $currentKey = null;
        $current = null;

        while (($line = fgets($handle)) !== false) {
            $sourceRows++;
            if ($sourceRows % 250000 === 0) {
                $this->line("  {$fileName}: {$sourceRows} source rows read, ".($created + $updated).' records staged/imported');
            }
            $row = $this->parseLongFormatLine($line);
            if ($row === null) {
                $skipped++;
                continue;
            }
            $entityType = trim((string) ($row[$columns['entity_type']] ?? ''));
            $recordId = trim((string) ($row[$columns['record_id']] ?? ''));

            if ($entityType === '' || $recordId === '') {
                $skipped++;
                continue;
            }

            $groupKey = $entityType.'|'.$recordId;
            if ($currentKey !== null && $groupKey !== $currentKey && $current !== null) {
                $this->stageRecord($tenantId, $sourceKey, $fileName, $jobId, $current, $buffer, $created, $updated, $departments, $people, $checkExisting);
                if (count($buffer) >= 1000) {
                    $this->flushOperational($tenantId, $buffer);
                }
            }

            if ($groupKey !== $currentKey) {
                $currentKey = $groupKey;
                $current = [
                    'department' => trim((string) ($row[$columns['department']] ?? '')),
                    'entity_type' => $entityType,
                    'record_id' => $recordId,
                    'source_file' => trim((string) ($row[$columns['source_file']] ?? '')),
                    'first_source_row' => $sourceRows,
                    'fields' => [],
                ];
            }

            $field = trim((string) ($row[$columns['field_name']] ?? ''));
            if ($field !== '') {
                $current['fields'][$field] = (string) ($row[$columns['field_value']] ?? '');
            }
        }

        if ($current !== null) {
            $this->stageRecord($tenantId, $sourceKey, $fileName, $jobId, $current, $buffer, $created, $updated, $departments, $people, $checkExisting);
        }

        fclose($handle);
        $this->flushOperational($tenantId, $buffer);

        DB::table('hpbrain_import_jobs')->where('id', $jobId)->where('tenant_id', $tenantId)->update([
            'status' => 'completed',
            'total_rows' => $sourceRows,
            'processed_rows' => $sourceRows,
            'success_count' => $created + $updated,
            'duplicate_count' => $skipped,
            'completed_date' => gmdate('Y-m-d H:i:s'),
            'updated_date' => gmdate('Y-m-d H:i:s'),
        ]);

        return [$fileName, $sourceRows, $created, $updated, $skipped, $departments, $people];
    }

    /**
     * @return array<int, string>|null
     */
    private function parseLongFormatLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            return null;
        }

        $head = explode(',', $line, 5);
        if (count($head) < 5) {
            return null;
        }

        $tail = $head[4];
        $lastComma = strrpos($tail, ',');
        if ($lastComma === false) {
            $fieldValue = $tail;
            $sourceFile = '';
        } else {
            $fieldValue = substr($tail, 0, $lastComma);
            $sourceFile = substr($tail, $lastComma + 1);
        }

        return [
            $this->csvScalar($head[0]),
            $this->csvScalar($head[1]),
            $this->csvScalar($head[2]),
            $this->csvScalar($head[3]),
            $this->csvScalar($fieldValue),
            $this->csvScalar($sourceFile),
        ];
    }

    private function csvScalar(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
            $value = str_replace('""', '"', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $buffer
     */
    private function stageRecord(
        string $tenantId,
        string $sourceKey,
        string $fileName,
        string $jobId,
        array $record,
        array &$buffer,
        int &$created,
        int &$updated,
        int &$departments,
        int &$people,
        bool $checkExisting,
    ): void {
        $entityType = (string) $record['entity_type'];
        $fields = (array) $record['fields'];
        $dataset = $this->dataset($entityType);
        $naturalKey = $this->naturalKey($sourceKey, $entityType, (string) $record['record_id']);
        $now = gmdate('Y-m-d H:i:s');

        $row = [
            'id' => $this->operationalId($tenantId, $dataset, $naturalKey),
            'tenant_id' => $tenantId,
            'org_id' => $tenantId,
            'dataset' => $dataset,
            'natural_key' => $naturalKey,
            'source_file' => $fileName,
            'source_row' => (int) $record['first_source_row'],
            'occurred_at' => $this->firstDate($fields, ['creation', 'created_at', 'date', 'posting_date', 'transaction_date', 'attendance_date', 'checkin_time', 'from_date', 'start_date']),
            'closed_at' => $this->firstDate($fields, ['modified', 'closed_at', 'close_date', 'resolved_at', 'to_date', 'end_date']),
            'status' => $this->firstValue($fields, ['status', 'workflow_state', 'docstatus', 'disabled', 'is_active']),
            'category' => $this->firstValue($fields, ['department', '_department', 'process_code', 'complaint_type', 'connection_type', 'type', 'category', 'designation']),
            'sub_category' => $this->firstValue($fields, ['sub_category', 'final_solution', 'hold_status', 'reason', 'purpose']),
            'owner_name' => $this->firstValue($fields, ['employee_name', 'sales_person_name', 'owner', 'assigned_to', 'technician', 'engineer_name', 'name']),
            'supervisor_name' => $this->firstValue($fields, ['_manager_name', 'manager', 'reports_to', 'tl', 'supervisor_name']),
            'zone' => $this->firstValue($fields, ['zone', 'Zone']),
            'area' => $this->firstValue($fields, ['area', 'service_area', 'branch', 'department']),
            'subject_ref' => $this->firstValue($fields, ['customer', 'customer_id', 'user_id', 'userid', 'employee', 'name']),
            'metric_value' => $this->firstDecimal($fields, ['amount', 'grand_total', 'total', 'hours', 'days', 'distance', 'rating', 'score', 'ctc']),
            'metric_unit' => null,
            'quantity' => $this->firstInt($fields, ['qty', 'quantity', 'count', 'no_of_days', 'leave_balance']),
            'payload' => json_encode($record, JSON_UNESCAPED_UNICODE),
            'import_job_id' => $jobId,
            'created_date' => $now,
            'updated_date' => $now,
        ];

        $row['row_hash'] = hash('sha256', json_encode(array_diff_key($row, array_flip(['id', 'source_row', 'import_job_id', 'created_date', 'updated_date'])), JSON_UNESCAPED_UNICODE) ?: '');

        $exists = $checkExisting
            ? DB::table('hpbrain_operational_records')
                ->where('tenant_id', $tenantId)
                ->where('dataset', $dataset)
                ->where('natural_key', $naturalKey)
                ->value('row_hash')
            : null;

        if ($exists === null) {
            $buffer[] = $row;
            $created++;
        } elseif ($exists !== $row['row_hash']) {
            DB::table('hpbrain_operational_records')
                ->where('tenant_id', $tenantId)
                ->where('dataset', $dataset)
                ->where('natural_key', $naturalKey)
                ->update(array_diff_key($row, ['id' => true, 'created_date' => true]));
            $updated++;
        }

        if ($entityType === 'Department') {
            $departments += $this->upsertDepartment($tenantId, $fields) ? 1 : 0;
        }

        if (in_array($entityType, ['Employee', 'SalesPerson'], true)) {
            $people += $this->upsertPerson($tenantId, $fields) ? 1 : 0;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function flushOperational(string $tenantId, array &$rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('hpbrain_operational_records')->insertOrIgnore($chunk);
        }

        $rows = [];
    }

    /**
     * @param array<string, string> $fields
     */
    private function upsertDepartment(string $tenantId, array $fields): bool
    {
        $name = trim((string) ($fields['department_name'] ?? $fields['name'] ?? $fields['_department'] ?? ''));
        if ($name === '') {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        DB::table('hrms_departments')->updateOrInsert(
            ['sub_institute_id' => $tenantId, 'department' => $name],
            ['parent_id' => 0, 'status' => 1, 'is_calculated' => 0, 'updated_at' => $now, 'created_at' => $now],
        );

        return true;
    }

    /**
     * @param array<string, string> $fields
     */
    private function upsertPerson(string $tenantId, array $fields): bool
    {
        $name = trim((string) ($fields['employee_name'] ?? $fields['sales_person_name'] ?? $fields['name'] ?? ''));
        if ($name === '') {
            return false;
        }

        $department = trim((string) ($fields['department'] ?? $fields['_department'] ?? ''));
        $departmentId = 0;
        if ($department !== '') {
            $this->upsertDepartment($tenantId, ['name' => $department]);
            $departmentId = (int) DB::table('hrms_departments')
                ->where('sub_institute_id', $tenantId)
                ->where('department', $department)
                ->value('id');
        }

        $profileId = (int) DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenantId)
            ->where('name', 'Employee')
            ->where('status', 1)
            ->value('id');

        [$first, $last] = $this->splitName($name);
        $email = trim((string) ($fields['company_email'] ?? $fields['personal_email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = 'no-email.'.substr(hash('sha256', $tenantId.'|'.$name), 0, 16).'@fibervalley.invalid';
        }

        $now = gmdate('Y-m-d H:i:s');
        DB::table('tbluser')->updateOrInsert(
            ['sub_institute_id' => $tenantId, 'email' => $email],
            [
                'first_name' => $first,
                'middle_name' => trim((string) ($fields['middle_name'] ?? '')),
                'last_name' => $last,
                'mobile' => trim((string) ($fields['cell_number'] ?? $fields['mobile'] ?? '')) ?: null,
                'department_id' => $departmentId,
                'user_profile_id' => $profileId,
                'status' => (($fields['status'] ?? '') === 'Inactive') ? 0 : 1,
                'password' => '',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        return true;
    }

    /**
     * @param array<string, string> $fields
     * @param list<string> $names
     */
    private function firstValue(array $fields, array $names): ?string
    {
        $lookup = [];
        foreach ($fields as $key => $value) {
            $lookup[strtolower($key)] = trim((string) $value);
        }

        foreach ($names as $name) {
            $value = $lookup[strtolower($name)] ?? '';
            if ($value !== '') {
                return mb_substr($value, 0, 191);
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $fields
     * @param list<string> $names
     */
    private function firstDate(array $fields, array $names): ?string
    {
        $value = $this->firstValue($fields, $names);
        if ($value === null) {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }

    /**
     * @param array<string, string> $fields
     * @param list<string> $needles
     */
    private function firstDecimal(array $fields, array $needles): ?float
    {
        foreach ($fields as $key => $value) {
            $lower = strtolower($key);
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    $clean = str_replace([',', 'Rs.', 'RS.', '/-'], '', trim((string) $value));
                    if (is_numeric($clean)) {
                        return round((float) $clean, 4);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $fields
     * @param list<string> $needles
     */
    private function firstInt(array $fields, array $needles): ?int
    {
        $decimal = $this->firstDecimal($fields, $needles);

        return $decimal === null ? null : (int) round($decimal);
    }

    private function dataset(string $entityType): string
    {
        return mb_substr(strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $entityType)), 0, 64);
    }

    private function naturalKey(string $sourceKey, string $entityType, string $recordId): string
    {
        $key = $sourceKey.'|'.$entityType.'|'.$recordId;

        return mb_strlen($key) <= 191 ? $key : mb_substr($sourceKey.'|'.$entityType.'|'.hash('sha256', $recordId), 0, 191);
    }

    private function dataSourceId(string $tenantId, string $sourceKey): string
    {
        return Uuid::uuid5(self::ID_NAMESPACE, 'data-source|'.$tenantId.'|'.$sourceKey)->toString();
    }

    private function operationalId(string $tenantId, string $dataset, string $naturalKey): string
    {
        return Uuid::uuid5(self::ID_NAMESPACE, 'operational-record|'.$tenantId.'|'.$dataset.'|'.mb_strtolower($naturalKey))->toString();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) <= 1) {
            return [mb_substr($name, 0, 128), ''];
        }

        $first = array_shift($parts);

        return [mb_substr((string) $first, 0, 128), mb_substr(implode(' ', $parts), 0, 128)];
    }

    /**
     * @return array{applicable: int, created: int, refreshed: int, skipped: int}
     */
    private function operationalRules(string $tenantId): array
    {
        $writer = app(OperationalSignalWriter::class);
        $rules = app(SignalRuleRegistry::class)->extraRulesFor($writer, $tenantId);
        $out = ['applicable' => count($rules), 'created' => 0, 'refreshed' => 0, 'skipped' => 0];

        foreach ($rules as $rule) {
            try {
                $outcome = $rule();
            } catch (\Throwable) {
                $out['skipped']++;
                continue;
            }

            match (true) {
                ($outcome['created'] ?? false) => $out['created']++,
                ($outcome['refreshed'] ?? false) => $out['refreshed']++,
                default => $out['skipped']++,
            };
        }

        return $out;
    }
}
