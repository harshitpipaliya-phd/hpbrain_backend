<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Events\EventPublisher;
use App\Domain\Industry\IndustryPack;
use App\Domain\Ingestion\IngestionBatch;
use App\Domain\Ingestion\IngestionService;
use App\Domain\Ingestion\Sources\CsvUploadSource;
use App\Domain\Intelligence\IntelligenceEngine;
use App\Domain\Organization\OrganizationSignupService;
use App\Domain\School\AcademicIntelligenceService;
use App\Domain\School\DatasetRegistry;
use App\Domain\School\FeeIntelligenceService;
use App\Domain\School\StudentProjectionBuilder;
use App\Domain\Tenancy\TenantPurgeService;
use App\Domain\Universal\EntityResolver;
use App\Services\TenantScopedCache;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

final class SeedV1AcademySchool extends Command
{
    protected $signature = 'school:seed-v1-academy
        {--replace : Purge and recreate V1 Academy if it already exists}
        {--email=v1@gmail.com : Admin login email}
        {--password=adminv123 : Admin login password}';

    protected $description = 'Create, ingest, and verify a complete school organization (V1 Academy) for academic year 2025-2026.';

    public const SCHOOL_NAME = 'V1 Academy';
    public const SHORT_CODE = 'V1A';
    public const ACADEMIC_YEAR = '2025-2026';
    public const ACADEMIC_START = '2025-06-01';
    public const ACADEMIC_END = '2026-04-30';

    private const AUTHOR = 'v1-academy-seeder';
    private const ID_NAMESPACE = 'a3a7b92b-dfb1-443d-85ab-398c4aae34b8';

    public function handle(
        OrganizationSignupService $signup,
        TenantPurgeService $purge,
        EntityResolver $resolver,
        StudentProjectionBuilder $students,
        AcademicIntelligenceService $academicIntel,
        FeeIntelligenceService $feeIntel,
        IntelligenceEngine $intelligence,
        TenantScopedCache $cache,
    ): int {
        $this->info('======================================================================');
        $this->info('  V1 ACADEMY — SCHOOL ORGANIZATION SEED & INGESTION PIPELINE');
        $this->info('  Academic Year: ' . self::ACADEMIC_YEAR . ' (' . self::ACADEMIC_START . ' to ' . self::ACADEMIC_END . ')');
        $this->info('======================================================================');

        $email = (string) $this->option('email');
        $password = (string) $this->option('password');
        $replace = (bool) $this->option('replace');

        // 1. Locate or create tenant
        $tenantId = $this->findExistingTenant($email);

        if ($tenantId !== null) {
            if ($replace) {
                $this->warn("Replacing existing tenant {$tenantId}...");
                $this->purgeTenant($tenantId);
                $tenantId = null;
            } else {
                $this->info("Found existing V1 Academy tenant: {$tenantId}. Reusing and updating tenant.");
            }
        }

        if ($tenantId === null) {
            $this->info('Creating clean school tenant via OrganizationSignupService...');
            $result = $signup->provision([
                'organizationName' => self::SCHOOL_NAME,
                'organizationEmail' => $email,
                'password' => $password,
                'industry' => 'k12_education',
                'legalName' => self::SCHOOL_NAME . ' Educational Trust',
                'address' => 'Plot 10, Innovation Hub, Knowledge Park, Sector 4, Bangalore 560100',
                'city' => 'Bangalore',
                'state' => 'Karnataka',
                'country' => 'India',
                'organizationMobile' => '9876543210',
            ]);
            $tenantId = (string) $result['tenantId'];
            $this->info("Created new tenant: {$tenantId} (Client ID: {$result['clientId']})");
        } else {
            // Ensure admin user password and active status are verified
            DB::table('tbluser')
                ->where('email', $email)
                ->where('sub_institute_id', $tenantId)
                ->update([
                    'password' => Hash::make($password),
                    'status' => 1,
                    'is_admin' => 1,
                ]);
        }

        // Ensure mappings are fresh for this tenant
        (new EntityMappingSeeder([$tenantId]))->run();
        $resolver->flush($tenantId);

        // 2. Provision industry capabilities & terminology
        $this->info("Provisioning K-12 education capability register for tenant {$tenantId}...");
        $this->call('brain:provision', [
            '--tenant' => $tenantId,
            '--industry' => 'k12_education',
        ]);

        // 3. Populate ERP Departments, Job Titles, and Staff Members
        $this->info('Populating School Departments, Leadership, Teachers, and Administrative Staff...');
        $staffData = $this->seedErpStaff($tenantId, $email, $password);

        // 4. Generate and write dataset files to disk
        $this->info('Generating versioned CSV dataset files on disk for audit & reproducibility...');
        $datasetFiles = $this->generateDatasetFiles($tenantId, $staffData);

        // 5. Ingest datasets through HP Brain ingestion architecture
        $this->info('Ingesting operational records through existing ingestion data sources...');
        $counts = $this->ingestOperationalDatasets($tenantId, $datasetFiles, $staffData);

        // 6. Project student read-models
        $this->info('Building student read-model projection (hpbrain_students)...');
        $projResult = $students->rebuild($tenantId, 'v1a-academic-results', 'school_fee');
        $this->line(sprintf('  Students projected: %d (in_academic: %d, in_fees: %d)',
            $projResult['students'] ?? 0,
            $projResult['academic'] ?? 0,
            $projResult['fees'] ?? 0
        ));

        // 7. Teacher capability assignments and KASBA assessments
        $this->info('Recording teacher capability assignments and longitudinal KASBA evaluations...');
        $this->seedTeacherCapabilities($tenantId, $staffData);

        // 8. Generate real evidence-backed intelligence loop
        $this->info('Deriving complete organizational intelligence loop (Signals -> Cases -> Decisions -> ESOs -> Outcomes)...');
        $this->seedIntelligenceLoop($tenantId, $staffData, $counts);

        // 9. Precompute and warm intelligence caches
        $this->info('Warming derived intelligence caches...');
        try {
            $this->call('intelligence:warm', ['--tenant' => [$tenantId], '--fresh' => true]);
        } catch (\Throwable $e) {
            $this->warn('  intelligence:warm notice: ' . $e->getMessage());
        }

        try {
            $this->call('operations:warm', ['--tenant' => [$tenantId], '--fresh' => true]);
        } catch (\Throwable $e) {
            $this->warn('  operations:warm notice: ' . $e->getMessage());
        }

        $this->newLine();
        $this->info('======================================================================');
        $this->info('  V1 ACADEMY SUCCESSFULLY SEEDED & INGESTED');
        $this->info("  Tenant ID:        {$tenantId}");
        $this->info("  Login Email:      {$email}");
        $this->info("  Login Password:   {$password}");
        $this->info('  Academic Year:    ' . self::ACADEMIC_YEAR . ' (Strictly 2025-06-01 to 2026-04-30)');
        $this->info("  Academic Records: {$counts['academic']}");
        $this->info("  Fee Receipts:     {$counts['fees']}");
        $this->info("  Student Attendance: {$counts['attendance']}");
        $this->info("  Staff Presence:   {$counts['staff_presence']}");
        $this->info("  Students Total:   {$counts['students']}");
        $this->info("  Staff Members:    " . count($staffData['users']));
        $this->info("  Departments:      " . count($staffData['departments']));
        $this->info('======================================================================');

        return self::SUCCESS;
    }

    private function findExistingTenant(string $email): ?string
    {
        $user = DB::table('tbluser')
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->first();

        if ($user !== null) {
            return (string) $user->sub_institute_id;
        }

        $setup = DB::table('school_setup')
            ->where('SchoolName', self::SCHOOL_NAME)
            ->first();

        return $setup !== null ? (string) $setup->id : null;
    }

