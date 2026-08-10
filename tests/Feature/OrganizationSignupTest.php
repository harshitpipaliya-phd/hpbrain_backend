<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authorization\Permission;
use App\Domain\Authorization\Role;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SeedsEntityMappings;
use Tests\TestCase;

/**
 * Self-service organization signup, end to end.
 *
 * The claim under test is not "the endpoint returns 201". It is that a person
 * who fills in FOUR FIELDS ends up with a TENANT that works: rows in all six
 * ERP tables with the foreign keys actually joined up, entity mappings so the
 * resolver can see it, an administrator that exists even though nobody was
 * asked to describe one, a password that is a hash, a token whose tenant claim
 * is the id the database allocated, and access to the ingestion engine — while
 * remaining unable to read any other organization's data.
 *
 * THE FORM COLLECTS AN ORGANIZATION, NOT A PERSON. There is no first name, no
 * last name, no separate administrator email and no administrator mobile in the
 * payload below, and the tests assert that the administrator is created anyway.
 * That is the whole point of this shape: the account is derived from the
 * organization, and the schema permits it because first_name, last_name, mobile
 * and user_name are all nullable while password, email and user_profile_id —
 * the three columns tbluser genuinely demands — all come from real input.
 *
 * THE SCHEMA BELOW IS BUILT HERE RATHER THAN FROM BuildsErpFixture. That trait
 * predates this work and defines tbluser without user_name, client_id, is_admin
 * or join_year, and defines no tblclient or school_setup at all. Widening it
 * would change the fixture eleven other tests assert against. The columns here
 * are copied from the live MySQL schema, including the nullability that
 * matters: tbluser.password, tbluser.email and tbluser.user_profile_id are NOT
 * NULL, and tbluser.email is globally unique.
 */
final class OrganizationSignupTest extends TestCase
{
    use SeedsEntityMappings;

    /** An organization that already exists, for the isolation assertions. */
    private const INCUMBENT = '1';

    /** The organization email, which is ALSO the administrator's login. */
    private const EMAIL = 'ops@northwind.example';

    private const PASSWORD = 'harbour road 7';

    /**
     * The complete signup payload. Four fields, one of them optional.
     *
     * @var array<string, mixed>
     */
    private const VALID = [
        'organizationName'      => 'Northwind Logistics',
        'organizationEmail'     => self::EMAIL,
        'organizationMobile'    => '9876543210',
        'password'              => self::PASSWORD,
        'password_confirmation' => self::PASSWORD,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildErpSchema();
        $this->buildBrainTables();
        $this->seedIncumbentTenant();
    }

    // =====================================================================
    // The organization is created, completely and correctly linked
    // =====================================================================

