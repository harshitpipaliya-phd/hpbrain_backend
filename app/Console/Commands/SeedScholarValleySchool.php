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

final class SeedScholarValleySchool extends Command
{
    protected $signature = 'school:seed-scholar-valley
        {--replace : Purge and recreate Scholar Valley if it already exists}
        {--email=admin@scholarvalley.edu : Admin login email}
        {--password=Admin@ScholarValley2025 : Admin login password}';

    protected $description = 'Create and ingest a complete fictional school organization (Scholar Valley International School) for academic year 2025-2026.';

    public const SCHOOL_NAME = 'Scholar Valley International School';
    public const SHORT_CODE = 'SVIS';
    public const ACADEMIC_YEAR = '2025-2026';
    public const ACADEMIC_START = '2025-06-01';
    public const ACADEMIC_END = '2026-04-30';

    private const AUTHOR = 'scholar-valley-seeder';
    private const ID_NAMESPACE = '7b92bdf1-443d-4110-85ab-398c4aae34b8';

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
        $this->info('  SCHOLAR VALLEY INTERNATIONAL SCHOOL — SEED & INGESTION PIPELINE');
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
                $this->info("Found existing Scholar Valley tenant: {$tenantId}. Reusing tenant.");
            }
        }

        if ($tenantId === null) {
            $this->info('Creating clean school tenant via OrganizationSignupService...');
            $result = $signup->provision([
                'organizationName' => self::SCHOOL_NAME,
                'organizationEmail' => $email,
                'password' => $password,
                'industry' => 'k12_education',
                'legalName' => self::SCHOOL_NAME . ' Trust',
                'address' => 'Plot 42, Knowledge Corridor, Tech City, Sector 5, Bangalore 560100',
                'city' => 'Bangalore',
                'state' => 'Karnataka',
                'country' => 'India',
                'organizationMobile' => '9876543210',
            ]);
            $tenantId = (string) $result['tenantId'];
            $this->info("Created new tenant: {$tenantId} (Client ID: {$result['clientId']})");
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
        $staffData = $this->seedErpStaff($tenantId);

        // 4. Generate and write dataset files to disk
        $this->info('Generating versioned CSV dataset files on disk for audit & reproducibility...');
        $datasetFiles = $this->generateDatasetFiles($tenantId, $staffData);

        // 5. Ingest datasets through HP Brain ingestion architecture
        $this->info('Ingesting operational records through existing ingestion data sources...');
        $counts = $this->ingestOperationalDatasets($tenantId, $datasetFiles, $staffData);

        // 6. Project student read-models
        $this->info('Building student read-model projection (hpbrain_students)...');
        $projResult = $students->rebuild($tenantId, 'svis-academic-results', 'school_fee');
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
        $this->info('  SCHOLAR VALLEY INTERNATIONAL SCHOOL SUCCESSFULLY SEEDED & INGESTED');
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

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        }

        // Clean ERP tables
        if (Schema::hasTable('tbluser')) {
            DB::table('tbluser')->where('sub_institute_id', $tenantId)->delete();
        }
        if (Schema::hasTable('hrms_departments')) {
            DB::table('hrms_departments')->where('sub_institute_id', $tenantId)->delete();
        }
        if (Schema::hasTable('hrms_job_titles')) {
            DB::table('hrms_job_titles')->where('sub_institute_id', $tenantId)->delete();
        }
        if (Schema::hasTable('tbluserprofilemaster')) {
            DB::table('tbluserprofilemaster')->where('sub_institute_id', $tenantId)->delete();
        }
        if (Schema::hasTable('institute_detail')) {
            DB::table('institute_detail')->where('sub_institute_id', $tenantId)->delete();
        }
        if (Schema::hasTable('org_details')) {
            DB::table('org_details')->where('sub_institute_id', $tenantId)->delete();
        }
        if (Schema::hasTable('hpbrain_entity_mappings')) {
            DB::table('hpbrain_entity_mappings')->where('tenant_id', $tenantId)->delete();
        }
        if (Schema::hasTable('school_setup')) {
            DB::table('school_setup')->where('id', $tenantId)->delete();
        }
    }

    /**
     * @return array{departments: array<string, int>, jobTitles: array<string, int>, profiles: array<string, int>, users: array<string, int>}
     */
    private function seedErpStaff(string $tenantId): array
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
                'first_name' => 'Evelyn',
                'last_name' => 'Vance',
                'email' => 'evelyn.vance@scholarvalley.edu',
                'employee_no' => 'EMP-001',
                'job' => 'Principal',
                'profile' => 'Principal',
                'dept' => 'Administration & Operations',
                'gender' => 'Female',
                'is_admin' => 1,
            ],
            [
                'first_name' => 'Marcus',
                'last_name' => 'Sterling',
                'email' => 'marcus.sterling@scholarvalley.edu',
                'employee_no' => 'EMP-002',
                'job' => 'Vice Principal',
                'profile' => 'Teacher',
                'dept' => 'Administration & Operations',
                'gender' => 'Male',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Clara',
                'last_name' => 'Higgins',
                'email' => 'clara.higgins@scholarvalley.edu',
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
                'email' => 'arthur.pendelton@scholarvalley.edu',
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
                'email' => 'rajesh.kulkarni@scholarvalley.edu',
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
                'email' => 'anita.sharma@scholarvalley.edu',
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
                'email' => 'david.chen@scholarvalley.edu',
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
                'email' => 'sarah.jenkins@scholarvalley.edu',
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
                'email' => 'vikram.patel@scholarvalley.edu',
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
                'email' => 'meenakshi.sundaram@scholarvalley.edu',
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
                'email' => 'rohan.verma@scholarvalley.edu',
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
                'email' => 'linda.gomez@scholarvalley.edu',
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
                'gender' => $staff['gender'],
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
                DB::table('tbluser')->where('id', $existingId)->update($userPayload);
                $userIds[$staff['first_name'] . ' ' . $staff['last_name']] = (int) $existingId;
            } else {
                $userPayload['password'] = Hash::make('Teacher@2025');
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
            'Administration & Operations' => $userIds['Marcus Sterling'] ?? null,
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
        $dir = base_path('database/seeders/data/scholar_valley');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Student roll (125 students across standards)
        $students = $this->generateStudentsList();

        // 1. Academic Results CSV
        $academicFile = $dir . '/svis_academic_results_2025_2026.csv';
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
            'CBSE-5' => [
                ['subject' => 'Mathematics', 'teacher' => 'Clara Higgins', 'dept' => 'Primary Section (Grades 1-5)'],
                ['subject' => 'English', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Science', 'teacher' => 'David Chen', 'dept' => 'Science & Mathematics Department'],
                ['subject' => 'Social Studies', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
                ['subject' => 'Hindi', 'teacher' => 'Sarah Jenkins', 'dept' => 'Languages & Humanities Department'],
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
            $baseAbility = $stu['ability']; // 0.35 to 0.95

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

                    // Score calculation
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
        $feeFile = $dir . '/svis_fee_collection_2025_2026.csv';
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
                'CBSE-3', 'CBSE-5' => 20000,
                'CBSE-7', 'CBSE-8' => 25000,
                'CBSE-9', 'CBSE-10' => 30000,
                default => 35000,
            };

            $concessionPct = $stu['scholarship_pct'];
            $scholarshipName = $stu['scholarship_name'];
            $feeCohort = $stu['fee_behavior']; // 'on_time', 'partial', 'overdue'

            foreach ($installments as $idx => $inst) {
                $feeRows++;
                $invId = sprintf('INV-2025-%s-Q%d', substr($stu['ref'], -3), $idx + 1);
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
                    $isLab ? 'Lab & Technology Fee' : 'Tuition Fee',
                    $inst['name'],
                    $gross,
                    $concession,
                    $net,
                    $paid,
                    $outstanding,
                    $inst['due'],
                    $paidDate,
                    $paidDate !== null ? $mode : '',
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
        $attFile = $dir . '/svis_student_attendance_2025_2026.csv';
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
            $baseAtt = $stu['attendance_base']; // 0.70 to 0.98
            $teacher = $stu['class_teacher'];
            $dept = $stu['department'];

            foreach ($months as $m) {
                $recId = sprintf('ATT-%s-%s', $stu['ref'], str_replace(' ', '-', $m['label']));
                $factor = $baseAtt;
                // Chronic low attendance group gets intervention in Term 2
                if ($stu['fee_behavior'] === 'overdue' && in_array($m['label'], ['June 2025', 'July 2025', 'August 2025'], true)) {
                    $factor = 0.71;
                } elseif ($stu['fee_behavior'] === 'overdue') {
                    $factor = 0.88; // Improved after parent intervention
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
        $staffPresenceFile = $dir . '/svis_staff_presence_2025_2026.csv';
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
                $ref = sprintf('SVIS-2025-%03d', $seq);
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

                $ability = 0.55 + (($seq % 40) / 100); // 0.55 to 0.95
                $attBase = 0.82 + (($seq % 17) / 100); // 0.82 to 0.99

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

        // 1. Configure Sources in hpbrain_data_sources
        DB::table('hpbrain_data_sources')->updateOrInsert(
            ['tenant_id' => $tenantId, 'source_key' => 'svis-academic-results'],
            [
                'display_name' => 'Scholar Valley Academic Examination Records (2025-2026)',
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

        DB::table('hpbrain_data_sources')->updateOrInsert(
            ['tenant_id' => $tenantId, 'source_key' => 'school_fee'],
            [
                'display_name' => 'Scholar Valley Fee Invoices & Collection (2025-2026)',
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
            'source_id' => 'svis-academic-results',
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
            $recordId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':svis-academic-results:' . $naturalKey);

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
                'dataset' => 'svis-academic-results',
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
                        'assessed_by' => 'Dr. Evelyn Vance (Principal)',
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
                        'assessed_by' => 'Dr. Evelyn Vance (Principal)',
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
        $principalId = (string) ($staffData['users']['Evelyn Vance'] ?? '1');
        $secDeptId = (string) ($staffData['departments']['Secondary Section (Grades 9-10)'] ?? '1');
        $finDeptId = (string) ($staffData['departments']['Finance & Accounts'] ?? '2');

        // =====================================================================
        // WORKFLOW 1: Academic Diagnostic -> Remedial ESO -> Outcome Gain
        // =====================================================================
        $sigId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':sig1');
        DB::table('hpbrain_signals')->updateOrInsert(['id' => $sigId1], [
            'tenant_id' => $tenantId,
            'dedupe_key' => $tenantId . ':sig:math-gap-cbse9:2025-2026',
            'org_id' => null,
            'source' => 'academic-analyzer',
            'classification' => 'risk',
            'rule_key' => 'academic.cohort_spread',
            'priority' => 'high',
            'severity' => 'high',
            'confidence' => 0.93,
            'related_entity_type' => 'Department',
            'related_entity_id' => $secDeptId,
            'department_id' => $secDeptId,
            'status' => 'investigating',
            'metadata' => json_encode([
                'title' => 'Grade 9 Mathematics Performance Gap in Mid-Term Examinations',
                'cohort' => 'CBSE-9',
                'subject' => 'Mathematics',
                'cohort_avg_pct' => 52.8,
                'school_avg_pct' => 71.8,
                'gap_points' => 19.0,
                'affected_students' => 15,
                'at_risk_students' => 6,
            ]),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-09-28 10:00:00',
            'updated_date' => $now,
        ]);

        $evId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':ev1');
        DB::table('hpbrain_evidence')->updateOrInsert(['id' => $evId1], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId1,
            'source' => 'svis-academic-results',
            'evidence_type' => 'assessment_records',
            'content' => 'Aggregated analysis of 120 exam answer scripts across Unit Test 1 and Mid-Term Exam shows 15 students in Grade 9 averaging 52.8% in Mathematics versus a school-wide subject benchmark of 71.8%. Specific conceptual gaps identified in algebraic factorization and coordinate geometry.',
            'provenance' => json_encode([
                'dataset' => 'svis-academic-results',
                'query' => "SELECT AVG(metric_value/quantity*100) FROM hpbrain_operational_records WHERE status='CBSE-9' AND category='Mathematics'",
                'rows_analyzed' => 120,
            ]),
            'confidence' => 0.95,
            'hash' => hash('sha256', 'grade-9-math-gap-evidence'),
            'version' => '1.0',
            'status' => 'active',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-09-28 10:30:00',
            'observed_date' => '2025-09-25 00:00:00',
            'ledger_sequence' => 1,
        ]);

        $caseId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':case1');
        DB::table('hpbrain_cases')->updateOrInsert(['id' => $caseId1], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId1,
            'title' => 'Diagnostic Review of Grade 9 Mathematics Conceptual Gaps',
            'description' => "Mid-term examination results indicate that Grade 9 Mathematics is performing 19.0 points below school benchmark. Left unaddressed, foundational algebraic deficits will severely impede standard 10 board preparation.\n\nSupporting Records: 120 exam records across 15 students.",
            'status' => 'resolved',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-09-29 11:00:00',
            'updated_date' => $now,
        ]);

        DB::table('hpbrain_case_evidence')->updateOrInsert(
            ['tenant_id' => $tenantId, 'case_id' => $caseId1, 'evidence_id' => $evId1],
            ['linked_date' => '2025-09-29 11:15:00']
        );

        $hypId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':hyp1');
        DB::table('hpbrain_hypotheses')->updateOrInsert(['id' => $hypId1], [
            'tenant_id' => $tenantId,
            'case_id' => $caseId1,
            'statement' => 'Cognitive gap in linear equation solving and geometric proofs from middle school transition is depressing secondary problem-solving speed.',
            'root_cause_family' => 'pedagogical_alignment',
            'confidence' => 0.89,
            'status' => 'confirmed',
            'supporting_evidence_ids' => json_encode([$evId1]),
            'proposed_by' => 'Dr. Rajesh Kulkarni',
            'created_date' => '2025-10-02 09:30:00',
        ]);
        DB::table('hpbrain_cases')->where('id', $caseId1)->update(['resolved_hypothesis_id' => $hypId1]);

        $stepId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':step1');
        DB::table('hpbrain_reasoning_steps')->updateOrInsert(['id' => $stepId1], [
            'tenant_id' => $tenantId,
            'case_id' => $caseId1,
            'signal_id' => $sigId1,
            'step_order' => 1,
            'description' => 'Targeted modular remediation focusing on algebraic foundations before proceeding with advanced quadratic syllabus will restore competency trajectory.',
            'confidence_score' => 0.91,
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-03 14:00:00',
        ]);

        $esoDefId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':eso-def1');
        DB::table('hpbrain_eso_definitions')->updateOrInsert(['id' => $esoDefId1], [
            'tenant_id' => $tenantId,
            'org_id' => null,
            'eso_code' => 'ESO-ACAD-REMEDIAL-01',
            'name' => 'Structured Remedial Mathematics Academic Intervention Protocol',
            'version' => '1.0',
            'status' => 'active',
            'owner' => 'Secondary Section',
            'provenance' => 'Academic Council Standards',
            'objective' => 'Accelerate at-risk student conceptual mastery through twice-weekly small group problem solving.',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-05 10:00:00',
            'updated_date' => $now,
        ]);

        $recId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':rec1');
        DB::table('hpbrain_recommendations')->updateOrInsert(['id' => $recId1], [
            'tenant_id' => $tenantId,
            'reasoning_step_id' => $stepId1,
            'category' => 'improve',
            'title' => 'Authorize 6-Week Grade 9 Mathematics Remedial Clinic',
            'description' => 'Mandate twice-weekly after-school tutorial clinics led by Dr. Rajesh Kulkarni, utilizing formative practice modules and individualized learning diagnostics.',
            'priority' => 'high',
            'urgency' => 'urgent',
            'confidence' => 0.92,
            'impact' => 'Restores average pass rate to >70% prior to final examination cycle.',
            'cost' => 'Zero external cost (reallocated internal tutorial periods)',
            'risk' => 'low',
            'dependencies' => json_encode([]),
            'status' => 'accepted',
            'eso_id' => $esoDefId1,
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-05 11:30:00',
            'updated_date' => $now,
        ]);

        $decId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':dec1');
        DB::table('hpbrain_decisions')->updateOrInsert(['id' => $decId1], [
            'tenant_id' => $tenantId,
            'recommendation_id' => $recId1,
            'decided_by' => $principalId,
            'executor_type' => 'human',
            'rationale' => 'Approved based on compelling empirical evidence from Unit Test 1 and Mid-Term datasets. Dr. Kulkarni to lead instruction.',
            'alternatives_considered' => json_encode(['External coaching referral (rejected)', 'Status quo with extra homework (rejected)']),
            'status' => 'approved',
            'confidence' => 0.94,
            'explanation' => 'Execution commenced on 2025-10-10.',
            'approved_by' => $principalId,
            'approved_date' => '2025-10-06 16:00:00',
            'approval_note' => 'Approved unanimously by Academic Council.',
            'created_date' => '2025-10-06 16:00:00',
        ]);

        $planId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':plan1');
        DB::table('hpbrain_measurement_plans')->updateOrInsert(['id' => $planId1], [
            'tenant_id' => $tenantId,
            'decision_id' => $decId1,
            'baseline_metric' => 'grade9_math_midterm_pct',
            'baseline_value' => 52.80,
            'target_value' => 70.00,
            'metric_unit' => 'percentage',
            'measurement_window_days' => 120,
            'owner_id' => $principalId,
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-07 09:00:00',
        ]);

        $execId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':exec1');
        DB::table('hpbrain_eso_executions')->updateOrInsert(['id' => $execId1], [
            'tenant_id' => $tenantId,
            'eso_id' => $esoDefId1,
            'eso_definition_id' => $esoDefId1,
            'decision_id' => $decId1,
            'status' => 'completed',
            'executed_by' => $principalId,
            'executor_type' => 'human',
            'input' => json_encode(['cohort' => 'CBSE-9', 'subject' => 'Mathematics', 'clinic_sessions' => 12]),
            'output' => json_encode(['sessions_conducted' => 12, 'attendance_rate' => 96.5, 'formative_quiz_average' => 71.4]),
            'started_date' => '2025-10-10 15:30:00',
            'completed_date' => '2025-12-05 16:30:00',
            'created_date' => '2025-10-10 15:30:00',
        ]);

        $outId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':out1');
        DB::table('hpbrain_outcomes')->updateOrInsert(['id' => $outId1], [
            'tenant_id' => $tenantId,
            'decision_id' => $decId1,
            'result' => 'Remedial intervention achieved dramatic academic recovery: Grade 9 Mathematics average rose to 66.4% in Unit Test 2 and reached 74.6% in the March 2026 Final Examination, exceeding the 70.0% target.',
            'metrics' => json_encode([
                'baseline_pct' => 52.80,
                'ut2_pct' => 66.40,
                'final_exam_pct' => 74.60,
                'net_gain_points' => 21.80,
                'target_achieved' => true,
            ]),
            'kpis' => json_encode(['cohort_pass_rate' => 100.0, 'at_risk_students_remaining' => 0]),
            'evidence_ids' => json_encode([$evId1]),
            'feedback' => 'Students demonstrated marked improvement in algebraic problem confidence.',
            'confidence' => 0.96,
            'created_by' => self::AUTHOR,
            'created_date' => '2026-03-29 16:00:00',
        ]);

        $learnId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':learn1');
        DB::table('hpbrain_learnings')->updateOrInsert(['id' => $learnId1], [
            'tenant_id' => $tenantId,
            'outcome_id' => $outId1,
            'mental_model_id' => null,
            'pattern' => 'Early diagnostic modular remediation in secondary mathematics',
            'description' => 'Targeted 6-week remedial intervention immediately following mid-term diagnostics recovers over 20 percentage points in secondary cohorts with zero student drop-off.',
            'domain' => 'Academics & Pedagogy',
            'confidence' => 0.94,
            'reusable' => 1,
            'created_by' => self::AUTHOR,
            'created_date' => '2026-03-30 11:00:00',
        ]);

        // =====================================================================
        // WORKFLOW 2: Fee Overdue Risk -> Counseling & Flexible Plan -> Recovery
        // =====================================================================
        $sigId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':sig2');
        DB::table('hpbrain_signals')->updateOrInsert(['id' => $sigId2], [
            'tenant_id' => $tenantId,
            'dedupe_key' => $tenantId . ':sig:fee-overdue-q3:2025-2026',
            'org_id' => null,
            'source' => 'fee-intelligence-service',
            'classification' => 'risk',
            'rule_key' => 'finance.overdue_concentration',
            'priority' => 'high',
            'severity' => 'high',
            'confidence' => 0.91,
            'related_entity_type' => 'Department',
            'related_entity_id' => $finDeptId,
            'department_id' => $finDeptId,
            'status' => 'resolved',
            'metadata' => json_encode([
                'title' => 'Concentration of Overdue Term 3 Tuition Fees in Secondary Cohorts',
                'overdue_amount' => 185000.0,
                'affected_families' => 5,
                'days_past_due' => 75,
            ]),
            'created_by' => self::AUTHOR,
            'created_date' => '2026-02-01 10:00:00',
            'updated_date' => $now,
        ]);

        $caseId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':case2');
        DB::table('hpbrain_cases')->updateOrInsert(['id' => $caseId2], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId2,
            'title' => 'Resolution of Term 3 Overdue Fee Aging and Parent Support Protocol',
            'description' => 'Five secondary families accumulated Rs 1,85,000 in overdue fees past 60 days. Proactive parent counseling and structured installment agreements required to prevent bad debt.',
            'status' => 'resolved',
            'created_by' => self::AUTHOR,
            'created_date' => '2026-02-02 11:00:00',
            'updated_date' => $now,
        ]);

        $decId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':dec2');
        DB::table('hpbrain_decisions')->updateOrInsert(['id' => $decId2], [
            'tenant_id' => $tenantId,
            'recommendation_id' => null,
            'decided_by' => $principalId,
            'executor_type' => 'human',
            'rationale' => 'Approved flexible two-part deferred installment plan for affected families after fee counseling.',
            'alternatives_considered' => json_encode(['Legal notice (rejected)', 'Withholding student exam access (rejected)']),
            'status' => 'approved',
            'confidence' => 0.88,
            'explanation' => 'Recovered Rs 1,52,000 (82.2%) within 45 days.',
            'approved_by' => $principalId,
            'approved_date' => '2026-02-05 14:00:00',
            'approval_note' => 'Approved with finance committee concurrence.',
            'created_date' => '2026-02-05 14:00:00',
        ]);

        $outId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':out2');
        DB::table('hpbrain_outcomes')->updateOrInsert(['id' => $outId2], [
            'tenant_id' => $tenantId,
            'decision_id' => $decId2,
            'result' => 'Flexible payment restructuring recovered Rs 1,52,000 of Rs 1,85,000 overdue tuition balance (82.2% collection recovery) with zero student dropouts.',
            'metrics' => json_encode(['total_overdue' => 185000.0, 'recovered_amount' => 152000.0, 'recovery_pct' => 82.2]),
            'kpis' => json_encode(['retention_rate' => 100.0]),
            'evidence_ids' => json_encode([]),
            'feedback' => 'Families expressed strong gratitude for compassionate school administration.',
            'confidence' => 0.93,
            'created_by' => self::AUTHOR,
            'created_date' => '2026-03-22 17:00:00',
        ]);

        // =====================================================================
        // WORKFLOW 3: Faculty Capability Assessment -> Upskilling -> Reassessment
        // =====================================================================
        $sigId3 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':sig3');
        DB::table('hpbrain_signals')->updateOrInsert(['id' => $sigId3], [
            'tenant_id' => $tenantId,
            'dedupe_key' => $tenantId . ':sig:cap-diff-inst:2025-2026',
            'org_id' => null,
            'source' => 'capability-analyzer',
            'classification' => 'gap',
            'rule_key' => 'capability.deficit',
            'priority' => 'medium',
            'severity' => 'medium',
            'confidence' => 0.88,
            'related_entity_type' => 'Person',
            'related_entity_id' => (string) ($staffData['users']['Rajesh Kulkarni'] ?? '5'),
            'department_id' => $secDeptId,
            'status' => 'resolved',
            'metadata' => json_encode([
                'title' => 'Development Need Identified in Differentiated Instruction Pedagogical Rubric',
                'capability' => 'ED_DIFFERENTIATED_INSTRUCTION',
                'baseline_level' => 2.4,
                'benchmark_level' => 4.0,
            ]),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-07-28 14:00:00',
            'updated_date' => $now,
        ]);

        $caseId3 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':case3');
        DB::table('hpbrain_cases')->updateOrInsert(['id' => $caseId3], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId3,
            'title' => 'STEM Faculty Pedagogical Differentiated Instruction Professional Development',
            'description' => 'Baseline KASBA evaluation revealed that while teacher subject knowledge is exemplary (4.8), structured differentiated teaching techniques scored 2.4.',
            'status' => 'resolved',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-08-01 10:00:00',
            'updated_date' => $now,
        ]);

        $decId3 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':dec3');
        DB::table('hpbrain_decisions')->updateOrInsert(['id' => $decId3], [
            'tenant_id' => $tenantId,
            'recommendation_id' => null,
            'decided_by' => $principalId,
            'executor_type' => 'human',
            'rationale' => 'Approved targeted peer mentoring and professional development workshop program for secondary STEM teachers.',
            'alternatives_considered' => json_encode(['Self-study reading (rejected)']),
            'status' => 'approved',
            'confidence' => 0.90,
            'explanation' => 'Workshops conducted during October 2025.',
            'approved_by' => $principalId,
            'approved_date' => '2025-08-10 11:00:00',
            'approval_note' => 'Approved for faculty growth.',
            'created_date' => '2025-08-10 11:00:00',
        ]);

        $outId3 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':out3');
        DB::table('hpbrain_outcomes')->updateOrInsert(['id' => $outId3], [
            'tenant_id' => $tenantId,
            'decision_id' => $decId3,
            'result' => 'March 2026 KASBA reassessment confirmed significant capability growth: Differentiated Instruction proficiency improved from 2.4 (Developing) to 4.2 (Mastered).',
            'metrics' => json_encode(['baseline_score' => 2.4, 'reassessed_score' => 4.2, 'growth_points' => 1.8]),
            'kpis' => json_encode(['mastery_status' => 'Achieved']),
            'evidence_ids' => json_encode([]),
            'feedback' => 'Observed classroom differentiation was exemplary.',
            'confidence' => 0.95,
            'created_by' => self::AUTHOR,
            'created_date' => '2026-03-28 16:00:00',
        ]);

        // Risks
        DB::table('hpbrain_risks')->updateOrInsert(
            ['id' => (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':risk1')],
            [
                'tenant_id' => $tenantId,
                'decision_id' => $decId1,
                'recommendation_id' => $recId1,
                'category' => 'academic',
                'probability' => 0.25,
                'impact' => 'medium',
                'score' => 2.5,
                'mitigation' => 'Structured diagnostic milestones at 4-week intervals to prevent learner cognitive fatigue.',
                'status' => 'mitigated',
                'created_by' => self::AUTHOR,
                'created_date' => '2025-10-06 17:00:00',
                'updated_date' => $now,
            ]
        );

        DB::table('hpbrain_risks')->updateOrInsert(
            ['id' => (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':risk2')],
            [
                'tenant_id' => $tenantId,
                'decision_id' => $decId2,
                'recommendation_id' => null,
                'category' => 'financial',
                'probability' => 0.20,
                'impact' => 'high',
                'score' => 3.0,
                'mitigation' => 'Pre-due-date SMS alerts and flexible bi-monthly installment payment plans.',
                'status' => 'mitigated',
                'created_by' => self::AUTHOR,
                'created_date' => '2026-02-05 15:00:00',
                'updated_date' => $now,
            ]
        );
    }
}
