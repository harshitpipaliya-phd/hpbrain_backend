<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Context\ContextEngine;
use App\Domain\Context\ContextQuery;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\Support\SeedsEntityMappings;
use Tests\TestCase;

/**
 * The Context Engine, attacked.
 *
 * WHY THIS FILE IS SEPARATE FROM ContextEngineTest. That one proves the engine
 * answers correctly. This one assumes the caller is hostile and proves that
 * every route into another organization's data is closed — object, user,
 * organization, signals and session, one test each, plus the transport-level
 * gates the endpoint inherits.
 *
 * THE FIXTURE IS BUILT TO MAKE A LEAK VISIBLE RATHER THAN PLAUSIBLE. Tenant
 * BETA holds a signal whose related_entity_id is ALPHA's person id. Nothing
 * legitimate produces that row; it exists so that a signal read which ever
 * lost its tenant predicate would return a finding about the wrong
 * organization's employee, and this file would fail rather than the leak
 * reaching an Assistant transcript.
 *
 * A CROSS-TENANT LOOKUP MUST ANSWER "NOT FOUND", NOT "FORBIDDEN". A 403 on an
 * id that exists elsewhere and a 404 on one that exists nowhere are
 * distinguishable, and the difference confirms which ids are live in another
 * organization. Every assertion below expects the same present:false /
 * object_not_found as a wholly imaginary id.
 */