    public function test_signup_creates_a_complete_organization(): void
    {
        $response = $this->postJson('/api/v1/auth/signup', self::VALID);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'accessToken', 'refreshToken',
            'user' => ['id', 'email', 'firstName', 'lastName', 'profileId', 'role'],
            'organization' => ['id', 'name', 'logo'],
        ]);

        $tenantId = $response->json('organization.id');

        // The tenant id IS school_setup.id. Asserted rather than assumed,
        // because the whole foreign-key graph hangs off that equality.
        $school = DB::table('school_setup')->where('SchoolName', 'Northwind Logistics')->first();
        self::assertNotNull($school, 'No school_setup row — the tenant root was never created.');
        self::assertSame((string) $school->id, $tenantId);

        // tblclient <- school_setup.client_id
        $client = DB::table('tblclient')->where('id', $school->client_id)->first();
        self::assertNotNull($client, 'school_setup.client_id points at no tblclient row.');
        self::assertSame('Northwind Logistics', $client->client_name);
        self::assertSame(self::EMAIL, $client->email);

        // institute_detail — what the Brain reads as Organization
        $org = DB::table('institute_detail')->where('sub_institute_id', $school->id)->first();
        self::assertNotNull($org, 'No institute_detail row — the Brain cannot see this organization.');
        self::assertSame('Northwind Logistics', $org->organization_name);
        self::assertSame(self::EMAIL, $org->organization_email);
        self::assertNull($org->deleted_at);

        // org_details — OrganizationProfile
        $profile = DB::table('org_details')->where('sub_institute_id', $school->id)->first();
        self::assertNotNull($profile, 'No org_details row.');
        self::assertSame('Northwind Logistics', $profile->legal_name);

        // The three user profiles, all belonging to the new tenant
        $profiles = DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $school->id)->orderBy('sort_order')->pluck('name')->all();
        self::assertSame(['Admin', 'Employee', 'HR'], $profiles);

        // Entity mappings, without which login cannot find this tenant at all
        $mapped = DB::table('hpbrain_entity_mappings')
            ->where('tenant_id', $tenantId)->distinct()->pluck('universal_entity')->sort()->values()->all();
        self::assertSame(
            ['Organization', 'OrganizationProfile', 'OrganizationUnit', 'Person', 'PersonProfile', 'Position'],
            $mapped,
        );
        self::assertSame(
            'tbluser',
            DB::table('hpbrain_entity_mappings')
                ->where('tenant_id', $tenantId)->where('universal_entity', 'Person')->value('source_entity'),
        );
    }

    // =====================================================================
    // The administrator is created INTERNALLY — nobody described one
    // =====================================================================

    /**
     * The payload names no person, and a complete administrator exists anyway.
     *
     * This is the test that says the UI simplification did not become a backend
     * removal. Every column tbluser demands is populated, the row is joined to
     * this tenant's Admin profile, and it is flagged as an administrator.
     */
    public function test_an_administrator_is_created_without_being_asked_for(): void
    {
        foreach (['firstName', 'lastName', 'email', 'mobile'] as $absent) {
            self::assertArrayNotHasKey($absent, self::VALID, "The form still collects {$absent}.");
        }

        $tenantId = (int) $this->postJson('/api/v1/auth/signup', self::VALID)->json('organization.id');

        $user = DB::table('tbluser')->where('sub_institute_id', $tenantId)->first();
        self::assertNotNull($user, 'No administrator was created for the new tenant.');

        // The three NOT NULL columns, all from real input.
        self::assertNotNull($user->password);
        self::assertSame(self::EMAIL, $user->email);
        self::assertSame(
            (int) DB::table('tbluserprofilemaster')
                ->where('sub_institute_id', $tenantId)->where('name', 'Admin')->value('id'),
            (int) $user->user_profile_id,
        );

        self::assertSame(1, (int) $user->is_admin);
        self::assertSame(1, (int) $user->status);
        self::assertSame($tenantId, (int) $user->sub_institute_id);
        self::assertSame(
            (int) DB::table('school_setup')->where('id', $tenantId)->value('client_id'),
            (int) $user->client_id,
        );
    }

    /**
     * The derived identity describes the ACCOUNT and invents no person.
     *
     * 'Administrator' is a role. A surname guessed from the email local part or
     * the organization name would be indistinguishable from a real one the
     * moment a second user joins, so none is written.
     */
    public function test_the_derived_administrator_invents_no_persons_name(): void
    {
        $tenantId = (int) $this->postJson('/api/v1/auth/signup', self::VALID)->json('organization.id');

        $user = DB::table('tbluser')->where('sub_institute_id', $tenantId)->first();

        self::assertSame('Administrator', $user->first_name);
        self::assertSame('', $user->last_name);
        self::assertSame('admin.northwindlogistics', $user->user_name);

        // The organization is the contact, since no person was named.
        self::assertSame(
            'Northwind Logistics',
            DB::table('institute_detail')->where('sub_institute_id', $tenantId)->value('handler_name'),
        );
    }

    /** The organization email is the login email, in one place and five columns. */
    public function test_the_organization_email_is_the_login_email(): void
    {
        $tenantId = (int) $this->postJson('/api/v1/auth/signup', self::VALID)->json('organization.id');
        $clientId = (int) DB::table('school_setup')->where('id', $tenantId)->value('client_id');

        self::assertSame(self::EMAIL, DB::table('tbluser')->where('sub_institute_id', $tenantId)->value('email'));
        self::assertSame(self::EMAIL, DB::table('school_setup')->where('id', $tenantId)->value('Email'));
        self::assertSame(self::EMAIL, DB::table('tblclient')->where('id', $clientId)->value('email'));
        self::assertSame(self::EMAIL, DB::table('institute_detail')->where('sub_institute_id', $tenantId)->value('organization_email'));
        self::assertSame(self::EMAIL, DB::table('org_details')->where('sub_institute_id', $tenantId)->value('email'));

        // And it is what logs in.
        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL, 'password' => self::PASSWORD,
        ])->assertStatus(200);
    }

    /**
     * The tenant id comes from AUTO_INCREMENT, not from MAX() over a satellite.
     *
     * This is the bug in OrganizationRepository::create() that must not be
     * reproduced here. institute_detail's highest sub_institute_id is set to 3
     * below while school_setup already holds id 9, so a MAX()+1 allocator would
     * hand out 4 — an id school_setup will itself issue later, and one that on
     * the live database already belongs to a different customer.
     */
    public function test_the_tenant_id_is_allocated_by_school_setup_not_by_max_plus_one(): void
    {
        DB::table('school_setup')->insert([
            'id' => 9, 'SchoolName' => 'Pre-existing Org', 'client_id' => 1,
        ]);
        DB::table('institute_detail')->where('sub_institute_id', self::INCUMBENT)
            ->update(['sub_institute_id' => 3]);

        $tenantId = (int) $this->postJson('/api/v1/auth/signup', self::VALID)->json('organization.id');

        self::assertGreaterThan(9, $tenantId, 'Tenant id collided with an existing school_setup row.');
        self::assertSame(
            $tenantId,
            (int) DB::table('school_setup')->where('SchoolName', 'Northwind Logistics')->value('id'),
        );
    }

    // =====================================================================
    // Login and tenant context
    // =====================================================================

    public function test_the_new_admin_can_log_in_and_the_token_carries_the_new_tenant(): void
    {
        $tenantId = $this->postJson('/api/v1/auth/signup', self::VALID)->json('organization.id');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL, 'password' => self::PASSWORD,
        ]);

        $login->assertStatus(200);
        self::assertSame($tenantId, $login->json('organization.id'));
        self::assertSame('Northwind Logistics', $login->json('organization.name'));

        // The profile named 'Admin' is what makes this a tenant administrator.
        self::assertSame('tenant_admin', $login->json('user.role'));

        $claims = Jwt::verify($login->json('accessToken'));
        self::assertSame($tenantId, $claims['tenantId']);
        self::assertSame('access', $claims['type']);

        // And that tenant claim is school_setup.id, not anything else.
        self::assertSame(
            (string) DB::table('school_setup')->where('SchoolName', 'Northwind Logistics')->value('id'),
            (string) $claims['tenantId'],
        );
    }

    /** The session returned by signup is real, whether or not the UI uses it. */
    public function test_the_token_returned_by_signup_is_immediately_usable(): void
    {
        $signup = $this->postJson('/api/v1/auth/signup', self::VALID);
        $tenantId = $signup->json('organization.id');

        $this->getJson("/api/v1/organizations/{$tenantId}", [
            'Authorization' => 'Bearer '.$signup->json('accessToken'),
        ])->assertStatus(200)->assertJsonFragment(['name' => 'Northwind Logistics']);
    }

    public function test_the_new_organization_can_reach_the_ingestion_engine(): void
    {
        $signup = $this->postJson('/api/v1/auth/signup', self::VALID);
        $tenantId = $signup->json('organization.id');

        // Every ingestion route is gated on settings.manage. Reaching a 200
        // proves the whole chain: valid token -> EnsureTenantScope -> the
        // tenant_admin role actually grants the permission.
        $this->getJson("/api/v1/ingestion/sources/{$tenantId}", [
            'Authorization' => 'Bearer '.$signup->json('accessToken'),
        ])->assertStatus(200)->assertExactJson([]);

        self::assertTrue(Role::TENANT_ADMIN->grants(Permission::SETTINGS_MANAGE));
    }

    public function test_logout_revokes_the_new_organizations_session(): void
    {
        $signup = $this->postJson('/api/v1/auth/signup', self::VALID);
        $refresh = $signup->json('refreshToken');

        $this->postJson('/api/v1/auth/logout', ['refreshToken' => $refresh], [
            'Authorization' => 'Bearer '.$signup->json('accessToken'),
        ])->assertStatus(200);

        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $refresh])->assertStatus(401);
    }

    // =====================================================================
    // Tenant isolation, both directions
    // =====================================================================

    public function test_the_new_organization_cannot_read_another_tenants_data(): void
    {
        $signup = $this->postJson('/api/v1/auth/signup', self::VALID);
        $token = $signup->json('accessToken');

        $this->getJson('/api/v1/organizations/'.self::INCUMBENT, [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(403)->assertJson(['error' => 'tenant_mismatch']);

        $this->getJson('/api/v1/ingestion/sources/'.self::INCUMBENT, [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(403);
    }

    public function test_an_existing_organization_cannot_read_the_new_one(): void
    {
        $tenantId = $this->postJson('/api/v1/auth/signup', self::VALID)->json('organization.id');

        $incumbent = Jwt::issueAccess([
            'id' => '1', 'tenantId' => self::INCUMBENT, 'role' => 'tenant_admin',
        ]);

        $this->getJson("/api/v1/organizations/{$tenantId}", [
            'Authorization' => 'Bearer '.$incumbent,
        ])->assertStatus(403)->assertJson(['error' => 'tenant_mismatch']);

        // And its own list still returns only itself, not the new organization.
        $own = $this->getJson('/api/v1/organizations/'.self::INCUMBENT, [
            'Authorization' => 'Bearer '.$incumbent,
        ]);

        $own->assertStatus(200);
        self::assertSame(['Incumbent Org'], array_column($own->json(), 'name'));
    }

    // =====================================================================
    // Credentials
    // =====================================================================

    public function test_the_password_is_hashed_and_plain_password_stays_null(): void
    {
        $this->postJson('/api/v1/auth/signup', self::VALID)->assertStatus(201);

        $user = DB::table('tbluser')->where('email', self::EMAIL)->first();

        self::assertNotSame(self::PASSWORD, $user->password, 'The raw password was stored.');
        self::assertTrue(Hash::check(self::PASSWORD, $user->password));
        self::assertNull($user->plain_password, 'plain_password must never be written.');

        // The legacy default, explicitly. This is the behaviour being removed.
        self::assertFalse(Hash::check('admin', $user->password));
    }

    /** A simple password is still stored with the full hashing architecture. */
    public function test_even_a_simple_password_is_bcrypt_hashed(): void
    {
        $this->postJson('/api/v1/auth/signup', [
            'password' => 'password', 'password_confirmation' => 'password',
        ] + self::VALID)->assertStatus(201);

        $stored = DB::table('tbluser')->where('email', self::EMAIL)->value('password');

        self::assertStringStartsWith('$2y$', $stored, 'Not a bcrypt hash.');
        self::assertNotSame('password', $stored);
        self::assertTrue(Hash::check('password', $stored));
    }

    /** Surrounding spaces are part of the password, not noise to be trimmed. */
    public function test_a_password_is_not_trimmed(): void
    {
        $this->postJson('/api/v1/auth/signup', [
            'password' => '  spaced out  ', 'password_confirmation' => '  spaced out  ',
        ] + self::VALID)->assertStatus(201);

        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL, 'password' => '  spaced out  ',
        ])->assertStatus(200);

        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL, 'password' => 'spaced out',
        ])->assertStatus(401);
    }

    public function test_no_credential_is_ever_returned_by_signup(): void
    {
        $response = $this->postJson('/api/v1/auth/signup', self::VALID);

        $body = $response->getContent();
        self::assertStringNotContainsString(self::PASSWORD, $body);
        self::assertStringNotContainsString('plain_password', $body);
        self::assertStringNotContainsString('password_hash', $body);
        $response->assertJsonMissingPath('user.password');
    }

    // =====================================================================
    // Duplicates
    // =====================================================================

    public function test_a_duplicate_organization_email_is_refused(): void
    {
        $this->postJson('/api/v1/auth/signup', self::VALID)->assertStatus(201);

        $this->postJson('/api/v1/auth/signup', [
            'organizationName' => 'Southwind Freight',
        ] + self::VALID)
            ->assertStatus(422)
            ->assertJsonValidationErrors('organizationEmail');

        self::assertSame(0, DB::table('school_setup')->where('SchoolName', 'Southwind Freight')->count());
    }

    /**
     * The email index is GLOBAL, so an address already used by a PERSON in
     * another tenant blocks signup here too.
     *
     * Worth its own test now that the organization email is the login email: a
     * per-tenant reading of that rule would let this through and then fail on
     * the insert with a 500.
     */
    public function test_an_email_belonging_to_another_tenant_is_refused(): void
    {
        DB::table('tbluser')->insert([
            'id' => 500, 'user_name' => 'existing.person', 'password' => Hash::make('whatever'),
            'first_name' => 'Existing', 'last_name' => 'Person',
            'email' => self::EMAIL, 'user_profile_id' => 1,
            'sub_institute_id' => (int) self::INCUMBENT, 'status' => 1,
        ]);

        $this->postJson('/api/v1/auth/signup', self::VALID)
            ->assertStatus(422)
            ->assertJsonValidationErrors('organizationEmail');
    }

    public function test_a_duplicate_organization_name_is_refused(): void
    {
        $this->postJson('/api/v1/auth/signup', self::VALID)->assertStatus(201);

        $this->postJson('/api/v1/auth/signup', [
            'organizationEmail' => 'ops@southwind.example',
        ] + self::VALID)
            ->assertStatus(422)
            ->assertJsonValidationErrors('organizationName');
    }

    /** Whitespace does not make a new organization out of an existing name. */
    public function test_a_whitespace_padded_duplicate_name_is_refused(): void
    {
        $this->postJson('/api/v1/auth/signup', self::VALID)->assertStatus(201);

        $this->postJson('/api/v1/auth/signup', [
            'organizationName'  => '   Northwind Logistics   ',
            'organizationEmail' => 'ops@southwind.example',
        ] + self::VALID)
            ->assertStatus(422)
            ->assertJsonValidationErrors('organizationName');
    }

    /**
     * A shared mobile number is NOT a duplicate.
     *
     * Verified against the live database: 287 users carry a mobile number and
     * only 165 are distinct, so 109 numbers are shared.
     */
    public function test_a_shared_mobile_number_is_allowed(): void
    {
        $this->postJson('/api/v1/auth/signup', self::VALID)->assertStatus(201);

        $this->postJson('/api/v1/auth/signup', [
            'organizationName'  => 'Southwind Freight',
            'organizationEmail' => 'ops@southwind.example',
        ] + self::VALID)->assertStatus(201);
    }

    // =====================================================================
    // Validation
    // =====================================================================

    /**
     * The mobile is genuinely optional.
     *
     * Nullable in all five tables that store it, and already NULL for 100 of
     * 389 live users. Omitting it must produce a complete organization, and the
     * column must be NULL rather than an empty string.
     */
    public function test_the_mobile_is_optional(): void
    {
        $payload = self::VALID;
        unset($payload['organizationMobile']);

        $tenantId = (int) $this->postJson('/api/v1/auth/signup', $payload)
            ->assertStatus(201)
            ->json('organization.id');

        self::assertNull(DB::table('tbluser')->where('sub_institute_id', $tenantId)->value('mobile'));
        self::assertNull(DB::table('school_setup')->where('id', $tenantId)->value('Mobile'));
    }

    /**
     * @dataProvider invalidPayloads
     *
     * @param  array<string, mixed>  $overrides
     */
    public function test_invalid_input_is_refused(array $overrides, string $expectedField): void
    {
        $this->postJson('/api/v1/auth/signup', $overrides + self::VALID)
            ->assertStatus(422)
            ->assertJsonValidationErrors($expectedField);

        self::assertSame(0, DB::table('school_setup')->where('SchoolName', '<>', 'Incumbent Org')->count());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidPayloads(): array
    {
        return [
            'organization name missing' => [['organizationName' => ''], 'organizationName'],
            'organization name too short' => [['organizationName' => 'X'], 'organizationName'],
            'email missing'             => [['organizationEmail' => ''], 'organizationEmail'],
            'email malformed'           => [['organizationEmail' => 'not-an-email'], 'organizationEmail'],
            'email has no domain'       => [['organizationEmail' => 'ops@'], 'organizationEmail'],
            'password missing'          => [['password' => '', 'password_confirmation' => ''], 'password'],
            'password not confirmed'    => [['password_confirmation' => 'something else'], 'password'],
            'confirmation missing'      => [['password_confirmation' => ''], 'password'],
            'password below the floor'  => [['password' => 'short7', 'password_confirmation' => 'short7'], 'password'],
            'mobile too short'          => [['organizationMobile' => '98123'], 'organizationMobile'],
            'mobile starts with zero'   => [['organizationMobile' => '0812345678'], 'organizationMobile'],
            'mobile not numeric'        => [['organizationMobile' => 'abcdefghij'], 'organizationMobile'],
            'logo is not a url'         => [['logo' => 'not a url'], 'logo'],
        ];
    }

    /**
     * NO COMPOSITION RULES. A password with no digit, no capital and no symbol
     * is accepted, because those rules push people towards 'Password1!' and are
     * not what makes a stored credential safe — the bcrypt hash is.
     */
    public function test_a_password_needs_no_digit_capital_or_symbol(): void
    {
        $this->postJson('/api/v1/auth/signup', [
            'password' => 'correcthorsebatterystaple',
            'password_confirmation' => 'correcthorsebatterystaple',
        ] + self::VALID)->assertStatus(201);
    }

    /** An organization name outside the Latin alphabet is a name, not an error. */
    public function test_a_non_latin_organization_name_is_accepted(): void
    {
        $this->postJson('/api/v1/auth/signup', [
            'organizationName' => 'Ståhl & Söner Logistik',
        ] + self::VALID)->assertStatus(201);
    }

    // =====================================================================
    // No OTP, no institute type, no captcha
    // =====================================================================

    public function test_signup_requires_neither_otp_nor_institute_type(): void
    {
        // self::VALID contains neither key. A 201 is the assertion.
        $this->postJson('/api/v1/auth/signup', self::VALID)->assertStatus(201);

        $school = DB::table('school_setup')->where('SchoolName', 'Northwind Logistics')->first();
        self::assertNull($school->institute_type, 'institute_type was written despite being dropped.');

        self::assertNull(DB::table('tbluser')->where('email', self::EMAIL)->value('otp'));
    }

    public function test_an_institute_type_in_the_body_is_ignored_rather_than_honoured(): void
    {
        $this->postJson('/api/v1/auth/signup', ['institute_type' => 'college'] + self::VALID)
            ->assertStatus(201);

        self::assertNull(
            DB::table('school_setup')->where('SchoolName', 'Northwind Logistics')->value('institute_type'),
        );
    }

    /**
     * Administrator fields in the body are IGNORED, not honoured.
     *
     * The account is derived. If a caller could still post firstName or email
     * and have them written, there would be two ways to create an administrator
     * and only one of them validated — including a way to set a login address
     * that bypassed the uniqueness rule now living on organizationEmail.
     */
    public function test_administrator_fields_in_the_body_are_ignored(): void
    {
        $tenantId = (int) $this->postJson('/api/v1/auth/signup', [
            'firstName' => 'Mallory',
            'lastName'  => 'Smith',
            'email'     => 'mallory@elsewhere.example',
            'mobile'    => '9999999999',
        ] + self::VALID)->assertStatus(201)->json('organization.id');

        $user = DB::table('tbluser')->where('sub_institute_id', $tenantId)->first();

        self::assertSame(self::EMAIL, $user->email, 'A body-supplied email became the login address.');
        self::assertSame('Administrator', $user->first_name);
        self::assertSame('', $user->last_name);
        self::assertSame('9876543210', $user->mobile);

        self::assertSame(0, DB::table('tbluser')->where('email', 'mallory@elsewhere.example')->count());
    }

    // =====================================================================
    // Throttle
    // =====================================================================

    /**
     * The signup limit is five attempts, and it is worth asserting the NUMBER.
     *
     * This was originally registered inside the group carrying `throttle:10,1`
     * while also declaring `throttle:5,10` of its own. Two unnamed throttle
     * middlewares on one route resolve to the same signature — route URI plus
     * IP — so both incremented the same counter and every request was charged
     * twice. The limit fired on the third signup instead of the sixth.
     *
     * A test that only asserted "eventually 429" would have passed throughout.
     * Counting is the whole point: the failure mode of a double-charged
     * throttle is a working endpoint that silently allows less than half what
     * it claims, which nothing else here would notice.
     */
    public function test_signup_allows_five_attempts_before_throttling(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/auth/signup', ['organizationEmail' => 'not-an-email'] + self::VALID)
                ->assertStatus(422, "Attempt {$i} was throttled; the limit is charged more than once per request.");
        }

        $this->postJson('/api/v1/auth/signup', self::VALID)->assertStatus(429);
    }

    // =====================================================================
    // Atomicity
    // =====================================================================

    /**
     * A failure part-way through leaves NOTHING.
     *
     * org_details is the table to break, not tbluser. Dropping tbluser would
     * fail in SignupRequest — the unique rule reads it — and never reach the
     * transaction, so the test would pass while proving nothing about rollback.
     * org_details is written at step 4, by which point tblclient, school_setup
     * and institute_detail have all inserted successfully inside the same
     * transaction. If the rollback did not cover them, the client and the
     * tenant would survive, and the tenant id they burned with them.
     */
    public function test_a_failure_rolls_the_whole_organization_back(): void
    {
        $clientsBefore = DB::table('tblclient')->count();
        $schoolsBefore = DB::table('school_setup')->count();
        $institutesBefore = DB::table('institute_detail')->count();

        Schema::drop('org_details');

        $this->postJson('/api/v1/auth/signup', self::VALID)->assertStatus(500);

        self::assertSame($clientsBefore, DB::table('tblclient')->count(), 'A client row survived the rollback.');
        self::assertSame($schoolsBefore, DB::table('school_setup')->count(), 'A tenant row survived the rollback.');
        self::assertSame($institutesBefore, DB::table('institute_detail')->count(), 'An organization row survived the rollback.');
        self::assertSame(0, DB::table('institute_detail')->where('organization_name', 'Northwind Logistics')->count());
        self::assertSame(0, DB::table('tbluser')->where('email', self::EMAIL)->count());
        self::assertSame(0, DB::table('hpbrain_entity_mappings')->where('tenant_id', '<>', self::INCUMBENT)->count());

        // The rolled-back email is free again — a failed attempt must not
        // permanently burn the address on a globally unique index.
        $this->rebuildOrgDetails();

        $this->postJson('/api/v1/auth/signup', self::VALID)->assertStatus(201);
    }

    /** The error body must not leak the schema detail that caused the failure. */
    public function test_a_server_failure_does_not_leak_database_detail(): void
    {
        Schema::drop('org_details');

        $response = $this->postJson('/api/v1/auth/signup', self::VALID);

        $response->assertStatus(500)->assertJson([
            'error'   => 'signup_failed',
            'message' => 'We could not create your organization. Please try again.',
        ]);

        $body = $response->getContent();
        self::assertStringNotContainsString('org_details', $body);
        self::assertStringNotContainsString('SQLSTATE', $body);
        self::assertStringNotContainsString(self::PASSWORD, $body);
    }
    // =====================================================================
    // Fixture
    // =====================================================================

    /**
     * The institute ERP, as the live MySQL schema declares it.
     *
     * Nullability is copied deliberately: password, email and user_profile_id
     * are the three NOT NULL columns tbluser actually has, and email carries a
     * global unique index. Those constraints are half of what this suite tests.
     */
    private function buildErpSchema(): void
    {
        Schema::create('tblclient', function ($t) {
            $t->increments('id');
            $t->string('client_name');
            $t->string('short_code')->nullable();
            $t->string('logo')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->string('country')->nullable();
            $t->string('email')->nullable();
            $t->string('contact_person')->nullable();
            $t->string('contact_person_mobile')->nullable();
            $t->string('contact_persoon_email')->nullable();
            $t->integer('number_of_schools')->nullable();
            $t->timestamp('deleted_at')->nullable();
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
            $t->integer('SortOrder')->default(0);
            $t->string('Logo')->nullable();
            $t->integer('client_id')->nullable();
            $t->char('is_lms', 1)->default('N');
            $t->string('syear')->nullable();
            $t->date('expire_date')->nullable();
            $t->text('institute_type')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('institute_detail', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id')->nullable();
            $t->string('organization_name')->nullable();
            $t->string('organization_code')->nullable();
            $t->string('organization_email')->nullable();
            $t->string('organization_ph_no')->nullable();
            $t->string('address')->nullable();
            $t->string('industry_type')->nullable();
            $t->string('handler_name')->nullable();
            $t->string('handler_mobile')->nullable();
            $t->string('handler_email')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('updated_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('org_details', function ($t) {
            $t->increments('id');
            $t->string('legal_name')->nullable();
            $t->text('registered_address')->nullable();
            $t->string('industry')->nullable();
            $t->string('logo')->nullable();
            $t->integer('sub_institute_id')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('updated_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->string('mobile_no', 20)->nullable();
            $t->string('country_code', 5)->default('+91');
            $t->string('email')->nullable();
            $t->string('website', 500)->nullable();
        });

        Schema::create('tbluserprofilemaster', function ($t) {
            $t->increments('id');
            $t->integer('parent_id')->nullable();
            $t->string('name')->nullable();
            $t->string('description')->nullable();
            $t->integer('sort_order')->nullable();
            $t->integer('status')->nullable();
            $t->integer('sub_institute_id')->nullable();
            $t->integer('client_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('tbluser', function ($t) {
            $t->increments('id');
            $t->string('user_name')->nullable();
            $t->string('password');                 // NOT NULL, no default
            $t->string('name_suffix')->nullable();
            $t->string('first_name')->nullable();
            $t->string('middle_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->unique();          // NOT NULL, GLOBALLY unique
            $t->string('mobile')->nullable();
            $t->string('gender', 1)->nullable();
            $t->string('otp')->nullable();
            $t->integer('user_profile_id');         // NOT NULL, no default
            $t->string('join_year')->nullable();
            $t->string('image')->nullable();
            $t->string('plain_password')->nullable();
            $t->integer('sub_institute_id')->nullable();
            $t->integer('client_id')->nullable();
            $t->integer('department_id')->nullable();
            $t->integer('jobtitle_id')->nullable();
            $t->string('employee_no')->nullable();
            $t->boolean('is_admin')->default(false);
            $t->integer('status')->default(1);
            $t->date('joined_date')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        // Mapped by EntityMappingSeeder, so they must exist for the resolver to
        // return a complete tenant even though signup writes no rows in them.
        Schema::create('hrms_departments', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('department');
            $t->text('roles_responsibility')->nullable();
            $t->integer('parent_id')->default(0);
            $t->integer('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('hrms_job_titles', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('title');
            $t->integer('is_active')->default(1);
        });
    }

    /** Only the Brain tables the signup and login paths actually touch. */
    private function buildBrainTables(): void
    {
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

        Schema::create('hpbrain_audit_logs', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36)->nullable();
            $t->string('actor_id')->nullable();
            $t->string('action')->nullable();
            $t->string('entity_type')->nullable();
            $t->string('entity_id')->nullable();
            $t->text('changes')->nullable();
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('hpbrain_data_sources', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('source_key', 190);
            $t->string('display_name');
            $t->string('source_type', 50)->default('csv_upload');
            $t->text('config')->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('last_synced_at')->nullable();
            $t->string('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
            $t->unique(['tenant_id', 'source_key'], 'data_sources_tenant_key_unique');
        });
    }

    /**
     * One organization that already exists, mapped, with a user of its own.
     *
     * Present so the isolation tests have a real neighbour to fail to reach,
     * and so the entity-mapping assertions can tell "signup wrote mappings for
     * its tenant" apart from "mappings happened to exist".
     */
    private function seedIncumbentTenant(): void
    {
        DB::table('tblclient')->insert(['id' => 1, 'client_name' => 'Incumbent Org']);

        DB::table('school_setup')->insert([
            'id' => (int) self::INCUMBENT, 'SchoolName' => 'Incumbent Org', 'client_id' => 1,
        ]);

        DB::table('institute_detail')->insert([
            'sub_institute_id'  => (int) self::INCUMBENT,
            'organization_name' => 'Incumbent Org',
            'organization_code' => 'INC',
            'industry_type'     => 'Education',
        ]);

        DB::table('org_details')->insert([
            'sub_institute_id' => (int) self::INCUMBENT, 'legal_name' => 'Incumbent Org Ltd',
        ]);

        DB::table('tbluserprofilemaster')->insert([
            'id' => 1, 'name' => 'Admin', 'status' => 1, 'sub_institute_id' => (int) self::INCUMBENT,
        ]);

        $this->installEntityMappings([self::INCUMBENT]);
    }

    /** Recreate org_details after a rollback test has dropped it. */
    private function rebuildOrgDetails(): void
    {
        Schema::create('org_details', function ($t) {
            $t->increments('id');
            $t->string('legal_name')->nullable();
            $t->text('registered_address')->nullable();
            $t->string('industry')->nullable();
            $t->string('logo')->nullable();
            $t->integer('sub_institute_id')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('updated_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->string('mobile_no', 20)->nullable();
            $t->string('country_code', 5)->default('+91');
            $t->string('email')->nullable();
            $t->string('website', 500)->nullable();
        });
    }
}
