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
 * POST learnings/{tenantId}/ask — LearningController::ask(), ADR-005's
 * flywheel wired to real retrieval (task 4 of the batch this test was
 * written for): MemoryGrounding::retrieveSemantic() -> RetrievalService::
 * searchMemory() -> AiGateway::completeWithRag().
 *
 * THE INVARIANT THIS TEST EXISTS TO PIN. ADR-005: a failed or unreusable
 * outcome is recorded so the organization learns from it, and is NEVER
 * offered back as a pattern to repeat. retrieveFor() (the five implemented
 * verbs' own path) already enforces this; retrieveSemantic() is new code
 * with its own enforcement, and a regression there would silently surface a
 * discredited pattern back to a user asking a plain question.
 */
final class LearningAskTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-learning-ask';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();

        // See MentalModelAskTest's setUp() for why: a fast fake 401 stands
        // in for this installation's real, reachable-but-unauthorized
        // GEMINI_API_KEY, so these tests exercise the keyword fallback
        // without paying GeminiProvider's real 30-second HTTP timeout.
        Http::fake(fn () => Http::response('unauthorized', 401));
    }

    private function auth(string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    private function seedLearning(string $tenant, string $pattern, bool $reusable = true, string $domain = 'finance'): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_learnings')->insert([
            'id' => $id, 'tenant_id' => $tenant,
            'pattern' => $pattern, 'description' => $pattern, 'domain' => $domain,
            'confidence' => 0.8, 'reusable' => $reusable ? 1 : 0, 'created_by' => 'system',
            'created_date' => '2026-08-01 00:00:00',
        ]);

        return $id;
    }

    public function test_empty_query_is_rejected(): void
    {
        $this->withHeaders($this->auth())
            ->postJson('/api/v1/learnings/'.self::TENANT.'/ask', ['query' => ''])
            ->assertStatus(422)
            ->assertJson(['error' => 'query_required']);
    }

    public function test_a_reusable_learning_matching_the_query_is_counted(): void
    {
        $this->seedLearning(self::TENANT, 'Fee reminder cadence reduces arrears');

        $body = $this->withHeaders($this->auth())
            ->postJson('/api/v1/learnings/'.self::TENANT.'/ask', ['query' => 'fee reminder'])
            ->assertOk()
            ->json();

        self::assertSame(1, $body['documentsFound']);
        self::assertNull($body['answer']);
        self::assertSame('ai_not_configured', $body['answerStatus']);
    }

    public function test_a_non_reusable_learning_is_never_retrieved_even_when_it_matches(): void
    {
        $this->seedLearning(self::TENANT, 'Fee reminder cadence reduces arrears', reusable: false);

        $body = $this->withHeaders($this->auth())
            ->postJson('/api/v1/learnings/'.self::TENANT.'/ask', ['query' => 'fee reminder'])
            ->assertOk()
            ->json();

        self::assertSame(0, $body['documentsFound']);
    }

    public function test_another_tenants_learnings_are_not_retrieved(): void
    {
        $this->seedLearning('tenant-learning-ask-other', 'Fee reminder cadence reduces arrears');

        $body = $this->withHeaders($this->auth())
            ->postJson('/api/v1/learnings/'.self::TENANT.'/ask', ['query' => 'fee reminder'])
            ->assertOk()
            ->json();

        self::assertSame(0, $body['documentsFound']);
    }
}
