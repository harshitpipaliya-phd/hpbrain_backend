<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ERP login via tbluser.
 *
 * Proves the unified /auth/login endpoint reads the real ERP table, resolves
 * the organization from sub_institute_id, migrates legacy passwords, and
 * returns only safe fields.
 */
final class ErpLoginTest extends TestCase
{
    use \Tests\Support\SeedsEntityMappings;

    private const TENANT = '1';
    private const EMAIL = 'ada.analyst@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('institute_detail', function ($t) {
            $t->string('sub_institute_id');
            $t->string('organization_name')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('org_details', function ($t) {
            $t->string('sub_institute_id');
            $t->string('legal_name')->nullable();
            $t->string('logo')->nullable();
        });

        Schema::create('tbluserprofilemaster', function ($t) {
            $t->integer('id')->primary();
            $t->integer('sub_institute_id');
            $t->string('name');
            $t->integer('status')->default(1);
        });

        Schema::create('tbluser', function ($t) {
            $t->integer('id')->primary();
            $t->string('employee_no')->nullable();
            $t->string('password')->nullable();
            $t->string('plain_password')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email');
            $t->string('mobile')->nullable();
            $t->string('gender')->nullable();
            $t->integer('sub_institute_id');
            $t->integer('user_profile_id')->nullable();
            $t->integer('status')->default(1);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
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

        Schema::create('hpbrain_refresh_tokens', function ($t) {
            $t->string('jti', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('user_id', 36);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        DB::table('institute_detail')->insert([
            'sub_institute_id' => self::TENANT, 'organization_name' => 'Test Org', 'deleted_at' => null,
        ]);

        DB::table('org_details')->insert([
            'sub_institute_id' => self::TENANT, 'legal_name' => 'Test Org Legal', 'logo' => null,
        ]);

        DB::table('tbluserprofilemaster')->insert([
            'id' => 1, 'sub_institute_id' => 1, 'name' => 'Employee', 'status' => 1,
        ]);

        // Login no longer assumes a table. It searches every tenant that maps
        // Person and lets the matching row name the tenant, so this fixture now
        // has to say where its people live — which is the point.
        $this->installEntityMappings([self::TENANT]);
    }

    public function test_an_active_user_with_bcrypt_password_can_log_in(): void
    {
        DB::table('tbluser')->insert([
            'id' => 1, 'employee_no' => 'E001', 'password' => Hash::make('secret123'),
            'plain_password' => null, 'first_name' => 'Ada', 'last_name' => 'Analyst',
            'email' => self::EMAIL, 'mobile' => null, 'gender' => null,
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 1,
            'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL, 'password' => 'secret123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'accessToken', 'refreshToken',
            'user' => ['id', 'email', 'firstName', 'lastName', 'employeeNo', 'profileId', 'role'],
            'organization' => ['id', 'name', 'logo'],
        ]);

        $this->assertSame(self::TENANT, $response->json('organization.id'));
        $this->assertSame(self::EMAIL, $response->json('user.email'));
    }

    public function test_wrong_password_returns_invalid_credentials(): void
    {
        DB::table('tbluser')->insert([
            'id' => 2, 'employee_no' => 'E002', 'password' => Hash::make('secret123'),
            'plain_password' => null, 'first_name' => 'Bob', 'last_name' => 'Builder',
            'email' => 'bob@example.com', 'mobile' => null, 'gender' => null,
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 1,
            'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'bob@example.com', 'password' => 'wrong',
        ])->assertStatus(401)->assertJson([
            'error' => 'invalid_credentials',
            'message' => 'Incorrect email or password.',
        ]);
    }

    public function test_unknown_email_returns_invalid_credentials(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com', 'password' => 'anything',
        ])->assertStatus(401)->assertJson([
            'error' => 'invalid_credentials',
            'message' => 'Incorrect email or password.',
        ]);
    }

    public function test_an_inactive_user_cannot_log_in(): void
    {
        DB::table('tbluser')->insert([
            'id' => 3, 'employee_no' => 'E003', 'password' => Hash::make('secret123'),
            'plain_password' => null, 'first_name' => 'Ina', 'last_name' => 'Inactive',
            'email' => 'ina@example.com', 'mobile' => null, 'gender' => null,
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 0,
            'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ina@example.com', 'password' => 'secret123',
        ])->assertStatus(401);
    }

    public function test_a_deleted_user_cannot_log_in(): void
    {
        DB::table('tbluser')->insert([
            'id' => 4, 'employee_no' => 'E004', 'password' => Hash::make('secret123'),
            'plain_password' => null, 'first_name' => 'Del', 'last_name' => 'Deleted',
            'email' => 'del@example.com', 'mobile' => null, 'gender' => null,
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 1,
            'created_at' => null, 'updated_at' => null, 'deleted_at' => '2024-01-01 00:00:00',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'del@example.com', 'password' => 'secret123',
        ])->assertStatus(401);
    }

    public function test_password_fields_are_never_returned(): void
    {
        DB::table('tbluser')->insert([
            'id' => 5, 'employee_no' => 'E005', 'password' => Hash::make('secret123'),
            'plain_password' => 'legacy', 'first_name' => 'Sec', 'last_name' => 'Secure',
            'email' => 'sec@example.com', 'mobile' => null, 'gender' => null,
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 1,
            'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'sec@example.com', 'password' => 'secret123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonMissing(['password', 'plain_password', 'password_hash']);
    }

    public function test_legacy_plain_password_is_migrated_on_login(): void
    {
        DB::table('tbluser')->insert([
            'id' => 6, 'employee_no' => 'E006', 'password' => null,
            'plain_password' => 'legacy123', 'first_name' => 'Leg', 'last_name' => 'Acy',
            'email' => 'leg@example.com', 'mobile' => null, 'gender' => null,
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 1,
            'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'leg@example.com', 'password' => 'legacy123',
        ])->assertStatus(200);

        $this->assertNotNull(Hash::check('legacy123', DB::table('tbluser')->where('id', 6)->value('password')));
        $this->assertNull(DB::table('tbluser')->where('id', 6)->value('plain_password'));
    }

    /**
     * An already-hashed password is NOT rewritten on login.
     *
     * Logging in used to call Hash::make() and UPDATE tbluser every single time,
     * even when the stored value was already a correct bcrypt hash. At the
     * configured cost of 12 that is ~336 ms of pure CPU per login for a write
     * that changed nothing but the salt — and it took a row lock on the ERP's
     * shared user table to do it.
     *
     * Asserting the stored hash is byte-identical after login is the only way to
     * catch a reintroduction: an unconditional rehash still leaves a VALID hash,
     * so every other assertion in this file would keep passing while login had
     * silently doubled in cost again.
     */
    public function test_an_already_hashed_password_is_not_rewritten_on_login(): void
    {
        $hash = Hash::make('Stable#123');

        DB::table('tbluser')->insert([
            'id' => 7, 'employee_no' => 'E007', 'password' => $hash,
            'plain_password' => null, 'first_name' => 'Sta', 'last_name' => 'Ble',
            'email' => 'stable@example.com', 'mobile' => null, 'gender' => null,
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 1,
            'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'stable@example.com', 'password' => 'Stable#123',
        ])->assertStatus(200);

        self::assertSame(
            $hash,
            DB::table('tbluser')->where('id', 7)->value('password'),
            'Login rewrote a password hash that was already correct.'
        );
    }

    public function test_role_is_resolved_from_profile(): void
    {
        DB::table('tbluserprofilemaster')->insert([
            'id' => 99, 'sub_institute_id' => 1, 'name' => 'HR Manager', 'status' => 1,
        ]);

        DB::table('tbluser')->insert([
            'id' => 7, 'employee_no' => 'E007', 'password' => Hash::make('secret123'),
            'plain_password' => null, 'first_name' => 'Mgr', 'last_name' => 'Manager',
            'email' => 'mgr@example.com', 'mobile' => null, 'gender' => null,
            'sub_institute_id' => 1, 'user_profile_id' => 99, 'status' => 1,
            'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'mgr@example.com', 'password' => 'secret123',
        ]);

        $response->assertStatus(200);
        $this->assertSame('manager', $response->json('user.role'));
    }

    public function test_logout_revokes_refresh_token(): void
    {
        DB::table('tbluser')->insert([
            'id' => 8, 'employee_no' => 'E008', 'password' => Hash::make('secret123'),
            'plain_password' => null, 'first_name' => 'Log', 'last_name' => 'Out',
            'email' => 'log@example.com', 'mobile' => null, 'gender' => null,
            'sub_institute_id' => 1, 'user_profile_id' => 1, 'status' => 1,
            'created_at' => null, 'updated_at' => null, 'deleted_at' => null,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'log@example.com', 'password' => 'secret123',
        ]);

        $login->assertStatus(200);
        $refreshToken = $login->json('refreshToken');

        $this->postJson('/api/v1/auth/logout', ['refreshToken' => $refreshToken], [
            'Authorization' => 'Bearer '.$login->json('accessToken'),
        ])->assertStatus(200);

        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $refreshToken])
            ->assertStatus(401);
    }

    public function test_refresh_is_rate_limited(): void
    {
        $token = Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'viewer',
        ]);

        $refresh = Jwt::issueRefresh([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'viewer',
        ]);

        for ($i = 0; $i < 25; $i++) {
            $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $refresh], [
                'Authorization' => 'Bearer '.$token,
            ]);
        }

        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $refresh], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(429);
    }
}
