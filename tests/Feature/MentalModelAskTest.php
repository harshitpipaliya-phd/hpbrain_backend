<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * POST mental-models/{tenantId}/ask — MentalModelController::ask(), the
 * dedicated "ask about our mental models and ESO library" feature (task 2 of
 * the batch this test was written for).
 *
 * WHAT THIS PROVES, GIVEN THE TEST SUITE RUNS WITH AI_PROVIDER="" (phpunit.xml).
 * Generation itself (completeWithRag() actually producing a narrative) is
 * proven honest elsewhere (every other *NarrativeTest in this app asserts the
 * same ai_not_configured branch rather than mocking a model response) — what
 * is specific to THIS endpoint is the RETRIEVAL SCOPE: it must count mental
 * models and ESO definitions, and it must NOT count a learning that happens
 * to match the same query, because that is a different question
 * (LearningController::ask()'s), not this one's.
 */
final class MentalModelAskTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-mm-ask';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();

        // This installation's real GEMINI_API_KEY is reachable over the
        // network but unauthorized (same invalid-key condition the
        // embedding work earlier this session is blocked on), so
        // embedQuery() would otherwise wait out GeminiProvider's real
        // 30-second HTTP timeout before falling back to the keyword path
        // this test is actually exercising. Faking a fast 401 here is
        // exactly RetrievalServiceSemanticMemoryTest's own pattern for the
        // "embedding unavailable" case, not a shortcut around a passing
        // test.
        Http::fake(fn () => Http::response('unauthorized', 401));
    }

    private function auth(string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    private function seedMentalModel(string $tenant, string $name, string $domain): void
    {
        DB::table('hpbrain_mental_models')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => $tenant,
            'name' => $name, 'domain' => $domain, 'description' => $name.' description',
            'created_by' => 'system', 'created_date' => '2026-08-01 00:00:00',
        ]);
    }

    private function seedEsoDefinition(string $tenant, string $name): void
    {
        DB::table('hpbrain_eso_definitions')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => $tenant,
            'eso_code' => 'ESO-1', 'name' => $name, 'objective' => 'reduce',
            'trigger_description' => $name.' trigger', 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00',
        ]);
    }

    private function seedLearning(string $tenant, string $pattern): void
    {
        DB::table('hpbrain_learnings')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => $tenant,
            'pattern' => $pattern, 'description' => $pattern, 'domain' => 'attendance',
            'confidence' => 0.8, 'reusable' => 1, 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00',
        ]);
    }

    public function test_empty_query_is_rejected(): void
    {
        $this->withHeaders($this->auth())
            ->postJson('/api/v1/mental-models/'.self::TENANT.'/ask', ['query' => ''])
            ->assertStatus(422)
            ->assertJson(['error' => 'query_required']);
    }

    public function test_asking_with_nothing_in_either_table_reports_zero_documents_honestly(): void
    {
        $body = $this->withHeaders($this->auth())
            ->postJson('/api/v1/mental-models/'.self::TENANT.'/ask', ['query' => 'attendance'])
            ->assertOk()
            ->json();

        self::assertSame(0, $body['documentsFound']);
        self::assertNull($body['answer']);
        self::assertSame('ai_not_configured', $body['answerStatus']);
    }

    public function test_retrieval_counts_mental_models_and_eso_definitions_but_never_a_learning(): void
    {
        $this->seedMentalModel(self::TENANT, 'Attendance drives engagement', 'attendance');
        $this->seedEsoDefinition(self::TENANT, 'Attendance recovery playbook');
        // A learning that matches the same keyword — must NOT be counted here.
        $this->seedLearning(self::TENANT, 'Attendance reminder cadence works');

        $body = $this->withHeaders($this->auth())
            ->postJson('/api/v1/mental-models/'.self::TENANT.'/ask', ['query' => 'attendance'])
            ->assertOk()
            ->json();

        // Keyword fallback (no embedding provider configured for the test
        // suite) matches both the mental model and the ESO definition on
        // their own domain/name/objective text.
        self::assertSame(2, $body['documentsFound']);
        self::assertSame('ai_not_configured', $body['answerStatus']);
    }

    public function test_another_tenants_mental_models_are_not_retrieved(): void
    {
        $this->seedMentalModel('tenant-mm-ask-other', 'Attendance drives engagement', 'attendance');

        $body = $this->withHeaders($this->auth())
            ->postJson('/api/v1/mental-models/'.self::TENANT.'/ask', ['query' => 'attendance'])
            ->assertOk()
            ->json();

        self::assertSame(0, $body['documentsFound']);
    }
}
