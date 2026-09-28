<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GET graph/{tenantId}/node?label=Evidence&id=… — the one fact "Open full
 * record" needs and did not have: which signal this evidence supports.
 *
 * There is no standalone evidence screen, so the frontend routes an Evidence
 * node's "Open full record" to that signal's chain instead of the generic
 * Evidence list — see GraphExplorer's openRecordAction. This pins the one
 * field that makes that possible.
 */
final class GraphNodeEvidenceLinkTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-graph-evidence';
    private const OTHER_TENANT = 'tenant-graph-evidence-other';

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

    private function insertSignal(string $id, string $tenant = self::TENANT): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'source' => 'test', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);
    }

    private function insertEvidence(string $id, ?string $signalId, string $tenant = self::TENANT): void
    {
        DB::table('hpbrain_evidence')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'signal_id' => $signalId,
            'source' => 'test', 'content' => json_encode(['text' => 'observed something']),
            'provenance' => json_encode([]), 'hash' => 'h-'.$id, 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00',
        ]);
    }

    public function test_an_evidence_node_carries_the_signal_id_it_supports(): void
    {
        $this->insertSignal('sig-1');
        $this->insertEvidence('ev-1', 'sig-1');

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/graph/'.self::TENANT.'/node?label=Evidence&id=ev-1')
            ->assertOk()
            ->json();

        self::assertSame('sig-1', $body['node']['properties']['signalId']);
    }

    public function test_an_evidence_node_with_no_signal_reports_null_not_a_missing_key(): void
    {
        $this->insertEvidence('ev-2', null);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/graph/'.self::TENANT.'/node?label=Evidence&id=ev-2')
            ->assertOk()
            ->json();

        self::assertArrayHasKey('signalId', $body['node']['properties']);
        self::assertNull($body['node']['properties']['signalId']);
    }

    public function test_evidence_from_another_tenant_is_not_found_not_borrowed(): void
    {
        $this->insertSignal('sig-x', self::OTHER_TENANT);
        $this->insertEvidence('ev-x', 'sig-x', self::OTHER_TENANT);

        $this->withHeaders($this->auth(self::TENANT))
            ->getJson('/api/v1/graph/'.self::TENANT.'/node?label=Evidence&id=ev-x')
            ->assertStatus(404);
    }
}
