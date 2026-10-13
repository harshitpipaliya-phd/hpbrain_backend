<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GET capabilities/{tenantId}/graph-narrative/{label}/{id} —
 * CapabilityController::graphNarrative(), Phase 5 GraphRAG's "what
 * capabilities does this org/person have, and how are they distributed".
 *
 * RETRIEVAL IS detail()+expand(), PROVEN AGAINST THE SAME
 * hpbrain_capability_assignments ROWS GraphProjection::expandPerson()/
 * attachOrganizationBranches() ALREADY WALK — this endpoint adds no new
 * SQL, so this test is really proving the wiring (route, controller,
 * trait, AiGateway call) rather than the traversal itself, which
 * EntityIntelligenceGraphNarrativeTest and GraphDepartmentHierarchyTest's
 * siblings already cover for Person/Department individually.
 */
final class CapabilityGraphNarrativeTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-capability-graph-narrative';

    private const OTHER_TENANT = 'tenant-capability-graph-narrative-other';

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

    private function url(string $tenant, string $label, string $id): string
    {
        return '/api/v1/capabilities/'.$tenant.'/graph-narrative/'.$label.'/'.$id;
    }

    /** Maps the universal Person entity onto hpbrain_people for one tenant. */
    private function mapPersonToHpbrainPeople(string $tenant): void
    {
        $fields = [
            'id' => 'id', 'tenantKey' => 'tenant_id', 'firstName' => 'first_name',
            'lastName' => 'last_name', 'email' => 'email', 'externalRef' => 'employee_id',
            'unit' => 'department_id', 'status' => 'status',
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

    public function test_a_persons_capabilities_are_narrated_through_detail_and_expand(): void
    {
        $this->mapPersonToHpbrainPeople(self::TENANT);

        DB::table('hpbrain_people')->insert([
            'id' => 'person-1', 'tenant_id' => self::TENANT, 'employee_id' => 'EMP-1',
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test',
            'org_id' => self::TENANT, 'status' => 'active', 'created_by' => 'test',
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);

        DB::table('hpbrain_capabilities')->insert([
            'id' => 'cap-1', 'tenant_id' => self::TENANT, 'org_id' => self::TENANT,
            'capability_code' => 'CAP-1', 'name' => 'Analytical Reasoning', 'category' => 'cognitive',
            'criticality' => 'high', 'created_by' => 'test',
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);

        DB::table('hpbrain_capability_assignments')->insert([
            'id' => 'assign-1', 'tenant_id' => self::TENANT, 'capability_id' => 'cap-1',
            'target_type' => 'Person', 'target_id' => 'person-1', 'status' => 'active',
            'assigned_by' => 'test', 'assigned_date' => '2026-01-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson($this->url(self::TENANT, 'Person', 'person-1'))
            ->assertOk()
            ->json();

        self::assertSame('Person', $body['label']);
        self::assertSame('person-1', $body['id']);
        self::assertSame(
            ['label' => 'Capability', 'relationship' => 'has capability', 'count' => 1, 'provenance' => 'hpbrain_capability_assignments.target_type and target_id.'],
            $body['connections'][0],
        );
        self::assertSame('ai_not_configured', $body['narrativeStatus']);
        self::assertNull($body['narrative']);
    }

    public function test_an_organizations_registered_capabilities_are_narrated(): void
    {
        DB::table('hpbrain_capabilities')->insert([
            'id' => 'cap-org-1', 'tenant_id' => self::TENANT, 'org_id' => self::TENANT,
            'capability_code' => 'CAP-ORG-1', 'name' => 'Curriculum Design', 'category' => 'academic',
            'criticality' => 'medium', 'created_by' => 'test',
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);

        // {id} is ignored for Organization — the tenant id is always used —
        // so an arbitrary value here must not change the result.
        $body = $this->withHeaders($this->auth())
            ->getJson($this->url(self::TENANT, 'Organization', 'whatever-the-caller-sent'))
            ->assertOk()
            ->json();

        self::assertSame('Organization', $body['label']);
        self::assertSame(self::TENANT, $body['id']);
        self::assertSame(self::TENANT, $body['node']['properties']['tenantId']);

        $capabilityConnection = null;

        foreach ($body['connections'] as $connection) {
            if ($connection['label'] === 'Capability') {
                $capabilityConnection = $connection;
            }
        }

        self::assertNotNull($capabilityConnection, 'expected a Capability connection on the organization');
        self::assertSame('holds capability', $capabilityConnection['relationship']);
        self::assertSame(1, $capabilityConnection['count']);
        self::assertSame('ai_not_configured', $body['narrativeStatus']);
    }

    public function test_capability_itself_is_not_a_narratable_subject(): void
    {
        $this->withHeaders($this->auth())
            ->getJson($this->url(self::TENANT, 'Capability', 'cap-1'))
            ->assertStatus(422)
            ->assertJson(['error' => 'unsupported_label', 'supported' => ['Organization', 'Person']]);
    }

    public function test_a_person_who_does_not_exist_is_a_404(): void
    {
        $this->mapPersonToHpbrainPeople(self::TENANT);

        $this->withHeaders($this->auth())
            ->getJson($this->url(self::TENANT, 'Person', 'does-not-exist'))
            ->assertStatus(404)
            ->assertJson(['error' => 'entity_not_found']);
    }

    public function test_a_persons_capabilities_are_tenant_scoped(): void
    {
        $this->mapPersonToHpbrainPeople(self::TENANT);

        DB::table('hpbrain_people')->insert([
            'id' => 'person-mine', 'tenant_id' => self::TENANT, 'employee_id' => 'EMP-2',
            'first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.test',
            'org_id' => self::TENANT, 'status' => 'active', 'created_by' => 'test',
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);

        // A foreign tenant with no mapping of its own, and no row of its own,
        // must not see this person — same non-leak every other graph
        // endpoint already guarantees.
        $this->withHeaders($this->auth(self::OTHER_TENANT))
            ->getJson($this->url(self::OTHER_TENANT, 'Person', 'person-mine'))
            ->assertStatus(404)
            ->assertJson(['error' => 'entity_not_found']);
    }
}
