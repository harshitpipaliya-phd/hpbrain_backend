<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use App\Domain\School\StudentProjectionBuilder;
use App\Domain\School\DatasetRegistry;
use App\Domain\Universal\EntityResolver;

class StudentProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Projection builder only runs on MySQL.');
        }
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_fee_projection_does_not_overwrite_student_standard_with_status()
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Projection builder only runs on MySQL.');
        }

        $tenantId = '12345';
        $now = now();

        DB::table('hpbrain_operational_records')->insert([
            [
                'id' => '111',
                'tenant_id' => $tenantId,
                'dataset' => 'academic-results',
                'natural_key' => 'acad-1',
                'source_file' => 'acad.csv',
                'source_row' => 1,
                'occurred_at' => $now,
                'status' => 'CBSE-10',
                'category' => 'Math',
                'sub_category' => 'Term 1',
                'owner_name' => 'Teacher',
                'area' => 'Academic',
                'subject_ref' => 'S-001',
                'metric_value' => 80,
                'metric_unit' => 'marks',
                'quantity' => 100,
                'payload' => json_encode(['student_name' => 'John Doe']),
                'row_hash' => 'h1',
            ],
            [
                'id' => '222',
                'tenant_id' => $tenantId,
                'dataset' => 'school-fee',
                'natural_key' => 'fee-1',
                'source_file' => 'fee.csv',
                'source_row' => 1,
                'occurred_at' => $now,
                'status' => 'Paid',
                'category' => 'Tuition',
                'sub_category' => 'Q1',
                'owner_name' => 'Finance',
                'area' => 'Finance',
                'subject_ref' => 'S-001',
                'metric_value' => 5000,
                'metric_unit' => 'INR',
                'quantity' => 1,
                'payload' => json_encode(['Student Name' => 'John Doe', 'Standard' => 'CBSE-10', 'Division' => 'A']),
                'row_hash' => 'h2',
            ]
        ]);

        $builder = new StudentProjectionBuilder(
            $this->app->make(DatasetRegistry::class),
            $this->app->make(EntityResolver::class)
        );

        $builder->rebuild($tenantId, 'academic-results', 'school-fee');

        $student = DB::table('hpbrain_students')->where('student_ref', 'S-001')->first();

        $this->assertNotNull($student);
        $this->assertEquals('CBSE-10', $student->standard);
        $this->assertEquals('A', $student->division);
        $this->assertNotEquals('Paid', $student->standard);
    }
}
