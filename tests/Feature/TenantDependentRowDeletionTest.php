<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantDeletionException;
use App\Domain\Tenancy\TenantOwnedTables;
use App\Domain\Tenancy\TenantPurgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Rows a tenant owns through a foreign key rather than through a tenant column.
 *
 * THE DEFECT THIS FIXES, IN THE SHAPE IT ACTUALLY OCCURRED. hp_erp holds
 * content_mapping_type — id, content_id, mapping_type_id, mapping_value_id, and
 * no tenant column anywhere. Its content_id is a foreign key into
 * content_master, which IS tenant-scoped, and the constraint is
 * ON DELETE NO ACTION.
 *
 * TenantOwnedTables discovers tables by looking for a tenant column, so it
 * never saw the junction: not to delete it, and not to order around it. The
 * sweep deleted content_master's rows, InnoDB refused with
 *
 *     SQLSTATE[23000]: 1451 Cannot delete or update a parent row
 *
 * and the whole transaction unwound. The organization survived its own
 * deletion, every time, for a reason no amount of retrying would change.
 *
 * THE FIXTURE IS TWO TENANTS, for the same reason the main deletion suite's is:
 * the junction table must come out FILTERED, not EMPTIED. Every assertion that
 * tenant A's junction rows are gone is paired with one that tenant B's are
 * still there, because a fix that deletes the whole junction table would pass
 * the first assertion and destroy another organization's data.
 */
final class TenantDependentRowDeletionTest extends TestCase
{
    use \Tests\Support\SeedsEntityMappings;

    private const TENANT_A = '601';

    private const TENANT_B = '602';

    private const NAME_A = 'Fiber Valley';

    private const NAME_B = 'Scholar Clone';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSchema();
        $this->seedTenant(self::TENANT_A, self::NAME_A, 701);
        $this->seedTenant(self::TENANT_B, self::NAME_B, 702);

