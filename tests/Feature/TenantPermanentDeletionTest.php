<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantOwnedTables;
use App\Domain\Tenancy\TenantPurgeService;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Permanent tenant deletion, end to end.
 *
 * THE FIXTURE IS TWO TENANTS AND THAT IS THE WHOLE POINT. Every assertion that
 * something was destroyed is paired with the same assertion inverted against
 * the other tenant, because a deletion that removes too much is a far worse
 * defect than one that removes too little — and a single-tenant fixture cannot
 * tell the two apart. Tenant A is deleted; tenant B is checked, table by table,
 * to be untouched and still able to sign in.
 *
 * The fixture also carries platform-scoped rows (tenant_id 'platform') in the
 * tables that hold them on the live database, so the tests prove the shipped
 * signal rules and industries survive an organization being destroyed.
 */
final class TenantPermanentDeletionTest extends TestCase
{
    use \Tests\Support\SeedsEntityMappings;

    private const TENANT_A = '501';

    private const TENANT_B = '502';

    private const NAME_A = 'Sunrise International School';

    private const NAME_B = 'Lions School';

    private const EMAIL_A = 'admin@sunrise.test';

    private const EMAIL_B = 'admin@lions.test';

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildErpTables();
        $this->buildBrainTables();

        $this->seedTenant(self::TENANT_A, self::NAME_A, self::EMAIL_A);
        $this->seedTenant(self::TENANT_B, self::NAME_B, self::EMAIL_B);
        $this->seedPlatformRows();

