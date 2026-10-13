<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\Embeddings;
use App\Services\RetrievalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * Phase 6.1 RAG v1, task 2 — RetrievalService::searchDocuments() real
 * semantic retrieval, with a keyword fallback for rows that are not (yet)
 * embedded, or embedded under a stale model/version.
 */
final class RetrievalServiceSemanticTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-retrieval';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();

        config(['brain.ai.gemini.api_key' => 'test-gemini-key']);
    }

    private function insertAsset(string $id, string $title, string $content, ?array $vector = null, bool $staleVersion = false): void
    {
        $row = [
            'id' => $id, 'tenant_id' => self::TENANT, 'title' => $title,
            'category' => 'policy', 'content' => $content, 'tags' => json_encode([]),
            'created_by' => 'test', 'created_date' => now(), 'updated_date' => now(),
        ];

        if ($vector !== null) {
            $row['embedding'] = Embeddings::encodeChunks([
                ['index' => 0, 'hash' => hash('sha256', $id . '#0'), 'text' => $content, 'vector' => $vector],
            ]);
            $row['embedding_model'] = Embeddings::CURRENT_MODEL;
            $row['embedding_version'] = $staleVersion ? 'v0-stale' : Embeddings::CURRENT_VERSION;
        }

        DB::table('hpbrain_knowledge_assets')->insert($row);
    }

    public function test_semantic_match_outranks_a_closer_textual_but_less_similar_row(): void
    {
        // Query vector is closest to asset-a's vector (cosine ~1.0), far from asset-b's (~0.0).
        Http::fake(fn () => Http::response(['embedding' => ['values' => [1.0, 0.0, 0.0]]], 200));

        $this->insertAsset('asset-a', 'Leave Policy', 'Employees accrue 18 days of leave per year.', [1.0, 0.0, 0.0]);
        $this->insertAsset('asset-b', 'Expense Policy', 'Claims must be submitted within 30 days.', [0.0, 1.0, 0.0]);

        $results = app(RetrievalService::class)->searchDocuments(self::TENANT, 'how much leave do employees get');

        $this->assertNotEmpty($results);
        $this->assertSame('asset-a', $results[0]['id']);
        $this->assertSame('semantic', $results[0]['retrieval']);
        $this->assertEqualsWithDelta(1.0, $results[0]['score'], 0.0001);
    }

    public function test_unembedded_row_still_returns_via_keyword_fallback_and_is_marked_and_downweighted(): void
    {
        Http::fake(fn () => Http::response(['embedding' => ['values' => [1.0, 0.0]]], 200));

        // No embedding at all — pre-migration-style row.
        $this->insertAsset('asset-c', 'Overtime Policy', 'Overtime is capped at 12 hours per week.');

        $results = app(RetrievalService::class)->searchDocuments(self::TENANT, 'Overtime Policy');

        $this->assertNotEmpty($results);
        $this->assertSame('asset-c', $results[0]['id']);
        $this->assertSame('keyword_only', $results[0]['retrieval']);
        // matchScore() for an exact title match is 1.0; the keyword-only
        // penalty must have been applied, not the raw heuristic score.
        $this->assertEqualsWithDelta(0.5, $results[0]['score'], 0.0001);
    }

    public function test_a_row_embedded_under_a_stale_version_falls_back_to_keyword_not_semantic(): void
    {
        Http::fake(fn () => Http::response(['embedding' => ['values' => [1.0, 0.0]]], 200));

        $this->insertAsset('asset-d', 'Notice Period Policy', 'Employees must give 60 days notice.', [1.0, 0.0], staleVersion: true);

        $results = app(RetrievalService::class)->searchDocuments(self::TENANT, 'Notice Period Policy');

        $this->assertNotEmpty($results);
        $this->assertSame('asset-d', $results[0]['id']);
        $this->assertSame('keyword_only', $results[0]['retrieval'], 'a stale embedding_version must not be trusted as current');
    }

    public function test_query_embed_failure_falls_back_to_the_original_keyword_behavior_for_every_row(): void
    {
        Http::fake(fn () => Http::response('rate limited', 429));

        $this->insertAsset('asset-e', 'Probation Policy', 'Probation is 90 days.', [1.0, 0.0]);

        $results = app(RetrievalService::class)->searchDocuments(self::TENANT, 'Probation Policy');

        $this->assertNotEmpty($results);
        $this->assertSame('keyword_only', $results[0]['retrieval']);
    }
}
