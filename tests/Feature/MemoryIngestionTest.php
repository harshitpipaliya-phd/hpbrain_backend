<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\Embeddings;
use App\Domain\Ai\MemoryIngestionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * Phase 6.1 RAG v1, memory extension — the ingestion path for the three
 * Organizational Memory & ESO tables. Mirrors KnowledgeIngestionTest.php's
 * core checks (embeds each chunk, stamps current model/version, re-ingest
 * overwrites rather than duplicates, ingestPending skips current rows) but
 * against MemoryIngestionService and across tables with different column
 * shapes — including the two (hpbrain_learnings, hpbrain_mental_models)
 * that have no updated_date column at all, which is the one way this
 * service's per-table config actually changes behavior.
 */
final class MemoryIngestionTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-memory-ingest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();

        config(['brain.ai.gemini.api_key' => 'test-gemini-key']);
    }

    public function test_ingest_row_embeds_a_learning_built_from_pattern_and_description_with_no_updated_date_stamp(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['embedding' => ['values' => [0.1, 0.2, 0.3]]], 200);
        });

        DB::table('hpbrain_learnings')->insert([
            'id' => 'learning-1', 'tenant_id' => self::TENANT,
            'pattern' => 'Short pattern.', 'description' => 'Short description.', 'domain' => 'ops',
            'created_by' => 'test', 'created_date' => now(),
        ]);

        $result = app(MemoryIngestionService::class)->ingestRow(self::TENANT, 'hpbrain_learnings', 'learning-1');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['chunks'], 'short pattern+description merge into one chunk');
        $this->assertSame(1, $calls);

        $row = DB::table('hpbrain_learnings')->where('id', 'learning-1')->first();
        $this->assertSame(Embeddings::CURRENT_MODEL, $row->embedding_model);
        $this->assertSame(Embeddings::CURRENT_VERSION, $row->embedding_version);

        $chunks = Embeddings::decodeChunks($row->embedding);
        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Short pattern.', $chunks[0]['text']);
        $this->assertStringContainsString('Short description.', $chunks[0]['text']);
    }

    public function test_ingest_row_embeds_an_eso_definition_and_does_stamp_updated_date(): void
    {
        Http::fake(fn () => Http::response(['embedding' => ['values' => [0.5, 0.5]]], 200));

        DB::table('hpbrain_eso_definitions')->insert([
            'id' => 'eso-1', 'tenant_id' => self::TENANT, 'org_id' => 'org-1',
            'eso_code' => 'eso-1', 'name' => 'Escalate low attendance', 'objective' => 'diagnose',
            'trigger_description' => 'Attendance drops below threshold.',
            'created_by' => 'test', 'created_date' => now()->subDay(), 'updated_date' => now()->subDay(),
        ]);

        $result = app(MemoryIngestionService::class)->ingestRow(self::TENANT, 'hpbrain_eso_definitions', 'eso-1');

        $this->assertSame('ok', $result['status']);

        $row = DB::table('hpbrain_eso_definitions')->where('id', 'eso-1')->first();
        $this->assertSame(Embeddings::CURRENT_MODEL, $row->embedding_model);
        $this->assertNotNull($row->updated_date);
    }

    public function test_reingesting_the_same_row_overwrites_rather_than_duplicates(): void
    {
        Http::fake(fn () => Http::response(['embedding' => ['values' => [1.0, 0.0]]], 200));

        DB::table('hpbrain_mental_models')->insert([
            'id' => 'model-1', 'tenant_id' => self::TENANT,
            'name' => 'Capacity planning', 'description' => 'A model for staffing.', 'domain' => 'ops',
            'created_by' => 'test', 'created_date' => now(),
        ]);

        $service = app(MemoryIngestionService::class);
        $service->ingestRow(self::TENANT, 'hpbrain_mental_models', 'model-1');
        $service->ingestRow(self::TENANT, 'hpbrain_mental_models', 'model-1');

        $this->assertSame(1, DB::table('hpbrain_mental_models')->where('id', 'model-1')->count());

        $row = DB::table('hpbrain_mental_models')->where('id', 'model-1')->first();
        $this->assertCount(1, Embeddings::decodeChunks($row->embedding));
    }

    public function test_ingest_pending_skips_rows_already_current_and_processes_the_rest(): void
    {
        Http::fake(fn () => Http::response(['embedding' => ['values' => [0.2, 0.8]]], 200));

        DB::table('hpbrain_learnings')->insert([
            'id' => 'already-current', 'tenant_id' => self::TENANT,
            'pattern' => 'Current pattern.', 'description' => null, 'domain' => 'ops',
            'embedding' => Embeddings::encodeChunks([['index' => 0, 'hash' => 'h', 'text' => 'x', 'vector' => [1.0]]]),
            'embedding_model' => Embeddings::CURRENT_MODEL, 'embedding_version' => Embeddings::CURRENT_VERSION,
            'created_by' => 'test', 'created_date' => now(),
        ]);

        DB::table('hpbrain_learnings')->insert([
            'id' => 'never-embedded', 'tenant_id' => self::TENANT,
            'pattern' => 'New pattern.', 'description' => null, 'domain' => 'ops',
            'created_by' => 'test', 'created_date' => now(),
        ]);

        $result = app(MemoryIngestionService::class)->ingestPending(self::TENANT, 'hpbrain_learnings', 50);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('never-embedded', $result['details'][0]['id']);
    }

    public function test_unsupported_table_is_rejected_rather_than_silently_ignored(): void
    {
        $result = app(MemoryIngestionService::class)->ingestRow(self::TENANT, 'hpbrain_signals', 'whatever');

        $this->assertSame('unsupported_table', $result['status']);
    }
}