        $this->installEntityMappings([self::TENANT_A, self::TENANT_B]);
    }

    /* ─────────────────────────── TEST 1 — organization deletion ─────────────────────────── */

    public function test_1_organization_is_permanently_deleted_and_tenant_no_longer_resolves(): void
    {
        $this->deleteTenantA()->assertOk()->assertJson(['ok' => true]);

        // Not soft-deleted. GONE.
        $this->assertDatabaseMissing('institute_detail', ['sub_institute_id' => self::TENANT_A]);
        $this->assertSame(0, DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count());

        // The tenant root itself, which the archive never touched.
        $this->assertSame(0, DB::table('school_setup')->where('id', self::TENANT_A)->count());
        $this->assertSame(0, DB::table('hpbrain_tenants')->where('id', self::TENANT_A)->count());

        // Tenant resolution: with no mapping rows the resolver reports the
        // tenant as having no entities at all, which is what makes every
        // tenant-scoped query for it fail closed rather than read someone else's
        // tables.
        $resolver = app(\App\Domain\Universal\EntityResolver::class);
        $resolver->flush();

        $this->assertFalse($resolver->has(self::TENANT_A, 'Organization'));
        $this->assertFalse($resolver->has(self::TENANT_A, 'Person'));
        $this->assertSame([], $resolver->mappedEntities(self::TENANT_A));
    }

    /* ─────────────────────────── TEST 2 — tenant data deletion ─────────────────────────── */

    public function test_2_every_tenant_owned_record_is_deleted_not_just_the_organization_row(): void
    {
        // Sanity: the fixture really does hold data across the graph.
        foreach ($this->tenantOwnedTables() as $table => $column) {
            $this->assertGreaterThan(
                0,
                DB::table($table)->where($column, self::TENANT_A)->count(),
                "fixture should have seeded {$table} for tenant A",
            );
        }

        $this->deleteTenantA()->assertOk();

        foreach ($this->tenantOwnedTables() as $table => $column) {
            $this->assertSame(
                0,
                DB::table($table)->where($column, self::TENANT_A)->count(),
                "{$table} still holds rows for the deleted tenant",
            );
        }
    }

    public function test_2b_response_reports_what_was_actually_deleted(): void
    {
        $body = $this->deleteTenantA()->assertOk()->json();

        $this->assertTrue($body['ok']);
        $this->assertSame(self::NAME_A, $body['organizationName']);
        $this->assertGreaterThan(10, $body['tables']);
        $this->assertGreaterThan(20, $body['rows']);

        // Named per table, so the figure is auditable rather than a bare total.
        $this->assertArrayHasKey('hpbrain_signals', $body['deleted']);
        $this->assertArrayHasKey('tbluser', $body['deleted']);
        $this->assertArrayHasKey('institute_detail', $body['deleted']);
    }

    /* ─────────────────────────── TEST 3 — authentication deletion ─────────────────────────── */

    public function test_3_login_with_the_deleted_organizations_credentials_fails(): void
    {
        // Proves the credentials worked BEFORE deletion, or the test after it
        // proves nothing.
        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL_A, 'password' => self::PASSWORD,
        ])->assertOk()->assertJsonStructure(['accessToken']);

        $this->deleteTenantA()->assertOk();

        app(\App\Domain\Universal\EntityResolver::class)->flush();

        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL_A, 'password' => self::PASSWORD,
        ])->assertStatus(401)->assertJson(['error' => 'invalid_credentials']);

        // All three mechanisms that kept a "deleted" organization signed in.
        $this->assertSame(0, DB::table('tbluser')->where('sub_institute_id', self::TENANT_A)->count());
        $this->assertSame(0, DB::table('hpbrain_entity_mappings')->where('tenant_id', self::TENANT_A)->count());
        $this->assertSame(0, DB::table('hpbrain_refresh_tokens')->where('tenant_id', self::TENANT_A)->count());
    }

    public function test_3b_a_refresh_token_issued_before_deletion_no_longer_grants_access(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL_A, 'password' => self::PASSWORD,
        ])->assertOk()->json();

        $this->deleteTenantA()->assertOk();
        app(\App\Domain\Universal\EntityResolver::class)->flush();

        // The stored token row is gone, so the tenant cannot be read back out of
        // the token store, and the organization it names no longer exists.
        $this->assertSame(0, DB::table('hpbrain_refresh_tokens')->where('tenant_id', self::TENANT_A)->count());

        // And the access token it already held resolves to nothing.
        $this->getJson('/api/v1/organizations/'.self::TENANT_A, [
            'Authorization' => 'Bearer '.$login['accessToken'],
        ])->assertStatus(404);
    }

    /* ─────────────────────────── TEST 4 — old tenant id / URL ─────────────────────────── */

    public function test_4_requests_for_the_deleted_tenant_id_no_longer_return_its_data(): void
    {
        $this->deleteTenantA()->assertOk();
        app(\App\Domain\Universal\EntityResolver::class)->flush();

        // A token minted for the dead tenant. EnsureTenantScope still admits it
        // — the token is validly signed — and the resolver then fails closed,
        // which is the correct layering: authentication did not change.
        $token = $this->tokenFor(self::TENANT_A);

        $this->getJson('/api/v1/organizations/'.self::TENANT_A, [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(404);

        $this->getJson('/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A, [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(404);
    }

    public function test_4b_a_second_deletion_of_the_same_tenant_reports_not_found(): void
    {
        $this->deleteTenantA()->assertOk();
        app(\App\Domain\Universal\EntityResolver::class)->flush();

        $this->deleteTenantA()->assertStatus(404)->assertJson(['error' => 'organization_not_found']);
    }

    /* ─────────────────────────── TEST 5 — tenant isolation ─────────────────────────── */

    public function test_5_the_other_organization_is_completely_untouched(): void
    {
        $before = $this->snapshotOf(self::TENANT_B);

        $this->deleteTenantA()->assertOk();

        $this->assertSame($before, $this->snapshotOf(self::TENANT_B), 'tenant B row counts changed');

        // Its organization, its people, its root and its mappings all survive.
        $this->assertSame(1, DB::table('institute_detail')->where('sub_institute_id', self::TENANT_B)->count());
        $this->assertSame(1, DB::table('school_setup')->where('id', self::TENANT_B)->count());
        $this->assertSame(
            self::NAME_B,
            DB::table('institute_detail')->where('sub_institute_id', self::TENANT_B)->value('organization_name'),
        );
    }

    public function test_5b_the_other_organization_can_still_log_in_and_read_its_data(): void
    {
        $this->deleteTenantA()->assertOk();
        app(\App\Domain\Universal\EntityResolver::class)->flush();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL_B, 'password' => self::PASSWORD,
        ])->assertOk()->json();

        $this->assertSame(self::TENANT_B, $login['organization']['id']);
        $this->assertSame(self::NAME_B, $login['organization']['name']);

        $rows = $this->getJson('/api/v1/organizations/'.self::TENANT_B, [
            'Authorization' => 'Bearer '.$login['accessToken'],
        ])->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertSame(self::NAME_B, $rows[0]['name']);
    }

    public function test_5c_shared_and_platform_owned_rows_survive(): void
    {
        $this->deleteTenantA()->assertOk();

        // The shipped rule definitions every tenant reads.
        $this->assertSame(1, DB::table('hpbrain_signal_rules')->where('tenant_id', 'platform')->count());
        $this->assertSame(1, DB::table('hpbrain_industries')->where('tenant_id', 'platform')->count());

        // A table with no tenant column at all is never addressed.
        $this->assertSame(2, DB::table('shared_lookup')->count());
    }

    public function test_5d_a_client_shared_with_another_organization_is_preserved(): void
    {
        // Re-point tenant B at tenant A's billing client, so the client is
        // genuinely shared between two organizations.
        DB::table('school_setup')->where('id', self::TENANT_B)->update(['client_id' => 901]);

        $this->deleteTenantA()->assertOk();

        $this->assertSame(1, DB::table('tblclient')->where('id', 901)->count(),
            'a client still owning another organization must not be deleted');
    }

    public function test_5e_a_client_owned_only_by_the_deleted_organization_is_removed(): void
    {
        $this->deleteTenantA()->assertOk();

        $this->assertSame(0, DB::table('tblclient')->where('id', 901)->count());
        $this->assertSame(1, DB::table('tblclient')->where('id', 902)->count(), "tenant B's client survives");
    }

    /* ─────────────────────────── TEST 6 — authorization ─────────────────────────── */

    public function test_6_a_non_admin_role_cannot_permanently_delete_an_organization(): void
    {
        foreach (['viewer', 'analyst', 'manager', 'member'] as $role) {
            $this->deleteAs(self::TENANT_A, $role)
                ->assertStatus(403);

            $this->assertSame(
                1,
                DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count(),
                "role {$role} must not have deleted anything",
            );
        }
    }

    public function test_6b_a_user_from_another_tenant_cannot_delete_this_one(): void
    {
        // A real tenant_admin — of tenant B — aiming at tenant A.
        $this->json('DELETE', '/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A, [
            'confirmName' => self::NAME_A,
        ], ['Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_B)])
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);

        $this->assertSame(1, DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count());
        $this->assertGreaterThan(0, DB::table('hpbrain_signals')->where('tenant_id', self::TENANT_A)->count());
    }

    public function test_6c_an_unauthenticated_request_is_rejected(): void
    {
        $this->json('DELETE', '/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A, [
            'confirmName' => self::NAME_A,
        ])->assertStatus(401);

        $this->assertSame(1, DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count());
    }

    public function test_6d_a_reserved_platform_tenant_id_cannot_be_deleted(): void
    {
        $this->json('DELETE', '/api/v1/organizations/platform/platform', [
            'confirmName' => 'platform',
        ], ['Authorization' => 'Bearer '.$this->tokenFor('platform')])
            ->assertStatus(422)
            ->assertJson(['error' => 'reserved_tenant_id']);

        $this->assertSame(1, DB::table('hpbrain_signal_rules')->where('tenant_id', 'platform')->count());
    }

    /* ─────────────────────────── TEST 7 — transaction rollback ─────────────────────────── */

    public function test_7_a_failure_part_way_through_rolls_the_entire_deletion_back(): void
    {
        $before = $this->snapshotOf(self::TENANT_A);

        // A controlled, genuine mid-transaction failure: the engine itself
        // refuses one of the deletes. Chosen over mocking the service because it
        // exercises the real DB::transaction boundary rather than a stand-in for
        // it — the whole claim under test is that the database unwinds.
        DB::statement(
            'CREATE TRIGGER block_signal_delete BEFORE DELETE ON hpbrain_signals
             BEGIN SELECT RAISE(ABORT, "controlled failure for rollback test"); END'
        );

        try {
            $this->deleteTenantA()->assertStatus(500)->assertJson(['error' => 'deletion_failed']);
        } finally {
            DB::statement('DROP TRIGGER block_signal_delete');
        }

        // EVERYTHING is still there — not just the organization row.
        $this->assertSame($before, $this->snapshotOf(self::TENANT_A), 'tenant A data was not fully restored');

        $this->assertSame(1, DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count());
        $this->assertSame(1, DB::table('school_setup')->where('id', self::TENANT_A)->count());
    }

    public function test_7b_login_still_works_after_a_rolled_back_deletion(): void
    {
        DB::statement(
            'CREATE TRIGGER block_signal_delete BEFORE DELETE ON hpbrain_signals
             BEGIN SELECT RAISE(ABORT, "controlled failure"); END'
        );

        try {
            $this->deleteTenantA()->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER block_signal_delete');
        }

        app(\App\Domain\Universal\EntityResolver::class)->flush();

        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL_A, 'password' => self::PASSWORD,
        ])->assertOk();
    }

    /* ─────────────────────────── TEST 8 — exact-name confirmation ─────────────────────────── */

    public function test_8_deletion_requires_the_exact_organization_name(): void
    {
        // Surrounding whitespace is deliberately absent from this list: Laravel's
        // global TrimStrings middleware strips it from the request body before
        // the controller runs, so ' Name' and 'Name' are the same input by the
        // time anything here can compare them. Every difference that is NOT
        // outer whitespace — a different name, different case, a truncation —
        // must be refused, and that is what is asserted.
        foreach ([
            'wrong name',
            strtolower(self::NAME_A),
            strtoupper(self::NAME_A),
            substr(self::NAME_A, 0, -1),
            self::NAME_A.'x',
            self::NAME_B,
            '',
        ] as $attempt) {
            $response = $this->json('DELETE', '/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A, [
                'confirmName' => $attempt,
            ], ['Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A)]);

            // An empty string fails validation; anything else fails the compare.
            $this->assertContains($response->status(), [422], "'{$attempt}' should not have been accepted");

            $this->assertSame(
                1,
                DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count(),
                "confirmName '{$attempt}' must not have deleted anything",
            );
        }

        // And the exact name does work.
        $this->deleteTenantA()->assertOk();
    }

    public function test_8b_missing_confirm_name_is_a_validation_error(): void
    {
        $this->json('DELETE', '/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A, [], [
            'Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A),
        ])->assertStatus(422);

        $this->assertSame(1, DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count());
    }

    /* ─────────────────────────── TEST 9 — not the archive ─────────────────────────── */

    public function test_9_permanent_deletion_is_a_different_endpoint_from_archive(): void
    {
        // The archive endpoint still exists and still does exactly what it did:
        // sets deleted_at on one row and destroys nothing.
        $this->postJson('/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A.'/archive', [], [
            'Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A),
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count(),
            'archive must still be a soft delete');
        $this->assertNotNull(
            DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->value('deleted_at'),
        );
        $this->assertGreaterThan(0, DB::table('hpbrain_signals')->where('tenant_id', self::TENANT_A)->count(),
            'archive must not delete tenant data');
        $this->assertGreaterThan(0, DB::table('tbluser')->where('sub_institute_id', self::TENANT_A)->count(),
            'archive must not delete logins');
    }

    public function test_9b_an_already_archived_organization_can_still_be_permanently_deleted(): void
    {
        $this->postJson('/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A.'/archive', [], [
            'Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A),
        ])->assertOk();

        // Archiving first must not make the organization unreachable to the real
        // delete, or the archive becomes a trap.
        $this->deleteTenantA()->assertOk();

        $this->assertSame(0, DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count());
        $this->assertSame(0, DB::table('tbluser')->where('sub_institute_id', self::TENANT_A)->count());
    }

    /* ─────────────── canonical name for an ARCHIVED organization ─────────────── */

    /**
     * The defect this class of test exists for.
     *
     * An organization archived through the old flow kept its real name in
     * institute_detail, but login reported a manufactured "Organization {id}"
     * because loadOrganization() filtered on deleted_at. The SPA stored that
     * placeholder, showed it in the delete dialog, and compared against it —
     * while the server compared against the real name. The confirmation was
     * unsatisfiable: no string the administrator could type would ever match.
     */
    public function test_login_reports_the_real_name_of_an_archived_organization(): void
    {
        $this->postJson('/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A.'/archive', [], [
            'Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A),
        ])->assertOk();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL_A, 'password' => self::PASSWORD,
        ])->assertOk()->json();

        $this->assertSame(self::NAME_A, $login['organization']['name']);
        $this->assertNotSame('Organization '.self::TENANT_A, $login['organization']['name']);
    }

    public function test_preview_and_login_agree_on_the_name_of_an_archived_organization(): void
    {
        $this->postJson('/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A.'/archive', [], [
            'Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A),
        ])->assertOk();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL_A, 'password' => self::PASSWORD,
        ])->assertOk()->json();

        $preview = $this->getJson(
            '/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A.'/deletion-preview',
            ['Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A)],
        )->assertOk()->json();

        // One canonical identity: same name, same tenant, from both endpoints.
        $this->assertSame($login['organization']['name'], $preview['organizationName']);
        $this->assertSame($login['organization']['id'], $preview['tenantId']);
    }

    /**
     * The canonical name is what the confirmation accepts, and the placeholder
     * that used to be displayed is refused — which is the correct outcome, and
     * the reason the fix is not "make the comparison permissive".
     */
    public function test_an_archived_organization_is_deletable_using_its_canonical_name(): void
    {
        $this->postJson('/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A.'/archive', [], [
            'Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A),
        ])->assertOk();

        // The old manufactured name must NOT work.
        $this->json('DELETE', '/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A, [
            'confirmName' => 'Organization '.self::TENANT_A,
        ], ['Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A)])
            ->assertStatus(422)
            ->assertJson(['error' => 'confirmation_mismatch']);

        $this->assertSame(1, DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count());

        // The canonical name does.
        $this->deleteTenantA()->assertOk();
        $this->assertSame(0, DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count());
    }

    public function test_preview_for_an_organization_that_does_not_exist_is_a_404(): void
    {
        DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->delete();

        $this->getJson(
            '/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A.'/deletion-preview',
            ['Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A)],
        )->assertStatus(404)->assertJson(['error' => 'organization_not_found']);
    }

    /* ─────────────────────────── preview + source-system gate ─────────────────────────── */

    public function test_preview_reports_the_plan_without_deleting_anything(): void
    {
        $body = $this->getJson(
            '/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A.'/deletion-preview',
            ['Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A)],
        )->assertOk()->json();

        $this->assertSame(self::TENANT_A, $body['tenantId']);
        $this->assertSame(self::NAME_A, $body['organizationName']);
        $this->assertGreaterThan(0, $body['totals']['rows']);
        $this->assertGreaterThan(0, $body['totals']['brain']);
        $this->assertGreaterThan(0, $body['totals']['identity']);

        // Nothing was written.
        $this->assertSame(1, DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count());
        $this->assertGreaterThan(0, DB::table('hpbrain_signals')->where('tenant_id', self::TENANT_A)->count());
    }

    public function test_rows_owned_by_another_application_block_the_delete_until_acknowledged(): void
    {
        // A table belonging to a different application sharing this database:
        // tenant-scoped, but not hpbrain_ and not an entity the Brain maps.
        Schema::create('lms_course_enroll', function ($t) {
            $t->increments('id');
            $t->string('sub_institute_id');
            $t->string('course');
        });

        DB::table('lms_course_enroll')->insert([
            ['sub_institute_id' => self::TENANT_A, 'course' => 'Physics'],
            ['sub_institute_id' => self::TENANT_B, 'course' => 'Chemistry'],
        ]);

        $blocked = $this->deleteTenantA()->assertStatus(409)->json();

        $this->assertSame('source_system_data_present', $blocked['error']);
        $this->assertSame(1, $blocked['rows']);
        $this->assertSame('lms_course_enroll', $blocked['tables'][0]['table']);

        // Refused means refused: nothing at all was destroyed.
        $this->assertSame(1, DB::table('institute_detail')->where('sub_institute_id', self::TENANT_A)->count());
        $this->assertGreaterThan(0, DB::table('hpbrain_signals')->where('tenant_id', self::TENANT_A)->count());

        // Acknowledged, it proceeds — and still only for tenant A.
        $this->json('DELETE', '/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A, [
            'confirmName'                 => self::NAME_A,
            'acknowledgeSourceSystemData' => true,
        ], ['Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A)])->assertOk();

        $this->assertSame(0, DB::table('lms_course_enroll')->where('sub_institute_id', self::TENANT_A)->count());
        $this->assertSame(1, DB::table('lms_course_enroll')->where('sub_institute_id', self::TENANT_B)->count());
    }

    /**
     * The ONE row deliberately left behind, and the only one.
     *
     * Section 3 of the specification lists "tenant-specific audit/activity
     * records" among the things to destroy, and every other audit row for this
     * tenant IS destroyed — hpbrain_audit_logs is swept like any other Brain
     * table. What survives is a single record written after the sweep, inside
     * the same transaction, saying that the organization was deleted and what
     * went with it.
     *
     * That is a deliberate departure from a literal reading of the spec, and it
     * is asserted here rather than left as an accident: an organization
     * disappearing from a shared production database with no trace of who
     * removed it, when, or how much went, is a worse outcome than one retained
     * row. Deleting this row too is a one-line change in
     * TenantPurgeService::recordAudit if that trade is not wanted.
     */
    public function test_the_only_surviving_row_is_the_deletion_audit_record(): void
    {
        $this->deleteTenantA()->assertOk();

        foreach ($this->tenantOwnedTables() as $table => $column) {
            $this->assertSame(0, DB::table($table)->where($column, self::TENANT_A)->count(), $table);
        }

        $audit = DB::table('hpbrain_audit_logs')->where('tenant_id', self::TENANT_A)->get();

        $this->assertCount(1, $audit, 'exactly one audit row must survive');
        $this->assertSame('organization.permanently_deleted', $audit[0]->action);

        // The pre-existing audit rows for this tenant were destroyed with
        // everything else — only the record OF the deletion is left.
        $this->assertSame(0, DB::table('hpbrain_audit_logs')
            ->where('tenant_id', self::TENANT_A)
            ->where('action', 'organization.created')
            ->count());
    }

    public function test_the_deletion_is_recorded_in_the_audit_log(): void
    {
        $this->deleteTenantA()->assertOk();

        $row = DB::table('hpbrain_audit_logs')
            ->where('tenant_id', self::TENANT_A)
            ->where('action', 'organization.permanently_deleted')
            ->first();

        $this->assertNotNull($row, 'the deletion must leave a record of itself');

        $changes = json_decode((string) $row->changes, true);
        $this->assertSame(self::NAME_A, $changes['organizationName']);
        $this->assertGreaterThan(0, $changes['rows']);
    }

    public function test_reserved_tenant_ids_are_recognised(): void
    {
        foreach (['platform', '*', 'PLATFORM', 'global', 'system', ''] as $reserved) {
            $this->assertTrue(TenantOwnedTables::isReserved($reserved), "'{$reserved}' should be reserved");
        }

        foreach (['1', '501', '1000000'] as $ordinary) {
            $this->assertFalse(TenantOwnedTables::isReserved($ordinary));
        }
    }

    public function test_the_plan_never_includes_a_table_without_a_tenant_scope(): void
    {
        $plan = app(TenantPurgeService::class)->plan(self::TENANT_A);

        $this->assertNotEmpty($plan->tables);

        foreach ($plan->tables as $table) {
            $this->assertNotSame('', $table->tenantColumn, "{$table->table} has no tenant column");
            $this->assertTrue(
                Schema::hasColumn($table->table, $table->tenantColumn),
                "{$table->table}.{$table->tenantColumn} does not exist",
            );
        }

        $names = array_map(static fn ($t) => $t->table, $plan->tables);
        $this->assertNotContains('shared_lookup', $names);
        $this->assertNotContains('hpbrain_schema_migrations', $names);
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function deleteTenantA(): \Illuminate\Testing\TestResponse
    {
        return $this->json('DELETE', '/api/v1/organizations/'.self::TENANT_A.'/'.self::TENANT_A, [
            'confirmName' => self::NAME_A,
        ], ['Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A)]);
    }

    private function deleteAs(string $tenant, string $role): \Illuminate\Testing\TestResponse
    {
        return $this->json('DELETE', '/api/v1/organizations/'.$tenant.'/'.$tenant, [
            'confirmName' => self::NAME_A,
        ], ['Authorization' => 'Bearer '.$this->tokenFor($tenant, $role)]);
    }

    private function tokenFor(string $tenant, string $role = 'tenant_admin'): string
    {
        return Jwt::issueAccess(['id' => '9001', 'tenantId' => $tenant, 'role' => $role]);
    }

    /**
     * Row counts for every tenant-scoped table, for one tenant.
     *
     * @return array<string, int>
     */
    private function snapshotOf(string $tenant): array
    {
        $out = [];

        foreach ($this->tenantOwnedTables() as $table => $column) {
            $out[$table] = DB::table($table)->where($column, $tenant)->count();
        }

        return $out;
    }

    /**
     * Every table the fixture seeds per tenant, and the column that scopes it.
     *
     * Written out rather than discovered, deliberately: this is the ASSERTION
     * side of the test, and if it read the same discovery code the
     * implementation uses, a bug in discovery would make the test agree with it.
     *
     * @return array<string, string>
     */
    private function tenantOwnedTables(): array
    {
        return [
            'institute_detail'          => 'sub_institute_id',
            'org_details'               => 'sub_institute_id',
            'hrms_departments'          => 'sub_institute_id',
            'hrms_job_titles'           => 'sub_institute_id',
            'tbluser'                   => 'sub_institute_id',
            'tbluserprofilemaster'      => 'sub_institute_id',
            'school_setup'              => 'id',
            'hpbrain_tenants'           => 'id',
            'hpbrain_organizations'     => 'tenant_id',
            'hpbrain_entity_mappings'   => 'tenant_id',
            'hpbrain_refresh_tokens'    => 'tenant_id',
            'hpbrain_signals'           => 'tenant_id',
            'hpbrain_evidence'          => 'tenant_id',
            'hpbrain_cases'             => 'tenant_id',
            'hpbrain_operational_records' => 'tenant_id',
            'hpbrain_import_jobs'       => 'tenant_id',
            'hpbrain_capabilities'      => 'tenant_id',
            'hpbrain_decisions'         => 'tenant_id',
            'hpbrain_terminology'       => 'tenant_id',
            'hpbrain_signal_rules'      => 'tenant_id',
        ];
    }

    /* ─────────────────────────── fixture ─────────────────────────── */

    private function buildErpTables(): void
    {
        Schema::create('tblclient', function ($t) {
            $t->integer('id')->primary();
            $t->string('client_name');
        });

        Schema::create('school_setup', function ($t) {
            $t->string('id')->primary();
            $t->string('SchoolName');
            $t->integer('client_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('institute_detail', function ($t) {
            $t->increments('pk');
            $t->string('sub_institute_id');
            $t->string('organization_name')->nullable();
            $t->string('organization_code')->nullable();
            $t->string('industry_type')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('org_details', function ($t) {
            $t->increments('id');
            $t->string('sub_institute_id');
            $t->string('legal_name')->nullable();
            $t->string('logo')->nullable();
        });

        Schema::create('hrms_departments', function ($t) {
            $t->increments('id');
            $t->string('sub_institute_id');
            $t->string('department');
            $t->text('roles_responsibility')->nullable();
            $t->integer('parent_id')->default(0);
            $t->integer('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('hrms_job_titles', function ($t) {
            $t->increments('id');
            $t->string('sub_institute_id');
            $t->string('title');
            $t->integer('is_active')->default(1);
        });

        Schema::create('tbluserprofilemaster', function ($t) {
            $t->increments('id');
            $t->string('sub_institute_id');
            $t->string('name');
            $t->integer('status')->default(1);
        });

        Schema::create('tbluser', function ($t) {
            $t->increments('id');
            $t->string('sub_institute_id');
            $t->string('employee_no')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('mobile')->nullable();
            $t->string('gender')->nullable();
            $t->string('password')->nullable();
            $t->string('plain_password')->nullable();
            $t->integer('department_id')->nullable();
            $t->integer('jobtitle_id')->nullable();
            $t->integer('user_profile_id')->nullable();
            $t->date('joined_date')->nullable();
            $t->integer('status')->default(1);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        // No tenant column anywhere: must never be addressed by the sweep.
        Schema::create('shared_lookup', function ($t) {
            $t->increments('id');
            $t->string('code');
        });

        DB::table('shared_lookup')->insert([['code' => 'A'], ['code' => 'B']]);
    }

    private function buildBrainTables(): void
    {
        $simple = [
            'hpbrain_organizations', 'hpbrain_signals', 'hpbrain_evidence', 'hpbrain_cases',
            'hpbrain_operational_records', 'hpbrain_import_jobs', 'hpbrain_capabilities',
            'hpbrain_decisions', 'hpbrain_terminology', 'hpbrain_industries',
        ];

        foreach ($simple as $table) {
            Schema::create($table, function ($t) {
                $t->increments('id');
                $t->string('tenant_id', 36);
                $t->string('name')->nullable();
                $t->timestamp('created_date')->nullable();
            });
        }

        Schema::create('hpbrain_signal_rules', function ($t) {
            $t->increments('id');
            $t->string('tenant_id', 36);
            $t->string('rule_key');
        });

        Schema::create('hpbrain_tenants', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('name');
            $t->string('status')->default('active');
        });

        Schema::create('hpbrain_refresh_tokens', function ($t) {
            $t->string('jti', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('user_id', 36);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('hpbrain_event_store', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('type');
            $t->string('tenant_id', 36);
            $t->string('entity_type');
            $t->string('entity_id', 36);
            $t->string('actor_id', 36);
            $t->text('payload');
            $t->text('metadata')->nullable();
            $t->string('correlation_id', 36)->nullable();
            $t->string('causation_id', 36)->nullable();
            $t->string('idempotency_key', 36)->nullable()->unique();
            $t->string('status')->default('pending');
            $t->integer('retry_count')->default(0);
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('hpbrain_audit_logs', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('entity_type');
            $t->string('entity_id', 36);
            $t->string('action');
            $t->string('actor_id', 36);
            $t->string('actor_name');
            $t->text('changes')->nullable();
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        // A Brain table with NO tenant column — the sweep must skip it rather
        // than delete every row in it.
        Schema::create('hpbrain_schema_migrations', function ($t) {
            $t->increments('id');
            $t->string('migration');
        });

        DB::table('hpbrain_schema_migrations')->insert([['migration' => 'initial']]);
    }

    private function seedTenant(string $tenant, string $name, string $email): void
    {
        $clientId = $tenant === self::TENANT_A ? 901 : 902;

        DB::table('tblclient')->insert(['id' => $clientId, 'client_name' => $name]);

        DB::table('school_setup')->insert([
            'id' => $tenant, 'SchoolName' => $name, 'client_id' => $clientId,
        ]);

        DB::table('institute_detail')->insert([
            'sub_institute_id' => $tenant, 'organization_name' => $name,
            'organization_code' => strtoupper(substr($name, 0, 4)), 'industry_type' => 'Education',
        ]);

        DB::table('org_details')->insert([
            'sub_institute_id' => $tenant, 'legal_name' => $name.' Ltd', 'logo' => null,
        ]);

        DB::table('hrms_departments')->insert([
            ['sub_institute_id' => $tenant, 'department' => 'Science', 'parent_id' => 0, 'status' => 1],
            ['sub_institute_id' => $tenant, 'department' => 'Admin', 'parent_id' => 1, 'status' => 1],
        ]);

        DB::table('hrms_job_titles')->insert([
            'sub_institute_id' => $tenant, 'title' => 'Teacher', 'is_active' => 1,
        ]);

        $profileId = DB::table('tbluserprofilemaster')->insertGetId([
            'sub_institute_id' => $tenant, 'name' => 'Admin', 'status' => 1,
        ]);

        DB::table('tbluser')->insert([
            'sub_institute_id' => $tenant, 'employee_no' => 'E1', 'first_name' => 'Administrator',
            'last_name' => '', 'email' => $email, 'password' => Hash::make(self::PASSWORD),
            'plain_password' => null, 'department_id' => 1, 'jobtitle_id' => 1,
            'user_profile_id' => $profileId, 'status' => 1,
        ]);

        DB::table('tbluser')->insert([
            'sub_institute_id' => $tenant, 'employee_no' => 'E2', 'first_name' => 'Staff',
            'last_name' => 'Member', 'email' => 'staff.'.$tenant.'@test.local',
            'password' => Hash::make(self::PASSWORD), 'plain_password' => null,
            'department_id' => 1, 'jobtitle_id' => 1, 'user_profile_id' => $profileId, 'status' => 1,
        ]);

        DB::table('hpbrain_tenants')->insert(['id' => $tenant, 'name' => $name, 'status' => 'active']);

        foreach ([
            'hpbrain_organizations', 'hpbrain_signals', 'hpbrain_evidence', 'hpbrain_cases',
            'hpbrain_operational_records', 'hpbrain_import_jobs', 'hpbrain_capabilities',
            'hpbrain_decisions', 'hpbrain_terminology',
        ] as $table) {
            DB::table($table)->insert([
                ['tenant_id' => $tenant, 'name' => $table.'-1'],
                ['tenant_id' => $tenant, 'name' => $table.'-2'],
            ]);
        }

        DB::table('hpbrain_signal_rules')->insert([
            'tenant_id' => $tenant, 'rule_key' => 'tenant-local-rule',
        ]);

        DB::table('hpbrain_refresh_tokens')->insert([
            'jti' => 'jti-'.$tenant, 'tenant_id' => $tenant, 'user_id' => '1',
            'expires_at' => now()->addDay()->format('Y-m-d H:i:s'), 'revoked_at' => null,
        ]);

        DB::table('hpbrain_audit_logs')->insert([
            'id' => 'audit-'.$tenant, 'tenant_id' => $tenant, 'entity_type' => 'Organization',
            'entity_id' => $tenant, 'action' => 'organization.created', 'actor_id' => 'system',
            'actor_name' => 'system', 'changes' => '{}', 'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Platform-scoped rows: the shipped rule definitions and industries every
     * tenant reads. They live in tenant-scoped tables under a reserved tenant
     * id, exactly as they do on the live database.
     */
    private function seedPlatformRows(): void
    {
        DB::table('hpbrain_signal_rules')->insert([
            'tenant_id' => 'platform', 'rule_key' => 'shipped-rule',
        ]);

        DB::table('hpbrain_industries')->insert([
            'tenant_id' => 'platform', 'name' => 'Education',
        ]);
    }
}
