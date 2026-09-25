<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsAiIntelligenceSchema;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * /api/v1/ai-intelligence/* — tenant isolation, platform-row governance, and the
 * metered model path, on the suite's in-memory SQLite database (never the shared
 * hp_erp server; see phpunit.xml).
 */
final class AiIntelligenceConsoleTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsAiIntelligenceSchema;

    private const TENANT_A = 'tenant-aii-a';

    private const TENANT_B = 'tenant-aii-b';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildAiIntelligenceSchema();

        config([
            'brain.ai.provider' => '',
            'brain.ai.anthropic.api_key' => '',
            'brain.ai.gemini.api_key' => '',
            'brain.ai.deepseek.api_key' => '',
        ]);
    }

    private function auth(string $tenant = self::TENANT_A, string $role = 'tenant_admin', string $user = 'user-aii-1'): array
    {
        return ['Authorization' => 'Bearer ' . Jwt::issueAccess(['id' => $user, 'tenantId' => $tenant, 'role' => $role])];
    }

    private function recommendation(string $tenant, string $title, string $status = 'pending'): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_recommendations')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'title' => $title, 'category' => 'act',
            'priority' => 'high', 'confidence' => 0.8, 'status' => $status, 'created_by' => 'test',
            'dependencies' => '[]', 'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);

        return $id;
    }

    /** @test */
    public function viewers_cannot_reach_the_console(): void
    {
        $this->withHeaders($this->auth(self::TENANT_A, 'viewer'))
            ->getJson('/api/v1/ai-intelligence/capabilities')
            ->assertStatus(403);
    }

    /** @test */
    public function recommendations_are_scoped_to_the_callers_tenant_only(): void
    {
        $own = $this->recommendation(self::TENANT_A, 'Tenant A recommendation');
        $other = $this->recommendation(self::TENANT_B, 'Tenant B secret recommendation');
        $platform = $this->recommendation('*', 'Platform-wide recommendation');

        $list = $this->withHeaders($this->auth())->getJson('/api/v1/ai-intelligence/recommendations/pending');
        $list->assertOk()->assertJsonPath('data.tenant_id', self::TENANT_A)->assertJsonPath('data.counts.pending', 1);
        $this->assertStringNotContainsString('Tenant B secret', $list->getContent());
        $this->assertStringNotContainsString('Platform-wide', $list->getContent());

        $this->withHeaders($this->auth())->getJson("/api/v1/ai-intelligence/recommendations/{$other}")->assertStatus(404);
        $this->withHeaders($this->auth())->postJson("/api/v1/ai-intelligence/recommendations/{$other}/approve")->assertStatus(404);
        // G2G let an organisation decide a '*' row for everyone; here it is not theirs.
        $this->withHeaders($this->auth())->postJson("/api/v1/ai-intelligence/recommendations/{$platform}/approve")->assertStatus(404);

        $this->withHeaders($this->auth())->getJson("/api/v1/ai-intelligence/recommendations/{$own}")
            ->assertOk()
            ->assertJsonPath('data.recommendation.is_pending', true)
            ->assertJsonStructure(['data' => ['recommendation', 'reasoning', 'evidence', 'tenant_id']]);

        $this->withHeaders($this->auth())->postJson("/api/v1/ai-intelligence/recommendations/{$own}/approve", ['note' => 'Go'])
            ->assertOk()
            ->assertJsonPath('data.recommendation.status', 'accepted');

        // Deciding a non-pending recommendation is a conflict.
        $this->withHeaders($this->auth())->postJson("/api/v1/ai-intelligence/recommendations/{$own}/reject")->assertStatus(409);

        $this->assertSame('pending', DB::table('hpbrain_recommendations')->where('id', $other)->value('status'));
        $this->assertSame('pending', DB::table('hpbrain_recommendations')->where('id', $platform)->value('status'));
        $this->assertTrue(DB::table('hpbrain_ai_audit_logs')
            ->where('tenant_id', self::TENANT_A)->where('event_type', 'ai.recommendation.approved')->exists());
    }

    /** @test */
    public function credentials_are_encrypted_masked_and_tenant_owned(): void
    {
        $created = $this->withHeaders($this->auth())->postJson('/api/v1/ai-intelligence/configuration', [
            'ai_module' => 'conversational_ai',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'api_key' => 'sk-ant-test-0123456789abcdef',
        ]);

        $created->assertStatus(201)->assertJsonPath('data.configuration.scope', 'institute')
            ->assertJsonPath('data.configuration.editable', true);

        $this->assertStringNotContainsString('0123456789abcdef', $created->getContent());
        $this->assertSame('sk-a••••••••cdef', $created->json('data.configuration.key_preview'));

        $id = $created->json('data.configuration.id');
        $stored = DB::table('hpbrain_ai_api_keys')->where('id', $id)->value('api_key');
        $this->assertStringNotContainsString('sk-ant-test', (string) $stored);

        // Tenant B neither sees nor edits it.
        $index = $this->withHeaders($this->auth(self::TENANT_B))->getJson('/api/v1/ai-intelligence/configuration');
        $index->assertOk();
        $this->assertSame([], $index->json('data.configurations'));

        $this->withHeaders($this->auth(self::TENANT_B))->putJson("/api/v1/ai-intelligence/configuration/{$id}", [
            'ai_module' => 'conversational_ai', 'provider' => 'anthropic',
        ])->assertStatus(404);

        // The audit row never carries the key.
        $audit = DB::table('hpbrain_ai_audit_logs')->where('tenant_id', self::TENANT_A)->pluck('payload')->implode(' ');
        $this->assertStringNotContainsString('sk-ant-test', $audit);

        // Platform catalogue rows are read-only to a tenant.
        $platformModel = DB::table('hpbrain_ai_models')->where('tenant_id', '*')->value('id');
        $this->withHeaders($this->auth())->putJson("/api/v1/ai-intelligence/configuration-models/{$platformModel}", [
            'provider' => 'anthropic', 'model_id' => 'x', 'label' => 'x',
        ])->assertStatus(403);
    }

    /** @test */
    public function templates_fork_platform_rows_and_only_the_super_admin_publishes_shared(): void
    {
        $payload = [
            'name' => 'Signal digest',
            'module_key' => 'signals',
            'status' => 'published',
            'user_prompt' => 'Summarise {{records}} for {{organisation_name}}.',
        ];

        $this->withHeaders($this->auth())->postJson('/api/v1/ai-intelligence/templates', $payload + ['shared' => true])
            ->assertStatus(403);

        $shared = $this->withHeaders($this->auth(self::TENANT_A, 'admin'))
            ->postJson('/api/v1/ai-intelligence/templates', $payload + ['shared' => true]);
        $shared->assertStatus(201)->assertJsonPath('data.template.is_platform', true)
            ->assertJsonPath('data.template.editable_in_place', false);
        $platformId = $shared->json('data.template.id');

        $own = $this->withHeaders($this->auth(self::TENANT_B))
            ->postJson('/api/v1/ai-intelligence/templates', ['name' => 'B private template'] + $payload);
        $own->assertStatus(201)->assertJsonPath('data.template.is_platform', false);

        // Tenant A sees the platform row, not tenant B's.
        $list = $this->withHeaders($this->auth())->getJson('/api/v1/ai-intelligence/templates');
        $list->assertOk();
        $this->assertStringNotContainsString('B private template', $list->getContent());
        $this->assertSame(1, $list->json('data.counts.total'));

        // A tenant editing the platform template gets its own copy.
        $edit = $this->withHeaders($this->auth())
            ->putJson("/api/v1/ai-intelligence/templates/{$platformId}", ['name' => 'My digest'] + $payload);
        $edit->assertOk()->assertJsonPath('data.action', 'overridden')
            ->assertJsonPath('data.template.tenant_id', self::TENANT_A);
        $this->assertSame('Signal digest', DB::table('hpbrain_ai_templates')->where('id', $platformId)->value('name'));

        $this->withHeaders($this->auth())->deleteJson("/api/v1/ai-intelligence/templates/{$platformId}")->assertStatus(422);

        // Published prompts must carry a grounding variable.
        $this->withHeaders($this->auth())->postJson('/api/v1/ai-intelligence/templates', [
            'name' => 'Ungrounded', 'module_key' => 'signals', 'status' => 'published', 'user_prompt' => 'Say hello.',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['user_prompt']]);
    }

    /** @test */
    public function platform_policies_are_forked_not_edited_and_cannot_be_retired(): void
    {
        DB::table('hpbrain_ai_policies')->insert([
            'id' => $platform = Uuid::uuid4()->toString(), 'tenant_id' => '*', 'name' => 'Baseline',
            'policy_type' => 'ai_assisted', 'status' => 1, 'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);

        $moduleId = DB::table('hpbrain_ai_modules')->where('tenant_id', '*')->where('module_key', 'signals')->value('id');

        $fork = $this->withHeaders($this->auth())->putJson("/api/v1/ai-intelligence/policies/{$platform}", [
            'name' => 'Our baseline', 'policy_type' => 'ai_free',
            'rules' => ['use_ai_for_summarization' => false],
            'assignments' => [['scope_type' => 'module', 'scope_id' => $moduleId]],
        ]);

        $fork->assertOk()->assertJsonPath('data.action', 'forked')->assertJsonPath('data.forked_from', $platform)
            ->assertJsonPath('data.policy.editable', true)
            ->assertJsonPath('data.policy.module_keys.0', 'signals');

        $this->assertSame('Baseline', DB::table('hpbrain_ai_policies')->where('id', $platform)->value('name'));
        $this->withHeaders($this->auth())->deleteJson("/api/v1/ai-intelligence/policies/{$platform}")->assertStatus(403);

        // An assignment naming a target the tenant does not have is refused.
        $this->withHeaders($this->auth())->postJson('/api/v1/ai-intelligence/policies', [
            'name' => 'Bad scope', 'policy_type' => 'custom',
            'assignments' => [['scope_type' => 'position', 'scope_id' => 'not-a-position']],
        ])->assertStatus(422);

        $b = $this->withHeaders($this->auth(self::TENANT_B))->getJson('/api/v1/ai-intelligence/policies');
        $b->assertOk();
        $this->assertStringNotContainsString('Our baseline', $b->getContent());
        $this->assertStringContainsString('Baseline', $b->getContent());
    }

    /** @test */
    public function ask_without_a_credential_is_a_clean_not_configured_answer(): void
    {
        $response = $this->withHeaders($this->auth())->postJson('/api/v1/ai-intelligence/ask', ['message' => 'How many open signals?']);

        $response->assertOk()
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.configured', false);

        $this->withHeaders($this->auth(self::TENANT_B))->getJson('/api/v1/ai-intelligence/conversations')
            ->assertOk()->assertJsonPath('data.conversations', []);

        $this->withHeaders($this->auth())->getJson('/api/v1/ai-intelligence/conversations')
            ->assertOk()->assertJsonCount(1, 'data.conversations');
    }

    /** @test */
    public function every_call_is_metered_and_a_spent_quota_refuses_before_the_network(): void
    {
        config(['brain.ai.provider' => 'anthropic', 'brain.ai.model' => 'claude-sonnet-5', 'brain.ai.anthropic.api_key' => 'sk-ant-env-key-0000000000']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'There are 0 open signals.']],
                'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
                'stop_reason' => 'end_turn',
                'model' => 'claude-sonnet-5',
            ]),
        ]);

        $this->withHeaders($this->auth())->postJson('/api/v1/ai-intelligence/ask', ['message' => 'Open signals?'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'There are 0 open signals.')
            ->assertJsonPath('data.usage.provider', 'anthropic');

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'sk-ant-env-key-0000000000')
            && $request->hasHeader('anthropic-version', '2023-06-01'));

        $event = DB::table('hpbrain_ai_usage_events')->where('tenant_id', self::TENANT_A)->first();
        $this->assertSame('success', $event->outcome);
        $this->assertSame(150, (int) $event->input_tokens + (int) $event->output_tokens);
        $this->assertSame('env', $event->source);
        // claude-sonnet-5 is priced from config/brain.php: 120/1000*0.003 + 30/1000*0.015.
        $this->assertEqualsWithDelta(0.00081, (float) $event->estimated_cost_usd, 0.000001);

        $this->withHeaders($this->auth())->postJson('/api/v1/ai-intelligence/usage/quota', [
            'period' => 'month', 'token_limit' => 100,
        ])->assertOk()->assertJsonPath('data.quotas.0.exceeded', true);

        $refused = $this->withHeaders($this->auth())->postJson('/api/v1/ai-intelligence/ask', ['message' => 'Again?']);
        $refused->assertOk()->assertJsonPath('data.answer', null)->assertJsonPath('data.configured', true);

        Http::assertSentCount(1);
        $this->assertTrue(DB::table('hpbrain_ai_usage_events')->where('outcome', 'refused')->exists());

        $this->withHeaders($this->auth(self::TENANT_B))->getJson('/api/v1/ai-intelligence/usage')
            ->assertOk()->assertJsonPath('data.totals.calls', 0);
        $this->withHeaders($this->auth())->getJson('/api/v1/ai-intelligence/usage')
            ->assertOk()->assertJsonPath('data.totals.calls', 2)->assertJsonPath('data.totals.refused', 1);
    }

    /** @test */
    public function evaluations_score_by_assertion_and_stay_in_their_tenant(): void
    {
        config(['brain.ai.provider' => 'deepseek', 'brain.ai.model' => 'deepseek-v4-flash', 'brain.ai.deepseek.api_key' => 'sk-deepseek-0000000000']);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Twelve open signals need triage.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 8],
            ]),
        ]);

        $this->withHeaders($this->auth())->postJson('/api/v1/ai-intelligence/templates', [
            'name' => 'Triage', 'template_key' => 'hpbrain.signals.triage', 'module_key' => 'signals',
            'status' => 'published', 'user_prompt' => 'Triage {{records}}',
        ])->assertStatus(201);

        $created = $this->withHeaders($this->auth())->postJson('/api/v1/ai-intelligence/evaluations', [
            'name' => 'Triage regression',
            'template_key' => 'hpbrain.signals.triage',
            'cases' => [
                ['label' => 'mentions count', 'variables' => ['records' => '- 12 signals'], 'expect_contains' => ['twelve'], 'expect_absent' => ['%']],
                ['label' => 'fails', 'expect_contains' => ['forty']],
            ],
        ]);
        $created->assertStatus(201);
        $id = $created->json('data.evaluation.id');

        $this->withHeaders($this->auth(self::TENANT_B))->postJson("/api/v1/ai-intelligence/evaluations/{$id}/run")->assertStatus(404);

        $run = $this->withHeaders($this->auth())->postJson("/api/v1/ai-intelligence/evaluations/{$id}/run");
        $run->assertOk()
            ->assertJsonPath('data.evaluation.status', 'completed')
            ->assertJsonPath('data.evaluation.passed_count', 1)
            ->assertJsonPath('data.evaluation.failed_count', 1);

        Http::assertSent(fn ($request) => ($request->data()['temperature'] ?? null) === 0.0 || ($request->data()['temperature'] ?? null) === 0);
        $this->assertSame(2, DB::table('hpbrain_ai_usage_events')->where('ai_module', 'evaluation_ai')->count());
    }

    /** @test */
    public function capabilities_count_only_the_callers_rows(): void
    {
        $this->recommendation(self::TENANT_A, 'A1');
        $this->recommendation(self::TENANT_B, 'B1');
        $this->recommendation(self::TENANT_B, 'B2');

        $detail = $this->withHeaders($this->auth())->getJson('/api/v1/ai-intelligence/capabilities/recommendations');
        $detail->assertOk()->assertJsonPath('data.state', 'live')->assertJsonPath('data.metrics.0.value', 1);
        $this->assertStringNotContainsString('B1', $detail->getContent());

        $index = $this->withHeaders($this->auth())->getJson('/api/v1/ai-intelligence/capabilities');
        $index->assertOk()->assertJsonCount(12, 'data.capabilities');
    }
}