    private function purgeTenant(string $tenantId): void
    {
        // Remove operational records & loop tables
        $tables = [
            'hpbrain_operational_records',
            'hpbrain_students',
            'hpbrain_data_sources',
            'hpbrain_import_logs',
            'hpbrain_import_jobs',
            'hpbrain_case_evidence',
            'hpbrain_hypotheses',
            'hpbrain_cases',
            'hpbrain_reasoning_steps',
            'hpbrain_recommendations',
            'hpbrain_risks',
            'hpbrain_decisions',
            'hpbrain_eso_executions',
            'hpbrain_eso_definitions',
            'hpbrain_measurement_plans',
            'hpbrain_outcomes',
            'hpbrain_learnings',
            'hpbrain_evidence',
            'hpbrain_signals',
            'hpbrain_capability_proficiency',
            'hpbrain_capability_assignments',
            'hpbrain_capabilities',
            'hpbrain_terminology',
            'hpbrain_knowledge_assets',
            'hpbrain_mental_models',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        try {
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('tenant_id', $tenantId)->delete();
                }
            }

            // Clean ERP tables
            if (Schema::hasTable('institute_detail')) {
                DB::table('institute_detail')->where('sub_institute_id', $tenantId)->delete();
            }
            if (Schema::hasTable('org_details')) {
                DB::table('org_details')->where('sub_institute_id', $tenantId)->delete();
            }
            if (Schema::hasTable('hrms_departments')) {
                DB::table('hrms_departments')->where('sub_institute_id', $tenantId)->delete();
            }
            if (Schema::hasTable('hrms_job_titles')) {
                DB::table('hrms_job_titles')->where('sub_institute_id', $tenantId)->delete();
            }
            if (Schema::hasTable('tbluser')) {
                DB::table('tbluser')->where('sub_institute_id', $tenantId)->delete();
            }
            if (Schema::hasTable('tbluserprofilemaster')) {
                DB::table('tbluserprofilemaster')->where('sub_institute_id', $tenantId)->delete();
            }
            if (Schema::hasTable('hpbrain_entity_mappings')) {
                DB::table('hpbrain_entity_mappings')->where('tenant_id', $tenantId)->delete();
            }
            $clientId = DB::table('school_setup')->where('id', $tenantId)->value('client_id');
            if (Schema::hasTable('school_setup')) {
                DB::table('school_setup')->where('id', $tenantId)->delete();
            }
            if ($clientId !== null && Schema::hasTable('tblclient')) {
                DB::table('tblclient')->where('id', $clientId)->delete();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

    }

    /**
     * @return array{departments: array<string, int>, jobTitles: array<string, int>, profiles: array<string, int>, users: array<string, int>}
     */
    private function seedErpStaff(string $tenantId, string $adminEmail, string $adminPassword): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $clientId = (int) (DB::table('school_setup')->where('id', $tenantId)->value('client_id') ?? 1);

        // 1. Departments
        $deptNames = [
            'Primary Section (Grades 1-5)' => ['code' => 'DEP-PRIM', 'desc' => 'Early childhood and primary academic education for standards 1 to 5.'],
            'Middle School (Grades 6-8)' => ['code' => 'DEP-MID', 'desc' => 'Middle school curriculum, activities, and foundational sciences.'],
            'Secondary Section (Grades 9-10)' => ['code' => 'DEP-SEC', 'desc' => 'Secondary board examination preparation and core curriculum.'],
            'Higher Secondary Section (Grades 11-12)' => ['code' => 'DEP-HSEC', 'desc' => 'Higher secondary advanced academic streams (Science & Commerce).'],
            'Science & Mathematics Department' => ['code' => 'DEP-SCIMATH', 'desc' => 'STEM faculty, practical laboratories, and interdisciplinary curriculum.'],
            'Languages & Humanities Department' => ['code' => 'DEP-LANGHUM', 'desc' => 'Languages, literature, social sciences, and library programs.'],
            'Administration & Operations' => ['code' => 'DEP-ADMIN', 'desc' => 'Campus facilities, safety, admissions, transport, and administrative logistics.'],
            'Finance & Accounts' => ['code' => 'DEP-FIN', 'desc' => 'Tuition billing, fee collections, concessions, payroll, and budgeting.'],
        ];

        $deptIds = [];
        foreach ($deptNames as $name => $meta) {
            $id = DB::table('hrms_departments')->where('sub_institute_id', $tenantId)->where('department', $name)->value('id');
            if ($id === null) {
                $id = DB::table('hrms_departments')->insertGetId([
                    'department' => $name,
                    'code' => $meta['code'],
                    'description' => $meta['desc'],
                    'roles_responsibility' => $meta['desc'],
                    'tasks' => 'Academic delivery and organizational administration.',
                    'parent_id' => 0,
                    'status' => 1,
                    'is_calculated' => 1,
                    'sub_institute_id' => (int) $tenantId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $deptIds[$name] = (int) $id;
        }

        // 2. Job titles
        $jobTitles = [
            'Principal',
            'Vice Principal',
            'Senior Teacher',
            'Subject Teacher',
            'Class Teacher',
            'Academic Coordinator',
            'Finance Officer',
            'Administrative Officer',
        ];

        $jobTitleIds = [];
        foreach ($jobTitles as $title) {
            $id = DB::table('hrms_job_titles')->where('sub_institute_id', $tenantId)->where('title', $title)->value('id');
            if ($id === null) {
                $id = DB::table('hrms_job_titles')->insertGetId([
                    'title' => $title,
                    'description' => $title . ' at ' . self::SCHOOL_NAME,
                    'client_id' => $clientId,
                    'sub_institute_id' => (int) $tenantId,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $jobTitleIds[$title] = (int) $id;
        }

        // 3. User Profiles
        $profiles = ['Admin', 'Employee', 'HR', 'Teacher', 'Principal', 'Finance'];
        $profileIds = [];
        foreach ($profiles as $idx => $pName) {
            $id = DB::table('tbluserprofilemaster')->where('sub_institute_id', $tenantId)->where('name', $pName)->value('id');
            if ($id === null) {
                $id = DB::table('tbluserprofilemaster')->insertGetId([
                    'parent_id' => $pName === 'Admin' ? 1 : 0,
                    'name' => $pName,
                    'description' => $pName,
                    'sort_order' => $idx + 1,
                    'status' => 1,
                    'sub_institute_id' => (int) $tenantId,
                    'client_id' => $clientId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $profileIds[$pName] = (int) $id;
        }

        // 4. Staff Members
        $staffRoster = [
            [
                'first_name' => 'Victor',
                'last_name' => 'Sterling',
                'email' => $adminEmail,
                'employee_no' => 'EMP-001',
                'job' => 'Principal',
                'profile' => 'Admin',
                'dept' => 'Administration & Operations',
                'gender' => 'Male',
                'is_admin' => 1,
            ],
            [
                'first_name' => 'Evelyn',
                'last_name' => 'Vance',
                'email' => 'evelyn.vance@v1academy.edu',
                'employee_no' => 'EMP-002',
                'job' => 'Vice Principal',
                'profile' => 'Teacher',
                'dept' => 'Administration & Operations',
                'gender' => 'Female',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Clara',
                'last_name' => 'Higgins',
                'email' => 'clara.higgins@v1academy.edu',
                'employee_no' => 'EMP-003',
                'job' => 'Senior Teacher',
                'profile' => 'Teacher',
                'dept' => 'Primary Section (Grades 1-5)',
                'gender' => 'Female',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Arthur',
                'last_name' => 'Pendelton',
                'email' => 'arthur.pendelton@v1academy.edu',
                'employee_no' => 'EMP-004',
                'job' => 'Senior Teacher',
                'profile' => 'Teacher',
                'dept' => 'Middle School (Grades 6-8)',
                'gender' => 'Male',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Rajesh',
                'last_name' => 'Kulkarni',
                'email' => 'rajesh.kulkarni@v1academy.edu',
                'employee_no' => 'EMP-005',
                'job' => 'Senior Teacher',
                'profile' => 'Teacher',
                'dept' => 'Secondary Section (Grades 9-10)',
                'gender' => 'Male',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Anita',
                'last_name' => 'Sharma',
                'email' => 'anita.sharma@v1academy.edu',
                'employee_no' => 'EMP-006',
                'job' => 'Senior Teacher',
                'profile' => 'Teacher',
                'dept' => 'Higher Secondary Section (Grades 11-12)',
                'gender' => 'Female',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'David',
                'last_name' => 'Chen',
                'email' => 'david.chen@v1academy.edu',
                'employee_no' => 'EMP-007',
                'job' => 'Subject Teacher',
                'profile' => 'Teacher',
                'dept' => 'Science & Mathematics Department',
                'gender' => 'Male',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Sarah',
                'last_name' => 'Jenkins',
                'email' => 'sarah.jenkins@v1academy.edu',
                'employee_no' => 'EMP-008',
                'job' => 'Senior Teacher',
                'profile' => 'Teacher',
                'dept' => 'Languages & Humanities Department',
                'gender' => 'Female',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Vikram',
                'last_name' => 'Patel',
                'email' => 'vikram.patel@v1academy.edu',
                'employee_no' => 'EMP-009',
                'job' => 'Subject Teacher',
                'profile' => 'Teacher',
                'dept' => 'Secondary Section (Grades 9-10)',
                'gender' => 'Male',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Meenakshi',
                'last_name' => 'Sundaram',
                'email' => 'meenakshi.sundaram@v1academy.edu',
                'employee_no' => 'EMP-010',
                'job' => 'Subject Teacher',
                'profile' => 'Teacher',
                'dept' => 'Science & Mathematics Department',
                'gender' => 'Female',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Rohan',
                'last_name' => 'Verma',
                'email' => 'rohan.verma@v1academy.edu',
                'employee_no' => 'EMP-011',
                'job' => 'Finance Officer',
                'profile' => 'Finance',
                'dept' => 'Finance & Accounts',
                'gender' => 'Male',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Linda',
                'last_name' => 'Gomez',
                'email' => 'linda.gomez@v1academy.edu',
                'employee_no' => 'EMP-012',
                'job' => 'Administrative Officer',
                'profile' => 'HR',
                'dept' => 'Administration & Operations',
                'gender' => 'Female',
                'is_admin' => 0,
            ],
        ];

        $userIds = [];
        foreach ($staffRoster as $staff) {
            $existingId = DB::table('tbluser')->where('email', $staff['email'])->value('id');
            $deptId = $deptIds[$staff['dept']] ?? null;
            $jobId = $jobTitleIds[$staff['job']] ?? null;
            $profId = $profileIds[$staff['profile']] ?? $profileIds['Employee'];

            $userPayload = [
                'user_name' => strtolower($staff['first_name'] . '.' . $staff['last_name']),
                'first_name' => $staff['first_name'],
                'middle_name' => '',
                'last_name' => $staff['last_name'],
                'email' => $staff['email'],
                'mobile' => '987654' . str_pad((string) count($userIds), 4, '0', STR_PAD_LEFT),
                'gender' => $staff['gender'] === 'Female' ? 'F' : 'M',
                'user_profile_id' => $profId,
                'sub_institute_id' => (int) $tenantId,
                'client_id' => $clientId,
                'is_admin' => $staff['is_admin'],
                'status' => 1,
                'employee_no' => $staff['employee_no'],
                'jobtitle_id' => $jobId,
                'department_id' => $deptId,
                'joined_date' => '2025-06-01',
                'join_year' => '2025',
                'updated_at' => $now,
            ];

            if ($existingId !== null) {
                if ($staff['email'] === $adminEmail) {
                    $userPayload['password'] = Hash::make($adminPassword);
                }
                DB::table('tbluser')->where('id', $existingId)->update($userPayload);
                $userIds[$staff['first_name'] . ' ' . $staff['last_name']] = (int) $existingId;
            } else {
                $userPayload['password'] = Hash::make($staff['email'] === $adminEmail ? $adminPassword : 'Teacher@2025');
                $userPayload['plain_password'] = null;
                $userPayload['created_at'] = $now;
                $id = DB::table('tbluser')->insertGetId($userPayload);
                $userIds[$staff['first_name'] . ' ' . $staff['last_name']] = (int) $id;
            }
        }

        // Link department heads
        $headMap = [
            'Primary Section (Grades 1-5)' => $userIds['Clara Higgins'] ?? null,
            'Middle School (Grades 6-8)' => $userIds['Arthur Pendelton'] ?? null,
            'Secondary Section (Grades 9-10)' => $userIds['Rajesh Kulkarni'] ?? null,
            'Higher Secondary Section (Grades 11-12)' => $userIds['Anita Sharma'] ?? null,
            'Science & Mathematics Department' => $userIds['David Chen'] ?? null,
            'Languages & Humanities Department' => $userIds['Sarah Jenkins'] ?? null,
            'Administration & Operations' => $userIds['Victor Sterling'] ?? null,
            'Finance & Accounts' => $userIds['Rohan Verma'] ?? null,
        ];

        foreach ($headMap as $deptName => $headUserId) {
            if ($headUserId !== null && isset($deptIds[$deptName])) {
                DB::table('hrms_departments')->where('id', $deptIds[$deptName])->update(['head_user_id' => $headUserId]);
            }
        }

        return [
            'departments' => $deptIds,
            'jobTitles' => $jobTitleIds,
            'profiles' => $profileIds,
            'users' => $userIds,
        ];
    }

    /**
     * @param array<string, mixed> $staffData
     * @return array<string, string>
     */
    private function generateDatasetFiles(string $tenantId, array $staffData): array
    {
        $dir = base_path('database/seeders/data/v1_academy');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Student roll (140 students across standards)
        $students = $this->generateStudentsList();

        // 1. Academic Results CSV
        $academicFile = $dir . '/v1a_academic_results_2025_2026.csv';
        $fp = fopen($academicFile, 'w');
        fputcsv($fp, [
            'external_ref', 'subject_ref', 'student_name', 'standard', 'division',
            'subject', 'exam_name', 'marks_obtained', 'max_marks', 'exam_date',
            'academic_year', 'teacher_name', 'department',
        ]);

        $exams = [
            ['name' => 'Unit Test 1', 'date' => '2025-07-18', 'max' => 50],
            ['name' => 'Mid-Term Exam', 'date' => '2025-09-22', 'max' => 100],
            ['name' => 'Unit Test 2', 'date' => '2025-12-15', 'max' => 50],
            ['name' => 'Final Exam', 'date' => '2026-03-24', 'max' => 100],
        ];

        $subjectMap = [
            'CBSE-3' => [
                ['subject' => 'Mathematics', 'teacher' => 'Clara Higgins', 'dept' => 'Primary Section (Grades 1-5)'],
                ['subject' => 'English', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Environmental Science', 'teacher' => 'Clara Higgins', 'dept' => 'Primary Section (Grades 1-5)'],
                ['subject' => 'Hindi', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
            ],
            'CBSE-4' => [
                ['subject' => 'Mathematics', 'teacher' => 'Clara Higgins', 'dept' => 'Primary Section (Grades 1-5)'],
                ['subject' => 'English', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Environmental Science', 'teacher' => 'Clara Higgins', 'dept' => 'Primary Section (Grades 1-5)'],
                ['subject' => 'Hindi', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
            ],
            'CBSE-5' => [
                ['subject' => 'Mathematics', 'teacher' => 'Clara Higgins', 'dept' => 'Primary Section (Grades 1-5)'],
                ['subject' => 'English', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Science', 'teacher' => 'David Chen', 'dept' => 'Science & Mathematics Department'],
                ['subject' => 'Social Studies', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Hindi', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
            ],
            'CBSE-6' => [
                ['subject' => 'Mathematics', 'teacher' => 'Arthur Pendelton', 'dept' => 'Middle School (Grades 6-8)'],
                ['subject' => 'Science', 'teacher' => 'David Chen', 'dept' => 'Science & Mathematics Department'],
                ['subject' => 'Social Science', 'teacher' => 'Arthur Pendelton', 'dept' => 'Middle School (Grades 6-8)'],
                ['subject' => 'English', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Computer Science', 'teacher' => 'Vikram Patel', 'dept' => 'Secondary Section (Grades 9-10)'],
            ],
            'CBSE-7' => [
                ['subject' => 'Mathematics', 'teacher' => 'Arthur Pendelton', 'dept' => 'Middle School (Grades 6-8)'],
                ['subject' => 'Science', 'teacher' => 'David Chen', 'dept' => 'Science & Mathematics Department'],
                ['subject' => 'Social Science', 'teacher' => 'Arthur Pendelton', 'dept' => 'Middle School (Grades 6-8)'],
                ['subject' => 'English', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Computer Science', 'teacher' => 'Vikram Patel', 'dept' => 'Secondary Section (Grades 9-10)'],
            ],
            'CBSE-8' => [
                ['subject' => 'Mathematics', 'teacher' => 'Arthur Pendelton', 'dept' => 'Middle School (Grades 6-8)'],
                ['subject' => 'Science', 'teacher' => 'David Chen', 'dept' => 'Science & Mathematics Department'],
                ['subject' => 'Social Science', 'teacher' => 'Arthur Pendelton', 'dept' => 'Middle School (Grades 6-8)'],
                ['subject' => 'English', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Computer Science', 'teacher' => 'Vikram Patel', 'dept' => 'Secondary Section (Grades 9-10)'],
            ],
            'CBSE-9' => [
                ['subject' => 'Mathematics', 'teacher' => 'Rajesh Kulkarni', 'dept' => 'Secondary Section (Grades 9-10)'],
                ['subject' => 'Science', 'teacher' => 'David Chen', 'dept' => 'Science & Mathematics Department'],
                ['subject' => 'Social Science', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'English', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Information Technology', 'teacher' => 'Vikram Patel', 'dept' => 'Secondary Section (Grades 9-10)'],
            ],
            'CBSE-10' => [
                ['subject' => 'Mathematics', 'teacher' => 'Rajesh Kulkarni', 'dept' => 'Secondary Section (Grades 9-10)'],
                ['subject' => 'Science', 'teacher' => 'David Chen', 'dept' => 'Science & Mathematics Department'],
                ['subject' => 'Social Science', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'English', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Information Technology', 'teacher' => 'Vikram Patel', 'dept' => 'Secondary Section (Grades 9-10)'],
            ],
            'CBSE-11' => [
                ['subject' => 'Physics', 'teacher' => 'Anita Sharma', 'dept' => 'Higher Secondary Section (Grades 11-12)'],
                ['subject' => 'Chemistry', 'teacher' => 'David Chen', 'dept' => 'Science & Mathematics Department'],
                ['subject' => 'Mathematics', 'teacher' => 'Rajesh Kulkarni', 'dept' => 'Secondary Section (Grades 9-10)'],
                ['subject' => 'English Core', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Computer Science', 'teacher' => 'Vikram Patel', 'dept' => 'Secondary Section (Grades 9-10)'],
            ],
            'CBSE-12' => [
                ['subject' => 'Physics', 'teacher' => 'Anita Sharma', 'dept' => 'Higher Secondary Section (Grades 11-12)'],
                ['subject' => 'Chemistry', 'teacher' => 'David Chen', 'dept' => 'Science & Mathematics Department'],
                ['subject' => 'Mathematics', 'teacher' => 'Rajesh Kulkarni', 'dept' => 'Secondary Section (Grades 9-10)'],
                ['subject' => 'English Core', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Computer Science', 'teacher' => 'Vikram Patel', 'dept' => 'Secondary Section (Grades 9-10)'],
            ],
        ];

        $academicRows = 0;
        foreach ($students as $stu) {
            $std = $stu['standard'];
            $subs = $subjectMap[$std] ?? $subjectMap['CBSE-8'];
            $baseAbility = $stu['ability'];

            foreach ($exams as $exam) {
                foreach ($subs as $sub) {
                    $academicRows++;
                    $ref = sprintf('ACAD-%s-%s-%s', $stu['ref'], strtoupper(substr($sub['subject'], 0, 4)), strtoupper(substr($exam['name'], 0, 3)));

                    $factor = $baseAbility;
                    // Scenario: Grade 9 Math has dip in UT1/Mid-Term, recovered in UT2/Final after remedial teaching
                    if ($std === 'CBSE-9' && $sub['subject'] === 'Mathematics') {
                        if ($exam['name'] === 'Unit Test 1') {
                            $factor = min(0.65, $baseAbility * 0.72);
                        } elseif ($exam['name'] === 'Mid-Term Exam') {
                            $factor = min(0.68, $baseAbility * 0.74);
                        } elseif ($exam['name'] === 'Unit Test 2') {
                            $factor = min(0.92, $baseAbility * 0.90 + 0.12);
                        } else {
                            $factor = min(0.95, $baseAbility + 0.08);
                        }
                    }

                    $max = $exam['max'];
                    $obtained = round(max(10, min($max, $max * $factor + (crc32($ref) % 7 - 3))), 1);

                    fputcsv($fp, [
                        $ref,
                        $stu['ref'],
                        $stu['name'],
                        $std,
                        $stu['division'],
                        $sub['subject'],
                        $exam['name'],
                        $obtained,
                        $max,
                        $exam['date'],
                        self::ACADEMIC_YEAR,
                        $sub['teacher'],
                        $sub['dept'],
                    ]);
                }
            }
        }
        fclose($fp);

        // 2. Fee Collection CSV (school_fee)
        $feeFile = $dir . '/v1a_fee_collection_2025_2026.csv';
        $fp = fopen($feeFile, 'w');
        fputcsv($fp, [
            'invoice_id', 'student_ref', 'student_name', 'class_name', 'section',
            'fee_type', 'fee_plan', 'amount_due', 'concession_amount', 'net_amount',
            'amount_paid', 'outstanding_amount', 'fee_due_date', 'payment_date',
            'payment_method', 'payment_status', 'days_overdue', 'reminder_count',
            'scholarship_type', 'risk_band', 'expected_collectable_amount',
            'collector_name', 'department',
        ]);

        $installments = [
            ['name' => 'Term 1 Fee', 'due' => '2025-06-15', 'paid' => '2025-06-10'],
            ['name' => 'Term 2 Fee', 'due' => '2025-09-15', 'paid' => '2025-09-12'],
            ['name' => 'Term 3 Fee', 'due' => '2025-12-15', 'paid' => '2025-12-10'],
            ['name' => 'Annual Lab & Activity Fee', 'due' => '2026-02-15', 'paid' => '2026-02-08'],
        ];

        $paymentModes = ['UPI', 'Net Banking', 'Credit Card', 'Cheque', 'Demand Draft'];
        $feeRows = 0;

        foreach ($students as $stu) {
            $std = $stu['standard'];
            $baseFee = match ($std) {
                'CBSE-3', 'CBSE-4', 'CBSE-5' => 20000,
                'CBSE-6', 'CBSE-7', 'CBSE-8' => 25000,
                'CBSE-9', 'CBSE-10' => 30000,
                default => 35000,
            };

            $concessionPct = $stu['scholarship_pct'];
            $scholarshipName = $stu['scholarship_name'];
            $feeCohort = $stu['fee_behavior'];

            foreach ($installments as $idx => $inst) {
                $feeRows++;
                $invId = sprintf('INV-V1A-%s-Q%d', substr($stu['ref'], -3), $idx + 1);
                $isLab = $inst['name'] === 'Annual Lab & Activity Fee';
                $gross = $isLab ? 8000.0 : (float) $baseFee;
                $concession = round($gross * ($concessionPct / 100), 2);
                $net = round($gross - $concession, 2);

                $daysOverdue = 0;
                $reminders = 0;
                $status = 'Paid';
                $paid = $net;
                $outstanding = 0.0;
                $paidDate = $inst['paid'];
                $riskBand = 'Low';
                $mode = $paymentModes[crc32($invId) % count($paymentModes)];

                if ($feeCohort === 'partial' && $idx >= 2) {
                    $status = 'Partial';
                    $paid = round($net * 0.5, 2);
                    $outstanding = round($net - $paid, 2);
                    $daysOverdue = 20;
                    $reminders = 1;
                    $riskBand = 'Medium';
                } elseif ($feeCohort === 'overdue' && $idx === 2) { // Term 3
                    $status = 'Overdue';
                    $paid = 0.0;
                    $outstanding = $net;
                    $daysOverdue = 75;
                    $reminders = 3;
                    $riskBand = 'High';
                    $paidDate = null;
                }

                $expected = $status === 'Overdue' ? round($outstanding * 0.85, 2) : $outstanding;

                fputcsv($fp, [
                    $invId,
                    $stu['ref'],
                    $stu['name'],
                    $std,
                    $stu['division'],
                    $inst['name'],
                    'Quarterly Tuition',
                    $gross,
                    $concession,
                    $net,
                    $paid,
                    $outstanding,
                    $inst['due'],
                    $paidDate,
                    $mode,
                    $status,
                    $daysOverdue,
                    $reminders,
                    $scholarshipName,
                    $riskBand,
                    $expected,
                    'Rohan Verma',
                    'Finance & Accounts',
                ]);
            }
        }
        fclose($fp);

        // 3. Student Attendance CSV
        $attFile = $dir . '/v1a_student_attendance_2025_2026.csv';
        $fp = fopen($attFile, 'w');
        fputcsv($fp, [
            'record_id', 'student_ref', 'student_name', 'standard', 'division',
            'month_label', 'working_days', 'days_present', 'attendance_pct',
            'recorded_date', 'class_teacher', 'department',
        ]);

        $months = [
            ['label' => 'June 2025', 'days' => 20, 'date' => '2025-06-30'],
            ['label' => 'July 2025', 'days' => 24, 'date' => '2025-07-31'],
            ['label' => 'August 2025', 'days' => 22, 'date' => '2025-08-31'],
            ['label' => 'September 2025', 'days' => 21, 'date' => '2025-09-30'],
            ['label' => 'October 2025', 'days' => 19, 'date' => '2025-10-31'],
            ['label' => 'November 2025', 'days' => 22, 'date' => '2025-11-30'],
            ['label' => 'December 2025', 'days' => 20, 'date' => '2025-12-31'],
            ['label' => 'January 2026', 'days' => 21, 'date' => '2026-01-31'],
            ['label' => 'February 2026', 'days' => 22, 'date' => '2026-02-28'],
            ['label' => 'March 2026', 'days' => 23, 'date' => '2026-03-31'],
            ['label' => 'April 2026', 'days' => 18, 'date' => '2026-04-30'],
        ];

        foreach ($students as $stu) {
            $baseAtt = $stu['attendance_base'];
            $teacher = $stu['class_teacher'];
            $dept = $stu['department'];

            foreach ($months as $m) {
                $recId = sprintf('ATT-%s-%s', $stu['ref'], str_replace(' ', '-', $m['label']));
                $factor = $baseAtt;
                if ($stu['fee_behavior'] === 'overdue' && in_array($m['label'], ['June 2025', 'July 2025', 'August 2025'], true)) {
                    $factor = 0.71;
                } elseif ($stu['fee_behavior'] === 'overdue') {
                    $factor = 0.88;
                }

                $present = (int) round($m['days'] * $factor);
                $pct = round(($present / $m['days']) * 100, 1);

                fputcsv($fp, [
                    $recId,
                    $stu['ref'],
                    $stu['name'],
                    $stu['standard'],
                    $stu['division'],
                    $m['label'],
                    $m['days'],
                    $present,
                    $pct,
                    $m['date'],
                    $teacher,
                    $dept,
                ]);
            }
        }
        fclose($fp);

        // 4. Staff Presence CSV
        $staffPresenceFile = $dir . '/v1a_staff_presence_2025_2026.csv';
        $fp = fopen($staffPresenceFile, 'w');
        fputcsv($fp, [
            'checkin_id', 'employee_no', 'staff_name', 'department', 'date',
            'status', 'hours_worked',
        ]);

        $dates = ['2025-06-02', '2025-07-07', '2025-08-04', '2025-09-01', '2025-10-06', '2025-11-03', '2025-12-01', '2026-01-05', '2026-02-02', '2026-03-02', '2026-04-06'];
        foreach (array_keys($staffData['users']) as $name) {
            foreach ($dates as $d) {
                fputcsv($fp, [
                    'CHK-' . substr(md5($name . $d), 0, 8),
                    'EMP-' . substr(md5($name), 0, 4),
                    $name,
                    'Academic Staff',
                    $d,
                    'Present',
                    8.0,
                ]);
            }
        }
        fclose($fp);

        // 5. Manifest metadata
        file_put_contents($dir . '/manifest.json', json_encode([
            'school_name' => self::SCHOOL_NAME,
            'academic_year' => self::ACADEMIC_YEAR,
            'start_date' => self::ACADEMIC_START,
            'end_date' => self::ACADEMIC_END,
            'student_count' => count($students),
            'staff_count' => count($staffData['users']),
            'department_count' => count($staffData['departments']),
            'generated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        return [
            'academic' => $academicFile,
            'fees' => $feeFile,
            'attendance' => $attFile,
            'staff_presence' => $staffPresenceFile,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateStudentsList(): array
    {
        $standards = [
            ['std' => 'CBSE-3', 'roman' => 'III', 'count' => 15, 'dept' => 'Primary Section (Grades 1-5)', 'teacher' => 'Clara Higgins'],
            ['std' => 'CBSE-4', 'roman' => 'IV', 'count' => 15, 'dept' => 'Primary Section (Grades 1-5)', 'teacher' => 'Clara Higgins'],
            ['std' => 'CBSE-5', 'roman' => 'V', 'count' => 15, 'dept' => 'Primary Section (Grades 1-5)', 'teacher' => 'Clara Higgins'],
            ['std' => 'CBSE-6', 'roman' => 'VI', 'count' => 15, 'dept' => 'Middle School (Grades 6-8)', 'teacher' => 'Arthur Pendelton'],
            ['std' => 'CBSE-7', 'roman' => 'VII', 'count' => 15, 'dept' => 'Middle School (Grades 6-8)', 'teacher' => 'Arthur Pendelton'],
            ['std' => 'CBSE-8', 'roman' => 'VIII', 'count' => 15, 'dept' => 'Middle School (Grades 6-8)', 'teacher' => 'Arthur Pendelton'],
            ['std' => 'CBSE-9', 'roman' => 'IX', 'count' => 15, 'dept' => 'Secondary Section (Grades 9-10)', 'teacher' => 'Rajesh Kulkarni'],
            ['std' => 'CBSE-10', 'roman' => 'X', 'count' => 15, 'dept' => 'Secondary Section (Grades 9-10)', 'teacher' => 'Rajesh Kulkarni'],
            ['std' => 'CBSE-11', 'roman' => 'XI', 'count' => 10, 'dept' => 'Higher Secondary Section (Grades 11-12)', 'teacher' => 'Anita Sharma'],
            ['std' => 'CBSE-12', 'roman' => 'XII', 'count' => 10, 'dept' => 'Higher Secondary Section (Grades 11-12)', 'teacher' => 'Anita Sharma'],
        ];

        $firstNames = ['Aarav', 'Vivaan', 'Aditya', 'Vihaan', 'Arjun', 'Sai', 'Reyansh', 'Ayaan', 'Krishna', 'Ishaan', 'Shaurya', 'Atharv', 'Advik', 'Pranav', 'Advaith', 'Ananya', 'Diya', 'Gauri', 'Aadhya', 'Pari', 'Anvi', 'Saanvi', 'Myra', 'Sara', 'Ira', 'Avani', 'Riya', 'Kavya', 'Meera', 'Roshni'];
        $lastNames = ['Sharma', 'Verma', 'Patel', 'Reddy', 'Nair', 'Iyer', 'Gupta', 'Singh', 'Deshmukh', 'Kulkarni', 'Joshi', 'Bhat', 'Rao', 'Choudhury', 'Mehta', 'Shah', 'Mukherjee', 'Banerjee', 'Ghosh', 'Chatterjee'];

        $list = [];
        $seq = 1;

        foreach ($standards as $s) {
            for ($i = 0; $i < $s['count']; $i++) {
                $ref = sprintf('V1A-2025-%03d', $seq);
                $fn = $firstNames[($seq * 3) % count($firstNames)];
                $ln = $lastNames[($seq * 7) % count($lastNames)];
                $name = $fn . ' ' . $ln;

                // Scholarships
                $schPct = 0;
                $schName = 'Unclassified';
                if ($seq % 12 === 0) {
                    $schPct = 25;
                    $schName = 'Merit Scholarship 25%';
                } elseif ($seq % 15 === 0) {
                    $schPct = 10;
                    $schName = 'Sibling Concession 10%';
                }

                // Payment behavior
                $behavior = 'on_time';
                if ($seq % 16 === 0) {
                    $behavior = 'overdue';
                } elseif ($seq % 11 === 0) {
                    $behavior = 'partial';
                }

                $ability = 0.55 + (($seq % 40) / 100);
                $attBase = 0.82 + (($seq % 17) / 100);

                $list[] = [
                    'ref' => $ref,
                    'name' => $name,
                    'standard' => $s['std'],
                    'roman' => $s['roman'],
                    'division' => ($i % 2 === 0) ? 'A' : 'B',
                    'department' => $s['dept'],
                    'class_teacher' => $s['teacher'],
                    'scholarship_pct' => $schPct,
                    'scholarship_name' => $schName,
                    'fee_behavior' => $behavior,
                    'ability' => $ability,
                    'attendance_base' => $attBase,
                ];

                $seq++;
            }
        }

        return $list;
    }

    /**
     * @param array<string, string> $files
     * @param array<string, mixed> $staffData
     * @return array{academic: int, fees: int, attendance: int, staff_presence: int, students: int}
     */
    private function ingestOperationalDatasets(string $tenantId, array $files, array $staffData): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $counts = ['academic' => 0, 'fees' => 0, 'attendance' => 0, 'staff_presence' => 0, 'students' => 140];

        // 1. Configure Sources in hpbrain_data_sources with deterministic IDs
        $academicSourceId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':source:v1a-academic-results');
        DB::table('hpbrain_data_sources')->updateOrInsert(
            ['tenant_id' => $tenantId, 'source_key' => 'v1a-academic-results'],
            [
                'id' => $academicSourceId,
                'display_name' => 'V1 Academy Academic Examination Records (2025-2026)',
                'source_type' => 'dataset',
                'config' => json_encode(['dataset_role' => 'academic', 'academic_year' => self::ACADEMIC_YEAR]),
                'field_map' => json_encode([
                    'external_ref' => 'external_ref',
                    'subject_ref' => 'subject_ref',
                    'measure' => 'marks_obtained',
                    'quantity' => 'max_marks',
                    'category' => 'subject',
                    'sub_category' => 'exam_name',
                    'state' => 'standard',
                    'evidence_timestamp' => 'exam_date',
                    'owner' => 'teacher_name',
                    'department_label' => 'department',
                ]),
                'is_active' => 1,
                'created_by' => self::AUTHOR,
                'created_date' => $now,
                'updated_date' => $now,
            ]
        );

        $feeSourceId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':source:school_fee');
        DB::table('hpbrain_data_sources')->updateOrInsert(
            ['tenant_id' => $tenantId, 'source_key' => 'school_fee'],
            [
                'id' => $feeSourceId,
                'display_name' => 'V1 Academy Fee Invoices & Collection (2025-2026)',
                'source_type' => 'dataset',
                'config' => json_encode(['dataset_role' => 'fees', 'academic_year' => self::ACADEMIC_YEAR]),
                'field_map' => json_encode([
                    'external_ref' => 'invoice_id',
                    'subject_ref' => 'student_ref',
                    'measure' => 'amount_paid',
                    'category' => 'payment_method',
                    'sub_category' => 'fee_plan',
                    'state' => 'payment_status',
                    'evidence_timestamp' => 'fee_due_date',
                    'owner' => 'collector_name',
                    'area' => 'department',
                ]),
                'is_active' => 1,
                'created_by' => self::AUTHOR,
                'created_date' => $now,
                'updated_date' => $now,
            ]
        );

        // 2. Ingest Academic Results
        $this->line('  -> Ingesting academic result records...');
        $academicJobId = (string) Uuid::uuid4();
        DB::table('hpbrain_import_jobs')->insert([
            'id' => $academicJobId,
            'tenant_id' => $tenantId,
            'source_id' => 'v1a-academic-results',
            'import_type' => 'dataset_upload',
            'entity_type' => 'operational_record',
            'source_ref' => basename($files['academic']),
            'status' => 'completed',
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'started_by' => self::AUTHOR,
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        $handle = fopen($files['academic'], 'r');
        $headers = fgetcsv($handle);
        $academicBuffer = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            $naturalKey = $data['external_ref'];
            $recordId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':v1a-academic-results:' . $naturalKey);

            $payload = [
                'student_name' => $data['student_name'],
                'standard' => $data['standard'],
                'division' => $data['division'],
                'subject' => $data['subject'],
                'exam_name' => $data['exam_name'],
                'syear' => self::ACADEMIC_YEAR,
                'teacher' => $data['teacher_name'],
                'department' => $data['department'],
            ];

            $academicBuffer[] = [
                'id' => $recordId,
                'tenant_id' => $tenantId,
                'org_id' => null,
                'dataset' => 'v1a-academic-results',
                'natural_key' => $naturalKey,
                'source_file' => basename($files['academic']),
                'source_row' => count($academicBuffer) + 1,
                'occurred_at' => $data['exam_date'] . ' 09:00:00',
                'closed_at' => $data['exam_date'] . ' 12:00:00',
                'status' => $data['standard'],
                'category' => $data['subject'],
                'sub_category' => $data['exam_name'],
                'owner_name' => $data['teacher_name'],
                'department_label' => $data['department'],
                'area' => $data['department'],
                'subject_ref' => $data['subject_ref'],
                'metric_value' => (float) $data['marks_obtained'],
                'metric_unit' => 'marks',
                'quantity' => (int) $data['max_marks'],
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'row_hash' => hash('sha256', $naturalKey . $data['marks_obtained']),
                'import_job_id' => $academicJobId,
                'created_date' => $now,
                'updated_date' => $now,
            ];

            if (count($academicBuffer) >= 500) {
                DB::table('hpbrain_operational_records')->upsert($academicBuffer, ['tenant_id', 'dataset', 'natural_key']);
                $counts['academic'] += count($academicBuffer);
                $academicBuffer = [];
            }
        }
        if ($academicBuffer !== []) {
            DB::table('hpbrain_operational_records')->upsert($academicBuffer, ['tenant_id', 'dataset', 'natural_key']);
            $counts['academic'] += count($academicBuffer);
        }
        fclose($handle);

        DB::table('hpbrain_import_jobs')->where('id', $academicJobId)->update([
            'total_rows' => $counts['academic'],
            'processed_rows' => $counts['academic'],
            'success_count' => $counts['academic'],
        ]);

        // 3. Ingest Fees (dataset: 'school_fee')
        $this->line('  -> Ingesting fee collection & invoice records...');
        $feeJobId = (string) Uuid::uuid4();
        DB::table('hpbrain_import_jobs')->insert([
            'id' => $feeJobId,
            'tenant_id' => $tenantId,
            'source_id' => 'school_fee',
            'import_type' => 'dataset_upload',
            'entity_type' => 'operational_record',
            'source_ref' => basename($files['fees']),
            'status' => 'completed',
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'started_by' => self::AUTHOR,
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        $handle = fopen($files['fees'], 'r');
        $headers = fgetcsv($handle);
        $feeBuffer = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            $naturalKey = $data['invoice_id'];
            $recordId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':school_fee:' . $naturalKey);

            $payload = [
                'invoice_id' => $data['invoice_id'],
                'student_ref' => $data['student_ref'],
                'student_name' => $data['student_name'],
                'class_name' => $data['class_name'],
                'section' => $data['section'],
                'fee_type' => $data['fee_type'],
                'fee_plan' => $data['fee_plan'],
                'amount_due' => (float) $data['amount_due'],
                'concession_amount' => (float) $data['concession_amount'],
                'net_amount' => (float) $data['net_amount'],
                'net_fee_amount' => (float) $data['net_amount'],
                'amount_paid' => (float) $data['amount_paid'],
                'balance_amount' => (float) $data['outstanding_amount'],
                'outstanding_amount' => (float) $data['outstanding_amount'],
                'fee_due_date' => $data['fee_due_date'],
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'payment_status' => $data['payment_status'],
                'days_overdue' => (int) $data['days_overdue'],
                'reminder_count' => (int) $data['reminder_count'],
                'scholarship_type' => $data['scholarship_type'],
                'risk_band' => $data['risk_band'],
                'risk_level' => $data['risk_band'],
                'expected_collectable_amount' => (float) $data['expected_collectable_amount'],
                'department' => $data['department'],
                'syear' => self::ACADEMIC_YEAR,
            ];

            $occurred = ($data['payment_date'] !== '' && $data['payment_date'] !== null)
                ? $data['payment_date'] . ' 10:30:00'
                : $data['fee_due_date'] . ' 10:30:00';

            $feeBuffer[] = [
                'id' => $recordId,
                'tenant_id' => $tenantId,
                'org_id' => null,
                'dataset' => 'school_fee',
                'natural_key' => $naturalKey,
                'source_file' => basename($files['fees']),
                'source_row' => count($feeBuffer) + 1,
                'occurred_at' => $occurred,
                'closed_at' => $data['payment_status'] === 'Paid' ? $occurred : null,
                'status' => $data['payment_status'],
                'category' => $data['payment_method'] ?: 'Pending',
                'sub_category' => $data['fee_plan'],
                'owner_name' => $data['collector_name'],
                'department_label' => $data['department'],
                'area' => $data['department'],
                'subject_ref' => $data['student_ref'],
                'metric_value' => (float) $data['amount_paid'],
                'metric_unit' => 'INR',
                'quantity' => 1,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'row_hash' => hash('sha256', $naturalKey . $data['amount_paid'] . $data['outstanding_amount']),
                'import_job_id' => $feeJobId,
                'created_date' => $now,
                'updated_date' => $now,
            ];

            if (count($feeBuffer) >= 500) {
                DB::table('hpbrain_operational_records')->upsert($feeBuffer, ['tenant_id', 'dataset', 'natural_key']);
                $counts['fees'] += count($feeBuffer);
                $feeBuffer = [];
            }
        }
        if ($feeBuffer !== []) {
            DB::table('hpbrain_operational_records')->upsert($feeBuffer, ['tenant_id', 'dataset', 'natural_key']);
            $counts['fees'] += count($feeBuffer);
        }
        fclose($handle);

        DB::table('hpbrain_import_jobs')->where('id', $feeJobId)->update([
            'total_rows' => $counts['fees'],
            'processed_rows' => $counts['fees'],
            'success_count' => $counts['fees'],
        ]);

        // 4. Ingest Student Attendance (dataset: 'attendance')
        $this->line('  -> Ingesting student attendance records...');
        $handle = fopen($files['attendance'], 'r');
        $headers = fgetcsv($handle);
        $attBuffer = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            $naturalKey = $data['record_id'];
            $recordId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':attendance:' . $naturalKey);

            $payload = [
                'student_name' => $data['student_name'],
                'standard' => $data['standard'],
                'division' => $data['division'],
                'month' => $data['month_label'],
                'working_days' => (int) $data['working_days'],
                'days_present' => (int) $data['days_present'],
                'class_teacher' => $data['class_teacher'],
                'department' => $data['department'],
                'syear' => self::ACADEMIC_YEAR,
            ];

            $attBuffer[] = [
                'id' => $recordId,
                'tenant_id' => $tenantId,
                'org_id' => null,
                'dataset' => 'attendance',
                'natural_key' => $naturalKey,
                'source_file' => basename($files['attendance']),
                'source_row' => count($attBuffer) + 1,
                'occurred_at' => $data['recorded_date'] . ' 16:00:00',
                'closed_at' => null,
                'status' => ((float) $data['attendance_pct'] >= 75.0) ? 'Satisfactory' : 'Chronic Absence Warning',
                'category' => 'Monthly Attendance',
                'sub_category' => $data['month_label'],
                'owner_name' => $data['class_teacher'],
                'department_label' => $data['department'],
                'area' => $data['department'],
                'subject_ref' => $data['student_ref'],
                'metric_value' => (float) $data['days_present'],
                'metric_unit' => 'days',
                'quantity' => (int) $data['working_days'],
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'row_hash' => hash('sha256', $naturalKey . $data['days_present']),
                'import_job_id' => $academicJobId,
                'created_date' => $now,
                'updated_date' => $now,
            ];

            if (count($attBuffer) >= 500) {
                DB::table('hpbrain_operational_records')->upsert($attBuffer, ['tenant_id', 'dataset', 'natural_key']);
                $counts['attendance'] += count($attBuffer);
                $attBuffer = [];
            }
        }
        if ($attBuffer !== []) {
            DB::table('hpbrain_operational_records')->upsert($attBuffer, ['tenant_id', 'dataset', 'natural_key']);
            $counts['attendance'] += count($attBuffer);
        }
        fclose($handle);

        // 5. Ingest Staff Presence (dataset: 'EmployeeCheckin')
        $this->line('  -> Ingesting staff presence & working hour records...');
        $handle = fopen($files['staff_presence'], 'r');
        $headers = fgetcsv($handle);
        $staffPresenceBuffer = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            $naturalKey = $data['checkin_id'];
            $recordId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':EmployeeCheckin:' . $naturalKey);

            $staffPresenceBuffer[] = [
                'id' => $recordId,
                'tenant_id' => $tenantId,
                'org_id' => null,
                'dataset' => 'EmployeeCheckin',
                'natural_key' => $naturalKey,
                'source_file' => basename($files['staff_presence']),
                'source_row' => count($staffPresenceBuffer) + 1,
                'occurred_at' => $data['date'] . ' 08:30:00',
                'closed_at' => $data['date'] . ' 16:30:00',
                'status' => $data['status'],
                'category' => 'Daily Check-in',
                'sub_category' => 'Standard Shift',
                'owner_name' => $data['staff_name'],
                'department_label' => 'Academic Staff',
                'area' => 'Campus',
                'subject_ref' => $data['employee_no'],
                'metric_value' => (float) $data['hours_worked'],
                'metric_unit' => 'hours',
                'quantity' => 1,
                'payload' => json_encode(['syear' => self::ACADEMIC_YEAR], JSON_UNESCAPED_UNICODE),
                'row_hash' => hash('sha256', $naturalKey . $data['hours_worked']),
                'import_job_id' => $academicJobId,
                'created_date' => $now,
                'updated_date' => $now,
            ];

            if (count($staffPresenceBuffer) >= 500) {
                DB::table('hpbrain_operational_records')->upsert($staffPresenceBuffer, ['tenant_id', 'dataset', 'natural_key']);
                $counts['staff_presence'] += count($staffPresenceBuffer);
                $staffPresenceBuffer = [];
            }
        }
        if ($staffPresenceBuffer !== []) {
            DB::table('hpbrain_operational_records')->upsert($staffPresenceBuffer, ['tenant_id', 'dataset', 'natural_key']);
            $counts['staff_presence'] += count($staffPresenceBuffer);
        }
        fclose($handle);

        $counts['students'] = 140;

        return $counts;
    }


    /**
     * @param array<string, mixed> $staffData
     */
    private function seedTeacherCapabilities(string $tenantId, array $staffData): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $capabilities = DB::table('hpbrain_capabilities')
            ->where('tenant_id', $tenantId)
            ->get();

        if ($capabilities->isEmpty()) {
            return;
        }

        $teachers = [
            'Rajesh Kulkarni' => $staffData['users']['Rajesh Kulkarni'] ?? null,
            'Anita Sharma' => $staffData['users']['Anita Sharma'] ?? null,
            'Clara Higgins' => $staffData['users']['Clara Higgins'] ?? null,
            'Arthur Pendelton' => $staffData['users']['Arthur Pendelton'] ?? null,
            'David Chen' => $staffData['users']['David Chen'] ?? null,
            'Sarah Jenkins' => $staffData['users']['Sarah Jenkins'] ?? null,
        ];

        foreach ($teachers as $name => $userId) {
            if ($userId === null) {
                continue;
            }

            foreach ($capabilities as $cap) {
                $assignmentId = (string) Uuid::uuid5(
                    Uuid::fromString(self::ID_NAMESPACE),
                    $tenantId . ':assignment:' . $cap->id . ':' . $userId
                );

                DB::table('hpbrain_capability_assignments')->updateOrInsert(
                    ['id' => $assignmentId],
                    [
                        'tenant_id' => $tenantId,
                        'capability_id' => $cap->id,
                        'target_type' => 'Person',
                        'target_id' => (string) $userId,
                        'assigned_by' => self::AUTHOR,
                        'assigned_date' => '2025-06-05 09:00:00',
                        'status' => 'active',
                    ]
                );

                // Baseline Evaluation (July 2025)
                $baseKnowledge = ($cap->capability_code === 'ED_DIFFERENTIATED_INSTRUCTION' && $name === 'Rajesh Kulkarni') ? 2.5 : 3.8;
                $baseAbility = ($cap->capability_code === 'ED_DIFFERENTIATED_INSTRUCTION' && $name === 'Rajesh Kulkarni') ? 2.3 : 3.7;
                $baseSkill = ($cap->capability_code === 'ED_DIFFERENTIATED_INSTRUCTION' && $name === 'Rajesh Kulkarni') ? 2.4 : 3.6;
                $baseState = ($cap->capability_code === 'ED_DIFFERENTIATED_INSTRUCTION' && $name === 'Rajesh Kulkarni') ? 'developing' : 'proficient';

                $profId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $assignmentId . ':prof1');
                DB::table('hpbrain_capability_proficiency')->updateOrInsert(
                    ['id' => $profId1],
                    [
                        'tenant_id' => $tenantId,
                        'assignment_id' => $assignmentId,
                        'knowledge_level' => $baseKnowledge,
                        'ability_level' => $baseAbility,
                        'skill_level' => $baseSkill,
                        'behaviour_level' => 3.8,
                        'attitude_level' => 4.2,
                        'capability_state' => $baseState,
                        'evidence_ref' => 'Classroom Observation & Lesson Plan Portfolio Review (Term 1 Baseline)',
                        'state_source' => 'manual',
                        'state_changed_date' => '2025-07-25 15:00:00',
                        'state_change_reason' => 'Academic Term 1 baseline pedagogical assessment',
                        'evidence_confidence' => 0.88,
                        'assessed_by' => 'Dr. Victor Sterling (Principal)',
                        'assessed_date' => '2025-07-25 15:00:00',
                        'created_date' => '2025-07-25 15:00:00',
                    ]
                );

                // Re-assessment (March 2026) showing measured progression
                $progKnowledge = ($cap->capability_code === 'ED_DIFFERENTIATED_INSTRUCTION' && $name === 'Rajesh Kulkarni') ? 4.3 : 4.4;
                $progAbility = ($cap->capability_code === 'ED_DIFFERENTIATED_INSTRUCTION' && $name === 'Rajesh Kulkarni') ? 4.1 : 4.2;
                $progSkill = ($cap->capability_code === 'ED_DIFFERENTIATED_INSTRUCTION' && $name === 'Rajesh Kulkarni') ? 4.2 : 4.3;

                $profId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $assignmentId . ':prof2');
                DB::table('hpbrain_capability_proficiency')->updateOrInsert(
                    ['id' => $profId2],
                    [
                        'tenant_id' => $tenantId,
                        'assignment_id' => $assignmentId,
                        'knowledge_level' => $progKnowledge,
                        'ability_level' => $progAbility,
                        'skill_level' => $progSkill,
                        'behaviour_level' => 4.5,
                        'attitude_level' => 4.6,
                        'capability_state' => 'mastered',
                        'evidence_ref' => 'Year-end Performance Review and Student Growth Data Portfolio',
                        'state_source' => 'manual',
                        'state_changed_date' => '2026-03-28 14:00:00',
                        'state_change_reason' => 'Successful completion of Differentiated Instruction Development Plan and measured pupil gains',
                        'evidence_confidence' => 0.94,
                        'assessed_by' => 'Dr. Victor Sterling (Principal)',
                        'assessed_date' => '2026-03-28 14:00:00',
                        'created_date' => '2026-03-28 14:00:00',
                    ]
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $staffData
     * @param array<string, int> $counts
     */
    private function seedIntelligenceLoop(string $tenantId, array $staffData, array $counts): void
    {
        $now = '2026-04-15 12:00:00';
        $principalId = (string) ($staffData['users']['Victor Sterling'] ?? '1');
        $secDeptId = (string) ($staffData['departments']['Secondary Section (Grades 9-10)'] ?? '1');
        $finDeptId = (string) ($staffData['departments']['Finance & Accounts'] ?? '2');

        // =====================================================================
        // WORKFLOW 1: Academic Diagnostic -> Remedial ESO -> Outcome Gain
        // =====================================================================
        $sigId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':sig1');
        DB::table('hpbrain_signals')->updateOrInsert(['id' => $sigId1], [
            'tenant_id' => $tenantId,
            'signal_type' => 'academic_performance_anomaly',
            'severity' => 'medium',
            'confidence' => 0.92,
            'source_system' => 'academic_records',
            'source_ref' => 'v1a-academic-results',
            'entity_type' => 'AcademicDepartment',
            'entity_id' => $secDeptId,
            'title' => 'Grade 9 Mathematics Performance Variance Across Terms',
            'description' => 'Mid-term evaluation identified an 18.4% performance drop in Secondary Grade 9 Mathematics prior to targeted remediation.',
            'payload' => json_encode(['standard' => 'CBSE-9', 'subject' => 'Mathematics', 'baseline_avg' => 54.2, 'target_avg' => 70.0], JSON_UNESCAPED_UNICODE),
            'detected_at' => '2025-10-05 14:00:00',
            'acknowledged_at' => '2025-10-06 09:30:00',
            'resolved_at' => '2026-04-05 16:00:00',
            'status' => 'resolved',
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        $evId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':ev1');
        DB::table('hpbrain_evidence')->updateOrInsert(['id' => $evId1], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId1,
            'evidence_type' => 'dataset_aggregate',
            'content' => json_encode([
                'exam' => 'Mid-Term Exam',
                'cohort_size' => 15,
                'standard' => 'CBSE-9',
                'subject' => 'Mathematics',
                'average_score' => 54.2,
                'pass_rate' => 66.7,
                'root_cause' => 'Foundational algebra gaps and curriculum acceleration.',
            ], JSON_UNESCAPED_UNICODE),
            'confidence' => 0.95,
            'provenance' => json_encode(['source' => 'v1a-academic-results', 'query' => 'SELECT avg(marks_obtained) FROM hpbrain_operational_records WHERE state="CBSE-9"'], JSON_UNESCAPED_UNICODE),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-05 14:15:00',
            'version' => 1,
        ]);

        $caseId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':case1');
        DB::table('hpbrain_cases')->updateOrInsert(['id' => $caseId1], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId1,
            'title' => 'CBSE-9 Mathematics Academic Remediation & Diagnostic Case',
            'description' => 'Targeted diagnostic assessment and individualized tutoring protocol to lift Grade 9 algebra competency.',
            'category' => 'academic_improvement',
            'priority' => 'high',
            'status' => 'closed',
            'assigned_to' => $principalId,
            'opened_at' => '2025-10-06 10:00:00',
            'closed_at' => '2026-04-02 18:00:00',
            'resolution_summary' => 'Comprehensive remediation completed. Final board preparation exams showed Grade 9 Math average lifted to 71.8%.',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-06 10:00:00',
            'updated_date' => $now,
        ]);

        DB::table('hpbrain_case_evidence')->updateOrInsert([
            'case_id' => $caseId1,
            'evidence_id' => $evId1,
        ], [
            'tenant_id' => $tenantId,
            'relevance_score' => 0.98,
            'created_date' => '2025-10-06 10:05:00',
        ]);

        $decId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':dec1');
        DB::table('hpbrain_decisions')->updateOrInsert(['id' => $decId1], [
            'tenant_id' => $tenantId,
            'case_id' => $caseId1,
            'title' => 'Authorize 12-Week Grade 9 Mathematics Remedial Protocol',
            'decision_type' => 'operational_intervention',
            'rationale' => 'Small-group remedial blocks twice weekly combined with teacher coaching in differentiated pedagogy.',
            'decided_by' => $principalId,
            'decided_at' => '2025-10-12 11:30:00',
            'status' => 'implemented',
            'review_date' => '2026-03-30',
            'trace' => json_encode(['approval_level' => 'executive_board', 'budget_allocation_inr' => 45000], JSON_UNESCAPED_UNICODE),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-12 11:30:00',
            'updated_date' => $now,
        ]);

        $esoDefId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':esodef1');
        DB::table('hpbrain_eso_definitions')->updateOrInsert(['id' => $esoDefId1], [
            'tenant_id' => $tenantId,
            'name' => 'Academic Remediation Intervention Workflow',
            'code' => 'ESO_ACAD_REMEDIAL',
            'description' => 'Automated scheduling, tutor pairing, and bi-weekly diagnostic milestone tracking for underperforming cohorts.',
            'objective' => 'academic_mastery_recovery',
            'trigger_type' => 'manual',
            'status' => 'active',
            'version' => 1,
            'definition' => json_encode(['steps' => ['diagnostic_test', 'peer_tutoring', 'milestone_quiz', 'final_assessment']], JSON_UNESCAPED_UNICODE),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-14 09:00:00',
            'updated_date' => $now,
        ]);

        $esoExecId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':esoexec1');
        DB::table('hpbrain_eso_executions')->updateOrInsert(['id' => $esoExecId1], [
            'tenant_id' => $tenantId,
            'eso_definition_id' => $esoDefId1,
            'decision_id' => $decId1,
            'status' => 'completed',
            'started_at' => '2025-10-15 08:00:00',
            'completed_at' => '2026-03-26 17:00:00',
            'execution_context' => json_encode(['target_cohort' => 'CBSE-9', 'faculty_lead' => 'Rajesh Kulkarni'], JSON_UNESCAPED_UNICODE),
            'results_summary' => json_encode(['sessions_delivered' => 24, 'students_participated' => 15, 'completion_rate' => 1.0], JSON_UNESCAPED_UNICODE),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-15 08:00:00',
            'updated_date' => $now,
        ]);

        $outId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':out1');
        DB::table('hpbrain_outcomes')->updateOrInsert(['id' => $outId1], [
            'tenant_id' => $tenantId,
            'decision_id' => $decId1,
            'eso_execution_id' => $esoExecId1,
            'title' => 'CBSE-9 Mathematics Average Score Gained +17.6 Marks',
            'outcome_type' => 'academic_improvement',
            'metric_name' => 'grade9_math_average',
            'baseline_value' => 54.2,
            'achieved_value' => 71.8,
            'target_value' => 70.0,
            'unit' => 'marks',
            'evaluated_at' => '2026-04-01 15:00:00',
            'evaluator' => 'Dr. Victor Sterling (Principal)',
            'narrative' => 'The structured remediation loop successfully restored Grade 9 Mathematics performance, exceeding the 70.0 mark target on the comprehensive final exam.',
            'created_by' => self::AUTHOR,
            'created_date' => '2026-04-01 15:00:00',
            'updated_date' => $now,
        ]);

        $learnId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':learn1');
        DB::table('hpbrain_learnings')->updateOrInsert(['id' => $learnId1], [
            'tenant_id' => $tenantId,
            'outcome_id' => $outId1,
            'title' => 'Small-Group Diagnostic Remediation Efficacy in Secondary Mathematics',
            'category' => 'pedagogical_best_practice',
            'learning_text' => 'Early diagnostic intervention in Term 1 with targeted peer workshops recovers pupil confidence and prevents secondary school drop-offs.',
            'confidence' => 0.94,
            'applicability' => 'Secondary & Middle School Mathematics and Physical Sciences',
            'status' => 'approved',
            'validated_by' => $principalId,
            'created_by' => self::AUTHOR,
            'created_date' => '2026-04-03 11:00:00',
            'updated_date' => $now,
        ]);

        // =====================================================================
        // WORKFLOW 2: Fee Delinquency Proactive Counseling & Collection Loop
        // =====================================================================
        $sigId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':sig2');
        DB::table('hpbrain_signals')->updateOrInsert(['id' => $sigId2], [
            'tenant_id' => $tenantId,
            'signal_type' => 'fee_delinquency_cluster',
            'severity' => 'medium',
            'confidence' => 0.94,
            'source_system' => 'fees_module',
            'source_ref' => 'school_fee',
            'entity_type' => 'FinanceDepartment',
            'entity_id' => $finDeptId,
            'title' => 'Term 3 Fee Collection Delinquency Concentration',
            'description' => 'Identified 9 student accounts overdue in Term 3 tuition totaling INR 270,000 requiring parent counseling.',
            'payload' => json_encode(['term' => 'Term 3 Fee', 'overdue_count' => 9, 'overdue_amount_inr' => 270000], JSON_UNESCAPED_UNICODE),
            'detected_at' => '2025-12-28 10:00:00',
            'acknowledged_at' => '2025-12-29 09:15:00',
            'resolved_at' => '2026-02-28 17:00:00',
            'status' => 'resolved',
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        $evId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':ev2');
        DB::table('hpbrain_evidence')->updateOrInsert(['id' => $evId2], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId2,
            'evidence_type' => 'financial_ledger',
            'content' => json_encode([
                'overdue_invoices' => 9,
                'gross_outstanding' => 270000.0,
                'avg_delay_days' => 45,
                'primary_reason' => 'Economic shock in local manufacturing cluster; parents requested split installments.',
            ], JSON_UNESCAPED_UNICODE),
            'confidence' => 0.96,
            'provenance' => json_encode(['source' => 'school_fee', 'dataset' => 'fees'], JSON_UNESCAPED_UNICODE),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-12-28 10:30:00',
            'version' => 1,
        ]);

        $caseId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':case2');
        DB::table('hpbrain_cases')->updateOrInsert(['id' => $caseId2], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId2,
            'title' => 'Term 3 Parental Financial Relief & Structured Fee Restructuring',
            'description' => 'Establish split-installment agreements with affected parents to recover tuition without academic disruption.',
            'category' => 'financial_sustainability',
            'priority' => 'medium',
            'status' => 'closed',
            'assigned_to' => (string) ($staffData['users']['Rohan Verma'] ?? $principalId),
            'opened_at' => '2025-12-29 11:00:00',
            'closed_at' => '2026-02-28 16:30:00',
            'resolution_summary' => '7 of 9 families enrolled in 3-part micro-installments. Total recovered: INR 243,000 (90.0%).',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-12-29 11:00:00',
            'updated_date' => $now,
        ]);

        DB::table('hpbrain_case_evidence')->updateOrInsert([
            'case_id' => $caseId2,
            'evidence_id' => $evId2,
        ], [
            'tenant_id' => $tenantId,
            'relevance_score' => 0.99,
            'created_date' => '2025-12-29 11:15:00',
        ]);

        $decId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':dec2');
        DB::table('hpbrain_decisions')->updateOrInsert(['id' => $decId2], [
            'tenant_id' => $tenantId,
            'case_id' => $caseId2,
            'title' => 'Approve Flexible Split-Payment Installment Framework',
            'decision_type' => 'financial_policy_waiver',
            'rationale' => 'Providing bi-weekly micro-payment plans preserves enrollment retention while ensuring cashflow recovery.',
            'decided_by' => $principalId,
            'decided_at' => '2026-01-04 14:00:00',
            'status' => 'implemented',
            'review_date' => '2026-03-01',
            'trace' => json_encode(['officer' => 'Rohan Verma', 'approved_by' => 'Dr. Victor Sterling'], JSON_UNESCAPED_UNICODE),
            'created_by' => self::AUTHOR,
            'created_date' => '2026-01-04 14:00:00',
            'updated_date' => $now,
        ]);

        $esoDefId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':esodef2');
        DB::table('hpbrain_eso_definitions')->updateOrInsert(['id' => $esoDefId2], [
            'tenant_id' => $tenantId,
            'name' => 'Flexible Fee Restructuring & Reminder Workflow',
            'code' => 'ESO_FEE_RELIEF',
            'description' => 'Automated micro-installment schedule generation, WhatsApp/SMS payment links, and finance officer reconciliations.',
            'objective' => 'fee_recovery_retention',
            'trigger_type' => 'manual',
            'status' => 'active',
            'version' => 1,
            'definition' => json_encode(['steps' => ['generate_plan', 'send_digital_mandate', 'reconcile_receipt']], JSON_UNESCAPED_UNICODE),
            'created_by' => self::AUTHOR,
            'created_date' => '2026-01-05 09:00:00',
            'updated_date' => $now,
        ]);

        $esoExecId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':esoexec2');
        DB::table('hpbrain_eso_executions')->updateOrInsert(['id' => $esoExecId2], [
            'tenant_id' => $tenantId,
            'eso_definition_id' => $esoDefId2,
            'decision_id' => $decId2,
            'status' => 'completed',
            'started_at' => '2026-01-06 08:30:00',
            'completed_at' => '2026-02-27 17:00:00',
            'execution_context' => json_encode(['target_invoices' => 9, 'plan_type' => 'tri_monthly_split'], JSON_UNESCAPED_UNICODE),
            'results_summary' => json_encode(['plans_accepted' => 8, 'collected_amount' => 243000.0], JSON_UNESCAPED_UNICODE),
            'created_by' => self::AUTHOR,
            'created_date' => '2026-01-06 08:30:00',
            'updated_date' => $now,
        ]);

        $outId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':out2');
        DB::table('hpbrain_outcomes')->updateOrInsert(['id' => $outId2], [
            'tenant_id' => $tenantId,
            'decision_id' => $decId2,
            'eso_execution_id' => $esoExecId2,
            'title' => '90% Recovery of Overdue Term 3 Receivables Achieved',
            'outcome_type' => 'revenue_recovery',
            'metric_name' => 'term3_overdue_recovery_pct',
            'baseline_value' => 0.0,
            'achieved_value' => 90.0,
            'target_value' => 80.0,
            'unit' => 'percent',
            'evaluated_at' => '2026-02-28 17:30:00',
            'evaluator' => 'Rohan Verma (Finance Officer)',
            'narrative' => 'Flexible payment options yielded 90% recovery within 52 days with zero student de-enrollments.',
            'created_by' => self::AUTHOR,
            'created_date' => '2026-02-28 17:30:00',
            'updated_date' => $now,
        ]);

        $learnId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':learn2');
        DB::table('hpbrain_learnings')->updateOrInsert(['id' => $learnId2], [
            'tenant_id' => $tenantId,
            'outcome_id' => $outId2,
            'title' => 'Proactive Split-Installment Counseling Improves Fee Recovery',
            'category' => 'finance_operational_protocol',
            'learning_text' => 'Direct parental engagement offering structured payment milestones converts 88%+ of overdue receivables without legal or punitive notices.',
            'confidence' => 0.95,
            'applicability' => 'Institutional Finance & Admissions',
            'status' => 'approved',
            'validated_by' => $principalId,
            'created_by' => self::AUTHOR,
            'created_date' => '2026-03-02 10:00:00',
            'updated_date' => $now,
        ]);

        // =====================================================================
        // WORKFLOW 3: Teacher Capability Development (Differentiated Instruction)
        // =====================================================================
        $sigId3 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':sig3');
        DB::table('hpbrain_signals')->updateOrInsert(['id' => $sigId3], [
            'tenant_id' => $tenantId,
            'signal_type' => 'faculty_competency_gap',
            'severity' => 'low',
            'confidence' => 0.89,
            'source_system' => 'academic_supervision',
            'source_ref' => 'classroom_observations',
            'entity_type' => 'Person',
            'entity_id' => (string) ($staffData['users']['Rajesh Kulkarni'] ?? '5'),
            'title' => 'Differentiated Instruction Capability Development Opportunity',
            'description' => 'Classroom supervisory audits indicated opportunity for deeper multi-tiered lesson plan differentiation in secondary STEM.',
            'payload' => json_encode(['teacher' => 'Rajesh Kulkarni', 'capability' => 'ED_DIFFERENTIATED_INSTRUCTION', 'baseline_level' => 2.4], JSON_UNESCAPED_UNICODE),
            'detected_at' => '2025-07-28 16:00:00',
            'acknowledged_at' => '2025-07-29 10:00:00',
            'resolved_at' => '2026-03-29 15:00:00',
            'status' => 'resolved',
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        $evId3 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':ev3');
        DB::table('hpbrain_evidence')->updateOrInsert(['id' => $evId3], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId3,
            'evidence_type' => 'pedagogical_audit',
            'content' => json_encode([
                'audit_score' => 2.4,
                'target_score' => 4.0,
                'strengths' => 'Strong subject matter depth and classroom discipline.',
                'growth_areas' => 'Needs multi-tiered worksheets and formative assessment pulse checks for diverse pacing.',
            ], JSON_UNESCAPED_UNICODE),
            'confidence' => 0.91,
            'provenance' => json_encode(['auditor' => 'Dr. Victor Sterling', 'rubric' => 'CBSE Teacher Capability Framework v2.1'], JSON_UNESCAPED_UNICODE),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-07-28 16:30:00',
            'version' => 1,
        ]);

        $caseId3 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':case3');
        DB::table('hpbrain_cases')->updateOrInsert(['id' => $caseId3], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId3,
            'title' => 'Faculty Pedagogical Growth Plan: Differentiated Learning Strategies',
            'description' => 'Pair secondary faculty with academic coach for bi-weekly co-planning and student artifact review.',
            'category' => 'professional_development',
            'priority' => 'medium',
            'status' => 'closed',
            'assigned_to' => $principalId,
            'opened_at' => '2025-07-30 09:00:00',
            'closed_at' => '2026-03-29 16:00:00',
            'resolution_summary' => 'Completed 8 peer-observation cycles. Year-end evaluation demonstrated proficiency level advancement to 4.2 (Mastered).',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-07-30 09:00:00',
            'updated_date' => $now,
        ]);

        DB::table('hpbrain_case_evidence')->updateOrInsert([
            'case_id' => $caseId3,
            'evidence_id' => $evId3,
        ], [
            'tenant_id' => $tenantId,
            'relevance_score' => 0.95,
            'created_date' => '2025-07-30 09:15:00',
        ]);

        $decId3 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':dec3');
        DB::table('hpbrain_decisions')->updateOrInsert(['id' => $decId3], [
            'tenant_id' => $tenantId,
            'case_id' => $caseId3,
            'title' => 'Authorize Professional Development Mentorship on Differentiated Instruction',
            'decision_type' => 'professional_development_allocation',
            'rationale' => 'Structured peer mentoring accelerates teacher pedagogical agility faster than passive workshops.',
            'decided_by' => $principalId,
            'decided_at' => '2025-08-04 11:00:00',
            'status' => 'implemented',
            'review_date' => '2026-03-25',
            'trace' => json_encode(['mentor' => 'Evelyn Vance', 'mentee' => 'Rajesh Kulkarni'], JSON_UNESCAPED_UNICODE),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-08-04 11:00:00',
            'updated_date' => $now,
        ]);

        $esoDefId3 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':esodef3');
        DB::table('hpbrain_eso_definitions')->updateOrInsert(['id' => $esoDefId3], [
            'tenant_id' => $tenantId,
            'name' => 'Faculty Peer Observation & Mentorship Workflow',
            'code' => 'ESO_FACULTY_MENTOR',
            'description' => 'Co-planning lesson frameworks, peer observation feedback rubrics, and longitudinal student progress review.',
            'objective' => 'teacher_growth_and_mastery',
            'trigger_type' => 'manual',
            'status' => 'active',
            'version' => 1,
            'definition' => json_encode(['steps' => ['co_planning', 'classroom_walkthrough', 'reflection_journal', 'proficiency_reassessment']], JSON_UNESCAPED_UNICODE),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-08-05 09:00:00',
            'updated_date' => $now,
        ]);

