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
 * Phase 6.1 RAG v1, memory extension — RetrievalService::searchMemory()
 * real semantic retrieval across hpbrain_learnings, hpbrain_mental_models
 * and hpbrain_eso_definitions, with the same keyword fallback for rows
 * that are not (yet) embedded, or embedded under a stale model/version.
 *
 * Mirrors RetrievalServiceSemanticTest.php's four scenarios exactly, one
 * memory table per scenario (plus the fourth scenario spanning all three)
 * so every table gets real coverage rather than re-testing the same table
 * four times.
 */
final class RetrievalServiceSemanticMemoryTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-retrieval-memory';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();

        config(['brain.ai.gemini.api_key' => 'test-gemini-key']);
    }

    private function insertLearning(string $id, string $pattern, string $description, ?array $vector = null, bool $staleVersion = false): void
    {
        $row = [
            'id' => $id, 'tenant_id' => self::TENANT, 'pattern' => $pattern,
            'description' => $description, 'domain' => 'ops',
            'created_by' => 'test', 'created_date' => now(),
        ];

        if ($vector !== null) {
            $row['embedding'] = Embeddings::encodeChunks([
                ['index' => 0, 'hash' => hash('sha256', $id . '#0'), 'text' => $pattern . "\n\n" . $description, 'vector' => $vector],
            ]);
            $row['embedding_model'] = Embeddings::CURRENT_MODEL;
            $row['embedding_version'] = $staleVersion ? 'v0-stale' : Embeddings::CURRENT_VERSION;
        }

        DB::table('hpbrain_learnings')->insert($row);
    }

    private function insertMentalModel(string $id, string $name, string $description, ?array $vector = null, bool $staleVersion = false): void
    {
        $row = [
            'id' => $id, 'tenant_id' => self::TENANT, 'name' => $name,
            'description' => $description, 'domain' => 'ops',
            'created_by' => 'test', 'created_date' => now(),
        ];

        if ($vector !== null) {
            $row['embedding'] = Embeddings::encodeChunks([
                ['index' => 0, 'hash' => hash('sha256', $id . '#0'), 'text' => $name . "\n\n" . $description, 'vector' => $vector],
            ]);
            $row['embedding_model'] = Embeddings::CURRENT_MODEL;
            $row['embedding_version'] = $staleVersion ? 'v0-stale' : Embeddings::CURRENT_VERSION;
        }

        DB::table('hpbrain_mental_models')->insert($row);
    }

    private function insertEsoDefinition(string $id, string $name, string $objective, ?array $vector = null, bool $staleVersion = false): void
    {
        $row = [
            'id' => $id, 'tenant_id' => self::TENANT, 'org_id' => 'org-1',
            'eso_code' => $id, 'name' => $name, 'objective' => $objective,
            'created_by' => 'test', 'created_date' => now(), 'updated_date' => now(),
        ];

        if ($vector !== null) {
            $row['embedding'] = Embeddings::encodeChunks([
                ['index' => 0, 'hash' => hash('sha256', $id . '#0'), 'text' => $name . "\n\n" . $objective, 'vector' => $vector],
            ]);
            $row['embedding_model'] = Embeddings::CURRENT_MODEL;
            $row['embedding_version'] = $staleVersion ? 'v0-stale' : Embeddings::CURRENT_VERSION;
        }

        DB::table('hpbrain_eso_definitions')->insert($row);
    }

    public function test_semantic_match_outranks_a_closer_textual_but_less_similar_row(): void
    {
        // Query vector is closest to learning-a's vector (cosine ~1.0), far from learning-b's (~0.0).
        Http::fake(fn () => Http::response(['embedding' => ['values' => [1.0, 0.0, 0.0]]], 200));

        $this->insertLearning('learning-a', 'Leave accrual pattern', 'Employees accrue 18 days of leave per year.', [1.0, 0.0, 0.0]);
        $this->insertLearning('learning-b', 'Expense claim pattern', 'Claims must be submitted within 30 days.', [0.0, 1.0, 0.0]);

        $results = app(RetrievalService::class)->searchMemory(self::TENANT, 'how much leave do employees get');

        $this->assertNotEmpty($results);
        $this->assertSame('learning-a', $results[0]['id']);
        $this->assertSame('semantic', $results[0]['retrieval']);
        $this->assertEqualsWithDelta(1.0, $results[0]['score'], 0.0001);
    }

    public function test_unembedded_row_still_returns_via_keyword_fallback_and_is_marked_and_downweighted(): void
    {
        Http::fake(fn () => Http::response(['embedding' => ['values' => [1.0, 0.0]]], 200));

        // No embedding at all — pre-migration-style row, in hpbrain_mental_models this time.
        $this->insertMentalModel('model-c', 'Overtime Policy Model', 'Overtime is capped at 12 hours per week.');

        $results = app(RetrievalService::class)->searchMemory(self::TENANT, 'Overtime Policy Model');

        $this->assertNotEmpty($results);
        $this->assertSame('model-c', $results[0]['id']);
        $this->assertSame('keyword_only', $results[0]['retrieval']);
        // matchScore() for an exact name match is 1.0; the keyword-only
        // penalty must have been applied, not the raw heuristic score.
        $this->assertEqualsWithDelta(0.5, $results[0]['score'], 0.0001);
    }

    public function test_a_row_embedded_under_a_stale_version_falls_back_to_keyword_not_semantic(): void
    {
        Http::fake(fn () => Http::response(['embedding' => ['values' => [1.0, 0.0]]], 200));

        // Stale version, in hpbrain_eso_definitions this time.
        $this->insertEsoDefinition('eso-d', 'Notice Period Escalation', 'Escalate when notice period compliance drops.', [1.0, 0.0], staleVersion: true);

        $results = app(RetrievalService::class)->searchMemory(self::TENANT, 'Notice Period Escalation');

        $this->assertNotEmpty($results);
        $this->assertSame('eso-d', $results[0]['id']);
        $this->assertSame('keyword_only', $results[0]['retrieval'], 'a stale embedding_version must not be trusted as current');
    }

    public function test_query_embed_failure_falls_back_to_the_original_keyword_behavior_for_every_row_across_all_three_tables(): void
    {
        Http::fake(fn () => Http::response('rate limited', 429));

        // All three tables get a fully CURRENT embedded row; the query's own
        // embed call still fails, so every one of them must fall back to the
        // original LIKE heuristic rather than being silently dropped.
        $this->insertLearning('learning-e', 'Probation Pattern', 'Probation is 90 days.', [1.0, 0.0]);
        $this->insertMentalModel('model-e', 'Probation Model', 'Probation is 90 days.', [1.0, 0.0]);
        $this->insertEsoDefinition('eso-e', 'Probation Escalation', 'Probation is 90 days.', [1.0, 0.0]);

        $results = app(RetrievalService::class)->searchMemory(self::TENANT, 'Probation');

        $this->assertNotEmpty($results);
        $ids = array_column($results, 'id');
        $this->assertContains('learning-e', $ids);
        $this->assertContains('model-e', $ids);
        $this->assertContains('eso-e', $ids);

        foreach ($results as $result) {
            $this->assertSame('keyword_only', $result['retrieval']);
        }
    }
}
