<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GET analytics/{tenantId}/executive-summary/graph-briefing —
 * AnalyticsController::executiveGraphBriefing(), Phase 5 GraphRAG's
 * "executive summary" (roadmap doc 5.6's graph-grounded counterpart).
 *
 * Deliberately grounds on GraphQueryPort::summary()/overview() rather than
 * on statistics() (the loop-table aggregate executiveNarrative() above
 * already grounds on) — this test proves THAT path, not a duplicate of
 * executiveNarrative()'s own coverage.
 *
 * No ERP entity mapping is installed for this tenant at all: summary()'s
 * loop counters (signals, recommendations, ...) come straight off
 * hpbrain_* tables, so an organization with intelligence history but no
 * HR/student import still gets a real, non-empty briefing — and a tenant
 * with neither is honestly `no_evidence`.
 */
final class ExecutiveGraphBriefingTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-exec-graph-briefing';

    private const EMPTY_TENANT = 'tenant-exec-graph-briefing-empty';

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
            ['id' => 'sig-1', 'tenant_id' => self::TENANT, 'source' => 'test', 'classification' => 'attendance', 'severity' => 'high', 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00'],
            ['id' => 'sig-2', 'tenant_id' => self::TENANT, 'source' => 'test', 'classification' => 'fees', 'severity' => 'medium', 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00'],
        ]);

        DB::table('hpbrain_recommendations')->insert([
            'id' => 'rec-1', 'tenant_id' => self::TENANT, 'title' => 'Call the guardian',
            'dependencies' => json_encode([]), 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/analytics/'.self::TENANT.'/executive-summary/graph-briefing')
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
            ->getJson('/api/v1/analytics/'.self::EMPTY_TENANT.'/executive-summary/graph-briefing')
            ->assertOk()
            ->json();

        self::assertSame('no_evidence', $body['narrativeStatus']);
        self::assertNull($body['narrative']);
        self::assertSame(0, $body['summary']['signals']);
    }

    public function test_the_briefing_is_tenant_scoped(): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => 'sig-mine-only', 'tenant_id' => self::TENANT, 'source' => 'test',
            'classification' => 'attendance', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth(self::EMPTY_TENANT))
            ->getJson('/api/v1/analytics/'.self::EMPTY_TENANT.'/executive-summary/graph-briefing')
            ->assertOk()
            ->json();

        self::assertSame(0, $body['summary']['signals'], 'the other tenant\'s signal must not be counted here');
    }
}