final class ContextTenantIsolationTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;
    use SeedsEntityMappings;

    private const ALPHA = '4';

    private const BETA = '5';

    /** ALPHA's person. Has no signals of its own. */
    private const ALPHA_PERSON = 101;

    /** BETA's person. Has one signal. */
    private const BETA_PERSON = 201;

    /** ALPHA's department. */
    private const ALPHA_UNIT = 301;

    /** BETA's department. */
    private const BETA_UNIT = 401;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->installEntityMappings([self::ALPHA, self::BETA]);
        $this->seedTenant(self::ALPHA, 'Alpha Health', self::ALPHA_PERSON, 'Asha', self::ALPHA_UNIT, 'Nursing');
        $this->seedTenant(self::BETA, 'Beta Telecom', self::BETA_PERSON, 'Bruno', self::BETA_UNIT, 'Sales');
        $this->seedSignals();
    }

    // ---- Transport gates the endpoint inherits ----------------------------

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/context/'.self::ALPHA.'?screen=person-profile')
            ->assertStatus(401)
            ->assertJson(['error' => 'missing_token']);
    }

    public function test_a_route_tenant_that_differs_from_the_token_is_refused(): void
    {
        // Not 404, not an empty context — 403, before the engine runs at all.
        // EnsureTenantScope owns this and the endpoint must not soften it.
        $this->getJson(
            '/api/v1/context/'.self::BETA.'?screen=person-profile&objectId='.self::BETA_PERSON,
            $this->token(self::ALPHA),
        )->assertStatus(403)->assertJson(['error' => 'tenant_mismatch']);
    }

    /**
     * Including for an admin. A route tenant is allowed to narrow to the token's
     * tenant and nothing else; it is not an organization switch.
     */
    public function test_an_admin_cannot_address_another_tenant_either(): void
    {
        $this->getJson(
            '/api/v1/context/'.self::BETA,
            $this->token(self::ALPHA, role: 'admin'),
        )->assertStatus(403);
    }

    /**
     * A tenant supplied as a query parameter is not read anywhere. The
     * organization that comes back is the token's, named as the token's ERP
     * names it.
     */
    public function test_a_tenant_id_query_parameter_cannot_redirect_the_lookup(): void
    {
        $body = $this->context(self::ALPHA, [
            'tenantId' => self::BETA,
            'screen'   => 'person-profile',
            'objectId' => (string) self::ALPHA_PERSON,
        ])->assertOk()->json();

        self::assertSame(self::ALPHA, $body['tenantId']);
        self::assertSame('Alpha Health', $body['organization']['name']);
        self::assertSame('Asha', $body['object']['data']['firstName']);
    }

    // ---- Layer by layer ---------------------------------------------------

    public function test_tenant_a_cannot_resolve_tenant_bs_object(): void
    {
        $body = $this->context(self::ALPHA, [
            'screen'   => 'person-profile',
            'objectId' => (string) self::BETA_PERSON,
        ])->assertOk()->json();

        self::assertFalse($body['object']['present']);
        // The SAME reason a wholly imaginary id gets — see the class docblock.
        self::assertSame('object_not_found', $body['object']['reason']);
        self::assertNull($body['object']['data']);

        // And the name is nowhere in the response, not even in a diagnostic.
        self::assertStringNotContainsString('Bruno', (string) json_encode($body));
    }

    public function test_a_cross_tenant_object_is_indistinguishable_from_a_nonexistent_one(): void
    {
        $foreign = $this->context(self::ALPHA, [
            'screen' => 'person-profile', 'objectId' => (string) self::BETA_PERSON,
        ])->json('object');

        $imaginary = $this->context(self::ALPHA, [
            'screen' => 'person-profile', 'objectId' => '999999',
        ])->json('object');

        // Identical but for the echoed id. A caller cannot tell from the
        // response which of the two ids exists in another organization.
        unset($foreign['id'], $imaginary['id']);

        self::assertSame($imaginary, $foreign);
    }

    public function test_tenant_a_cannot_resolve_tenant_bs_department(): void
    {
        $body = $this->context(self::ALPHA, [
            'screen'   => 'department-profile',
            'objectId' => (string) self::BETA_UNIT,
        ])->assertOk()->json();

        self::assertFalse($body['object']['present']);
        self::assertSame('object_not_found', $body['object']['reason']);
        self::assertStringNotContainsString('Sales', (string) json_encode($body));
    }

    /**
     * THE SIGNAL LEAK THIS FIXTURE WAS BUILT FOR. BETA holds a signal whose
     * subject id is ALPHA's person id. ALPHA resolving its own person must see
     * zero signals — the row belongs to another organization and its id
     * matching is a coincidence the tenant predicate exists to survive.
     */
    public function test_tenant_a_never_receives_tenant_bs_signals_about_a_colliding_id(): void
    {
        $body = $this->context(self::ALPHA, [
            'screen'   => 'person-profile',
            'objectId' => (string) self::ALPHA_PERSON,
        ])->assertOk()->json();

        self::assertTrue($body['object']['present'], 'ALPHA lost access to its own person.');
        self::assertTrue($body['signals']['present']);
        self::assertSame(0, $body['signals']['count'], 'A signal crossed a tenant boundary.');
        self::assertStringNotContainsString('beta_only_finding', (string) json_encode($body));
    }

    /** The rightful owner still sees it, so the guard above rejects the tenant
     *  rather than breaking the read. */
    public function test_tenant_b_still_sees_its_own_signal(): void
    {
        $body = $this->context(self::BETA, [
            'screen'   => 'person-profile',
            'objectId' => (string) self::BETA_PERSON,
        ], userId: (string) self::BETA_PERSON)->assertOk()->json();

        self::assertSame(1, $body['signals']['count']);
        self::assertSame('beta_only_finding', $body['signals']['items'][0]['rule_key']);
    }

    public function test_the_organization_layer_is_always_the_tokens_own(): void
    {
        self::assertSame(
            'Alpha Health',
            $this->context(self::ALPHA, ['screen' => 'home'])->json('organization.name'),
        );

        self::assertSame(
            'Beta Telecom',
            $this->context(self::BETA, ['screen' => 'home'], userId: (string) self::BETA_PERSON)
                ->json('organization.name'),
        );
    }

    /**
     * A token whose `sub` belongs to another tenant's ERP. The tenant claim is
     * what scopes the read, so the person is looked for in ALPHA and is not
     * there — the user layer reports the gap and no BETA employee is described.
     */
    public function test_a_user_id_from_another_tenant_does_not_resolve(): void
    {
        $body = $this->context(self::ALPHA, [
            'screen' => 'person-profile', 'objectId' => (string) self::ALPHA_PERSON,
        ], userId: (string) self::BETA_PERSON)->assertOk()->json();

        self::assertFalse($body['user']['present']);
        self::assertSame('user_record_not_found', $body['user']['reason']);
        self::assertNull($body['user']['data']);
        self::assertStringNotContainsString('Bruno', (string) json_encode($body));
    }

    /**
     * The session layer carries the caller's own screen and token identity and
     * nothing that could belong to another organization.
     */
    public function test_the_session_layer_carries_nothing_tenant_owned(): void
    {
        $session = $this->context(self::ALPHA, [
            'screen' => 'person-profile', 'objectId' => (string) self::ALPHA_PERSON,
        ])->json('session');

        /*
          ASSERTED ON THE KEY SET, NOT BY SCANNING THE VALUES. A substring
          search is the wrong instrument here and this test was briefly written
          that way: tenant ids in this fixture are single digits, so looking for
          "5" inside the payload matched a character of the token's own random
          jti and failed on a response that was entirely correct.

          The structural assertion is also the stronger one. These five keys are
          all the layer has, and each is either the caller's own input or a
          claim from the caller's own token — so a field carrying anything
          tenant-owned could only arrive as a SIXTH key, which this catches
          whatever its value happens to be.
        */
        self::assertSame(
            ['present', 'screen', 'screenKnown', 'id', 'expiresAt'],
            array_keys($session),
        );

        self::assertSame('person-profile', $session['screen']);

        // The distinctive names from the other organization, which a leak would
        // have to spell out in full.
        foreach (['Beta Telecom', 'Bruno', 'Sales'] as $foreign) {
            self::assertStringNotContainsString($foreign, (string) json_encode($session));
        }
    }

    // ---- A tenant that is not provisioned at all --------------------------

    /**
     * A validly-signed token for a tenant with no mappings — the state a
     * permanently-deleted organization leaves behind.
     *
     * Every layer fails closed and names the reason. Nothing is borrowed from
     * ALPHA or BETA, which is the property EntityResolver's no-fallback design
     * guarantees and which the engine must not undo.
     */
    public function test_an_unprovisioned_tenant_resolves_nothing_and_borrows_nothing(): void
    {
        $body = $this->context('ghost-tenant', [
            'screen' => 'person-profile', 'objectId' => (string) self::ALPHA_PERSON,
        ])->assertOk()->json();

        self::assertFalse($body['object']['present']);
        self::assertSame('entity_not_mapped_for_tenant', $body['object']['reason']);

        self::assertFalse($body['user']['present']);
        self::assertSame('entity_not_mapped_for_tenant', $body['user']['reason']);

        self::assertFalse($body['organization']['present']);
        self::assertSame('entity_not_mapped_for_tenant', $body['organization']['reason']);

        self::assertFalse($body['signals']['present']);
        self::assertSame('no_object_resolved', $body['signals']['reason']);

        self::assertStringNotContainsString('Asha', (string) json_encode($body));
    }

    /**
     * The engine itself, called with a foreign tenant directly.
     *
     * The HTTP layer cannot produce this — EnsureTenantScope refuses it — but
     * the engine is a Domain service other code may call, and its tenant
     * argument has to be load-bearing on its own rather than only because a
     * middleware checked it first.
     */
    public function test_the_engine_scopes_by_its_tenant_argument_alone(): void
    {
        $context = app(ContextEngine::class)->resolve(new ContextQuery(
            tenantId: self::ALPHA,
            userId: (string) self::ALPHA_PERSON,
            role: 'admin',
            screen: 'person-profile',
            objectId: (string) self::BETA_PERSON,
        ));

        self::assertFalse($context->object->present);
        self::assertSame('object_not_found', $context->object->reason);
        self::assertSame('Alpha Health', $context->organization->data['name']);
    }

    // ---- Fixture -----------------------------------------------------------

    /** @return array<string, string> */
    private function token(string $tenant, ?string $userId = null, string $role = 'admin'): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id'       => $userId ?? (string) self::ALPHA_PERSON,
            'tenantId' => $tenant,
            'role'     => $role,
        ])];
    }

    /** @param array<string, string> $query */
    private function context(string $tenant, array $query, ?string $userId = null): TestResponse
    {
        return $this->getJson(
            '/api/v1/context/'.$tenant.'?'.http_build_query($query),
            $this->token($tenant, $userId),
        );
    }

    private function seedTenant(
        string $tenant,
        string $name,
        int $personId,
        string $firstName,
        int $unitId,
        string $unitName,
    ): void {
        DB::table('institute_detail')->insert([
            'sub_institute_id' => (int) $tenant,
            'organization_name' => $name,
            'organization_code' => strtoupper(substr($name, 0, 3)),
            'industry_type' => 'Healthcare',
        ]);

        DB::table('org_details')->insert([
            'sub_institute_id' => (int) $tenant, 'legal_name' => $name.' Pvt Ltd',
        ]);

        DB::table('hrms_departments')->insert([
            'id' => $unitId, 'sub_institute_id' => (int) $tenant,
            'department' => $unitName, 'parent_id' => 0, 'status' => 1,
        ]);

        DB::table('tbluser')->insert([
            'id' => $personId, 'sub_institute_id' => (int) $tenant, 'employee_no' => 'E'.$personId,
            'first_name' => $firstName, 'last_name' => 'Owner', 'email' => $firstName.'@x.test',
            'department_id' => $unitId, 'user_profile_id' => (int) $tenant, 'jobtitle_id' => (int) $tenant,
            'status' => 1,
        ]);

        DB::table('tbluserprofilemaster')->insert([
            'id' => (int) $tenant, 'sub_institute_id' => (int) $tenant, 'name' => 'Super Admin', 'status' => 1,
        ]);

        DB::table('hrms_job_titles')->insert([
            'id' => (int) $tenant, 'sub_institute_id' => (int) $tenant, 'title' => 'Staff', 'is_active' => 1,
        ]);
    }

    /**
     * One signal in BETA about BETA's own person, and one in BETA whose subject
     * id is ALPHA's person id. The second is the trap.
     */
    private function seedSignals(): void
    {
        foreach ([(string) self::BETA_PERSON, (string) self::ALPHA_PERSON] as $subjectId) {
            DB::table('hpbrain_signals')->insert([
                'id' => Uuid::uuid4()->toString(),
                'tenant_id' => self::BETA,
                'org_id' => self::BETA,
                'source' => 'erp.data_quality',
                'classification' => 'workforce',
                'rule_key' => 'beta_only_finding',
                'priority' => 'high',
                'severity' => 'high',
                'confidence' => 1.0,
                'related_entity_type' => 'Person',
                'related_entity_id' => $subjectId,
                'status' => 'new',
                'metadata' => json_encode(['rule' => 'beta_only_finding', 'tenant' => self::BETA]),
                'created_by' => 'system',
                'created_date' => '2026-08-25 07:12:52',
                'updated_date' => '2026-08-25 07:12:52',
            ]);
        }
    }
}
