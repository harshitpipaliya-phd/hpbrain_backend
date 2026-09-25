<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

final class CreationWorkflowTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;

    private const TENANT = '4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->seedErpFixture((int) self::TENANT);
        (new EntityMappingSeeder())->run();
    }

    private function auth(string $tenant = self::TENANT, string $role = 'admin'): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => $tenant, 'role' => $role,
        ])];
    }

    /** @test */
    public function department_can_be_created_with_tenant_isolation_and_transaction(): void
    {
        $payload = [
            'name'        => 'Quality Assurance',
            'description' => 'Clinical QA and Standards',
            'code'        => 'QA',
        ];

        $response = $this->withHeaders($this->auth())->postJson('/api/v1/departments', $payload);

        $response->assertStatus(201);
        $data = $response->json();

        $this->assertSame('Quality Assurance', $data['name']);
        $this->assertSame('Clinical QA and Standards', $data['description']);
        $this->assertSame(self::TENANT, $data['orgId']);
        $this->assertSame('active', $data['status']);

        // Verify in database table
        $this->assertDatabaseHas('hrms_departments', [
            'department'           => 'Quality Assurance',
            'roles_responsibility' => 'Clinical QA and Standards',
            'sub_institute_id'     => (int) self::TENANT,
            'status'               => 1,
        ]);
    }

    /** @test */
    public function duplicate_department_name_in_same_tenant_is_rejected(): void
    {
        $payload = [
            'name' => 'Duplicate Dept',
        ];

        $first = $this->withHeaders($this->auth())->postJson('/api/v1/departments', $payload);
        $first->assertStatus(201);

        $second = $this->withHeaders($this->auth())->postJson('/api/v1/departments', $payload);
        $second->assertStatus(422)
            ->assertJsonFragment(['error' => 'department_already_exists']);
    }

    /** @test */
    public function person_can_be_created_with_proper_profile_and_tenant_isolation(): void
    {
        // First ensure a department exists
        $dept = $this->withHeaders($this->auth())->postJson('/api/v1/departments', [
            'name' => 'Cardiology',
        ]);
        $deptId = (int) $dept->json('id');

        $personPayload = [
            'employeeId'   => 'EMP-9901',
            'firstName'    => 'Jane',
            'lastName'     => 'Doe',
            'email'        => 'jane.doe@example.com',
            'phone'        => '9876543210',
            'gender'       => 'female',
            'departmentId' => $deptId,
            'joiningDate'  => '2026-09-01',
        ];

        $response = $this->withHeaders($this->auth())->postJson('/api/v1/people', $personPayload);

        $response->assertStatus(201);
        $data = $response->json();

        $this->assertSame('Jane', $data['firstName']);
        $this->assertSame('Doe', $data['lastName']);
        $this->assertSame('jane.doe@example.com', $data['email']);
        $this->assertSame(self::TENANT, $data['orgId']);
        $this->assertNotEmpty($data['tempPassword']);

        // Verify in database table
        $this->assertDatabaseHas('tbluser', [
            'employee_no'      => 'EMP-9901',
            'first_name'       => 'Jane',
            'last_name'        => 'Doe',
            'email'            => 'jane.doe@example.com',
            'department_id'    => $deptId,
            'sub_institute_id' => (int) self::TENANT,
            'status'           => 1,
        ]);
    }

    /** @test */
    public function duplicate_person_email_is_rejected(): void
    {
        $payload = [
            'employeeId' => 'EMP-1111',
            'firstName'  => 'First',
            'lastName'   => 'Person',
            'email'      => 'unique.email@example.com',
        ];

        $first = $this->withHeaders($this->auth())->postJson('/api/v1/people', $payload);
        $first->assertStatus(201);

        $secondPayload = [
            'employeeId' => 'EMP-2222',
            'firstName'  => 'Second',
            'lastName'   => 'Person',
            'email'      => 'unique.email@example.com',
        ];

        $second = $this->withHeaders($this->auth())->postJson('/api/v1/people', $secondPayload);
        $second->assertStatus(422)
            ->assertJsonFragment(['error' => 'email_already_exists']);
    }

    /** @test */
    public function person_cannot_be_assigned_to_department_of_another_tenant(): void
    {
        // Insert department directly belonging to another tenant
        $otherDeptId = DB::table('hrms_departments')->insertGetId([
            'sub_institute_id'     => 9999,
            'department'           => 'Foreign Department',
            'roles_responsibility' => 'Foreign',
            'parent_id'            => 0,
            'status'               => 1,
            'is_calculated'        => 0,
        ]);

        $personPayload = [
            'employeeId'   => 'EMP-3333',
            'firstName'    => 'Malicious',
            'lastName'     => 'CrossTenant',
            'email'        => 'crosstenant@example.com',
            'departmentId' => $otherDeptId,
        ];

        $response = $this->withHeaders($this->auth(self::TENANT))->postJson('/api/v1/people', $personPayload);
        $response->assertStatus(422)
            ->assertJsonFragment(['error' => 'department_not_found']);
    }

    /** @test */
    public function person_options_returns_departments_and_roles_scoped_to_tenant(): void
    {
        $response = $this->withHeaders($this->auth(self::TENANT))->getJson('/api/v1/people/' . self::TENANT . '/options');
        $response->assertOk()
            ->assertJsonStructure([
                'departments',
                'roles',
            ]);
    }
}
