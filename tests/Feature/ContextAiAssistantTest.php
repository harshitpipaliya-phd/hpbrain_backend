<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\Support\SeedsEntityMappings;
use Tests\TestCase;

/**
 * The Context Engine where it is actually wired in: opening the AI Assistant.
 *
 * THE ONE INTEGRATION POINT. The engine is not called from any screen, any
 * dashboard or any other controller — only from POST /v1/ai/workspace/sessions,
 * so that a conversation started over a person's profile knows which person.
 *
 * WHAT THESE TESTS PIN DOWN:
 *
 *   1. The session row records the object it was opened over, in the
 *      context_type / context_entity_id columns that have existed since
 *      2026_01_01_001600 and that nothing has ever written.
 *   2. It records the object the SERVER resolved, never the id the caller sent.
 *      A foreign or imaginary id leaves the session with no subject rather than
 *      with a subject that does not exist in this organization.
 *   3. Omitting the context parameters behaves exactly as before, because every
 *      existing client omits them.
 *   4. Nothing is reasoned over and nothing else is written. No model is called
 *      on this path, and the only row created is the session itself.
 */
final class ContextAiAssistantTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;
    use SeedsEntityMappings;

    private const TENANT = '4';

    private const OTHER_TENANT = '5';

    private const PERSON = 101;

    private const OTHER_PERSON = 201;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->installEntityMappings([self::TENANT, self::OTHER_TENANT]);
        $this->seedTenant(self::TENANT, 'Alpha Health', self::PERSON, 'Asha');
        $this->seedTenant(self::OTHER_TENANT, 'Beta Telecom', self::OTHER_PERSON, 'Bruno');

        DB::table('hpbrain_signals')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::TENANT,
            'org_id' => self::TENANT,
            'source' => 'erp.data_quality',
            'classification' => 'workforce',
            'rule_key' => 'people_without_department',
            'priority' => 'medium',
            'severity' => 'medium',
            'confidence' => 1.0,
            'related_entity_type' => 'Person',
            'related_entity_id' => (string) self::PERSON,
            'status' => 'new',
            'metadata' => json_encode(['rule' => 'people_without_department', 'affectedCount' => 1]),
            'created_by' => 'system',
            'created_date' => '2026-08-25 07:12:52',
            'updated_date' => '2026-08-25 07:12:52',
        ]);
    }

    public function test_opening_the_assistant_over_a_person_binds_that_person_to_the_session(): void
    {
        $body = $this->open([
            'title'    => 'Why is Asha flagged?',
            'screen'   => 'person-profile',
            'objectId' => (string) self::PERSON,
        ])->assertStatus(201)->json();

        // The context travels back with the session, so the Assistant does not
        // need a second call to learn what it was opened over.
        self::assertTrue($body['context']['object']['present']);
        self::assertSame('Person', $body['context']['object']['type']);
        self::assertSame('Asha', $body['context']['object']['data']['firstName']);
        self::assertSame(1, $body['context']['signals']['count']);
        self::assertSame('Alpha Health', $body['context']['organization']['name']);

        // And the same resolution is what was persisted — one lookup, not two
        // that could disagree.
        $row = DB::table('hpbrain_conversation_sessions')->where('id', $body['id'])->first();

        self::assertSame('Person', $row->context_type);
        self::assertSame((string) self::PERSON, $row->context_entity_id);
        self::assertSame(self::TENANT, $row->tenant_id);
    }

    /**
     * Every existing client posts only a title. That must keep working, and the
     * two context columns must stay null rather than acquire a placeholder.
     */
    public function test_a_session_opened_with_no_context_is_unchanged(): void
    {
        $body = $this->open(['title' => 'General question'])->assertStatus(201)->json();

        self::assertNull($body['contextType']);
        self::assertNull($body['contextEntityId']);
        self::assertFalse($body['context']['object']['present']);
        self::assertSame('no_object_requested', $body['context']['object']['reason']);

        $row = DB::table('hpbrain_conversation_sessions')->where('id', $body['id'])->first();

        self::assertNull($row->context_type);
        self::assertNull($row->context_entity_id);

        // The tenant-wide layers still resolve: a conversation with no subject
        // still has an organization and a user behind it.
        self::assertTrue($body['context']['organization']['present']);
        self::assertTrue($body['context']['user']['present']);
    }

    /**
     * THE IMPORTANT ONE. A caller naming another organization's person must not
     * get a session whose recorded subject is that person — the id would then
     * sit in the transcript as the thing being discussed, and every later read
     * of the session would present it as such.
     */
    public function test_a_foreign_object_id_is_not_recorded_as_the_sessions_subject(): void
    {
        $body = $this->open([
            'title'    => 'Tell me about this person',
            'screen'   => 'person-profile',
            'objectId' => (string) self::OTHER_PERSON,
        ])->assertStatus(201)->json();

        self::assertFalse($body['context']['object']['present']);
        self::assertSame('object_not_found', $body['context']['object']['reason']);
        self::assertNull($body['contextType']);
        self::assertNull($body['contextEntityId']);

        $row = DB::table('hpbrain_conversation_sessions')->where('id', $body['id'])->first();

        self::assertNull($row->context_type, 'A foreign id became a session subject.');
        self::assertNull($row->context_entity_id);

        self::assertStringNotContainsString('Bruno', (string) json_encode($body));
    }

    public function test_an_imaginary_object_id_is_treated_the_same_way(): void
    {
        $body = $this->open([
            'title'    => 'Anything',
            'screen'   => 'person-profile',
            'objectId' => '999999',
        ])->assertStatus(201)->json();

        self::assertNull($body['contextType']);
        self::assertSame('object_not_found', $body['context']['object']['reason']);
    }

    public function test_an_object_type_outside_the_vocabulary_is_refused(): void
    {
        $this->open([
            'title'      => 'Anything',
            'screen'     => 'person-profile',
            'objectId'   => (string) self::PERSON,
            'objectType' => 'Invoice',
        ])->assertStatus(422);
    }

    // ---- The surface the AI Assistant screen actually calls ---------------

    /**
     * POST /v1/conversations/sessions — what AIAssistant.tsx posts to.
     *
     * The screen labelled "AI Assistant" in the SPA goes through
     * conversationApi, not through the ai/workspace routes, so both had to
     * learn about context or the integration would have been wired to the half
     * of the product nothing opens. Same table, same two columns, same shared
     * concern.
     */
    public function test_the_conversation_surface_binds_the_resolved_object_too(): void
    {
        $body = $this->postJson('/api/v1/conversations/sessions', [
            'title'    => 'Why is Asha flagged?',
            'screen'   => 'person-profile',
            'objectId' => (string) self::PERSON,
        ], $this->auth())->assertStatus(201)->json();

        self::assertSame('Person', $body['context_type']);
        self::assertSame((string) self::PERSON, $body['context_entity_id']);
        self::assertTrue($body['context']['object']['present']);
        self::assertSame('Asha', $body['context']['object']['data']['firstName']);
        self::assertSame(1, $body['context']['signals']['count']);

        $row = DB::table('hpbrain_conversation_sessions')->where('id', $body['id'])->first();

        self::assertSame('Person', $row->context_type);
        self::assertSame((string) self::PERSON, $row->context_entity_id);
    }

    public function test_the_conversation_surface_refuses_a_foreign_object_as_a_subject(): void
    {
        $body = $this->postJson('/api/v1/conversations/sessions', [
            'title'    => 'Tell me about this person',
            'screen'   => 'person-profile',
            'objectId' => (string) self::OTHER_PERSON,
        ], $this->auth())->assertStatus(201)->json();

        self::assertNull($body['context_type']);
        self::assertNull($body['context_entity_id']);
        self::assertSame('object_not_found', $body['context']['object']['reason']);
        self::assertStringNotContainsString('Bruno', (string) json_encode($body));
    }

    /** Existing clients post a title alone, and must keep working. */
    public function test_the_conversation_surface_is_unchanged_without_context(): void
    {
        $body = $this->postJson('/api/v1/conversations/sessions', [
            'title' => 'General question',
        ], $this->auth())->assertStatus(201)->json();

        self::assertNull($body['context_type']);
        self::assertNull($body['context_entity_id']);
        self::assertSame('General question', $body['title']);
    }

    /**
     * READ-ONLY, PROVED BY COUNTING. The engine holds no writer, and opening a
     * session must create exactly one row — the session — and touch nothing in
     * the intelligence loop. A context lookup that wrote a signal, an event or
     * an evidence row would be producing intelligence rather than describing
     * what is on the screen.
     */
    public function test_resolving_context_writes_nothing_but_the_session(): void
    {
        $before = $this->rowCounts();

        $this->open([
            'title'    => 'Why is Asha flagged?',
            'screen'   => 'person-profile',
            'objectId' => (string) self::PERSON,
        ])->assertStatus(201);

        $after = $this->rowCounts();

        self::assertSame($before['hpbrain_signals'], $after['hpbrain_signals']);
        self::assertSame($before['hpbrain_evidence'], $after['hpbrain_evidence']);
        self::assertSame($before['hpbrain_event_store'], $after['hpbrain_event_store']);
        self::assertSame($before['hpbrain_recommendations'], $after['hpbrain_recommendations']);
        self::assertSame($before['hpbrain_conversation_messages'], $after['hpbrain_conversation_messages']);
        self::assertSame($before['hpbrain_conversation_sessions'] + 1, $after['hpbrain_conversation_sessions']);
    }

    /**
     * GET /v1/context/{tenantId} writes nothing at all — not even a session.
     * It is the read-only endpoint, and this is the assertion that says so.
     */
    public function test_the_context_endpoint_itself_writes_nothing(): void
    {
        $before = $this->rowCounts();

        $this->getJson(
            '/api/v1/context/'.self::TENANT.'?screen=person-profile&objectId='.self::PERSON,
            $this->auth(),
        )->assertOk();

        self::assertSame($before, $this->rowCounts());
    }

    // ---- Fixture -----------------------------------------------------------

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $tables = [
            'hpbrain_signals', 'hpbrain_evidence', 'hpbrain_event_store',
            'hpbrain_recommendations', 'hpbrain_conversation_sessions',
            'hpbrain_conversation_messages', 'hpbrain_audit_logs',
        ];

        $out = [];

        foreach ($tables as $table) {
            $out[$table] = DB::table($table)->count();
        }

        return $out;
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => (string) self::PERSON, 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    /** @param array<string, string> $payload */
    private function open(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/ai/workspace/sessions', $payload, $this->auth());
    }

    private function seedTenant(string $tenant, string $name, int $personId, string $firstName): void
    {
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
            'id' => (int) $tenant, 'sub_institute_id' => (int) $tenant,
            'department' => 'Operations', 'parent_id' => 0, 'status' => 1,
        ]);

        DB::table('tbluser')->insert([
            'id' => $personId, 'sub_institute_id' => (int) $tenant, 'employee_no' => 'E'.$personId,
            'first_name' => $firstName, 'last_name' => 'Owner', 'email' => $firstName.'@x.test',
            'department_id' => (int) $tenant, 'user_profile_id' => (int) $tenant,
            'jobtitle_id' => (int) $tenant, 'status' => 1,
        ]);

        DB::table('tbluserprofilemaster')->insert([
            'id' => (int) $tenant, 'sub_institute_id' => (int) $tenant, 'name' => 'Super Admin', 'status' => 1,
        ]);

        DB::table('hrms_job_titles')->insert([
            'id' => (int) $tenant, 'sub_institute_id' => (int) $tenant, 'title' => 'Staff', 'is_active' => 1,
        ]);
    }
}
