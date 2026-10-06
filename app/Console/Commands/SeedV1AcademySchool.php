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
        {--skip-warm : Skip derived intelligence cache warmers after seeding}
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
        $skipWarm = (bool) $this->option('skip-warm');

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
        $this->ensureV1AcademyCapabilities($tenantId);
        $this->seedTeacherCapabilities($tenantId, $staffData);

        // 8. Generate real evidence-backed intelligence loop
        $this->info('Deriving complete organizational intelligence loop (Signals -> Cases -> Decisions -> ESOs -> Outcomes)...');
        $this->seedIntelligenceLoop($tenantId, $staffData, $counts);

        // 9. Precompute and warm intelligence caches
        if ($skipWarm) {
            $this->warn('Skipping derived intelligence cache warmers (--skip-warm).');
        } else {
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
                    'is_calculated' => 0,
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
            'Examination Controller',
            'Student Counsellor',
            'IT Coordinator',
            'Librarian',
            'Sports Coach',
            'Transport Coordinator',
            'Lab Assistant',
            'Accounts Assistant',
            'Admissions Counsellor',
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
            [
                'first_name' => 'Kavya',
                'last_name' => 'Nair',
                'email' => 'kavya.nair@v1academy.edu',
                'employee_no' => 'EMP-013',
                'job' => 'Class Teacher',
                'profile' => 'Teacher',
                'dept' => 'Primary Section (Grades 1-5)',
                'gender' => 'Female',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Amit',
                'last_name' => 'Deshpande',
                'email' => 'amit.deshpande@v1academy.edu',
                'employee_no' => 'EMP-014',
                'job' => 'Class Teacher',
                'profile' => 'Teacher',
                'dept' => 'Middle School (Grades 6-8)',
                'gender' => 'Male',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Pooja',
                'last_name' => 'Raman',
                'email' => 'pooja.raman@v1academy.edu',
                'employee_no' => 'EMP-015',
                'job' => 'Subject Teacher',
                'profile' => 'Teacher',
                'dept' => 'Languages & Humanities Department',
                'gender' => 'Female',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Nikhil',
                'last_name' => 'Bhat',
                'email' => 'nikhil.bhat@v1academy.edu',
                'employee_no' => 'EMP-016',
                'job' => 'Subject Teacher',
                'profile' => 'Teacher',
                'dept' => 'Science & Mathematics Department',
                'gender' => 'Male',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Farah',
                'last_name' => 'Qureshi',
                'email' => 'farah.qureshi@v1academy.edu',
                'employee_no' => 'EMP-017',
                'job' => 'Examination Controller',
                'profile' => 'Teacher',
                'dept' => 'Administration & Operations',
                'gender' => 'Female',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Suresh',
                'last_name' => 'Naidu',
                'email' => 'suresh.naidu@v1academy.edu',
                'employee_no' => 'EMP-018',
                'job' => 'IT Coordinator',
                'profile' => 'Employee',
                'dept' => 'Administration & Operations',
                'gender' => 'Male',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Priyanka',
                'last_name' => 'Joshi',
                'email' => 'priyanka.joshi@v1academy.edu',
                'employee_no' => 'EMP-019',
                'job' => 'Student Counsellor',
                'profile' => 'Employee',
                'dept' => 'Administration & Operations',
                'gender' => 'Female',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Irfan',
                'last_name' => 'Shaikh',
                'email' => 'irfan.shaikh@v1academy.edu',
                'employee_no' => 'EMP-020',
                'job' => 'Transport Coordinator',
                'profile' => 'Employee',
                'dept' => 'Administration & Operations',
                'gender' => 'Male',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Neha',
                'last_name' => 'Agarwal',
                'email' => 'neha.agarwal@v1academy.edu',
                'employee_no' => 'EMP-021',
                'job' => 'Accounts Assistant',
                'profile' => 'Finance',
                'dept' => 'Finance & Accounts',
                'gender' => 'Female',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Mohan',
                'last_name' => 'Reddy',
                'email' => 'mohan.reddy@v1academy.edu',
                'employee_no' => 'EMP-022',
                'job' => 'Sports Coach',
                'profile' => 'Teacher',
                'dept' => 'Secondary Section (Grades 9-10)',
                'gender' => 'Male',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Sunita',
                'last_name' => 'Mishra',
                'email' => 'sunita.mishra@v1academy.edu',
                'employee_no' => 'EMP-023',
                'job' => 'Librarian',
                'profile' => 'Employee',
                'dept' => 'Languages & Humanities Department',
                'gender' => 'Female',
                'is_admin' => 0,
            ],
            [
                'first_name' => 'Rahul',
                'last_name' => 'Shetty',
                'email' => 'rahul.shetty@v1academy.edu',
                'employee_no' => 'EMP-024',
                'job' => 'Lab Assistant',
                'profile' => 'Employee',
                'dept' => 'Higher Secondary Section (Grades 11-12)',
                'gender' => 'Male',
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

        // 5. Transport CSV
        $transportFile = $dir . '/v1a_transport.csv';
        $fp = fopen($transportFile, 'w');
        fputcsv($fp, ['student_ref', 'student_name', 'standard', 'division', 'transport_mode', 'route_id']);
        foreach ($students as $stu) {
            if ($stu['transport_mode'] === 'school_bus') {
                fputcsv($fp, [
                    $stu['ref'],
                    $stu['name'],
                    $stu['standard'],
                    $stu['division'],
                    $stu['transport_mode'],
                    $stu['route_id']
                ]);
            }
        }
        fclose($fp);

        // 6. Hostel CSV
        $hostelFile = $dir . '/v1a_hostel.csv';
        $fp = fopen($hostelFile, 'w');
        fputcsv($fp, ['student_ref', 'student_name', 'standard', 'division', 'hostel_name', 'room_no']);
        foreach ($students as $stu) {
            if ($stu['transport_mode'] === 'hostel') {
                fputcsv($fp, [
                    $stu['ref'],
                    $stu['name'],
                    $stu['standard'],
                    $stu['division'],
                    $stu['hostel_name'],
                    $stu['room_no']
                ]);
            }
        }
        fclose($fp);

        // 8. Sports CSV
        $sportsFile = $dir . '/v1a_sports.csv';
        $fp = fopen($sportsFile, 'w');
        fputcsv($fp, ['student_ref', 'student_name', 'standard', 'division', 'sport', 'event_name', 'participation', 'skill_assessment']);
        $sports = ['Basketball', 'Football', 'Cricket', 'Athletics', 'Table Tennis'];
        $i = 0;
        foreach ($students as $stu) {
            $sport = $sports[$i % count($sports)];
            $participates = ($i % 5 !== 0); // 80% participation
            fputcsv($fp, [
                $stu['ref'],
                $stu['name'],
                $stu['standard'],
                $stu['division'],
                $sport,
                'Annual Sports Meet 2025',
                $participates ? 'Participated' : 'Did Not Participate',
                $participates ? (['Beginner', 'Intermediate', 'Advanced'][$i % 3]) : 'N/A'
            ]);
            $i++;
        }
        fclose($fp);

        // 9. GK CSV
        $gkFile = $dir . '/v1a_gk.csv';
        $fp = fopen($gkFile, 'w');
        fputcsv($fp, ['student_ref', 'student_name', 'standard', 'division', 'quiz_topic', 'score', 'max_score']);
        $topics = ['Indian History', 'Geography', 'Science & Environment', 'Current Affairs'];
        $i = 0;
        foreach ($students as $stu) {
            foreach ($topics as $topic) {
                // Generate a deterministic score between 40 and 100
                $score = 40 + (($i * strlen($topic)) % 61);
                fputcsv($fp, [
                    $stu['ref'],
                    $stu['name'],
                    $stu['standard'],
                    $stu['division'],
                    $topic,
                    $score,
                    100
                ]);
            }
            $i++;
        }
        fclose($fp);

        // 10. Manifest metadata
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
            'transport' => $transportFile,
            'hostel' => $hostelFile,
            'sports' => $sportsFile,
            'gk' => $gkFile,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateStudentsList(): array
    {
        $standards = [
            ['std' => 'Nursery', 'roman' => 'Nursery', 'count' => 60, 'dept' => 'Primary Section (Grades 1-5)', 'teacher' => 'Clara Higgins'],
            ['std' => 'KG', 'roman' => 'KG', 'count' => 60, 'dept' => 'Primary Section (Grades 1-5)', 'teacher' => 'Clara Higgins'],
            ['std' => 'CBSE-1', 'roman' => 'I', 'count' => 60, 'dept' => 'Primary Section (Grades 1-5)', 'teacher' => 'Clara Higgins'],
            ['std' => 'CBSE-2', 'roman' => 'II', 'count' => 60, 'dept' => 'Primary Section (Grades 1-5)', 'teacher' => 'Clara Higgins'],
            ['std' => 'CBSE-3', 'roman' => 'III', 'count' => 60, 'dept' => 'Primary Section (Grades 1-5)', 'teacher' => 'Clara Higgins'],
            ['std' => 'CBSE-4', 'roman' => 'IV', 'count' => 60, 'dept' => 'Primary Section (Grades 1-5)', 'teacher' => 'Clara Higgins'],
            ['std' => 'CBSE-5', 'roman' => 'V', 'count' => 60, 'dept' => 'Primary Section (Grades 1-5)', 'teacher' => 'Clara Higgins'],
            ['std' => 'CBSE-6', 'roman' => 'VI', 'count' => 60, 'dept' => 'Middle School (Grades 6-8)', 'teacher' => 'Arthur Pendelton'],
            ['std' => 'CBSE-7', 'roman' => 'VII', 'count' => 60, 'dept' => 'Middle School (Grades 6-8)', 'teacher' => 'Arthur Pendelton'],
            ['std' => 'CBSE-8', 'roman' => 'VIII', 'count' => 60, 'dept' => 'Middle School (Grades 6-8)', 'teacher' => 'Arthur Pendelton'],
            ['std' => 'CBSE-9', 'roman' => 'IX', 'count' => 60, 'dept' => 'Secondary Section (Grades 9-10)', 'teacher' => 'Rajesh Kulkarni'],
            ['std' => 'CBSE-10', 'roman' => 'X', 'count' => 60, 'dept' => 'Secondary Section (Grades 9-10)', 'teacher' => 'Rajesh Kulkarni'],
            ['std' => 'CBSE-11', 'roman' => 'XI', 'count' => 60, 'dept' => 'Higher Secondary Section (Grades 11-12)', 'teacher' => 'Anita Sharma'],
            ['std' => 'CBSE-12', 'roman' => 'XII', 'count' => 60, 'dept' => 'Higher Secondary Section (Grades 11-12)', 'teacher' => 'Anita Sharma'],
        ];

        $firstNames = ['Aarav', 'Vivaan', 'Aditya', 'Vihaan', 'Arjun', 'Sai', 'Reyansh', 'Ayaan', 'Krishna', 'Ishaan', 'Shaurya', 'Atharv', 'Advik', 'Pranav', 'Advaith', 'Ananya', 'Diya', 'Gauri', 'Aadhya', 'Pari', 'Anvi', 'Saanvi', 'Myra', 'Sara', 'Ira', 'Avani', 'Riya', 'Kavya', 'Meera', 'Roshni', 'Neha', 'Pooja', 'Rahul', 'Rohan', 'Amit', 'Sunil', 'Karan', 'Vikram', 'Suresh', 'Ramesh', 'Ravi', 'Ritu', 'Geeta', 'Seema', 'Sita', 'Anita', 'Sunita', 'Asha', 'Usha', 'Lata'];
        $lastNames = ['Sharma', 'Verma', 'Patel', 'Reddy', 'Nair', 'Iyer', 'Gupta', 'Singh', 'Deshmukh', 'Kulkarni', 'Joshi', 'Bhat', 'Rao', 'Choudhury', 'Mehta', 'Shah', 'Mukherjee', 'Banerjee', 'Ghosh', 'Chatterjee', 'Nath', 'Sen', 'Das', 'Bose', 'Mitra', 'Dutta', 'Chopra', 'Malhotra', 'Kapoor', 'Khanna', 'Bhatia', 'Ahuja', 'Chauhan', 'Rajput', 'Yadav', 'Mishra', 'Tiwari', 'Pandey', 'Dubey', 'Ojha'];

        $list = [];
        $seq = 1;

        foreach ($standards as $s) {
            for ($i = 0; $i < $s['count']; $i++) {
                $ref = sprintf('V1A-2025-%04d', $seq);
                $nameSpace = count($firstNames) * count($lastNames);
                $pairIndex = (($seq - 1) * 37) % $nameSpace;
                $fn = $firstNames[$pairIndex % count($firstNames)];
                $ln = $lastNames[intdiv($pairIndex, count($firstNames)) % count($lastNames)];
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

                // Transport vs Hostel logic (Hostel: 20%, School Bus: 60%, Own Transport: 20%)
                $transportMode = 'own_transport';
                $routeId = null;
                $hostelName = null;
                $roomNo = null;

                if ($seq % 5 === 0) {
                    $transportMode = 'hostel';
                    $hostelName = ($seq % 2 === 0) ? 'Boys Hostel A' : 'Girls Hostel B';
                    $roomNo = 'Room-' . (100 + ($seq % 50));
                } elseif ($seq % 5 !== 4) { // 60%
                    $transportMode = 'school_bus';
                    $routeId = 'Route-' . (($seq % 10) + 1);
                }

                $list[] = [
                    'ref' => $ref,
                    'name' => $name,
                    'standard' => $s['std'],
                    'roman' => $s['roman'],
                    // Ensure exactly 30 per section
                    'division' => ($i < 30) ? 'A' : 'B',
                    'department' => $s['dept'],
                    'class_teacher' => $s['teacher'],
                    'scholarship_pct' => $schPct,
                    'scholarship_name' => $schName,
                    'fee_behavior' => $behavior,
                    'ability' => $ability,
                    'attendance_base' => $attBase,
                    'transport_mode' => $transportMode,
                    'route_id' => $routeId,
                    'hostel_name' => $hostelName,
                    'room_no' => $roomNo,
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
        $academicJobId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':import-job:v1a-academic-results');
        DB::table('hpbrain_import_jobs')->updateOrInsert(
            ['id' => $academicJobId],
            [
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
            ]
        );

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
        $feeJobId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':import-job:school_fee');
        DB::table('hpbrain_import_jobs')->updateOrInsert(
            ['id' => $feeJobId],
            [
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
            ]
        );

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

        // 6. Ingest Transport (dataset: 'v1a-transport')
        $this->line('  -> Ingesting transport assignment records...');
        $handle = fopen($files['transport'], 'r');
        $headers = fgetcsv($handle);
        $transportBuffer = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            $naturalKey = 'TRN-' . $data['student_ref'];
            $recordId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':v1a-transport:' . $naturalKey);

            $transportBuffer[] = [
                'id' => $recordId,
                'tenant_id' => $tenantId,
                'org_id' => null,
                'dataset' => 'v1a-transport',
                'natural_key' => $naturalKey,
                'source_file' => basename($files['transport']),
                'source_row' => count($transportBuffer) + 1,
                'occurred_at' => $now,
                'closed_at' => null,
                'status' => 'Active',
                'category' => 'Transport Allocation',
                'sub_category' => $data['transport_mode'],
                'owner_name' => 'Transport Department',
                'department_label' => 'Transport',
                'area' => 'Transport',
                'subject_ref' => $data['student_ref'],
                'metric_value' => 1,
                'metric_unit' => 'allocation',
                'quantity' => 1,
                'payload' => json_encode(['route_id' => $data['route_id'], 'standard' => $data['standard']], JSON_UNESCAPED_UNICODE),
                'row_hash' => hash('sha256', $naturalKey),
                'import_job_id' => $academicJobId, // Using academic job id for simplicity in seeder
                'created_date' => $now,
                'updated_date' => $now,
            ];

            if (count($transportBuffer) >= 500) {
                DB::table('hpbrain_operational_records')->upsert($transportBuffer, ['tenant_id', 'dataset', 'natural_key']);
                $transportBuffer = [];
            }
        }
        if ($transportBuffer !== []) {
            DB::table('hpbrain_operational_records')->upsert($transportBuffer, ['tenant_id', 'dataset', 'natural_key']);
        }
        fclose($handle);

        // 7. Ingest Hostel (dataset: 'v1a-hostel')
        $this->line('  -> Ingesting hostel allocation records...');
        $handle = fopen($files['hostel'], 'r');
        $headers = fgetcsv($handle);
        $hostelBuffer = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            $naturalKey = 'HST-' . $data['student_ref'];
            $recordId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':v1a-hostel:' . $naturalKey);

            $hostelBuffer[] = [
                'id' => $recordId,
                'tenant_id' => $tenantId,
                'org_id' => null,
                'dataset' => 'v1a-hostel',
                'natural_key' => $naturalKey,
                'source_file' => basename($files['hostel']),
                'source_row' => count($hostelBuffer) + 1,
                'occurred_at' => $now,
                'closed_at' => null,
                'status' => 'Active',
                'category' => 'Hostel Allocation',
                'sub_category' => $data['hostel_name'],
                'owner_name' => 'Hostel Department',
                'department_label' => 'Hostel',
                'area' => 'Residential',
                'subject_ref' => $data['student_ref'],
                'metric_value' => 1,
                'metric_unit' => 'allocation',
                'quantity' => 1,
                'payload' => json_encode(['room_no' => $data['room_no'], 'standard' => $data['standard']], JSON_UNESCAPED_UNICODE),
                'row_hash' => hash('sha256', $naturalKey),
                'import_job_id' => $academicJobId,
                'created_date' => $now,
                'updated_date' => $now,
            ];

            if (count($hostelBuffer) >= 500) {
                DB::table('hpbrain_operational_records')->upsert($hostelBuffer, ['tenant_id', 'dataset', 'natural_key']);
                $hostelBuffer = [];
            }
        }
        if ($hostelBuffer !== []) {
            DB::table('hpbrain_operational_records')->upsert($hostelBuffer, ['tenant_id', 'dataset', 'natural_key']);
        }
        fclose($handle);

        // 8. Ingest Sports (dataset: 'v1a-sports')
        $this->line('  -> Ingesting sports participation records...');
        $handle = fopen($files['sports'], 'r');
        $headers = fgetcsv($handle);
        $sportsBuffer = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            $naturalKey = 'SPR-' . $data['student_ref'];
            $recordId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':v1a-sports:' . $naturalKey);

            $sportsBuffer[] = [
                'id' => $recordId,
                'tenant_id' => $tenantId,
                'org_id' => null,
                'dataset' => 'v1a-sports',
                'natural_key' => $naturalKey,
                'source_file' => basename($files['sports']),
                'source_row' => count($sportsBuffer) + 1,
                'occurred_at' => $now,
                'closed_at' => null,
                'status' => $data['participation'],
                'category' => 'Sports',
                'sub_category' => $data['sport'],
                'owner_name' => 'Sports Department',
                'department_label' => 'Extracurricular',
                'area' => 'Campus',
                'subject_ref' => $data['student_ref'],
                'metric_value' => $data['participation'] === 'Participated' ? 1 : 0,
                'metric_unit' => 'participation',
                'quantity' => 1,
                'payload' => json_encode(['skill_assessment' => $data['skill_assessment'], 'standard' => $data['standard']], JSON_UNESCAPED_UNICODE),
                'row_hash' => hash('sha256', $naturalKey),
                'import_job_id' => $academicJobId,
                'created_date' => $now,
                'updated_date' => $now,
            ];

            if (count($sportsBuffer) >= 500) {
                DB::table('hpbrain_operational_records')->upsert($sportsBuffer, ['tenant_id', 'dataset', 'natural_key']);
                $sportsBuffer = [];
            }
        }
        if ($sportsBuffer !== []) {
            DB::table('hpbrain_operational_records')->upsert($sportsBuffer, ['tenant_id', 'dataset', 'natural_key']);
        }
        fclose($handle);

        // 9. Ingest GK (dataset: 'v1a-gk')
        $this->line('  -> Ingesting general knowledge records...');
        $handle = fopen($files['gk'], 'r');
        $headers = fgetcsv($handle);
        $gkBuffer = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            $naturalKey = 'GK-' . $data['quiz_topic'] . '-' . $data['student_ref'];
            $recordId = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':v1a-gk:' . $naturalKey);

            $gkBuffer[] = [
                'id' => $recordId,
                'tenant_id' => $tenantId,
                'org_id' => null,
                'dataset' => 'v1a-gk',
                'natural_key' => $naturalKey,
                'source_file' => basename($files['gk']),
                'source_row' => count($gkBuffer) + 1,
                'occurred_at' => $now,
                'closed_at' => null,
                'status' => 'Completed',
                'category' => 'General Knowledge',
                'sub_category' => $data['quiz_topic'],
                'owner_name' => 'Academics',
                'department_label' => 'Extracurricular',
                'area' => 'Online',
                'subject_ref' => $data['student_ref'],
                'metric_value' => $data['score'],
                'metric_unit' => 'marks',
                'quantity' => $data['max_score'],
                'payload' => json_encode(['standard' => $data['standard'], 'quiz_topic' => $data['quiz_topic']], JSON_UNESCAPED_UNICODE),
                'row_hash' => hash('sha256', $naturalKey),
                'import_job_id' => $academicJobId,
                'created_date' => $now,
                'updated_date' => $now,
            ];

            if (count($gkBuffer) >= 500) {
                DB::table('hpbrain_operational_records')->upsert($gkBuffer, ['tenant_id', 'dataset', 'natural_key']);
                $gkBuffer = [];
            }
        }
        if ($gkBuffer !== []) {
            DB::table('hpbrain_operational_records')->upsert($gkBuffer, ['tenant_id', 'dataset', 'natural_key']);
        }
        fclose($handle);

        $counts['students'] = 840;

        return $counts;
    }


    private function ensureV1AcademyCapabilities(string $tenantId): void
    {
        $now = now()->format('Y-m-d H:i:s');
        // ERP-backed tenants have no hpbrain_organizations row: the organization
        // id IS the tenant id, and the Capabilities screen filters on it.
        $orgId = (string) (DB::table('hpbrain_organizations')
            ->where('tenant_id', $tenantId)
            ->value('id') ?? $tenantId);

        // The K-12 pack provisions its capabilities with a NULL org_id, which
        // that filter excludes; claim them for this organization.
        DB::table('hpbrain_capabilities')
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereNull('org_id')->orWhere('org_id', ''))
            ->update(['org_id' => $orgId]);

        $capabilities = [
            'ED_EXAMINATION_MANAGEMENT' => ['Examination Management', 'Exam scheduling, invigilation, evaluation coordination, and result controls.', 'operations'],
            'ED_SCHOOL_ADMINISTRATION' => ['School Administration', 'Admissions, transport, safety, compliance, records, and daily operations.', 'operations'],
        ];

        foreach ($capabilities as $code => [$name, $description, $category]) {
            $existingId = DB::table('hpbrain_capabilities')
                ->where('tenant_id', $tenantId)
                ->where('capability_code', $code)
                ->value('id');

            $payload = [
                'org_id' => $orgId,
                'name' => $name,
                'description' => $description,
                'category' => $category,
                'capability_type' => 'competency',
                'difficulty' => 'intermediate',
                'criticality' => in_array($code, ['ED_TEACHING_INSTRUCTION', 'ED_ASSESSMENT_DESIGN', 'ED_EXAMINATION_MANAGEMENT'], true) ? 'high' : 'medium',
                'version' => 1,
                'status' => 'active',
                'created_by' => self::AUTHOR,
                'created_date' => $now,
                'updated_date' => $now,
                'knowledge' => json_encode(['rubric' => 'Knows the school process and relevant CBSE/K-12 practices.']),
                'ability' => json_encode(['rubric' => 'Can execute the process consistently in daily school operations.']),
                'skill' => json_encode(['rubric' => 'Applies techniques effectively with students, parents, or staff.']),
                'behaviour' => json_encode(['rubric' => 'Demonstrates reliability, care, and professional judgment.']),
                'attitude' => json_encode(['rubric' => 'Shows growth mindset, collaboration, and student-first orientation.']),
            ];

            if ($existingId === null) {
                $payload['id'] = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':capability:' . $code);
                $payload['tenant_id'] = $tenantId;
                $payload['capability_code'] = $code;

                DB::table('hpbrain_capabilities')->insert($payload);
            } else {
                DB::table('hpbrain_capabilities')
                    ->where('id', $existingId)
                    ->where('tenant_id', $tenantId)
                    ->update($payload);
            }
        }

        $retiredSupplemental = [
            'ED_TEACHING_INSTRUCTION',
            'ED_CURRICULUM_PLANNING',
            'ED_STUDENT_MENTORING',
            'ED_ACADEMIC_PLANNING',
            'ED_TECHNOLOGY',
        ];

        $retiredIds = DB::table('hpbrain_capabilities')
            ->where('tenant_id', $tenantId)
            ->whereIn('capability_code', $retiredSupplemental)
            ->pluck('id')
            ->all();

        if ($retiredIds !== []) {
            DB::table('hpbrain_capabilities')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $retiredIds)
                ->update([
                    'status' => 'inactive',
                    'updated_date' => $now,
                ]);

            DB::table('hpbrain_capability_assignments')
                ->where('tenant_id', $tenantId)
                ->whereIn('capability_id', $retiredIds)
                ->update(['status' => 'inactive']);
        }
    }

    /**
     * @param array<string, mixed> $staffData
     */
    private function seedTeacherCapabilities(string $tenantId, array $staffData): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $capabilities = DB::table('hpbrain_capabilities')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get();

        if ($capabilities->isEmpty()) {
            return;
        }

        $teacherProfileIds = DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenantId)
            ->whereIn('name', ['Teacher', 'Principal', 'Admin', 'Finance', 'Employee'])
            ->pluck('id')
            ->all();

        $teachers = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->when($teacherProfileIds !== [], fn ($query) => $query->whereIn('user_profile_id', $teacherProfileIds))
            ->get(['id', 'first_name', 'last_name']);

        foreach ($teachers as $teacher) {
            $userId = (int) $teacher->id;
            $name = trim((string) $teacher->first_name . ' ' . (string) $teacher->last_name);

            if ($userId === 0 || $name === '') {
                continue;
            }

            foreach ($capabilities as $cap) {
              try {
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
                        'evidence_ref' => 'BASELINE-JUL2025-T1',
                        'state_source' => 'manual',
                        'state_changed_date' => '2025-07-25 15:00:00',
                        'state_change_reason' => 'Classroom Observation & Lesson Plan Portfolio Review (Term 1 Baseline). Academic Term 1 baseline pedagogical assessment.',
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
                        'evidence_ref' => 'REVIEW-MAR2026-YEND',
                        'state_source' => 'manual',
                        'state_changed_date' => '2026-03-28 14:00:00',
                        'state_change_reason' => 'Year-end Performance Review and Student Growth Data Portfolio. Successful completion of Differentiated Instruction Development Plan and measured pupil gains.',
                        'evidence_confidence' => 0.94,
                        'assessed_by' => 'Dr. Victor Sterling (Principal)',
                        'assessed_date' => '2026-03-28 14:00:00',
                        'created_date' => '2026-03-28 14:00:00',
                    ]
                );
              } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                  $this->warn("  Already recorded: {$name} / {$cap->capability_code} (skipping, idempotent re-run).");
              }
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
                'title' => 'Grade 9 Mathematics marks are too low in the mid-term exams',
                'cohort' => 'CBSE-9',
                'subject' => 'Mathematics',
                'cohort_avg_pct' => 54.2,
                'school_avg_pct' => 71.8,
                'gap_points' => 17.6,
                'affected_students' => 15,
                'at_risk_students' => 6,
            ]),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-05 14:00:00',
            'updated_date' => $now,
        ]);

        $evId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':ev1');
        DB::table('hpbrain_evidence')->updateOrInsert(['id' => $evId1], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId1,
            'source' => 'v1a-academic-results',
            'evidence_type' => 'assessment_records',
            'content' => json_encode([
                'summary' => 'Our check of exam papers from Unit Test 1 and the Mid-Term Exam shows 15 students in Grade 9 getting an average of 54.2% in Maths, while the rest of the school averages 71.8%. They are struggling the most with basic algebra and coordinate geometry.',
                'records_examined' => 120,
                'target_grade' => 'CBSE-9',
                'subject' => 'Mathematics',
            ], JSON_UNESCAPED_UNICODE),
            'provenance' => json_encode([
                'dataset' => 'v1a-academic-results',
                'query' => "SELECT AVG(metric_value/quantity*100) FROM hpbrain_operational_records WHERE status='CBSE-9' AND category='Mathematics'",
                'rows_analyzed' => 120,
            ]),
            'confidence' => 0.95,
            'hash' => hash('sha256', $tenantId . '-grade-9-math-gap-evidence'),
            'version' => '1.0',
            'status' => 'active',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-05 14:15:00',
            'observed_date' => '2025-10-05 00:00:00',
        ]);

        $caseId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':case1');
        DB::table('hpbrain_cases')->updateOrInsert(['id' => $caseId1], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId1,
            'title' => 'Grade 9 Mathematics low scores and basic gaps',
            'description' => "Grade 9 students have scored low in their Mathematics mid-term exams, falling short of the school's target by 17.6 marks on average. This is worrying because without clear basics in algebra, they will struggle heavily next year during their standard 10 board exams.\n\nSupporting Records: Exam records of 15 students.",
            'status' => 'resolved',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-06 10:00:00',
            'updated_date' => $now,
        ]);

        DB::table('hpbrain_case_evidence')->updateOrInsert(
            ['tenant_id' => $tenantId, 'case_id' => $caseId1, 'evidence_id' => $evId1],
            ['linked_date' => '2025-10-06 10:05:00']
        );

        $hypId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':hyp1');
        DB::table('hpbrain_hypotheses')->updateOrInsert(['id' => $hypId1], [
            'tenant_id' => $tenantId,
            'case_id' => $caseId1,
            'statement' => 'Students missed key basics in linear equations and geometry when they moved up from middle school, slowing down their ability to solve secondary-level problems.',
            'root_cause_family' => 'pedagogical_alignment',
            'confidence' => 0.89,
            'status' => 'confirmed',
            'supporting_evidence_ids' => json_encode([$evId1]),
            'proposed_by' => 'Dr. Rajesh Kulkarni',
            'created_date' => '2025-10-08 09:30:00',
        ]);
        DB::table('hpbrain_cases')->where('id', $caseId1)->update(['resolved_hypothesis_id' => $hypId1]);

        $stepId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':step1');
        DB::table('hpbrain_reasoning_steps')->updateOrInsert(['id' => $stepId1], [
            'tenant_id' => $tenantId,
            'case_id' => $caseId1,
            'signal_id' => $sigId1,
            'step_order' => 1,
            'description' => 'Running focused extra classes for basic algebra before teaching the tough quadratic chapters will help them catch up.',
            'confidence_score' => 0.91,
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-09 14:00:00',
        ]);

        $esoDefId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':esodef1');
        DB::table('hpbrain_eso_definitions')->updateOrInsert(['id' => $esoDefId1], [
            'tenant_id' => $tenantId,
            'org_id' => null,
            'eso_code' => 'ESO_ACAD_REMEDIAL',
            'name' => 'Extra Mathematics coaching for students falling behind',
            'version' => '1.0',
            'status' => 'active',
            'owner' => $secDeptId,
            'provenance' => 'Academic Council Standards',
            'trigger_description' => 'Help students catch up by practicing in small groups twice a week.',
            'objective' => 'Remedial Math Academic Intervention',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-12 09:00:00',
            'updated_date' => $now,
        ]);

        $recId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':rec1');
        DB::table('hpbrain_recommendations')->updateOrInsert(['id' => $recId1], [
            'tenant_id' => $tenantId,
            'reasoning_step_id' => $stepId1,
            'category' => 'improve',
            'title' => 'Start a 12-week extra coaching plan for Grade 9 Mathematics',
            'description' => 'Hold extra tutorial classes twice a week after school. Let Dr. Rajesh Kulkarni lead these, focusing on practice papers and personal attention to clear basic doubts.',
            'priority' => 'high',
            'urgency' => 'urgent',
            'confidence' => 0.92,
            'impact' => 'Restores average pass rate to over 70% before the final exams.',
            'cost' => 'Zero external cost (reallocated internal tutorial periods)',
            'risk' => 'low',
            'dependencies' => json_encode([]),
            'status' => 'accepted',
            'eso_id' => $esoDefId1,
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-12 11:30:00',
            'updated_date' => $now,
        ]);

        $decId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':dec1');
        DB::table('hpbrain_decisions')->updateOrInsert(['id' => $decId1], [
            'tenant_id' => $tenantId,
            'recommendation_id' => $recId1,
            'decided_by' => $principalId,
            'executor_type' => 'human',
            'rationale' => 'Agreed. Small-group teaching twice a week is the best way to help them without putting too much pressure.',
            'alternatives_considered' => json_encode(['Sending them to external tuitions (rejected)', 'Just giving more homework (rejected)']),
            'status' => 'approved',
            'confidence' => 0.94,
            'explanation' => 'Coaching starts on 15th October 2025.',
            'approved_by' => $principalId,
            'approved_date' => '2025-10-12 11:30:00',
            'approval_note' => 'Approved by all teachers in the Academic Council.',
            'created_date' => '2025-10-12 11:30:00',
        ]);

        $planId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':plan1');
        DB::table('hpbrain_measurement_plans')->updateOrInsert(['id' => $planId1], [
            'tenant_id' => $tenantId,
            'decision_id' => $decId1,
            'baseline_metric' => 'grade9_math_midterm_pct',
            'baseline_value' => 54.20,
            'target_value' => 70.00,
            'metric_unit' => 'percentage',
            'measurement_window_days' => 120,
            'owner_id' => $principalId,
            'created_by' => self::AUTHOR,
            'created_date' => '2025-10-13 09:00:00',
        ]);

        $esoExecId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':esoexec1');
        DB::table('hpbrain_eso_executions')->updateOrInsert(['id' => $esoExecId1], [
            'tenant_id' => $tenantId,
            'eso_id' => $esoDefId1,
            'eso_definition_id' => $esoDefId1,
            'decision_id' => $decId1,
            'status' => 'completed',
            'executed_by' => $principalId,
            'executor_type' => 'human',
            'input' => json_encode(['cohort' => 'CBSE-9', 'subject' => 'Mathematics', 'clinic_sessions' => 24, 'faculty_lead' => 'Rajesh Kulkarni']),
            'output' => json_encode(['sessions_delivered' => 24, 'students_participated' => 15, 'completion_rate' => 1.0]),
            'started_date' => '2025-10-15 08:00:00',
            'completed_date' => '2026-03-26 17:00:00',
            'created_date' => '2025-10-15 08:00:00',
        ]);

        $outId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':out1');
        DB::table('hpbrain_outcomes')->updateOrInsert(['id' => $outId1], [
            'tenant_id' => $tenantId,
            'decision_id' => $decId1,
            'result' => 'Remedial intervention achieved dramatic academic recovery: Grade 9 Mathematics average rose to 66.4% in Unit Test 2 and reached 71.8% in the March 2026 Final Examination, exceeding the 70.0% target.',
            'metrics' => json_encode([
                'baseline_pct' => 54.20,
                'ut2_pct' => 66.40,
                'final_exam_pct' => 71.80,
                'net_gain_points' => 17.60,
                'target_achieved' => true,
            ]),
            'kpis' => json_encode(['cohort_pass_rate' => 100.0, 'at_risk_students_remaining' => 0]),
            'evidence_ids' => json_encode([$evId1]),
            'feedback' => 'Students demonstrated marked improvement in algebraic problem confidence.',
            'confidence' => 0.96,
            'created_by' => self::AUTHOR,
            'created_date' => '2026-04-01 15:00:00',
        ]);

        $learnId1 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':learn1');
        DB::table('hpbrain_learnings')->updateOrInsert(['id' => $learnId1], [
            'tenant_id' => $tenantId,
            'outcome_id' => $outId1,
            'mental_model_id' => null,
            'pattern' => 'Early diagnostic modular remediation in secondary mathematics',
            'description' => 'Targeted 12-week remedial intervention immediately following mid-term diagnostics recovers over 17 percentage points in secondary cohorts with zero student drop-off.',
            'domain' => 'Academics & Pedagogy',
            'confidence' => 0.94,
            'reusable' => 1,
            'created_by' => self::AUTHOR,
            'created_date' => '2026-04-03 11:00:00',
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
                'title' => 'Concentration of Overdue Term 3 Tuition Fees',
                'overdue_amount' => 270000.0,
                'affected_families' => 9,
                'days_past_due' => 75,
            ]),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-12-28 10:00:00',
            'updated_date' => $now,
        ]);

        $caseId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':case2');
        DB::table('hpbrain_cases')->updateOrInsert(['id' => $caseId2], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId2,
            'title' => 'Term 3 Parental Financial Relief & Structured Fee Restructuring',
            'description' => 'Nine student accounts accumulated Rs 2,70,000 in overdue Term 3 tuition past 45 days. Proactive parent counseling and structured installment agreements required to prevent bad debt.',
            'status' => 'resolved',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-12-29 11:00:00',
            'updated_date' => $now,
        ]);

        $decId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':dec2');
        DB::table('hpbrain_decisions')->updateOrInsert(['id' => $decId2], [
            'tenant_id' => $tenantId,
            'recommendation_id' => null,
            'decided_by' => $principalId,
            'executor_type' => 'human',
            'rationale' => 'Providing bi-weekly micro-payment plans preserves enrollment retention while ensuring cashflow recovery.',
            'alternatives_considered' => json_encode(['Legal notice (rejected)', 'Withholding student exam access (rejected)']),
            'status' => 'approved',
            'confidence' => 0.88,
            'explanation' => 'Recovered Rs 2,43,000 (90.0%) within 52 days.',
            'approved_by' => $principalId,
            'approved_date' => '2026-01-04 14:00:00',
            'approval_note' => 'Approved with finance committee concurrence.',
            'created_date' => '2026-01-04 14:00:00',
        ]);

        $outId2 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':out2');
        DB::table('hpbrain_outcomes')->updateOrInsert(['id' => $outId2], [
            'tenant_id' => $tenantId,
            'decision_id' => $decId2,
            'result' => 'Flexible payment restructuring recovered Rs 2,43,000 of Rs 2,70,000 overdue tuition balance (90.0% collection recovery) with zero student dropouts.',
            'metrics' => json_encode(['total_overdue' => 270000.0, 'recovered_amount' => 243000.0, 'recovery_pct' => 90.0]),
            'kpis' => json_encode(['retention_rate' => 100.0]),
            'evidence_ids' => json_encode([]),
            'feedback' => 'Families expressed strong gratitude for compassionate school administration.',
            'confidence' => 0.93,
            'created_by' => self::AUTHOR,
            'created_date' => '2026-02-28 17:30:00',
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
            'description' => 'Baseline KASBA evaluation revealed that while teacher subject knowledge is strong, structured differentiated teaching techniques scored 2.4.',
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
            'rationale' => 'Structured peer mentoring accelerates teacher pedagogical agility faster than passive workshops.',
            'alternatives_considered' => json_encode(['Self-study reading (rejected)']),
            'status' => 'approved',
            'confidence' => 0.90,
            'explanation' => 'Workshops conducted during October 2025.',
            'approved_by' => $principalId,
            'approved_date' => '2025-08-04 11:00:00',
            'approval_note' => 'Approved for faculty growth.',
            'created_date' => '2025-08-04 11:00:00',
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
                'created_date' => '2025-10-12 17:00:00',
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
                'created_date' => '2026-01-04 15:00:00',
                'updated_date' => $now,
            ]
        );
        // =====================================================================
        // WORKFLOW 4: Sports + Participation -> Coaching -> Engagement
        // =====================================================================
        $sigId4 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':sig4');
        DB::table('hpbrain_signals')->updateOrInsert(['id' => $sigId4], [
            'tenant_id' => $tenantId,
            'dedupe_key' => $tenantId . ':sig:sports-drop-cbse10:2025-2026',
            'org_id' => null,
            'source' => 'sports-analyzer',
            'classification' => 'risk',
            'rule_key' => 'participation.decline',
            'priority' => 'medium',
            'severity' => 'medium',
            'confidence' => 0.88,
            'related_entity_type' => 'Department',
            'related_entity_id' => $secDeptId,
            'department_id' => $secDeptId,
            'status' => 'investigating',
            'metadata' => json_encode([
                'title' => 'Grade 10 Sports participation dropping before board exams',
                'cohort' => 'CBSE-10',
                'affected_students' => 20,
            ]),
            'created_by' => self::AUTHOR,
            'created_date' => '2025-11-10 09:00:00',
            'updated_date' => $now,
        ]);

        $evId4 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':ev4');
        DB::table('hpbrain_evidence')->updateOrInsert(['id' => $evId4], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId4,
            'source' => 'v1a-sports',
            'evidence_type' => 'participation_records',
            'content' => json_encode([
                'summary' => 'Our review of sports attendance shows a 40% drop in Grade 10 students joining after-school sports like Basketball and Football in Term 2.',
            ], JSON_UNESCAPED_UNICODE),
            'provenance' => json_encode(['dataset' => 'v1a-sports']),
            'confidence' => 0.90,
            'hash' => hash('sha256', $tenantId . '-sports-drop'),
            'version' => '1.0',
            'status' => 'active',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-11-10 09:10:00',
            'observed_date' => '2025-11-10 00:00:00',
        ]);

        $caseId4 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':case4');
        DB::table('hpbrain_cases')->updateOrInsert(['id' => $caseId4], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId4,
            'title' => 'Grade 10 Sports Engagement Program',
            'description' => 'Students are skipping physical activities due to exam stress. Need to introduce short, high-energy 30-minute sports breaks instead of 2-hour matches.',
            'status' => 'open',
            'created_by' => self::AUTHOR,
            'created_date' => '2025-11-12 10:00:00',
            'updated_date' => $now,
        ]);

        DB::table('hpbrain_case_evidence')->updateOrInsert([
            'tenant_id' => $tenantId,
            'case_id' => $caseId4,
            'evidence_id' => $evId4,
        ]);

        $recId4 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':rec4');
        DB::table('hpbrain_recommendations')->updateOrInsert(['id' => $recId4], [
            'tenant_id' => $tenantId,
            'reasoning_step_id' => null,
            'category' => 'improve',
            'title' => 'Start a 30-minute "Exam Buster" fitness break program for Grade 10 students',
            'description' => 'Grade 10 students are avoiding long sports sessions because they need more time for board exam preparation. Implement short breaks. Physical fitness reduces exam stress and improves concentration. If they stop playing entirely, their overall health and study stamina will drop.',
            'priority' => 'medium',
            'urgency' => 'steady',
            'confidence' => 0.85,
            'impact' => 'Improves student physical health without taking away study time',
            'cost' => 'Zero external cost',
            'risk' => 'low',
            'dependencies' => json_encode([]),
            'status' => 'pending',
            'eso_id' => null,
            'created_by' => self::AUTHOR,
            'created_date' => '2025-11-13 14:00:00',
            'updated_date' => $now,
        ]);

        // =====================================================================
        // WORKFLOW 5: GK + Learning -> Knowledge Gap -> Remedial action
        // =====================================================================
        $sigId5 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':sig5');
        DB::table('hpbrain_signals')->updateOrInsert(['id' => $sigId5], [
            'tenant_id' => $tenantId,
            'dedupe_key' => $tenantId . ':sig:gk-gap-science:2025-2026',
            'org_id' => null,
            'source' => 'gk-analyzer',
            'classification' => 'risk',
            'rule_key' => 'academic.cohort_spread',
            'priority' => 'low',
            'severity' => 'low',
            'confidence' => 0.95,
            'related_entity_type' => 'Department',
            'related_entity_id' => $secDeptId,
            'department_id' => $secDeptId,
            'status' => 'investigating',
            'metadata' => json_encode([
                'title' => 'General Knowledge scores in Science and Tech are below average',
                'cohort' => 'Whole School',
                'subject' => 'GK - Science and Tech',
            ]),
            'created_by' => self::AUTHOR,
            'created_date' => '2026-02-01 10:00:00',
            'updated_date' => $now,
        ]);

        $evId5 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':ev5');
        DB::table('hpbrain_evidence')->updateOrInsert(['id' => $evId5], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId5,
            'source' => 'v1a-gk',
            'evidence_type' => 'assessment_records',
            'content' => json_encode([
                'summary' => 'Quiz results from Term 2 show that while students score well in Geography and Indian History (70%+), their Science and Tech quiz scores average only 45%.',
            ], JSON_UNESCAPED_UNICODE),
            'provenance' => json_encode(['dataset' => 'v1a-gk']),
            'confidence' => 0.95,
            'hash' => hash('sha256', $tenantId . '-gk-science-gap'),
            'version' => '1.0',
            'status' => 'active',
            'created_by' => self::AUTHOR,
            'created_date' => '2026-02-01 10:15:00',
            'observed_date' => '2026-02-01 00:00:00',
        ]);

        $caseId5 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':case5');
        DB::table('hpbrain_cases')->updateOrInsert(['id' => $caseId5], [
            'tenant_id' => $tenantId,
            'signal_id' => $sigId5,
            'title' => 'Science and Technology GK Improvement',
            'description' => 'Students lack awareness of current scientific events and basic technology concepts in GK quizzes.',
            'status' => 'open',
            'created_by' => self::AUTHOR,
            'created_date' => '2026-02-03 09:00:00',
            'updated_date' => $now,
        ]);

        DB::table('hpbrain_case_evidence')->updateOrInsert([
            'tenant_id' => $tenantId,
            'case_id' => $caseId5,
            'evidence_id' => $evId5,
        ]);

        $recId5 = (string) Uuid::uuid5(Uuid::fromString(self::ID_NAMESPACE), $tenantId . ':rec5');
        DB::table('hpbrain_recommendations')->updateOrInsert(['id' => $recId5], [
            'tenant_id' => $tenantId,
            'reasoning_step_id' => null,
            'category' => 'improve',
            'title' => 'Include a "Science Fact of the Day" in the morning assembly',
            'description' => 'Students are scoring significantly lower in Science & Tech GK compared to other topics. Scientific awareness is crucial for modern education and competitive exams. Small, daily exposure improves retention better than cramming.',
            'priority' => 'low',
            'urgency' => 'steady',
            'confidence' => 0.90,
            'impact' => 'Improves student awareness of current technology trends',
            'cost' => 'Zero external cost',
            'risk' => 'low',
            'dependencies' => json_encode([]),
            'status' => 'pending',
            'eso_id' => null,
            'created_by' => self::AUTHOR,
            'created_date' => '2026-02-04 11:00:00',
            'updated_date' => $now,
        ]);

        $this->completeInvestigationChains($tenantId, $principalId, $secDeptId, $finDeptId);
    }

    /**
     * Every investigation carries the whole chain, not just the first one:
     * signal -> evidence -> hypothesis -> reasoning -> recommendation ->
     * decision, and for the two resolved cases the ESO, execution, measurement
     * plan and learning behind the outcome. Open cases stop at a decision that
     * is genuinely waiting, which is what fills the Decision Queue.
     */
    private function completeInvestigationChains(string $tenantId, string $principalId, string $secDeptId, string $finDeptId): void
    {
        $ns = Uuid::fromString(self::ID_NAMESPACE);
        $id = static fn (string $key): string => (string) Uuid::uuid5($ns, $tenantId . ':' . $key);
        $now = '2026-04-15 12:00:00';

        $specs = [
            2 => [
                'resolved' => true,
                'evidence' => [
                    'source' => 'school_fee', 'type' => 'fee_ledger', 'confidence' => 0.93,
                    'created' => '2025-12-28 10:20:00', 'observed' => '2025-12-28 00:00:00',
                    'summary' => 'The Term 3 fee ledger shows 9 student accounts holding Rs 2,70,000 unpaid, 75 days past the due date. All nine paid Terms 1 and 2 on time, so this is a change in behaviour, not a history of default.',
                    'provenance' => ['dataset' => 'school_fee', 'rows_analyzed' => 3360, 'filter' => 'term=3 AND status=overdue'],
                ],
                'hypothesis' => [
                    'statement' => 'A single lump-sum Term 3 due date fell right after the festival and Term 2 exam-fee cycle, leaving these families short of cash. Non-payment is a liquidity timing problem, not disengagement from the school.',
                    'family' => 'process_design', 'confidence' => 0.87, 'status' => 'confirmed',
                    'by' => 'Finance Officer', 'created' => '2025-12-30 10:00:00',
                ],
                'steps' => [
                    ['Accounts that paid the first two terms on time and went quiet only on Term 3 point to cash timing, so a firm legal stance would damage a relationship that is otherwise healthy.', 0.88, '2025-12-31 11:00:00'],
                    ['Bi-weekly micro-payments keep every family inside the school fee cycle and recover cash sooner than a settlement date nobody can meet.', 0.90, '2026-01-02 15:00:00'],
                ],
                'recommendation' => [
                    'category' => 'improve', 'title' => 'Offer bi-weekly instalment plans to the nine overdue Term 3 families',
                    'description' => 'Replace the single overdue balance with a bi-weekly micro-payment schedule agreed in a counselling call with each family, with an SMS reminder two days before every instalment.',
                    'priority' => 'high', 'urgency' => 'urgent', 'confidence' => 0.90,
                    'impact' => 'Recovers at least 85% of the Rs 2,70,000 overdue balance without losing a single enrolment.',
                    'cost' => 'Zero external cost (finance office time only)', 'risk' => 'low',
                    'status' => 'accepted', 'created' => '2026-01-03 10:00:00',
                ],
                'decision' => ['status' => 'approved'],
                'eso' => [
                    'code' => 'ESO_FIN_INSTALMENT', 'name' => 'Structured instalment plan for overdue fee accounts',
                    'trigger' => 'Fee accounts more than 45 days overdue with a clean payment history.',
                    'objective' => 'Recover overdue fees via agreed instalments',
                    'owner' => $finDeptId,
                    'input' => ['families' => 9, 'overdue_amount' => 270000, 'schedule' => 'bi-weekly'],
                    'output' => ['plans_agreed' => 9, 'recovered_amount' => 243000, 'recovery_pct' => 90.0],
                    'started' => '2026-01-06 09:00:00', 'completed' => '2026-02-27 17:00:00',
                ],
                'plan' => ['metric' => 'term3_overdue_amount', 'baseline' => 270000.00, 'target' => 40500.00, 'unit' => 'INR', 'days' => 60, 'created' => '2026-01-05 09:00:00'],
                'learning' => [
                    'pattern' => 'Instalment plans beat escalation for clean-history overdue accounts',
                    'description' => 'Families with a clean payment record respond to a counselled bi-weekly plan: 90% of the overdue balance was recovered in 52 days with no dropouts, where escalation risks both the cash and the enrolment.',
                    'domain' => 'Finance & Fee Collection', 'confidence' => 0.91, 'created' => '2026-03-02 11:00:00',
                ],
            ],
            3 => [
                'resolved' => true,
                'evidence' => [
                    'source' => 'kasba-assessments', 'type' => 'capability_assessment', 'confidence' => 0.90,
                    'created' => '2025-07-28 14:20:00', 'observed' => '2025-07-28 00:00:00',
                    'summary' => 'Baseline KASBA evaluation of the STEM faculty scored Subject Knowledge at 4.3 but Differentiated Instruction at only 2.4 against a benchmark of 4.0. The gap is in applying techniques, not in knowing the subject.',
                    'provenance' => ['dataset' => 'hpbrain_capability_assignments', 'capability' => 'ED_ASSESSMENT_DESIGN', 'assessed_teachers' => 8],
                ],
                'hypothesis' => [
                    'statement' => 'Teachers know their subjects deeply but have had no structured, observed practice in planning one lesson for mixed-ability learners, so strong knowledge is not turning into differentiated teaching.',
                    'family' => 'capability_gap', 'confidence' => 0.86, 'status' => 'confirmed',
                    'by' => 'Head of Science & Mathematics', 'created' => '2025-07-30 10:00:00',
                ],
                'steps' => [
                    ['The knowledge score is high while the skill score is low, so another subject workshop would repeat what teachers already know; the gap needs practice with feedback.', 0.87, '2025-07-31 11:00:00'],
                    ['Paired peer mentoring with classroom observation turns knowledge into skill faster than passive workshops, and costs only timetable time.', 0.90, '2025-08-02 15:00:00'],
                ],
                'recommendation' => [
                    'category' => 'develop', 'title' => 'Run a term-long peer-mentoring cycle on differentiated instruction for STEM faculty',
                    'description' => 'Pair each STEM teacher with a mentor for fortnightly lesson-planning sessions and two observed lessons, then reassess the capability with the same KASBA rubric.',
                    'priority' => 'medium', 'urgency' => 'steady', 'confidence' => 0.88,
                    'impact' => 'Lifts Differentiated Instruction from 2.4 to the 4.0 benchmark within one term.',
                    'cost' => 'Zero external cost (reallocated planning periods)', 'risk' => 'low',
                    'status' => 'accepted', 'created' => '2025-08-03 10:00:00',
                ],
                'decision' => ['status' => 'approved'],
                'eso' => [
                    'code' => 'ESO_FACULTY_MENTORING', 'name' => 'Peer mentoring cycle for a faculty capability gap',
                    'trigger' => 'A KASBA assessment scoring a teaching capability well below benchmark.',
                    'objective' => 'Close a faculty capability gap by mentoring',
                    'owner' => $secDeptId,
                    'input' => ['faculty' => 8, 'capability' => 'Differentiated Instruction', 'cycle' => 'one term'],
                    'output' => ['mentoring_sessions' => 32, 'observed_lessons' => 16, 'teachers_completed' => 8],
                    'started' => '2025-08-11 09:00:00', 'completed' => '2025-10-31 17:00:00',
                ],
                'plan' => ['metric' => 'kasba_differentiated_instruction', 'baseline' => 2.40, 'target' => 4.00, 'unit' => 'KASBA level', 'days' => 210, 'created' => '2025-08-05 09:00:00'],
                'learning' => [
                    'pattern' => 'Observed peer mentoring converts knowledge into classroom skill',
                    'description' => 'Where subject knowledge is already strong, a mentoring cycle with observed lessons moved the skill score from 2.4 to 4.2, which a passive workshop would not have done.',
                    'domain' => 'People & Capability', 'confidence' => 0.92, 'created' => '2026-04-01 11:00:00',
                ],
            ],
            4 => [
                'resolved' => false,
                'hypothesis' => [
                    'statement' => 'Grade 10 students are dropping long after-school matches to protect board-exam revision time, not because they have lost interest in sport.',
                    'family' => 'workload_pressure', 'confidence' => 0.82, 'status' => 'proposed',
                    'by' => 'Sports Coach', 'created' => '2025-11-14 10:00:00',
                ],
                'steps' => [
                    ['The drop is concentrated in Term 2, the term that carries the pre-board mock exams, and Grade 9 participation did not fall, so the cause is exam pressure on Grade 10 specifically.', 0.84, '2025-11-15 11:00:00'],
                    ['A 30-minute break fits between study blocks, so it removes the trade-off between fitness and revision instead of asking students to choose.', 0.85, '2025-11-17 15:00:00'],
                ],
                'recommendation_status' => 'pending',
                'decision' => [
                    'status' => 'pending', 'date' => '2025-11-18 10:00:00',
                    'rationale' => 'Awaiting Principal review: short fitness breaks are low-cost, but need timetable space agreed with the Grade 10 class teachers.',
                    'alternatives' => ['Keep 2-hour matches only (not recommended: participation already falling)', 'Cancel Term 2 sports for Grade 10 (not recommended: removes stress relief)'],
                ],
            ],
            5 => [
                'resolved' => false,
                'hypothesis' => [
                    'statement' => 'The curriculum treats Science and Technology GK as part of textbook science, so current events and applied technology are never taught, while Geography and History are reinforced through regular quizzes.',
                    'family' => 'curriculum_coverage', 'confidence' => 0.80, 'status' => 'proposed',
                    'by' => 'Academic Coordinator', 'created' => '2026-02-05 10:00:00',
                ],
                'steps' => [
                    ['Scores of 70%+ in Geography and History against 45% in Science and Tech show the students can retain GK when it is reinforced, so the gap is exposure rather than ability.', 0.83, '2026-02-06 11:00:00'],
                    ['A daily two-minute science fact gives repeated exposure without adding a period, which suits a low-severity gap better than a new class.', 0.86, '2026-02-09 15:00:00'],
                ],
                'recommendation_status' => 'pending',
                'decision' => [
                    'status' => 'pending', 'date' => '2026-02-10 10:00:00',
                    'rationale' => 'Awaiting Principal review: a morning-assembly slot is free, and the proposal needs a teacher roster to prepare the daily fact.',
                    'alternatives' => ['Add a weekly GK period (not recommended: no free timetable slot)', 'Do nothing and review next term (not recommended: gap already visible in Term 2)'],
                ],
            ],
        ];

        foreach ($specs as $n => $spec) {
            $caseId = $id('case' . $n);
            $sigId = $id('sig' . $n);
            $evidenceId = $id('ev' . $n);
            $hypId = $id('hyp' . $n);
            $recId = $id('rec' . $n);
            $decId = $id('dec' . $n);

            // Evidence (cases 4 and 5 already own one).
            if (isset($spec['evidence'])) {
                $e = $spec['evidence'];
                DB::table('hpbrain_evidence')->updateOrInsert(['id' => $evidenceId], [
                    'tenant_id' => $tenantId,
                    'signal_id' => $sigId,
                    'source' => $e['source'],
                    'evidence_type' => $e['type'],
                    'content' => json_encode(['summary' => $e['summary']], JSON_UNESCAPED_UNICODE),
                    'provenance' => json_encode($e['provenance']),
                    'confidence' => $e['confidence'],
                    'hash' => hash('sha256', $tenantId . '-case' . $n . '-evidence'),
                    'version' => '1.0',
                    'status' => 'active',
                    'created_by' => self::AUTHOR,
                    'created_date' => $e['created'],
                    'observed_date' => $e['observed'],
                ]);
                DB::table('hpbrain_case_evidence')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'case_id' => $caseId, 'evidence_id' => $evidenceId],
                    ['linked_date' => $e['created']]
                );
            }

            $h = $spec['hypothesis'];
            DB::table('hpbrain_hypotheses')->updateOrInsert(['id' => $hypId], [
                'tenant_id' => $tenantId,
                'case_id' => $caseId,
                'statement' => $h['statement'],
                'root_cause_family' => $h['family'],
                'confidence' => $h['confidence'],
                'status' => $h['status'],
                'supporting_evidence_ids' => json_encode([$evidenceId]),
                'proposed_by' => $h['by'],
                'created_date' => $h['created'],
            ]);
            if ($spec['resolved']) {
                DB::table('hpbrain_cases')->where('id', $caseId)->update(['resolved_hypothesis_id' => $hypId]);
            }

            // Reasoning steps; the recommendation hangs off the last one.
            $lastStepId = null;
            foreach ($spec['steps'] as $i => [$description, $confidence, $created]) {
                $lastStepId = $id('step' . $n . '-' . ($i + 1));
                DB::table('hpbrain_reasoning_steps')->updateOrInsert(['id' => $lastStepId], [
                    'tenant_id' => $tenantId,
                    'case_id' => $caseId,
                    'signal_id' => $sigId,
                    'step_order' => $i + 1,
                    'description' => $description,
                    'confidence_score' => $confidence,
                    'created_by' => self::AUTHOR,
                    'created_date' => $created,
                ]);
            }

            // Cases 2 and 3 get their ESO and recommendation here; 4 and 5 already have a recommendation.
            $esoDefId = null;
            if (isset($spec['eso'])) {
                $o = $spec['eso'];
                $esoDefId = $id('esodef' . $n);
                DB::table('hpbrain_eso_definitions')->updateOrInsert(['id' => $esoDefId], [
                    'tenant_id' => $tenantId,
                    'org_id' => null,
                    'eso_code' => $o['code'],
                    'name' => $o['name'],
                    'version' => '1.0',
                    'status' => 'active',
                    'owner' => $o['owner'],
                    'provenance' => 'School Leadership Standards',
                    'trigger_description' => $o['trigger'],
                    'objective' => $o['objective'],
                    'created_by' => self::AUTHOR,
                    'created_date' => $spec['recommendation']['created'],
                    'updated_date' => $now,
                ]);
            }

            if (isset($spec['recommendation'])) {
                $r = $spec['recommendation'];
                DB::table('hpbrain_recommendations')->updateOrInsert(['id' => $recId], [
                    'tenant_id' => $tenantId,
                    'reasoning_step_id' => $lastStepId,
                    'category' => $r['category'],
                    'title' => $r['title'],
                    'description' => $r['description'],
                    'priority' => $r['priority'],
                    'urgency' => $r['urgency'],
                    'confidence' => $r['confidence'],
                    'impact' => $r['impact'],
                    'cost' => $r['cost'],
                    'risk' => $r['risk'],
                    'dependencies' => json_encode([]),
                    'status' => $r['status'],
                    'eso_id' => $esoDefId,
                    'created_by' => self::AUTHOR,
                    'created_date' => $r['created'],
                    'updated_date' => $now,
                ]);
            } else {
                DB::table('hpbrain_recommendations')->where('id', $recId)->update([
                    'reasoning_step_id' => $lastStepId,
                    'status' => $spec['recommendation_status'],
                    'updated_date' => $now,
                ]);
            }

            // Decision, linked to the recommendation. Approved ones already exist for cases 2 and 3.
            $d = $spec['decision'];
            $approved = $d['status'] === 'approved';
            if ($approved) {
                DB::table('hpbrain_decisions')->where('id', $decId)->update(['recommendation_id' => $recId]);
            } else {
                DB::table('hpbrain_decisions')->updateOrInsert(['id' => $decId], [
                    'tenant_id' => $tenantId,
                    'recommendation_id' => $recId,
                    'decided_by' => $principalId,
                    'executor_type' => 'human',
                    'rationale' => $d['rationale'],
                    'alternatives_considered' => json_encode($d['alternatives']),
                    'status' => 'pending',
                    'confidence' => 0.80,
                    'explanation' => 'Waiting for Principal approval.',
                    'created_date' => $d['date'],
                ]);

                continue;
            }

            // Resolved cases: measurement plan, execution and learning behind the existing outcome.
            $pl = $spec['plan'];
            DB::table('hpbrain_measurement_plans')->updateOrInsert(['id' => $id('plan' . $n)], [
                'tenant_id' => $tenantId,
                'decision_id' => $decId,
                'baseline_metric' => $pl['metric'],
                'baseline_value' => $pl['baseline'],
                'target_value' => $pl['target'],
                'metric_unit' => $pl['unit'],
                'measurement_window_days' => $pl['days'],
                'owner_id' => $principalId,
                'created_by' => self::AUTHOR,
                'created_date' => $pl['created'],
            ]);

            $o = $spec['eso'];
            DB::table('hpbrain_eso_executions')->updateOrInsert(['id' => $id('esoexec' . $n)], [
                'tenant_id' => $tenantId,
                'eso_id' => $esoDefId,
                'eso_definition_id' => $esoDefId,
                'decision_id' => $decId,
                'status' => 'completed',
                'executed_by' => $principalId,
                'executor_type' => 'human',
                'input' => json_encode($o['input']),
                'output' => json_encode($o['output']),
                'started_date' => $o['started'],
                'completed_date' => $o['completed'],
                'created_date' => $o['started'],
            ]);

            $l = $spec['learning'];
            DB::table('hpbrain_learnings')->updateOrInsert(['id' => $id('learn' . $n)], [
                'tenant_id' => $tenantId,
                'outcome_id' => $id('out' . $n),
                'mental_model_id' => null,
                'pattern' => $l['pattern'],
                'description' => $l['description'],
                'domain' => $l['domain'],
                'confidence' => $l['confidence'],
                'reusable' => 1,
                'created_by' => self::AUTHOR,
                'created_date' => $l['created'],
            ]);
        }
    }
}