        $this->installEntityMappings([self::TENANT_A, self::TENANT_B]);
    }

    /* ───────────────────────── discovery ───────────────────────── */

    public function test_a_junction_table_with_no_tenant_column_is_discovered_as_a_dependent(): void
    {
        $tables     = app(TenantOwnedTables::class);
        $classified = $tables->classify(self::TENANT_A);
        $counted    = $tables->withCounts(self::TENANT_A, $classified);

        $dependents = $tables->dependentRows($counted);
        $names      = array_map(static fn ($d) => $d->table, $dependents);

        $this->assertContains(
            'content_mapping_type',
            $names,
            'The junction table hanging off content_master must be discovered, or the delete trips over it.',
        );
    }

    public function test_the_dependent_count_is_scoped_to_this_tenant_not_the_whole_table(): void
    {
        $tables     = app(TenantOwnedTables::class);
        $counted    = $tables->withCounts(self::TENANT_A, $tables->classify(self::TENANT_A));
        $dependents = $tables->dependentRowsWithCounts(self::TENANT_A, $tables->dependentRows($counted));

        $junction = null;
        foreach ($dependents as $d) {
            if ($d->table === 'content_mapping_type') {
                $junction = $d;
            }
        }

        $this->assertNotNull($junction);
        // 5 rows in the table: 2 for A, 3 for B.
        $this->assertSame(5, DB::table('content_mapping_type')->count());
        $this->assertSame(2, $junction->rows, 'Only the rows pointing at THIS tenant may be counted.');
    }

    public function test_the_plan_reports_dependents_and_includes_them_in_the_total(): void
    {
        $plan = app(TenantPurgeService::class)->plan(self::TENANT_A)->toArray();

        $this->assertArrayHasKey('dependents', $plan);

        $tables = array_column($plan['dependents'], 'table');
        $this->assertContains('content_mapping_type', $tables);

        $this->assertGreaterThanOrEqual(
            2,
            $plan['totals']['rows'],
            'Transitively-owned rows are destroyed, so the preview must count them.',
        );
    }

    /* ───────────────────────── deletion ───────────────────────── */

    public function test_the_purge_succeeds_where_it_previously_rolled_back(): void
    {
        $result = app(TenantPurgeService::class)->purge(self::TENANT_A, self::NAME_A, true, 'tester');

        $this->assertSame(self::TENANT_A, $result['tenantId']);

        $this->assertSame(
            0,
            DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count(),
            'The organization row must be gone — this is the assertion that failed before the fix.',
        );
        $this->assertSame(
            0,
            DB::table('content_master')->where('sub_institute_id', self::TENANT_A)->count(),
        );
    }

    public function test_the_tenants_junction_rows_are_deleted(): void
    {
        app(TenantPurgeService::class)->purge(self::TENANT_A, self::NAME_A, true, 'tester');

        $survivors = DB::table('content_mapping_type')
            ->whereIn('content_id', function ($q): void {
                $q->select('id')->from('content_master')->where('sub_institute_id', self::TENANT_A);
            })->count();

        $this->assertSame(0, $survivors);
    }

    public function test_the_other_organizations_junction_rows_are_untouched(): void
    {
        app(TenantPurgeService::class)->purge(self::TENANT_A, self::NAME_A, true, 'tester');

        $this->assertSame(
            3,
            DB::table('content_mapping_type')->count(),
            'The junction table is filtered, never emptied. Tenant B\'s three rows must remain.',
        );
        $this->assertSame(
            2,
            DB::table('content_master')->where('sub_institute_id', self::TENANT_B)->count(),
        );
    }

    public function test_the_deleted_report_names_the_junction_table(): void
    {
        $result = app(TenantPurgeService::class)->purge(self::TENANT_A, self::NAME_A, true, 'tester');

        $this->assertArrayHasKey(
            'content_mapping_type',
            $result['deleted'],
            'What was destroyed must be reported, including rows the plan reached indirectly.',
        );
        $this->assertSame(2, $result['deleted']['content_mapping_type']);
    }

    /* ───────────────────── cross-tenant protection ───────────────────── */

    public function test_a_row_owned_by_another_tenant_referencing_this_one_refuses_the_delete(): void
    {
        // Tenant B's row points at tenant A's content. Deleting it would destroy
        // B's data to let A's deletion through; leaving it makes the foreign key
        // refuse. The operation must stop and say which table.
        DB::table('content_reviews')->insert([
            'sub_institute_id' => self::TENANT_B,
            'content_id'       => $this->contentIdFor(self::TENANT_A),
            'note'             => 'shared review',
        ]);

        try {
            app(TenantPurgeService::class)->purge(self::TENANT_A, self::NAME_A, true, 'tester');
            $this->fail('A cross-tenant reference must refuse the deletion.');
        } catch (TenantDeletionException $e) {
            $this->assertSame('cross_tenant_reference', $e->reason);
            $this->assertSame(409, $e->status);
            $this->assertSame('content_reviews', $e->payload['conflicts'][0]['table']);
        }
    }

    public function test_nothing_is_deleted_when_a_cross_tenant_reference_refuses(): void
    {
        DB::table('content_reviews')->insert([
            'sub_institute_id' => self::TENANT_B,
            'content_id'       => $this->contentIdFor(self::TENANT_A),
            'note'             => 'shared review',
        ]);

        try {
            app(TenantPurgeService::class)->purge(self::TENANT_A, self::NAME_A, true, 'tester');
        } catch (TenantDeletionException) {
            // expected
        }

        $this->assertSame(
            1,
            DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count(),
            'A refusal must leave the organization intact.',
        );
        $this->assertSame(
            2,
            DB::table('content_master')->where('sub_institute_id', self::TENANT_A)->count(),
        );
        $this->assertSame(5, DB::table('content_mapping_type')->count());
    }

    public function test_a_tenants_own_rows_in_that_table_are_not_a_conflict(): void
    {
        // Same table, but the row belongs to the tenant being deleted. That is
        // ordinary owned data, swept by its own tenant scope — not a conflict.
        DB::table('content_reviews')->insert([
            'sub_institute_id' => self::TENANT_A,
            'content_id'       => $this->contentIdFor(self::TENANT_A),
            'note'             => 'own review',
        ]);

        $result = app(TenantPurgeService::class)->purge(self::TENANT_A, self::NAME_A, true, 'tester');

        $this->assertSame(0, DB::table('content_reviews')->where('sub_institute_id', self::TENANT_A)->count());
        $this->assertArrayHasKey('content_reviews', $result['deleted']);
    }

    /* ────────────── authorship is not ownership ────────────── */

    public function test_a_shared_lookup_row_authored_by_this_tenant_is_not_deleted(): void
    {
        // lms_mapping_type on the live database: 56 rows of LMS taxonomy shared
        // by every organization, carrying created_by/updated_by/deleted_by into
        // tbluser. Treating "points at a row this tenant owns" as ownership
        // would destroy all 56 because one school's administrator authored them.
        $before = DB::table('lms_mapping_type')->count();

        app(TenantPurgeService::class)->purge(self::TENANT_A, self::NAME_A, true, 'tester');

        $this->assertSame(
            $before,
            DB::table('lms_mapping_type')->count(),
            'Shared reference data must survive the deletion of a tenant whose user authored it.',
        );
    }

    public function test_the_authorship_pointer_is_cleared_rather_than_left_dangling(): void
    {
        $result = app(TenantPurgeService::class)->purge(self::TENANT_A, self::NAME_A, true, 'tester');

        $this->assertNull(
            DB::table('lms_mapping_type')->where('id', 1)->value('created_by'),
            'The reference to a deleted user must be nulled, or the foreign key still refuses.',
        );

        $this->assertArrayHasKey('lms_mapping_type.created_by', $result['dissociated']);
        $this->assertArrayNotHasKey(
            'lms_mapping_type',
            $result['deleted'],
            'A dissociated row was not deleted and must not be reported as though it were.',
        );
    }

    public function test_another_tenants_authorship_of_the_same_shared_row_is_untouched(): void
    {
        app(TenantPurgeService::class)->purge(self::TENANT_A, self::NAME_A, true, 'tester');

        $this->assertNotNull(
            DB::table('lms_mapping_type')->where('id', 2)->value('created_by'),
            'Only the deleted tenant\'s authorship is cleared.',
        );
    }

    public function test_mode_classification_separates_actors_from_owners(): void
    {
        $this->assertSame('dissociate', \App\Domain\Tenancy\TenantDependentRows::modeFor('created_by'));
        $this->assertSame('dissociate', \App\Domain\Tenancy\TenantDependentRows::modeFor('updated_by'));
        $this->assertSame('dissociate', \App\Domain\Tenancy\TenantDependentRows::modeFor('reviewed_by'));
        $this->assertSame('dissociate', \App\Domain\Tenancy\TenantDependentRows::modeFor('assigned_to'));

        // A real parent reference, not an actor.
        $this->assertSame('delete', \App\Domain\Tenancy\TenantDependentRows::modeFor('content_id'));
        $this->assertSame('delete', \App\Domain\Tenancy\TenantDependentRows::modeFor('user_id'));
    }

    /* ───────────────────────── fixture ───────────────────────── */

    private function contentIdFor(string $tenant): int
    {
        return (int) DB::table('content_master')->where('sub_institute_id', $tenant)->value('id');
    }

    private function buildSchema(): void
    {
        Schema::create('tblclient', function ($t): void {
            $t->integer('id')->primary();
            $t->string('client_name');
        });

        Schema::create('school_setup', function ($t): void {
            $t->string('id')->primary();
            $t->string('SchoolName');
            $t->integer('client_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('institute_detail', function ($t): void {
            $t->increments('pk');
            $t->string('sub_institute_id');
            $t->string('organization_name')->nullable();
            $t->string('organization_code')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        // The tenant-scoped parent.
        Schema::create('content_master', function ($t): void {
            $t->increments('id');
            $t->string('sub_institute_id');
            $t->string('title');
        });

        // The junction with NO tenant column — the shape that caused the bug.
        Schema::create('content_mapping_type', function ($t): void {
            $t->increments('id');
            $t->unsignedInteger('content_id');
            $t->integer('mapping_type_id')->default(0);
            $t->foreign('content_id')->references('id')->on('content_master');
        });

        // A child that DOES declare an owner. Used for the cross-tenant case.
        Schema::create('content_reviews', function ($t): void {
            $t->increments('id');
            $t->string('sub_institute_id');
            $t->unsignedInteger('content_id');
            $t->string('note')->nullable();
            $t->foreign('content_id')->references('id')->on('content_master');
        });

        // Users, so an authorship edge into a tenant-scoped table exists.
        Schema::create('tbluser', function ($t): void {
            $t->increments('id');
            $t->string('sub_institute_id');
            $t->string('email')->nullable();
            $t->integer('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });

        // SHARED reference data, owned by nobody, authored by whoever touched
        // it last. The shape of hp_erp's lms_mapping_type.
        Schema::create('lms_mapping_type', function ($t): void {
            $t->increments('id');
            $t->string('name');
            $t->unsignedInteger('created_by')->nullable();
            $t->foreign('created_by')->references('id')->on('tbluser');
        });

        Schema::create('hpbrain_audit_logs', function ($t): void {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('entity_type');
            $t->string('entity_id');
            $t->string('action');
            $t->string('actor_id')->nullable();
            $t->string('actor_name')->nullable();
            $t->text('changes')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    private function seedTenant(string $tenant, string $name, int $clientId): void
    {
        DB::table('tblclient')->insert(['id' => $clientId, 'client_name' => $name]);

        DB::table('school_setup')->insert([
            'id' => $tenant, 'SchoolName' => $name, 'client_id' => $clientId,
        ]);

        DB::table('institute_detail')->insert([
            'sub_institute_id'  => $tenant,
            'organization_name' => $name,
            'organization_code' => strtoupper(substr($name, 0, 4)),
        ]);

        // Two content rows each; A gets two junction rows, B gets three, so a
        // fix that empties the table instead of filtering it is caught.
        $ids = [];
        foreach (['Fibre splicing', 'Cable routing'] as $title) {
            $ids[] = DB::table('content_master')->insertGetId([
                'sub_institute_id' => $tenant, 'title' => $title,
            ]);
        }

        $rows = $tenant === self::TENANT_A ? 2 : 3;

        for ($i = 0; $i < $rows; $i++) {
            DB::table('content_mapping_type')->insert([
                'content_id' => $ids[$i % count($ids)], 'mapping_type_id' => $i,
            ]);
        }

        $userId = DB::table('tbluser')->insertGetId([
            'sub_institute_id' => $tenant,
            'email'            => 'admin@'.strtolower(str_replace(' ', '', $name)).'.test',
        ]);

        // One row of SHARED taxonomy per tenant, authored by that tenant's user.
        // Row 1 is authored by A (whose deletion must clear the pointer without
        // removing the row); row 2 by B (which must not be touched at all).
        DB::table('lms_mapping_type')->insert([
            'name' => $name.' taxonomy', 'created_by' => $userId,
        ]);
    }
}
