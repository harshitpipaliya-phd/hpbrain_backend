<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * GET entity-intelligence/{tenantId}/{label}/{id}/narrative —
 * EntityIntelligenceController::graphNarrative(), Phase 5 GraphRAG's
 * "entity intelligence" (roadmap doc 5.7), across any of GraphVocabulary's
 * 14 labels.
 *
 * TWO RETRIEVAL SHAPES, ONE ENDPOINT. `Signal` is FAMILY_INTELLIGENCE, so
 * it is retrieved through reasoningTrail() — proven against the same chain
 * shape as SignalReasoningExplanationTest. `Dataset` is not, so it is
 * retrieved through detail() + expand() instead — a label chosen
 * deliberately because its facts and neighbours (hpbrain_operational_records,
 * hpbrain_signals.source) are Brain's own tables, with no ERP entity
 * mapping required to exercise it.
 */
final class EntityIntelligenceGraphNarrativeTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-entity-graph-narrative';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    private function url(string $label, string $id): string
    {
        return '/api/v1/entity-intelligence/'.self::TENANT.'/'.$label.'/'.$id.'/narrative';
    }

    // ---- Intelligence family: Signal, via reasoningTrail() -----------------

    public function test_an_intelligence_family_label_is_retrieved_through_the_reasoning_trail(): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => 'sig-1', 'tenant_id' => self::TENANT, 'source' => 'test',
            'classification' => 'attendance', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);

        DB::table('hpbrain_cases')->insert([
            'id' => 'case-1', 'tenant_id' => self::TENANT, 'signal_id' => 'sig-1',
            'title' => 'Attendance case', 'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson($this->url('Signal', 'sig-1'))
            ->assertOk()
            ->json();

        self::assertSame('Signal', $body['label']);
        self::assertArrayHasKey('trail', $body);
        self::assertGreaterThan(1, count($body['trail']));
        self::assertSame('ai_not_configured', $body['narrativeStatus']);
    }

    public function test_an_intelligence_family_entity_with_no_trail_is_no_evidence(): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => 'sig-bare', 'tenant_id' => self::TENANT, 'source' => 'test',
            'classification' => 'attendance', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson($this->url('Signal', 'sig-bare'))
            ->assertOk()
            ->json();

        self::assertSame('no_evidence', $body['narrativeStatus']);
        self::assertNull($body['narrative']);
    }

    // ---- Non-intelligence family: Dataset, via detail() + expand() --------

    public function test_a_non_intelligence_family_label_is_retrieved_through_detail_and_expand(): void
    {
        foreach (['r-1', 'r-2'] as $recordId) {
            DB::table('hpbrain_operational_records')->insert([
                'id' => $recordId, 'tenant_id' => self::TENANT, 'org_id' => self::TENANT,
                'dataset' => 'attendance-feed', 'natural_key' => $recordId,
                'occurred_at' => '2026-08-01 00:00:00', 'row_hash' => 'hash-'.$recordId,
                'created_date' => '2026-08-01 00:00:00',
            ]);
        }

        // A signal the dataset generated — expandDataset()'s own neighbour link.
        DB::table('hpbrain_signals')->insert([
            'id' => 'sig-from-dataset', 'tenant_id' => self::TENANT, 'source' => 'attendance-feed',
            'classification' => 'attendance', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00', 'updated_date' => '2026-08-01 00:00:00',
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson($this->url('Dataset', 'attendance-feed'))
            ->assertOk()
            ->json();

        self::assertSame('Dataset', $body['label']);
        self::assertArrayHasKey('facts', $body);
        self::assertArrayHasKey('connections', $body);
        self::assertSame('ai_not_configured', $body['narrativeStatus']);
    }

    public function test_an_unknown_graph_label_is_rejected_before_any_query(): void
    {
        $this->withHeaders($this->auth())
            ->getJson($this->url('NotARealLabel', 'whatever'))
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'supported']);
    }

    public function test_an_entity_that_does_not_exist_is_a_404(): void
    {
        $this->withHeaders($this->auth())
            ->getJson($this->url('Dataset', 'does-not-exist'))
            ->assertStatus(404)
            ->assertJson(['error' => 'entity_not_found']);
    }
}
