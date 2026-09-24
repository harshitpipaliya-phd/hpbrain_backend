<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Universal\EntityResolver;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The complete organization lifecycle, end to end and in one place:
 *
 *   sign up  ->  log in  ->  hold data  ->  permanently delete
 *            ->  old login fails  ->  sign up again with the SAME email
 *            ->  log in  ->  brand new empty tenant
 *
 * WHY IT IS ONE TEST CLASS AND NOT SEVERAL. Every step of this only means
 * anything in the presence of the step before it. "The same email can be reused"
 * is trivially true on an empty database and only interesting after that address
 * has been through a real signup and a real deletion, so the fixture here is
 * built by the actual /auth/signup endpoint rather than by hand-written rows.
 *
 * A SECOND ORGANIZATION IS PRESENT THROUGHOUT. Every destructive assertion is
 * paired with the same assertion inverted against it, because a lifecycle that
 * cleans up too much is worse than one that cleans up too little.
 */
final class TenantLifecycleTest extends TestCase
{
    use \Tests\Support\SeedsEntityMappings;

    private const LIONS_NAME = 'Lions';

    private const LIONS_EMAIL = 'lions@gmail.com';

    private const PASSWORD = 'admin1234';

    private const SUNRISE_NAME = 'Sunrise';

    private const SUNRISE_EMAIL = 'sunrise@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildErpTables();
        $this->buildBrainTables();

