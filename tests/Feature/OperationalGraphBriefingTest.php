<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GET operations/{tenantId}/graph-briefing —
 * OperationalIntelligenceController::graphBriefing(), Phase 5 GraphRAG's
 * "operational patterns from the org graph".
 *
 * Same retrieval shape as ExecutiveGraphBriefingTest
 * (GraphQueryPort::summary()/overview()), proven here against this
 * controller's own route and service name rather than re-proving
 * organizationBriefingDocuments() itself.
 */
final class OperationalGraphBriefingTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-ops-graph-briefing';

    private const EMPTY_TENANT = 'tenant-ops-graph-briefing-empty';

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
            ['id' => 'ops-sig-1', 'tenant_id' => self::TENANT, 'source' => 'test', 'classification' => 'attendance', 'severity' => 'high', 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00'],
            ['id' => 'ops-sig-2', 'tenant_id' => self::TENANT, 'source' => 'test', 'classification' => 'fees', 'severity' => 'medium', 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00'],
        ]);

        DB::table('hpbrain_recommendations')->insert([
            'id' => 'ops-rec-1', 'tenant_id' => self::TENANT, 'title' => 'Call the guardian',
            'dependencies' => json_encode([]), 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/operations/'.self::TENANT.'/graph-briefing')
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
            ->getJson('/api/v1/operations/'.self::EMPTY_TENANT.'/graph-briefing')
            ->assertOk()
            ->json();

        self::assertSame('no_evidence', $body['narrativeStatus']);
        self::assertNull($body['narrative']);
        self::assertSame(0, $body['summary']['signals']);
    }

    public function test_the_briefing_is_tenant_scoped(): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => 'ops-sig-mine-only', 'tenant_id' => self::TENANT, 'source' => 'test',
            'classification' => 'attendance', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth(self::EMPTY_TENANT))
            ->getJson('/api/v1/operations/'.self::EMPTY_TENANT.'/graph-briefing')
            ->assertOk()
            ->json();

        self::assertSame(0, $body['summary']['signals'], 'the other tenant\'s signal must not be counted here');
    }
}
