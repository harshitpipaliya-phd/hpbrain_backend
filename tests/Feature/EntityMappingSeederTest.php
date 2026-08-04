<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Universal\EntityResolver;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * Phase 1's central claim: the resolver returns exactly the strings the
 * hardcoded references currently use, so replacing them changes nothing.
 *
 * The literals asserted below were read off the code being replaced, not off
 * the seeder — asserting the seeder against itself would prove only that it is
 * self-consistent. Sources:
 *
 *   institute_detail / sub_institute_id / organization_name / organization_code
 *   / industry_type          -> OrganizationRepository::list()
 *   hrms_departments / department / roles_responsibility / parent_id / status
 *                            -> SignalGenerator::departmentsWithoutManager()
 *   tbluser / department_id / user_profile_id / email / first_name / last_name
 *   / employee_no            -> SignalGenerator::peopleWithoutDepartment() and
 *                               WorkspaceController::homeMetrics()
 *
 * If Phase 2 changes any resolved value, this test fails before the behaviour
 * does.
 */
final class EntityMappingSeederTest extends TestCase
{
    use BuildsBrainSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();

        // The seeder reads its tenant list from the ERP's organization register.
        Schema::create('institute_detail', function ($t) {
            $t->integer('id')->primary();
            $t->integer('sub_institute_id');
            $t->string('organization_name')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        DB::table('institute_detail')->insert([
            ['id' => 1, 'sub_institute_id' => 4, 'organization_name' => 'SIDS HealthCare', 'deleted_at' => null],
            ['id' => 2, 'sub_institute_id' => 6, 'organization_name' => 'Scholar Clone Pvt. Ltd.', 'deleted_at' => null],
            // Soft-deleted: must not receive mappings.
            ['id' => 3, 'sub_institute_id' => 9, 'organization_name' => 'Gone', 'deleted_at' => '2026-01-01 00:00:00'],
        ]);

        (new EntityMappingSeeder())->run();
    }

    private function resolver(): EntityResolver
    {
        return new EntityResolver();
    }

    /** @test */
    public function person_resolves_to_the_columns_the_hardcoded_queries_use(): void
    {
        $person = $this->resolver()->resolve('4', 'Person');

        $this->assertSame('tbluser', $person->table);
        $this->assertSame('sub_institute_id', $person->tenantKey);
        $this->assertSame('id', $person->primaryKey);

        $this->assertSame([
            'externalRef' => 'employee_no',
            'firstName'   => 'first_name',
            'lastName'    => 'last_name',
            'email'       => 'email',
            'phone'       => 'mobile',
            'unit'        => 'department_id',
            'position'    => 'jobtitle_id',
            'profile'     => 'user_profile_id',
            'status'      => 'status',
            'joinedDate'  => 'joined_date',
        ], $person->columns([
            'externalRef', 'firstName', 'lastName', 'email', 'phone',
            'unit', 'position', 'profile', 'status', 'joinedDate',
        ]));
    }

    /** @test */
    public function organization_unit_resolves_to_hrms_departments(): void
    {
        $unit = $this->resolver()->resolve('4', 'OrganizationUnit');

        $this->assertSame('hrms_departments', $unit->table);
        $this->assertSame('sub_institute_id', $unit->tenantKey);
        $this->assertSame('id', $unit->primaryKey);
        $this->assertSame('department', $unit->field('name'));
        $this->assertSame('roles_responsibility', $unit->field('description'));
        $this->assertSame('parent_id', $unit->field('parent'));
        $this->assertSame('status', $unit->field('status'));
    }

    /** @test */
    public function organization_identity_is_its_tenant_key_as_the_repository_selects_it(): void
    {
        // OrganizationRepository::list() selects `d.sub_institute_id as id`.
        $org = $this->resolver()->resolve('4', 'Organization');

        $this->assertSame('institute_detail', $org->table);
        $this->assertSame('sub_institute_id', $org->primaryKey);
        $this->assertSame('sub_institute_id', $org->tenantKey);
        $this->assertSame('organization_name', $org->field('name'));
        $this->assertSame('organization_code', $org->field('code'));
        $this->assertSame('industry_type', $org->field('industry'));
    }

    /** @test */
    public function fields_the_erp_has_no_column_for_stay_unmapped(): void
    {
        // The honesty requirement, enforced. hrms_departments has no manager
        // column and hrms_job_titles has neither a reporting line nor a vacancy
        // flag. None of them may be quietly pointed at a lookalike column.
        $this->assertFalse($this->resolver()->resolve('4', 'OrganizationUnit')->has('head'));

        $position = $this->resolver()->resolve('4', 'Position');
        $this->assertSame('hrms_job_titles', $position->table);
        $this->assertSame('title', $position->field('title'));
        $this->assertFalse($position->has('unit'));
        $this->assertFalse($position->has('reportsTo'));
        $this->assertFalse($position->has('isVacant'));
    }

    /** @test */
    public function every_existing_tenant_is_mapped_not_only_one(): void
    {
        // All tenants run on the same ERP tables today. Seeding one would leave
        // the rest resolving nothing, and the resolver fails closed.
        foreach (['4', '6'] as $tenantId) {
            $this->assertSame(
                ['Organization', 'OrganizationUnit', 'Person', 'Position'],
                $this->resolver()->mappedEntities($tenantId),
                "Tenant {$tenantId} should have the full entity set.",
            );
        }
    }

    /** @test */
    public function soft_deleted_organizations_are_not_seeded(): void
    {
        $this->assertSame([], $this->resolver()->mappedEntities('9'));
    }

    /** @test */
    public function the_seeder_is_idempotent(): void
    {
        $before = DB::table('hpbrain_entity_mappings')->count();

        // Re-running must not duplicate or fail. It would violate the corrected
        // unique key if it inserted rather than updated.
        (new EntityMappingSeeder())->run();

        $this->assertSame($before, DB::table('hpbrain_entity_mappings')->count());
        $this->assertSame('tbluser', $this->resolver()->resolve('4', 'Person')->table);
    }

    /** @test */
    public function a_tenant_the_seeder_never_saw_resolves_nothing(): void
    {
        $this->assertFalse($this->resolver()->has('99', 'Person'));
    }
}