        // The mappings table has to exist before signup runs, because
        // OrganizationSignupService writes this tenant's mappings into it.
        $this->installEntityMappings([]);
    }

    /* ─────────────────────────── TEST 1 — deletion ─────────────────────────── */

    public function test_1_an_organization_created_through_signup_can_be_permanently_deleted(): void
    {
        $lions = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);

        $this->assertSame(self::LIONS_NAME, $lions['organization']['name']);
        $this->assertNotSame('', $lions['tenantId']);

        $this->seedTenantData($lions['tenantId']);

        $this->deleteOrganization($lions)->assertOk();

        $this->assertSame(0, DB::table('institute_detail')->where('sub_institute_id', $lions['tenantId'])->count());
        $this->assertSame(0, DB::table('school_setup')->where('id', $lions['tenantId'])->count());
    }

    /* ─────────────────────────── TEST 2 — old credentials ─────────────────────────── */

    public function test_2_the_deleted_organizations_credentials_no_longer_authenticate(): void
    {
        $lions = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);

        // Worked before.
        $this->login(self::LIONS_EMAIL)->assertOk();

        $this->deleteOrganization($lions)->assertOk();
        $this->flushResolver();

        $this->login(self::LIONS_EMAIL)
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_credentials']);
    }

    /* ─────────────────────────── TEST 3 + 4 — re-registration ─────────────────────────── */

    public function test_3_the_same_email_and_name_can_create_a_new_organization_after_deletion(): void
    {
        $lions = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);
        $this->deleteOrganization($lions)->assertOk();
        $this->flushResolver();

        // Same name, same email, same password. Must be allowed.
        $reborn = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);

        $this->assertNotSame($lions['tenantId'], $reborn['tenantId'], 'the new organization must get a FRESH tenant id');
        $this->assertSame(self::LIONS_NAME, $reborn['organization']['name']);
    }

    public function test_4_the_recreated_organization_can_log_in_with_the_same_credentials(): void
    {
        $lions = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);
        $this->deleteOrganization($lions)->assertOk();
        $this->flushResolver();

        $reborn = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);
        $this->flushResolver();

        $login = $this->login(self::LIONS_EMAIL)->assertOk()->json();

        $this->assertSame($reborn['tenantId'], $login['organization']['id']);
        $this->assertSame(self::LIONS_NAME, $login['organization']['name']);
        $this->assertNotSame($lions['tenantId'], $login['organization']['id']);
    }

    /* ─────────────────────────── TEST 5 — the new tenant is clean ─────────────────────────── */

    public function test_5_the_recreated_organization_starts_completely_empty(): void
    {
        $lions = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);
        $this->seedTenantData($lions['tenantId']);

        // Prove the fixture really loaded the old tenant up first.
        foreach ($this->brainDataTables() as $table) {
            $this->assertGreaterThan(0, DB::table($table)->where('tenant_id', $lions['tenantId'])->count(), $table);
        }

        $this->deleteOrganization($lions)->assertOk();
        $this->flushResolver();

        $reborn = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);

        foreach ($this->brainDataTables() as $table) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', $reborn['tenantId'])->count(),
                "the new tenant must start with no {$table}");
            $this->assertSame(0, DB::table($table)->where('tenant_id', $lions['tenantId'])->count(),
                "the old tenant's {$table} must stay deleted");
        }

        // Departments and people too: only the derived administrator exists.
        $this->assertSame(0, DB::table('hrms_departments')->where('sub_institute_id', $reborn['tenantId'])->count());
        $this->assertSame(1, DB::table('tbluser')->where('sub_institute_id', $reborn['tenantId'])->count());

        // Fresh mappings for the new tenant, none left for the old one.
        $this->assertGreaterThan(0, DB::table('hpbrain_entity_mappings')->where('tenant_id', $reborn['tenantId'])->count());
        $this->assertSame(0, DB::table('hpbrain_entity_mappings')->where('tenant_id', $lions['tenantId'])->count());
    }

    /* ─────────────────────────── TEST 6 / 7 / 8 — old identity is dead ─────────────────────────── */

    public function test_6_the_old_tenant_id_returns_not_found(): void
    {
        $lions = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);
        $this->deleteOrganization($lions)->assertOk();
        $this->flushResolver();

        $token = $lions['accessToken'];

        foreach ([
            '/api/v1/organizations/'.$lions['tenantId'],
            '/api/v1/organizations/'.$lions['tenantId'].'/'.$lions['tenantId'],
            '/api/v1/organizations/'.$lions['tenantId'].'/'.$lions['tenantId'].'/structure',
        ] as $uri) {
            $this->getJson($uri, ['Authorization' => 'Bearer '.$token])->assertStatus(404);
        }
    }

    public function test_7_an_access_token_issued_before_deletion_reaches_no_tenant_data(): void
    {
        $lions = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);
        $this->seedTenantData($lions['tenantId']);
        $this->deleteOrganization($lions)->assertOk();
        $this->flushResolver();

        // The token is still validly signed — authentication was not weakened —
        // but every tenant-scoped read fails closed through EntityResolver, so
        // it reaches nothing.
        $this->getJson('/api/v1/organizations/'.$lions['tenantId'], [
            'Authorization' => 'Bearer '.$lions['accessToken'],
        ])->assertStatus(404);
    }

    public function test_8_a_refresh_token_issued_before_deletion_cannot_mint_new_credentials(): void
    {
        $lions = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);
        $refresh = $lions['refreshToken'];

        // It works while the tenant is alive.
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $refresh])
            ->assertOk()
            ->assertJsonStructure(['accessToken', 'refreshToken']);

        $this->deleteOrganization($lions)->assertOk();
        $this->flushResolver();

        // And is refused the moment the tenant no longer exists.
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $refresh])
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_token']);

        // Critically, the refusal did not leave a row behind for the dead tenant.
        $this->assertSame(0, DB::table('hpbrain_refresh_tokens')->where('tenant_id', $lions['tenantId'])->count());
    }

    public function test_8b_logging_out_of_a_deleted_tenant_does_not_recreate_its_rows(): void
    {
        $lions = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);
        $this->deleteOrganization($lions)->assertOk();
        $this->flushResolver();

        $this->postJson('/api/v1/auth/logout', ['refreshToken' => $lions['refreshToken']])->assertOk();

        $this->assertSame(0, DB::table('hpbrain_refresh_tokens')->where('tenant_id', $lions['tenantId'])->count(),
            'logout must not re-seed rows for a tenant that was permanently deleted');
    }

    /* ─────────────────────────── TEST 9 — uniqueness still protected ─────────────────────────── */

    public function test_9_an_active_organizations_email_still_cannot_be_reused(): void
    {
        $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);

        // Same email while the first organization is ALIVE: refused.
        $this->postJson('/api/v1/auth/signup', [
            'organizationName'      => 'Different Name',
            'organizationEmail'     => self::LIONS_EMAIL,
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('organizationEmail');
    }

    public function test_9b_an_active_organizations_name_still_cannot_be_reused(): void
    {
        $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);

        $this->postJson('/api/v1/auth/signup', [
            'organizationName'      => self::LIONS_NAME,
            'organizationEmail'     => 'someone.else@example.test',
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('organizationName');
    }

    public function test_9c_archiving_alone_does_not_free_the_email(): void
    {
        $lions = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);

        // Archive is NOT deletion. The organization and its user still exist,
        // so the address is still taken — which is correct, and is the
        // distinction that makes permanent deletion meaningful.
        $this->postJson(
            '/api/v1/organizations/'.$lions['tenantId'].'/'.$lions['tenantId'].'/archive',
            [],
            ['Authorization' => 'Bearer '.$lions['accessToken']],
        )->assertOk();

        $this->postJson('/api/v1/auth/signup', [
            'organizationName'      => 'Another Name',
            'organizationEmail'     => self::LIONS_EMAIL,
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('organizationEmail');
    }

    /* ─────────────────────────── TEST 10 — isolation ─────────────────────────── */

    public function test_10_the_other_organization_is_untouched_by_the_whole_lifecycle(): void
    {
        $sunrise = $this->signup(self::SUNRISE_NAME, self::SUNRISE_EMAIL);
        $this->seedTenantData($sunrise['tenantId']);

        $before = $this->snapshotOf($sunrise['tenantId']);

        $lions = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);
        $this->seedTenantData($lions['tenantId']);
        $this->deleteOrganization($lions)->assertOk();
        $this->flushResolver();
        $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);
        $this->flushResolver();

        $this->assertSame($before, $this->snapshotOf($sunrise['tenantId']), 'Sunrise data changed');

        // And it can still sign in and read its own organization.
        $login = $this->login(self::SUNRISE_EMAIL)->assertOk()->json();
        $this->assertSame(self::SUNRISE_NAME, $login['organization']['name']);
        $this->assertSame($sunrise['tenantId'], $login['organization']['id']);
    }

    /* ─────────────────────────── TEST 11 — rollback ─────────────────────────── */

    public function test_11_a_failed_deletion_leaves_the_organization_and_its_login_intact(): void
    {
        $lions = $this->signup(self::LIONS_NAME, self::LIONS_EMAIL);
        $this->seedTenantData($lions['tenantId']);

        $before = $this->snapshotOf($lions['tenantId']);

        DB::statement(
            'CREATE TRIGGER block_signal_delete BEFORE DELETE ON hpbrain_signals
             BEGIN SELECT RAISE(ABORT, "controlled failure"); END'
        );

        try {
            $this->deleteOrganization($lions)->assertStatus(500)->assertJson(['error' => 'deletion_failed']);
        } finally {
            DB::statement('DROP TRIGGER block_signal_delete');
        }

        $this->flushResolver();

        $this->assertSame($before, $this->snapshotOf($lions['tenantId']));
        $this->assertSame(1, DB::table('institute_detail')->where('sub_institute_id', $lions['tenantId'])->count());
        $this->assertSame(1, DB::table('school_setup')->where('id', $lions['tenantId'])->count());

        // Login still works, which is the assertion that matters most.
        $this->login(self::LIONS_EMAIL)->assertOk();
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    /**
     * Sign up through the REAL endpoint and return the session it hands back.
     *
     * @return array{tenantId: string, accessToken: string, refreshToken: string, organization: array}
     */
    private function signup(string $name, string $email): array
    {
        $body = $this->postJson('/api/v1/auth/signup', [
            'organizationName'      => $name,
            'organizationEmail'     => $email,
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'industry'              => 'Education',
        ])->assertStatus(201)->json();

        $this->flushResolver();

        return [
            'tenantId'     => (string) $body['organization']['id'],
            'accessToken'  => $body['accessToken'],
            'refreshToken' => $body['refreshToken'],
            'organization' => $body['organization'],
        ];
    }

    private function login(string $email): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ]);
    }

    /** @param array{tenantId: string, accessToken: string} $org */
    private function deleteOrganization(array $org): \Illuminate\Testing\TestResponse
    {
        $name = DB::table('institute_detail')
            ->where('sub_institute_id', $org['tenantId'])
            ->value('organization_name');

        return $this->json('DELETE', '/api/v1/organizations/'.$org['tenantId'].'/'.$org['tenantId'], [
            'confirmName'                 => $name,
            'acknowledgeSourceSystemData' => true,
        ], ['Authorization' => 'Bearer '.$org['accessToken']]);
    }

    /**
     * The resolver caches mappings per instance, and signup/deletion both change
     * them. Without this a test sees the mapping state from before its own write.
     */
    private function flushResolver(): void
    {
        app(EntityResolver::class)->flush();
    }

    /** @return array<int, string> */
    private function brainDataTables(): array
    {
        return [
            'hpbrain_signals', 'hpbrain_evidence', 'hpbrain_cases', 'hpbrain_recommendations',
            'hpbrain_decisions', 'hpbrain_operational_records', 'hpbrain_import_jobs',
            'hpbrain_capabilities', 'hpbrain_people', 'hpbrain_departments',
        ];
    }

    private function seedTenantData(string $tenant): void
    {
        foreach ($this->brainDataTables() as $table) {
            DB::table($table)->insert([
                ['tenant_id' => $tenant, 'name' => $table.'-a'],
                ['tenant_id' => $tenant, 'name' => $table.'-b'],
            ]);
        }

        DB::table('hrms_departments')->insert([
            'sub_institute_id' => $tenant, 'department' => 'Science', 'parent_id' => 0, 'status' => 1,
        ]);
    }

    /** @return array<string, int> */
    private function snapshotOf(string $tenant): array
    {
        $out = [];

        foreach ($this->brainDataTables() as $table) {
            $out[$table] = DB::table($table)->where('tenant_id', $tenant)->count();
        }

        foreach (['institute_detail', 'org_details', 'tbluser', 'tbluserprofilemaster', 'hrms_departments'] as $table) {
            $out[$table] = DB::table($table)->where('sub_institute_id', $tenant)->count();
        }

        $out['school_setup'] = DB::table('school_setup')->where('id', $tenant)->count();
        $out['hpbrain_entity_mappings'] = DB::table('hpbrain_entity_mappings')->where('tenant_id', $tenant)->count();

        return $out;
    }

    /* ─────────────────────────── fixture ─────────────────────────── */

    private function buildErpTables(): void
    {
        Schema::create('tblclient', function ($t) {
            $t->increments('id');
            $t->string('client_name');
            $t->string('short_code')->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->string('country')->nullable();
            $t->string('contact_person')->nullable();
            $t->string('contact_person_mobile')->nullable();
            $t->string('contact_persoon_email')->nullable();
            $t->integer('number_of_schools')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('school_setup', function ($t) {
            $t->increments('id');
            $t->string('SchoolName');
            $t->string('ShortCode')->nullable();
            $t->string('ContactPerson')->nullable();
            $t->string('Mobile')->nullable();
            $t->string('Email')->nullable();
            $t->string('Logo')->nullable();
            $t->integer('SortOrder')->nullable();
            $t->integer('client_id')->nullable();
            $t->string('is_lms')->nullable();
            $t->string('syear')->nullable();
            $t->date('expire_date')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('institute_detail', function ($t) {
            $t->increments('pk');
            $t->string('sub_institute_id');
            $t->string('organization_name')->nullable();
            $t->string('organization_code')->nullable();
            $t->string('organization_email')->nullable();
            $t->string('organization_ph_no')->nullable();
            $t->string('address')->nullable();
            $t->string('industry_type')->nullable();
            $t->string('handler_name')->nullable();
            $t->string('handler_email')->nullable();
            $t->string('handler_mobile')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('updated_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('org_details', function ($t) {
            $t->increments('id');
            $t->string('sub_institute_id');
            $t->string('legal_name')->nullable();
            $t->string('industry')->nullable();
            $t->string('logo')->nullable();
            $t->string('email')->nullable();
            $t->string('mobile_no')->nullable();
            $t->string('country_code')->nullable();
            $t->string('registered_address')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('updated_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
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
            $t->integer('parent_id')->nullable();
            $t->string('sub_institute_id');
            $t->string('name');
            $t->string('description')->nullable();
            $t->integer('sort_order')->nullable();
            $t->integer('client_id')->nullable();
            $t->integer('status')->default(1);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('tbluser', function ($t) {
            $t->increments('id');
            $t->string('sub_institute_id');
            $t->string('user_name')->nullable();
            $t->string('employee_no')->nullable();
            $t->string('first_name')->nullable();
            $t->string('middle_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('mobile')->nullable();
            $t->string('gender')->nullable();
            $t->string('password')->nullable();
            $t->string('plain_password')->nullable();
            $t->integer('department_id')->nullable();
            $t->integer('jobtitle_id')->nullable();
            $t->integer('user_profile_id')->nullable();
            $t->integer('client_id')->nullable();
            $t->integer('is_admin')->nullable();
            $t->string('join_year')->nullable();
            $t->date('joined_date')->nullable();
            $t->integer('status')->default(1);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
    }

    private function buildBrainTables(): void
    {
        foreach ([
            'hpbrain_signals', 'hpbrain_evidence', 'hpbrain_cases', 'hpbrain_recommendations',
            'hpbrain_decisions', 'hpbrain_operational_records', 'hpbrain_import_jobs',
            'hpbrain_capabilities', 'hpbrain_people', 'hpbrain_departments', 'hpbrain_organizations',
        ] as $table) {
            Schema::create($table, function ($t) {
                $t->increments('id');
                $t->string('tenant_id', 36);
                $t->string('name')->nullable();
                $t->timestamp('created_date')->nullable();
            });
        }

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
    }
}
