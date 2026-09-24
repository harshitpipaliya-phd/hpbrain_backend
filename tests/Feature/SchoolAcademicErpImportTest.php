<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class SchoolAcademicErpImportTest extends TestCase
{
    use \Tests\Support\BuildsBrainSchema;

    private const TENANT = '254';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->seedOrganizationMapping();
        $this->seedErpRows();
    }

    public function test_school_erp_academic_results_reuse_existing_operational_pipeline(): void
    {
        $exit = Artisan::call('brain:import-erp-school-academics', [
            '--tenant' => self::TENANT,
            '--no-warm' => true,
        ]);

        $this->assertSame(0, $exit, Artisan::output());

        $this->assertSame(2, DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)
            ->where('dataset', 'erp-academic-results')
            ->count());

        $this->assertDatabaseHas('hpbrain_data_sources', [
            'tenant_id' => self::TENANT,
            'source_key' => 'erp-academic-results',
            'source_type' => 'dataset',
            'is_active' => 1,
        ]);

        $source = DB::table('hpbrain_data_sources')
            ->where('tenant_id', self::TENANT)
            ->where('source_key', 'erp-academic-results')
            ->first();

        $this->assertSame('academic', json_decode((string) $source->config, true)['dataset_role']);

        $record = DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)
            ->where('dataset', 'erp-academic-results')
            ->where('natural_key', '1')
            ->first();

        $this->assertSame('HH-1001', $record->subject_ref);
        $this->assertSame('English', $record->category);
        $this->assertSame('Standard-1', $record->status);
        $this->assertSame('Asha Shah', json_decode((string) $record->payload, true)['student_name']);

        Artisan::call('brain:import-erp-school-academics', [
            '--tenant' => self::TENANT,
            '--no-warm' => true,
        ]);

        $this->assertSame(2, DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)
            ->where('dataset', 'erp-academic-results')
            ->count());
    }

    private function buildErpSchema(): void
    {
        Schema::create('institute_detail', function ($table): void {
            $table->integer('sub_institute_id')->primary();
            $table->string('organization_name')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('tblstudent', function ($table): void {
            $table->integer('id')->primary();
            $table->string('enrollment_no')->nullable();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->integer('sub_institute_id');
            $table->integer('status')->nullable();
        });

        Schema::create('result_marks', function ($table): void {
            $table->integer('id')->primary();
            $table->integer('student_id');
            $table->integer('exam_id');
            $table->decimal('points', 8, 2)->nullable();
            $table->string('grade')->nullable();
            $table->decimal('per', 8, 2)->nullable();
            $table->string('comment')->nullable();
            $table->string('is_absent')->nullable();
            $table->integer('sub_institute_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('exam_title')->nullable();
            $table->string('standard_name')->nullable();
            $table->string('subject_name')->nullable();
        });

        Schema::create('result_create_exam', function ($table): void {
            $table->integer('id')->primary();
            $table->integer('syear')->nullable();
            $table->integer('sub_institute_id');
            $table->integer('standard_id')->nullable();
            $table->integer('subject_id')->nullable();
            $table->string('title')->nullable();
            $table->decimal('points', 8, 2)->nullable();
            $table->decimal('con_point', 8, 2)->nullable();
        });

        Schema::create('standard', function ($table): void {
            $table->integer('id')->primary();
            $table->string('name')->nullable();
            $table->integer('sub_institute_id');
        });

        Schema::create('subject', function ($table): void {
            $table->integer('id')->primary();
            $table->string('subject_name')->nullable();
            $table->integer('sub_institute_id');
        });
    }

    private function seedOrganizationMapping(): void
    {
        DB::table('institute_detail')->insert([
            'sub_institute_id' => self::TENANT,
            'organization_name' => 'Hills High School',
            'deleted_at' => null,
        ]);

        foreach ([
            'id' => 'sub_institute_id',
            'tenantKey' => 'sub_institute_id',
            'name' => 'organization_name',
            'deletedAt' => 'deleted_at',
        ] as $field => $column) {
            DB::table('hpbrain_entity_mappings')->insert([
                'id' => Uuid::uuid4()->toString(),
                'tenant_id' => self::TENANT,
                'source_system' => 'erp',
                'source_entity' => 'institute_detail',
                'source_field' => $column,
                'universal_entity' => 'Organization',
                'universal_field' => $field,
                'mapping_type' => 'direct',
                'is_active' => 1,
                'created_by' => 'test',
                'created_date' => now(),
            ]);
        }
    }

    private function seedErpRows(): void
    {
        DB::table('tblstudent')->insert([
            'id' => 184607,
            'enrollment_no' => 'HH-1001',
            'first_name' => 'Asha',
            'middle_name' => null,
            'last_name' => 'Shah',
            'sub_institute_id' => self::TENANT,
            'status' => 1,
        ]);

        DB::table('standard')->insert([
            'id' => 3291,
            'name' => 'Standard-1',
            'sub_institute_id' => self::TENANT,
        ]);

        DB::table('subject')->insert([
            ['id' => 4508, 'subject_name' => 'English', 'sub_institute_id' => self::TENANT],
            ['id' => 4509, 'subject_name' => 'Maths', 'sub_institute_id' => self::TENANT],
        ]);

        DB::table('result_create_exam')->insert([
            ['id' => 10685, 'syear' => 2025, 'sub_institute_id' => self::TENANT, 'standard_id' => 3291, 'subject_id' => 4508, 'title' => 'PT-1', 'points' => 20, 'con_point' => 20],
            ['id' => 10686, 'syear' => 2025, 'sub_institute_id' => self::TENANT, 'standard_id' => 3291, 'subject_id' => 4509, 'title' => 'PT-1', 'points' => 20, 'con_point' => 20],
        ]);

        DB::table('result_marks')->insert([
            ['id' => 1, 'student_id' => 184607, 'exam_id' => 10685, 'points' => 18, 'grade' => 'A', 'per' => 90, 'comment' => '', 'is_absent' => '', 'sub_institute_id' => self::TENANT, 'created_at' => '2025-07-01 00:00:00', 'updated_at' => '2025-07-01 00:00:00'],
            ['id' => 2, 'student_id' => 184607, 'exam_id' => 10686, 'points' => 17, 'grade' => 'A', 'per' => 85, 'comment' => '', 'is_absent' => '', 'sub_institute_id' => self::TENANT, 'created_at' => '2025-07-02 00:00:00', 'updated_at' => '2025-07-02 00:00:00'],
        ]);
    }
}
