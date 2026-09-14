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
 * GET /v1/context/{tenantId} — the Context Engine.
 *
 * WHAT THESE TESTS ARE ACTUALLY DEFENDING. The engine's whole value is that a
 * consumer can trust every field in its output, so the assertions that matter
 * most are the negative ones: an object that does not resolve must say so and
 * name why, and it must never be filled in from a neighbouring row, a default,
 * or the caller's own request. Half the cases below exist to prove an absence
 * is reported as an absence.
 *
 * THE FIXTURE IS SHAPED LIKE THE LIVE DATABASE, including its inconsistency.
 * Tenant 1000018 in production carries twenty-six signals whose
 * related_entity_type is `Department` and four whose type is `Person`, and the
 * id spaces of those two overlap — so the fixture gives department 2050 and
 * person 2050 the same id on purpose. If the type predicate were ever dropped
 * from the signal read, that pair is what catches it.
 */
final class ContextEngineTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;
    use SeedsEntityMappings;

    private const TENANT = '4';

    /** A real person in TENANT, with one signal about them. */
    private const PERSON = 1;

    /** A person in TENANT with no signal at all. */
    private const PERSON_WITHOUT_SIGNALS = 2;

    /** Soft-deleted in the ERP. Exists as a row, must not resolve. */
    private const DELETED_PERSON = 9;

    /**
     * A department, and a person, sharing one id.
     *
     * The department has signals; the person has none. Any confusion between
     * them shows up as the person inheriting the department's findings.
     */
    private const SHARED_ID = 2050;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->installEntityMappings([self::TENANT]);
        $this->seedErp();
        $this->seedSignals();
    }

    // ---- The contract ------------------------------------------------------

    public function test_it_resolves_all_five_layers_for_a_real_object(): void
    {
        $body = $this->context(['screen' => 'person-profile', 'objectId' => (string) self::PERSON])
            ->assertOk()
            ->json();

        self::assertSame(self::TENANT, $body['tenantId']);

        // OBJECT — a row that was read, by primary key and tenant key.
        self::assertTrue($body['object']['present']);
        self::assertSame('Person', $body['object']['type']);
        self::assertSame('1', $body['object']['id']);
        self::assertSame('Asha', $body['object']['data']['firstName']);
        self::assertSame('Rao', $body['object']['data']['lastName']);

        // USER — the caller, resolved through the same Person mapping.
        self::assertTrue($body['user']['present']);
        self::assertSame('1', $body['user']['id']);
        self::assertSame('admin', $body['user']['role']);
        self::assertSame('Asha', $body['user']['data']['firstName']);

        // ORGANIZATION — the tenant's own row, named as the ERP names it.
        self::assertTrue($body['organization']['present']);
        self::assertSame(self::TENANT, $body['organization']['id']);
        self::assertSame('SIDS HealthCare', $body['organization']['name']);

        // SIGNALS — the real rows whose recorded subject is this object.
        self::assertTrue($body['signals']['present']);
        self::assertSame(1, $body['signals']['count']);
        self::assertSame('people_without_department', $body['signals']['items'][0]['rule_key']);
        self::assertSame('Person', $body['signals']['items'][0]['related_entity_type']);

        // SESSION — the screen, and the identity of the token it arrived on.
        self::assertTrue($body['session']['present']);
        self::assertSame('person-profile', $body['session']['screen']);
        self::assertTrue($body['session']['screenKnown']);
        self::assertNotNull($body['session']['id']);

        // An absent layer carries a reason; a present one carries none, or the
        // two states would be distinguishable only by convention.
        foreach (['object', 'user', 'organization', 'signals', 'session'] as $layer) {
            self::assertArrayNotHasKey('reason', $body[$layer], "Present layer {$layer} carried a reason.");
        }
    }

    /**
     * The JSON column arrives decoded, as every other hpbrain_* read delivers
     * it. A consumer calling ->map on the six characters of a JSON string is
     * the defect BaseRepository::hydrate() exists to prevent.
     */
    public function test_signal_metadata_arrives_as_a_structure_not_a_string(): void
    {
        $metadata = $this->context(['screen' => 'person-profile', 'objectId' => (string) self::PERSON])
            ->json('signals.items.0.metadata');

        self::assertIsArray($metadata);
        self::assertSame(1, $metadata['affectedCount']);
    }

    // ---- Honest absence ----------------------------------------------------

    public function test_no_object_id_is_reported_as_no_object_requested(): void
    {
        $body = $this->context(['screen' => 'person-profile'])->assertOk()->json();

        self::assertFalse($body['object']['present']);
        self::assertSame('no_object_requested', $body['object']['reason']);
        self::assertNull($body['object']['data']);

        // The other four layers are unaffected: a request with no object is
        // still a real question about the tenant, the user and the session.
        self::assertTrue($body['user']['present']);
        self::assertTrue($body['organization']['present']);
        self::assertTrue($body['session']['present']);

        // Signals are ABSENT, not empty. There is no subject to ask about, and
        // answering "0 signals" would state a fact about an object that was
        // never resolved.
        self::assertFalse($body['signals']['present']);
        self::assertSame('no_object_resolved', $body['signals']['reason']);
        self::assertSame([], $body['signals']['items']);
    }

    public function test_an_undeclared_screen_resolves_no_object_and_says_so(): void
    {
        $body = $this->context(['screen' => 'not-a-real-screen', 'objectId' => (string) self::PERSON])
            ->assertOk()
            ->json();

        self::assertFalse($body['object']['present']);
        self::assertSame('unknown_screen', $body['object']['reason']);

        // The screen is still echoed, marked unknown — so a caller with a typo
        // learns why the object is missing instead of silently receiving none.
        self::assertSame('not-a-real-screen', $body['session']['screen']);
        self::assertFalse($body['session']['screenKnown']);
    }

    /**
     * A tenant-wide screen is BOUND — to nothing. That is a different fact from
     * a screen nobody has declared, and the reasons must differ.
     */
    public function test_a_tenant_wide_screen_is_distinguished_from_an_unknown_one(): void
    {
        $body = $this->context(['screen' => 'signals', 'objectId' => (string) self::PERSON])
            ->assertOk()
            ->json();

        self::assertFalse($body['object']['present']);
        self::assertSame('screen_not_bound_to_entity', $body['object']['reason']);
        self::assertTrue($body['session']['screenKnown']);
    }

    public function test_an_explicit_object_type_overrides_the_screens_binding(): void
    {
        $body = $this->context([
            'screen'     => 'signals',
            'objectId'   => (string) self::PERSON,
            'objectType' => 'Person',
        ])->assertOk()->json();

        self::assertTrue($body['object']['present']);
        self::assertSame('Person', $body['object']['type']);
    }

    public function test_an_object_type_outside_the_vocabulary_is_refused(): void
    {
        // 422, not a present:false object. The caller mistyped a parameter, and
        // answering "no object" would describe that as a fact about the tenant.
        $this->context([
            'screen'     => 'person-profile',
            'objectId'   => (string) self::PERSON,
            'objectType' => 'Invoice',
        ])->assertStatus(422);
    }

    public function test_a_missing_object_is_not_found_rather_than_guessed(): void
    {
        $body = $this->context(['screen' => 'person-profile', 'objectId' => '987654'])
            ->assertOk()
            ->json();

        self::assertFalse($body['object']['present']);
        self::assertSame('object_not_found', $body['object']['reason']);
        // The type and the id asked for are echoed; nothing else is invented.
        self::assertSame('Person', $body['object']['type']);
        self::assertSame('987654', $body['object']['id']);
        self::assertNull($body['object']['data']);
    }

    public function test_a_soft_deleted_object_does_not_resolve(): void
    {
        $body = $this->context(['screen' => 'person-profile', 'objectId' => (string) self::DELETED_PERSON])
            ->assertOk()
            ->json();

        self::assertFalse($body['object']['present']);
        self::assertSame('object_not_found', $body['object']['reason']);
    }

    public function test_an_object_with_no_signals_reports_zero_rather_than_absent(): void
    {
        $body = $this->context([
            'screen'   => 'person-profile',
            'objectId' => (string) self::PERSON_WITHOUT_SIGNALS,
        ])->assertOk()->json();

        self::assertTrue($body['object']['present']);

        // PRESENT and empty. The table was read and this person has none —
        // which is a fact about the organization, not a failure to look.
        self::assertTrue($body['signals']['present']);
        self::assertSame(0, $body['signals']['count']);
        self::assertSame([], $body['signals']['items']);
        self::assertArrayNotHasKey('reason', $body['signals']);
    }

    /**
     * Student is the one entity an HR-shaped ERP legitimately does not have.
     * This fixture has no student table, so the mapping is absent — and the
     * engine must report that rather than reach for the person table.
     */
    public function test_an_unmapped_entity_fails_closed(): void
    {
        $body = $this->context(['screen' => 'student-profile', 'objectId' => '1'])
            ->assertOk()
            ->json();

        self::assertFalse($body['object']['present']);
        self::assertSame('entity_not_mapped_for_tenant', $body['object']['reason']);
        self::assertSame('Student', $body['object']['type']);
        self::assertNull($body['object']['data']);
    }

    /**
     * An id wider than hpbrain_signals.related_entity_id cannot be a subject
     * this system has ever recorded. Asserted against the engine directly: the
     * HTTP layer caps the parameter at 36 characters, so the guard inside the
     * engine is unreachable through a request and would otherwise go unproven.
     */
    public function test_an_over_long_object_id_is_rejected_by_the_engine(): void
    {
        $context = app(ContextEngine::class)->resolve(new ContextQuery(
            tenantId: self::TENANT,
            userId: (string) self::PERSON,
            role: 'admin',
            screen: 'person-profile',
            objectId: str_repeat('9', 37),
        ));

        self::assertFalse($context->object->present);
        self::assertSame('object_id_unusable', $context->object->reason);
    }

    public function test_a_token_with_no_person_row_reports_the_role_and_the_gap(): void
    {
        // A validly-signed token for this tenant naming a person who is not in
        // its ERP. The identity is real and the record is not, and the response
        // has to say both — dropping the role would misdescribe the request
        // that authorization actually allowed.
        $body = $this->context(
            ['screen' => 'person-profile', 'objectId' => (string) self::PERSON],
            userId: '777777',
        )->assertOk()->json();

        self::assertFalse($body['user']['present']);
        self::assertSame('user_record_not_found', $body['user']['reason']);
        self::assertSame('777777', $body['user']['id']);
        self::assertSame('admin', $body['user']['role']);
        self::assertNull($body['user']['data']);

        // The object is unaffected — the screen still shows what it shows.
        self::assertTrue($body['object']['present']);
    }

    // ---- The signal subject vocabulary ------------------------------------

    /**
     * The twenty-six live signals that say `Department` rather than
     * `OrganizationUnit` must be reachable, and must report the type actually
     * stored on their row rather than a rewritten one.
     */
    public function test_department_typed_signals_resolve_for_an_organization_unit(): void
    {
        $body = $this->context([
            'screen'   => 'department-profile',
            'objectId' => (string) self::SHARED_ID,
        ])->assertOk()->json();

        self::assertTrue($body['object']['present']);
        self::assertSame('OrganizationUnit', $body['object']['type']);
        self::assertSame('Sales', $body['object']['data']['name']);

        self::assertSame(1, $body['signals']['count']);
        self::assertSame('workload_concentration', $body['signals']['items'][0]['rule_key']);
        // Verbatim, not normalised to the universal name.
        self::assertSame('Department', $body['signals']['items'][0]['related_entity_type']);
    }

    /**
     * THE COLLISION GUARD. Department 2050 and person 2050 are different rows
     * in different tables. Matching signals on related_entity_id alone would
     * hand the department's findings to the person.
     */
    public function test_a_person_does_not_inherit_signals_belonging_to_a_department_with_the_same_id(): void
    {
        $body = $this->context([
            'screen'   => 'person-profile',
            'objectId' => (string) self::SHARED_ID,
        ])->assertOk()->json();

        self::assertTrue($body['object']['present']);
        self::assertSame('Person', $body['object']['type']);
        self::assertSame('Priya', $body['object']['data']['firstName']);

        self::assertTrue($body['signals']['present']);
        self::assertSame(0, $body['signals']['count'], 'A person picked up a department\'s signals.');
    }

    // ---- What must never be in the payload --------------------------------

    /**
     * The engine answers "what is on the screen", which needs a label and an
     * id. Email, phone, date of birth and gender are all mapped for Person and
     * none of them is needed for that; a context payload is the wrong place to
     * widen who can read them, and this one is handed to a model.
     */
    public function test_no_contact_details_appear_in_the_object_or_user_layers(): void
    {
        $body = $this->context(['screen' => 'person-profile', 'objectId' => (string) self::PERSON])
            ->assertOk()
            ->json();

        foreach (['object', 'user'] as $layer) {
            foreach (['email', 'phone', 'gender', 'birthDate', 'password', 'plain_password'] as $field) {
                self::assertArrayNotHasKey(
                    $field,
                    $body[$layer]['data'],
                    "{$layer} leaked {$field}."
                );
            }
        }
    }

    public function test_the_session_layer_exposes_no_token_or_secret(): void
    {
        $session = $this->context(['screen' => 'person-profile'])->assertOk()->json('session');

        self::assertSame(
            ['present', 'screen', 'screenKnown', 'id', 'expiresAt'],
            array_keys($session),
            'The session layer gained a field; check it is not a credential.'
        );

        $encoded = json_encode($session);

        foreach (['Bearer', 'eyJ', 'authorization', 'cookie', config('brain.jwt_secret')] as $forbidden) {
            if ((string) $forbidden === '') {
                continue;
            }

            self::assertStringNotContainsStringIgnoringCase((string) $forbidden, (string) $encoded);
        }
    }

    // ---- Fixture -----------------------------------------------------------

    /**
     * @param  array<string, string>  $query
     */
    private function context(array $query, ?string $userId = null, string $role = 'admin'): TestResponse
    {
        return $this->getJson(
            '/api/v1/context/'.self::TENANT.'?'.http_build_query($query),
            ['Authorization' => 'Bearer '.Jwt::issueAccess([
                'id'       => $userId ?? (string) self::PERSON,
                'tenantId' => self::TENANT,
                'role'     => $role,
            ])],
        );
    }

    /**
     * One organization, three departments, four active people and one deleted.
     *
     * Department 2050 and person 2050 share an id deliberately — see the
     * SHARED_ID docblock.
     */
    private function seedErp(): void
    {
        DB::table('institute_detail')->insert([
            'sub_institute_id' => (int) self::TENANT,
            'organization_name' => 'SIDS HealthCare',
            'organization_code' => 'SIDS',
            'industry_type' => 'Healthcare',
        ]);

        DB::table('org_details')->insert([
            'sub_institute_id' => (int) self::TENANT, 'legal_name' => 'SIDS HealthCare Pvt Ltd',
        ]);

        DB::table('hrms_departments')->insert([
            ['id' => 1, 'sub_institute_id' => (int) self::TENANT, 'department' => 'Nursing', 'parent_id' => 0, 'status' => 1],
            ['id' => self::SHARED_ID, 'sub_institute_id' => (int) self::TENANT, 'department' => 'Sales', 'parent_id' => 1, 'status' => 1],
        ]);

        $people = [
            [self::PERSON, 'E1', 'Asha', 'Rao', 'asha@x.test', null],
            [self::PERSON_WITHOUT_SIGNALS, 'E2', 'Bilal', 'Khan', 'bilal@x.test', null],
            [self::SHARED_ID, 'E3', 'Priya', 'Nair', 'priya@x.test', null],
            [self::DELETED_PERSON, 'E9', 'Gone', 'Person', 'gone@x.test', '2026-01-01 00:00:00'],
        ];

        foreach ($people as [$id, $no, $first, $last, $email, $deletedAt]) {
            DB::table('tbluser')->insert([
                'id' => $id, 'sub_institute_id' => (int) self::TENANT, 'employee_no' => $no,
                'first_name' => $first, 'last_name' => $last, 'email' => $email,
                'department_id' => 1, 'user_profile_id' => 1, 'jobtitle_id' => 1,
                'status' => 1, 'deleted_at' => $deletedAt,
            ]);
        }

        DB::table('tbluserprofilemaster')->insert([
            'id' => 1, 'sub_institute_id' => (int) self::TENANT, 'name' => 'Super Admin', 'status' => 1,
        ]);

        DB::table('hrms_job_titles')->insert([
            'id' => 1, 'sub_institute_id' => (int) self::TENANT, 'title' => 'Staff Nurse', 'is_active' => 1,
        ]);
    }

    /**
     * One Person-typed signal and one Department-typed one, exactly as the live
     * table holds both spellings.
     */
    private function seedSignals(): void
    {
        $this->signal('Person', (string) self::PERSON, 'people_without_department', [
            'rule' => 'people_without_department', 'affectedCount' => 1, 'sampleIds' => [self::PERSON],
        ]);

        $this->signal('Department', (string) self::SHARED_ID, 'workload_concentration', [
            'title' => 'Workload concentrated in a single unit — Sales', 'observedPeople' => 1,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function signal(string $type, string $entityId, string $ruleKey, array $metadata): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::TENANT,
            'org_id' => self::TENANT,
            'source' => 'erp.data_quality',
            'classification' => 'workforce',
            'rule_key' => $ruleKey,
            'priority' => 'medium',
            'severity' => 'medium',
            'confidence' => 1.0,
            'related_entity_type' => $type,
            'related_entity_id' => $entityId,
            'status' => 'new',
            'metadata' => json_encode($metadata),
            'created_by' => 'system',
            'created_date' => '2026-08-25 07:12:52',
            'updated_date' => '2026-08-25 07:25:09',
        ]);
    }
}
