<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\Embeddings;
use App\Domain\Ai\KnowledgeIngestionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * Phase 6.1 RAG v1, task 1 — the ingestion path. Verifies: deterministic
 * chunking, that ingestAsset() actually calls Gemini's embed endpoint once
 * per chunk and stores the result with the current model/version stamp,
 * and that re-running it is an overwrite (idempotent), not a duplicate.
 */
final class KnowledgeIngestionTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-ingest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();

        config(['brain.ai.gemini.api_key' => 'test-gemini-key']);
    }

    public function test_chunk_splits_on_blank_lines_and_hard_splits_long_paragraphs(): void
    {
        $service = app(KnowledgeIngestionService::class);

        $chunks = $service->chunk("First paragraph.\n\nSecond paragraph.", 1000);
        $this->assertSame(["First paragraph.\n\nSecond paragraph."], $chunks);

        $long = str_repeat('a', 2500);
        $chunks = $service->chunk($long, 1000);
        $this->assertCount(3, $chunks);
        $this->assertSame(1000, mb_strlen($chunks[0]));
        $this->assertSame(500, mb_strlen($chunks[2]));
    }

    public function test_chunk_is_deterministic(): void
    {
        $service = app(KnowledgeIngestionService::class);
        $text = "Paragraph one.\n\nParagraph two.\n\nParagraph three.";

        $this->assertSame($service->chunk($text), $service->chunk($text));
    }

    public function test_ingest_asset_embeds_each_chunk_and_stamps_model_and_version(): void
    {
        $calls = 0;

        Http::fake(function ($request) use (&$calls) {
            $calls++;

            return Http::response(['embedding' => ['values' => [0.1, 0.2, 0.3]]], 200);
        });

        $assetId = 'asset-1';
        DB::table('hpbrain_knowledge_assets')->insert([
            'id' => $assetId, 'tenant_id' => self::TENANT, 'title' => 'Test Doc',
            'category' => 'policy', 'content' => "Para one.\n\nPara two.", 'tags' => json_encode([]),
            'created_by' => 'test', 'created_date' => now(), 'updated_date' => now(),
        ]);

        $service = app(KnowledgeIngestionService::class);
        // A small maxChunkChars so the two short paragraphs are NOT merged
        // into one chunk — chunk()'s own (separately tested) merge
        // behavior would otherwise collapse them, which is correct
        // chunker behavior, not what this test is checking.
        $result = $service->ingestAsset(self::TENANT, $assetId, 10);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['chunks']);
        $this->assertSame(2, $calls, 'one Gemini embed call per chunk');

        $row = DB::table('hpbrain_knowledge_assets')->where('id', $assetId)->first();
        $this->assertSame(Embeddings::CURRENT_MODEL, $row->embedding_model);
        $this->assertSame(Embeddings::CURRENT_VERSION, $row->embedding_version);

        $chunks = Embeddings::decodeChunks($row->embedding);
        $this->assertCount(2, $chunks);
        $this->assertSame('Para one.', $chunks[0]['text']);
        $this->assertSame([0.1, 0.2, 0.3], $chunks[0]['vector']);
        $this->assertSame(hash('sha256', $assetId . '#0'), $chunks[0]['hash']);
    }

    public function test_reingesting_the_same_asset_overwrites_rather_than_duplicates(): void
    {
        Http::fake(fn () => Http::response(['embedding' => ['values' => [1.0, 0.0]]], 200));

        $assetId = 'asset-2';
        DB::table('hpbrain_knowledge_assets')->insert([
            'id' => $assetId, 'tenant_id' => self::TENANT, 'title' => 'Test Doc 2',
            'category' => 'policy', 'content' => 'Single paragraph of content.', 'tags' => json_encode([]),
            'created_by' => 'test', 'created_date' => now(), 'updated_date' => now(),
        ]);

        $service = app(KnowledgeIngestionService::class);
        $service->ingestAsset(self::TENANT, $assetId);
        $service->ingestAsset(self::TENANT, $assetId);

        $this->assertSame(1, DB::table('hpbrain_knowledge_assets')->where('id', $assetId)->count());

        $row = DB::table('hpbrain_knowledge_assets')->where('id', $assetId)->first();
        $this->assertCount(1, Embeddings::decodeChunks($row->embedding));
    }

    public function test_ingest_pending_skips_rows_already_current_and_processes_the_rest(): void
    {
        Http::fake(fn () => Http::response(['embedding' => ['values' => [0.5, 0.5]]], 200));

        // Two separate inserts, not a multi-row insert with differing key
        // sets — Laravel's query builder requires every row in a single
        // insert() call to share identical keys (it derives the column
        // list from the first row), which these two rows do not.
        DB::table('hpbrain_knowledge_assets')->insert([
            'id' => 'already-current', 'tenant_id' => self::TENANT, 'title' => 'Current',
            'category' => 'policy', 'content' => 'Already embedded content.', 'tags' => json_encode([]),
            'embedding' => Embeddings::encodeChunks([['index' => 0, 'hash' => 'h', 'text' => 'x', 'vector' => [1.0]]]),
            'embedding_model' => Embeddings::CURRENT_MODEL, 'embedding_version' => Embeddings::CURRENT_VERSION,
            'created_by' => 'test', 'created_date' => now(), 'updated_date' => now(),
        ]);

        DB::table('hpbrain_knowledge_assets')->insert([
            'id' => 'never-embedded', 'tenant_id' => self::TENANT, 'title' => 'New',
            'category' => 'policy', 'content' => 'Never embedded content.', 'tags' => json_encode([]),
            'created_by' => 'test', 'created_date' => now(), 'updated_date' => now(),
        ]);

        $service = app(KnowledgeIngestionService::class);
        $result = $service->ingestPending(self::TENANT, 50);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('never-embedded', $result['details'][0]['id']);
    }
}