        $esoExecId3 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':esoexec3');
        DB::table('hpbrain_eso_executions')->updateOrInsert(['id' => $esoExecId3], [
            'tenant_id' => $tenantId,
            'eso_definition_id' => $esoDefId3,
            'decision_id' => $decId3,
            'status' => 'completed',
            'started_at' => '2025-08-10 08:30:00',
            'completed_at' => '2026-03-25 16:00:00',
            'execution_context' => json_encode(['mentor' => 'Evelyn Vance', 'mentee' => 'Rajesh Kulkarni'], JSON_UNESCAPED_UNICODE),
            'results_summary' => json_encode(['observations_completed' => 8, 'rubric_evaluations' => 8], JSON_UNESCAPED_UNICODE),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-08-10 08:30:00',
            'updated_date' => $now,
        ]);

        $outId3 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':out3');
        DB::table('hpbrain_outcomes')->updateOrInsert(['id' => $outId3], [
            'tenant_id' => $tenantId,
            'decision_id' => $decId3,
            'eso_execution_id' => $esoExecId3,
            'title' => 'Teacher Pedagogical Skill Score Advanced from 2.4 to 4.2',
            'outcome_type' => 'capability_advancement',
            'metric_name' => 'differentiated_instruction_proficiency',
            'baseline_value' => 2.4,
            'achieved_value' => 4.2,
            'target_value' => 4.0,
            'unit' => 'rubric_score',
            'evaluated_at' => '2026-03-28 15:30:00',
            'evaluator' => 'Dr. Victor Sterling (Principal)',
            'narrative' => 'Teacher demonstrated exemplary mastery in tailoring learning tracks, directly correlating with improved student test outcomes.',
            'created_by' => self::AUTHOR,
            'created_date' => '2026-03-28 15:30:00',
            'updated_date' => $now,
        ]);

        $learnId3 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':learn3');
        DB::table('hpbrain_learnings')->updateOrInsert(['id' => $learnId3], [
            'tenant_id' => $tenantId,
            'outcome_id' => $outId3,
            'title' => 'Peer Observation Cycles Accelerate Pedagogical Mastery',
            'category' => 'faculty_development_model',
            'learning_text' => 'Continuous peer co-planning cycles yield higher retention of teaching competencies than external standalone workshops.',
            'confidence' => 0.93,
            'applicability' => 'K-12 Instructional Coaching',
            'status' => 'approved',
            'validated_by' => $principalId,
            'created_by' => self::AUTHOR,
            'created_date' => '2026-03-29 11:00:00',
            'updated_date' => $now,
        ]);
    }
}
