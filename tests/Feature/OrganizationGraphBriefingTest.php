<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GET organization-intelligence/{tenantId}/graph-briefing —
 * OrganizationIntelligenceController::graphBriefing(), Phase 5 GraphRAG's
 * "org health from graph traversal".
 *
 * Same retrieval shape as ExecutiveGraphBriefingTest/
 * OperationalGraphBriefingTest (GraphQueryPort::summary()/overview()),
 * proven here against this controller's own route, service name, and the
 * route-ordering rule stated in routes/api.php (`/graph-briefing` must
 * precede the bare `{tenantId}` route).
 */
final class OrganizationGraphBriefingTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-org-graph-briefing';

    private const EMPTY_TENANT = 'tenant-org-graph-briefing-empty';

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

    public function test_a_tenant_with_intelligence_history_gets_a_grounded_briefing(): void
    {
        DB::table('hpbrain_signals')->insert([
            ['id' => 'org-sig-1', 'tenant_id' => self::TENANT, 'source' => 'test', 'classification' => 'attendance', 'severity' => 'high', 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00'],
            ['id' => 'org-sig-2', 'tenant_id' => self::TENANT, 'source' => 'test', 'classification' => 'fees', 'severity' => 'medium', 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00'],
        ]);

        DB::table('hpbrain_recommendations')->insert([
            'id' => 'org-rec-1', 'tenant_id' => self::TENANT, 'title' => 'Call the guardian',
            'dependencies' => json_encode([]), 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organization-intelligence/'.self::TENANT.'/graph-briefing')
            ->assertOk()
            ->json();

        self::assertSame(2, $body['summary']['signals']);
        self::assertSame(1, $body['summary']['recommendations']);
        self::assertGreaterThan(1, $body['nodeCount']);
        self::assertSame('ai_not_configured', $body['narrativeStatus']);
        self::assertNull($body['narrative']);
    }

    public function test_a_tenant_with_nothing_recorded_at_all_is_honestly_no_evidence(): void
    {
        $body = $this->withHeaders($this->auth(self::EMPTY_TENANT))
            ->getJson('/api/v1/organization-intelligence/'.self::EMPTY_TENANT.'/graph-briefing')
            ->assertOk()
            ->json();

        self::assertSame('no_evidence', $body['narrativeStatus']);
        self::assertNull($body['narrative']);
        self::assertSame(0, $body['summary']['signals']);
    }

    public function test_the_briefing_is_tenant_scoped(): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => 'org-sig-mine-only', 'tenant_id' => self::TENANT, 'source' => 'test',
            'classification' => 'attendance', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth(self::EMPTY_TENANT))
            ->getJson('/api/v1/organization-intelligence/'.self::EMPTY_TENANT.'/graph-briefing')
            ->assertOk()
            ->json();

        self::assertSame(0, $body['summary']['signals'], 'the other tenant\'s signal must not be counted here');
    }

    public function test_the_route_is_not_swallowed_by_the_bare_tenant_id_route(): void
    {
        // If `/graph-briefing` were registered AFTER `{tenantId}` (or not at
        // all), this would resolve to index() and never reach the briefing
        // shape at all — asserting narrativeStatus is what proves which
        // route actually ran.
        $body = $this->withHeaders($this->auth(self::EMPTY_TENANT))
            ->getJson('/api/v1/organization-intelligence/'.self::EMPTY_TENANT.'/graph-briefing')
            ->assertOk()
            ->json();

        self::assertArrayHasKey('narrativeStatus', $body);
        self::assertArrayNotHasKey('state', $body);
    }
}
