<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AiWorkspaceService;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GET ai/workspace/sessions/{sessionId}/graph-narrative —
 * AiWorkspaceController::graphNarrative(), Phase 5 GraphRAG's "workspace
 * intelligence from graph context".
 *
 * TWO SCOPES, READ FROM THE SESSION'S OWN ROW. A session opened over a
 * resolved object (context_type + context_entity_id, written by store()'s
 * fix earlier the same day this endpoint was added) is narrated as THAT
 * ENTITY; a session opened with no object — every session created before
 * that fix, and still most sessions afterwards, since a session need not
 * be opened over anything — falls back to the organization-wide briefing.
 * Both are proven here, plus the honest no_evidence/404 edges and tenant
 * isolation.
 */
final class WorkspaceGraphNarrativeTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-workspace-graph-narrative';

    private const OTHER_TENANT = 'tenant-workspace-graph-narrative-other';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    private function auth(string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    private function url(string $sessionId): string
    {
        return '/api/v1/ai/workspace/sessions/'.$sessionId.'/graph-narrative';
    }

    private function mapPersonToHpbrainPeople(string $tenant): void
    {
        $fields = [
            'id' => 'id', 'tenantKey' => 'tenant_id', 'firstName' => 'first_name',
            'lastName' => 'last_name', 'email' => 'email', 'status' => 'status',
        ];

        foreach ($fields as $field => $column) {
            DB::table('hpbrain_entity_mappings')->insert([
                'id' => Uuid::uuid4()->toString(), 'tenant_id' => $tenant, 'source_system' => 'erp',
                'source_entity' => 'hpbrain_people', 'source_field' => $column,
                'universal_entity' => 'Person', 'universal_field' => $field,
                'mapping_type' => 'direct', 'is_active' => 1,
                'created_by' => 'test', 'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
            ]);
        }
    }

    public function test_a_session_bound_to_a_person_is_narrated_as_that_entity(): void
    {
        $this->mapPersonToHpbrainPeople(self::TENANT);

        DB::table('hpbrain_people')->insert([
            'id' => 'person-ctx', 'tenant_id' => self::TENANT, 'employee_id' => 'EMP-CTX',
            'first_name' => 'Marie', 'last_name' => 'Curie', 'email' => 'marie@example.test',
            'org_id' => self::TENANT, 'status' => 'active', 'created_by' => 'test',
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);

        $session = app(AiWorkspaceService::class)->createSession(
            self::TENANT, 'user-1', 'About Marie', 'Person', 'person-ctx',
        );

        $body = $this->withHeaders($this->auth())
            ->getJson($this->url($session['id']))
            ->assertOk()
            ->json();

        self::assertSame('entity', $body['scope']);
        self::assertSame('Person', $body['label']);
        self::assertSame('person-ctx', $body['id']);
        self::assertSame($session['id'], $body['sessionId']);
        self::assertSame('ai_not_configured', $body['narrativeStatus']);
    }

    public function test_a_session_with_no_context_falls_back_to_the_organization_wide_briefing(): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => 'sig-workspace-1', 'tenant_id' => self::TENANT, 'source' => 'test',
            'classification' => 'attendance', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);

        $session = app(AiWorkspaceService::class)->createSession(self::TENANT, 'user-1', 'Open-ended chat');

        $body = $this->withHeaders($this->auth())
            ->getJson($this->url($session['id']))
            ->assertOk()
            ->json();

        self::assertSame('organization', $body['scope']);
        self::assertSame(1, $body['summary']['signals']);
        self::assertSame('ai_not_configured', $body['narrativeStatus']);
        self::assertArrayNotHasKey('label', $body);
    }

    public function test_a_session_bound_to_an_entity_type_with_no_graph_label_falls_back_too(): void
    {
        // Position has no GraphVocabulary counterpart — see
        // AiWorkspaceController::CONTEXT_TYPE_TO_GRAPH_LABEL.
        $session = app(AiWorkspaceService::class)->createSession(
            self::TENANT, 'user-1', 'About a role', 'Position', 'role-1',
        );

        $body = $this->withHeaders($this->auth())
            ->getJson($this->url($session['id']))
            ->assertOk()
            ->json();

        self::assertSame('organization', $body['scope']);
    }

    public function test_an_unknown_session_is_a_404(): void
    {
        $this->withHeaders($this->auth())
            ->getJson($this->url('does-not-exist'))
            ->assertStatus(404)
            ->assertJson(['error' => 'session_not_found']);
    }

    public function test_the_endpoint_is_tenant_scoped(): void
    {
        $session = app(AiWorkspaceService::class)->createSession(self::TENANT, 'user-1', 'Private session');

        $this->withHeaders($this->auth(self::OTHER_TENANT))
            ->getJson($this->url($session['id']))
            ->assertStatus(404)
            ->assertJson(['error' => 'session_not_found']);
    }
}
