<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Graph\GraphProjection;
use App\Domain\Organization\OrganizationStructureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GraphProjection::departmentHierarchy() — the new, additive `WITH RECURSIVE`
 * read ADR-008 asked for (task 2). NOT wired into
 * attachOrganizationBranches()/buildOverview(), deliberately — see that
 * method's docblock for why; this test only proves the new method itself,
 * not a change to overview()/expand()'s existing behaviour.
 *
 * EQUIVALENCE, NOT IDENTITY. The recursive method's `tree` is genuinely
 * nested (3 levels here); OrganizationStructureService's existing flat read
 * is not, by design. What must agree between them is the set of department
 * ids and, for each one, its staff count — and the staff counts are proven
 * to agree BY CONSTRUCTION in this test, because departmentHierarchy()
 * reads them from OrganizationStructureService::getPeopleCountByDepartment()
 * rather than re-deriving them (see GraphProjection's docblock on that
 * method). This test still asserts the counts explicitly rather than taking
 * that claim on faith.
 */
final class GraphDepartmentHierarchyTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-dept-hierarchy';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    private function mapField(string $entity, string $field, string $table, string $column): void
    {
        DB::table('hpbrain_entity_mappings')->insert([
            'id'                   => Uuid::uuid4()->toString(),
            'tenant_id'            => self::TENANT,
            'source_system'        => 'erp',
            'source_entity'        => $table,
            'source_field'         => $column,
            'universal_entity'     => $entity,
            'universal_field'      => $field,
            'mapping_type'         => 'direct',
            'transform_expression' => null,
            'lookup_table'         => null,
            'is_active'            => 1,
            'created_by'           => 'test',
            'created_date'         => '2026-01-01 00:00:00',
            'updated_date'         => '2026-01-01 00:00:00',
        ]);
    }

    /**
     * A real ERP-shaped self-referencing department table — same column
     * shape as hrms_departments (tests/Feature/ErpLoginTest.php L388-398):
     * id, a tenant-scoping column, a name, a self-referencing parent_id
     * (0 means root, matching ImportSchoolFeeWorkspace's seeding convention),
     * and a status.
     */
    private function buildErpFixture(): void
    {
        Schema::create('test_erp_departments', function ($t) {
            $t->integer('id')->primary();
            $t->string('sub_institute_id', 64);
            $t->string('department');
            $t->integer('parent_id')->default(0);
            $t->integer('status')->default(1);
        });

        Schema::create('test_erp_staff', function ($t) {
            $t->integer('id')->primary();
            $t->string('sub_institute_id', 64);
            $t->integer('department_id')->nullable();
            $t->integer('status')->default(1);
        });

        $this->mapField('OrganizationUnit', 'id', 'test_erp_departments', 'id');
        $this->mapField('OrganizationUnit', 'tenantKey', 'test_erp_departments', 'sub_institute_id');
        $this->mapField('OrganizationUnit', 'name', 'test_erp_departments', 'department');
        $this->mapField('OrganizationUnit', 'parent', 'test_erp_departments', 'parent_id');
        $this->mapField('OrganizationUnit', 'status', 'test_erp_departments', 'status');

        $this->mapField('Person', 'id', 'test_erp_staff', 'id');
        $this->mapField('Person', 'tenantKey', 'test_erp_staff', 'sub_institute_id');
        $this->mapField('Person', 'unit', 'test_erp_staff', 'department_id');
        $this->mapField('Person', 'status', 'test_erp_staff', 'status');

        // Three real levels: Administration -> Finance -> Accounts Payable,
        // plus a sibling root (Academics) with no children. tenantKey
        // (sub_institute_id) is self::TENANT -- the SAME tenant id
        // hpbrain_entity_mappings.tenant_id above is keyed on, because
        // EntityResolver resolves the mapping by tenant_id and then scopes
        // the resolved ERP table by that same id's value in its own
        // tenantKey column (sub_institute_id here, whatever it is called on
        // a real ERP table).
        DB::table('test_erp_departments')->insert([
            ['id' => 1, 'sub_institute_id' => self::TENANT, 'department' => 'Administration', 'parent_id' => 0, 'status' => 1],
            ['id' => 2, 'sub_institute_id' => self::TENANT, 'department' => 'Finance', 'parent_id' => 1, 'status' => 1],
            ['id' => 3, 'sub_institute_id' => self::TENANT, 'department' => 'Accounts Payable', 'parent_id' => 2, 'status' => 1],
            ['id' => 4, 'sub_institute_id' => self::TENANT, 'department' => 'Academics', 'parent_id' => 0, 'status' => 1],
        ]);

        DB::table('test_erp_staff')->insert([
            ['id' => 1, 'sub_institute_id' => self::TENANT, 'department_id' => 3, 'status' => 1],
            ['id' => 2, 'sub_institute_id' => self::TENANT, 'department_id' => 3, 'status' => 1],
            ['id' => 3, 'sub_institute_id' => self::TENANT, 'department_id' => 2, 'status' => 1],
            ['id' => 4, 'sub_institute_id' => self::TENANT, 'department_id' => 4, 'status' => 1],
            ['id' => 5, 'sub_institute_id' => self::TENANT, 'department_id' => 4, 'status' => 1],
            ['id' => 6, 'sub_institute_id' => self::TENANT, 'department_id' => 4, 'status' => 1],
        ]);
    }

    public function test_recursive_hierarchy_nests_sub_departments_three_levels_deep(): void
    {
        $this->buildErpFixture();

        $result = app(GraphProjection::class)->departmentHierarchy(self::TENANT);

        self::assertTrue($result['supported'], $result['reason'] ?? 'expected supported=true');

        $roots = $result['tree'];
        $byName = [];
        foreach ($roots as $root) {
            $byName[$root['name']] = $root;
        }

        self::assertArrayHasKey('Administration', $byName);
        self::assertArrayHasKey('Academics', $byName);
        self::assertSame(0, $byName['Administration']['depth']);

        $finance = $byName['Administration']['children'][0];
        self::assertSame('Finance', $finance['name']);
        self::assertSame(1, $finance['depth']);

        $accountsPayable = $finance['children'][0];
        self::assertSame('Accounts Payable', $accountsPayable['name']);
        self::assertSame(2, $accountsPayable['depth']);
        self::assertSame([], $accountsPayable['children']);

        self::assertSame([], $byName['Academics']['children']);
    }

    public function test_member_counts_agree_with_the_existing_flat_read_by_sharing_the_source(): void
    {
        $this->buildErpFixture();

        $result = app(GraphProjection::class)->departmentHierarchy(self::TENANT);
        self::assertTrue($result['supported']);

        $flatById = [];
        foreach ($result['flat'] as $node) {
            $flatById[$node['id']] = $node;
        }

        // Independently computed by the SAME iterative path
        // attachOrganizationBranches() already trusts.
        $expectedCounts = app(OrganizationStructureService::class)->getPeopleCountByDepartment(self::TENANT);

        self::assertSame(2, $flatById['3']['members']); // Accounts Payable
        self::assertSame(1, $flatById['2']['members']); // Finance (direct only, not rolled up from its child)
        self::assertSame(0, $flatById['1']['members']); // Administration — no direct staff
        self::assertSame(3, $flatById['4']['members']); // Academics

        foreach ($flatById as $id => $node) {
            self::assertSame(
                $expectedCounts[$id] ?? 0,
                $node['members'],
                "department {$id} ({$node['name']}) must agree with OrganizationStructureService::getPeopleCountByDepartment()",
            );
        }
    }

    public function test_a_tenant_with_no_departments_at_all_reports_unsupported_honestly(): void
    {
        // No hpbrain_entity_mappings rows, no HR departments, no student rows
        // to derive academic sections from either -> SOURCE_NONE.
        $result = app(GraphProjection::class)->departmentHierarchy('tenant-with-nothing');

        self::assertFalse($result['supported']);
        self::assertSame([], $result['tree']);
        self::assertStringContainsString('no departments', (string) $result['reason']);
    }
}
