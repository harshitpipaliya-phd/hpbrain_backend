<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\AiIntelligence\Configuration\ResolvedAiConfiguration;
use App\Domain\AiIntelligence\Stack\AiStackExampleSeeder;
use App\Domain\AiIntelligence\Support\AiUsageMeter;
use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsAiIntelligenceSchema;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * The AI Stack's database-driven profile, per-module attribution of metered calls,
 * the tool-agent run summary, the data-source module check, and the
 * `ai-stack:seed-examples` command (idempotent, dry-run writes nothing, examples are
 * tenant + module scoped and created through the real controller actions).
 * In-memory SQLite, never the shared server.
 */
final class AiStackExamplesTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsAiIntelligenceSchema;
    use BuildsErpFixture;

    private const TENANT_A = '4';

    private const TENANT_B = 'tenant-examples-b';

    private const BASE = '/api/v1/ai-intelligence';

    /** Every table the examples may write. */
    private const AI_TABLES = [
        'hpbrain_ai_policies', 'hpbrain_ai_policy_rules', 'hpbrain_ai_policy_assignments', 'hpbrain_ai_templates',
        'hpbrain_ai_suggestions', 'hpbrain_ai_tool_agents', 'hpbrain_ai_tool_agent_runs', 'hpbrain_ai_generated_reports',
        'hpbrain_ai_module_model_bindings', 'hpbrain_ai_conversations', 'hpbrain_ai_conversation_turns',
        'hpbrain_ai_usage_events', 'hpbrain_ai_audit_logs',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildAiIntelligenceSchema();
        $this->buildErpSchema();
        $this->seedErpFixture((int) self::TENANT_A);
        (new EntityMappingSeeder([self::TENANT_A]))->run();

        config([
            'brain.ai.provider' => '',
            'brain.ai.anthropic.api_key' => '',
            'brain.ai.gemini.api_key' => '',
            'brain.ai.deepseek.api_key' => '',
        ]);
    }

    private function auth(string $tenant = self::TENANT_A): array
    {
        return ['Authorization' => 'Bearer ' . Jwt::issueAccess(['id' => 'user-examples-1', 'tenantId' => $tenant, 'role' => 'tenant_admin'])];
    }

    private function signal(string $tenant, string $classification, string $severity = 'high'): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => $tenant, 'source' => 'test', 'classification' => $classification,
            'severity' => $severity, 'priority' => 'normal', 'status' => 'new', 'confidence' => 0.7,
            'created_by' => 'test', 'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $out = [];

        foreach (self::AI_TABLES as $table) {
            $out[$table] = DB::table($table)->count();
        }

        return $out;
    }

    /** @test */
    public function the_profile_serves_the_module_catalogue_from_the_database(): void
    {
        $profile = $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/profile');

        $profile->assertOk()
            ->assertJsonPath('data.module.key', 'signals')
            ->assertJsonPath('data.module.label', 'Signals')
            ->assertJsonPath('data.module.icon', 'activity')
            ->assertJsonPath('data.module.capabilities.agent', true)
            ->assertJsonPath('data.module.registry_keys.0', 'signal_intelligence')
            ->assertJsonPath('data.data_sources.0.name', 'signals.open')
            ->assertJsonPath('data.tools.0.key', 'signals.open')
            ->assertJsonPath('data.tools.0.risk', 'read')
            ->assertJsonPath('data.tools.0.kind', 'mcp')
            ->assertJsonPath('data.tools.0.available', true)
            ->assertJsonPath('data.tools.0.example_input', ['severity' => null, 'status' => null, 'limit' => 50])
            ->assertJsonPath('data.presets.0.name', 'Open signal reader')
            ->assertJsonPath('data.presets.0.module', 'signals')
            ->assertJsonPath('data.presets.0.tools_allowed', ['signals.open'])
            ->assertJsonPath('data.presets.0.status', 'active');

        $this->assertSame(['module', 'data_sources', 'tools', 'presets'], array_keys($profile->json('data')));
        $this->assertSame(['key', 'label', 'description', 'icon', 'capabilities', 'registry_keys'], array_keys($profile->json('data.module')));
        $this->assertSame(['name', 'label', 'description', 'columns', 'arguments'], array_keys($profile->json('data.data_sources.0')));
        $this->assertSame(['key', 'label', 'description', 'module', 'risk', 'kind', 'available', 'example_input'], array_keys($profile->json('data.tools.0')));
        $this->assertSame(['name', 'description', 'module', 'tools_allowed', 'instructions', 'status'], array_keys($profile->json('data.presets.0')));

        $arguments = collect($profile->json('data.data_sources.0.arguments'))->keyBy('key');
        $this->assertSame(['key' => 'limit', 'type' => 'integer', 'description' => 'Maximum rows to return (1–500, default 100).', 'default' => 100, 'min' => 1, 'max' => 500], $arguments['limit']);
        $this->assertSame(['low', 'medium', 'high', 'critical'], $arguments['severity']['values']);
        $this->assertArrayNotHasKey('default', $arguments['severity']);

        // Labels are the HP Brain screen names, from the platform rows.
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/executions/profile')->assertOk()->assertJsonPath('data.module.label', 'Execution Center');

        // A tenant row shadows the platform's label, and a tenant-hidden module is a 404.
        DB::table('hpbrain_ai_modules')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::TENANT_B, 'module_key' => 'signals', 'label' => 'Alerts',
            'status' => 1, 'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);
        $this->withHeaders($this->auth(self::TENANT_B))->getJson(self::BASE . '/modules/signals/profile')
            ->assertOk()
            ->assertJsonPath('data.module.label', 'Alerts')
            ->assertJsonPath('data.presets.0.name', 'Open signal reader')
            // An unmapped tenant's ERP-backed source is measured as unavailable, not assumed.
            ;
        $this->withHeaders($this->auth(self::TENANT_B))->getJson(self::BASE . '/modules/people/profile')
            ->assertOk()->assertJsonPath('data.tools.0.available', false);
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/no_such/profile')->assertStatus(404);
    }

    /** @test */
    public function usage_is_attributed_by_product_module_so_modules_sharing_a_capability_differ(): void
    {
        $meter = app(AiUsageMeter::class);
        $config = new ResolvedAiConfiguration('anthropic', 'claude-sonnet-5', 'k', 'pool', null, 'institute', null);

        // signals and evidence both use conversational_ai.
        $meter->record('conversational_ai', $config, self::TENANT_A, 100, 20, 50, ['product_module' => 'signals']);
        $meter->record('conversational_ai', $config, self::TENANT_A, 100, 20, 50, ['product_module' => 'signals']);
        $meter->record('conversational_ai', $config, self::TENANT_A, 0, 0, null, ['product_module' => 'signals', 'outcome' => 'refused', 'error' => 'quota']);
        $meter->record('conversational_ai', $config, self::TENANT_A, 100, 20, 50, ['product_module' => 'evidence', 'outcome' => 'failed', 'error' => 'boom']);
        // Not attributed to any module (the console, or an older row).
        $meter->record('conversational_ai', $config, self::TENANT_A, 100, 20, 50);
        // Another tenant's signals row.
        $meter->record('conversational_ai', $config, self::TENANT_B, 100, 20, 50, ['product_module' => 'signals']);

        $signals = $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/usage')->assertOk();
        $signals->assertJsonPath('data.generation.total', 3)
            ->assertJsonPath('data.generation.tokens.outputs', 2)
            ->assertJsonPath('data.generation.tokens.prompt_tokens', 200)
            ->assertJsonPath('data.generation.tokens.reviewed', null);
        $this->assertArrayNotHasKey('by_intent', $signals->json('data.conversations.turn_detail'));

        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/evidence/usage')
            ->assertOk()->assertJsonPath('data.generation.total', 1);

        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/guardrails')
            ->assertOk()
            ->assertJsonPath('data.refusal_totals', ['refused' => 1, 'failed' => 0, 'total' => 1])
            ->assertJsonCount(1, 'data.refusals');
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/evidence/guardrails')
            ->assertOk()
            ->assertJsonPath('data.refusal_totals', ['refused' => 0, 'failed' => 1, 'total' => 1]);

        // A module with no registry consumer still counts calls made from it.
        $meter->record('conversational_ai', $config, self::TENANT_A, 10, 5, 5, ['product_module' => 'policies']);
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/policies/usage')
            ->assertOk()->assertJsonPath('data.generation.total', 1);
    }

    /** @test */
    public function the_assistant_attributes_its_call_to_the_module_it_was_asked_from(): void
    {
        config(['brain.ai.provider' => 'anthropic', 'brain.ai.model' => 'claude-sonnet-5', 'brain.ai.anthropic.api_key' => 'sk-ant-env-key-0000000000']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'One open signal.']],
                'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $this->withHeaders($this->auth())->postJson(self::BASE . '/ask', ['message' => 'How many open signals?', 'module_key' => 'signals'])->assertOk();
        $this->withHeaders($this->auth())->postJson(self::BASE . '/ask', ['message' => 'Console question'])->assertOk();

        $this->assertSame(['signals', null], DB::table('hpbrain_ai_usage_events')->orderBy('created_date')->orderBy('product_module', 'desc')->pluck('product_module')->all());
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/usage')->assertJsonPath('data.generation.total', 1);
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/evidence/usage')->assertJsonPath('data.generation.total', 0);
    }

    /** @test */
    public function tool_agent_runs_carry_a_summary_over_every_run_not_the_page(): void
    {
        $this->signal(self::TENANT_A, 'a');

        $agent = $this->withHeaders($this->auth())->postJson(self::BASE . '/tool-agents', [
            'name' => 'Reader', 'module' => 'signals', 'tools_allowed' => ['signals.open'], 'status' => 'active',
        ])->assertStatus(201)->json('data.agent.id');

        $this->withHeaders($this->auth())->postJson(self::BASE . "/tool-agents/{$agent}/run")->assertJsonPath('data.run.status', 'success');
        $this->withHeaders($this->auth())->postJson(self::BASE . "/tool-agents/{$agent}/run")->assertJsonPath('data.run.status', 'success');
        $this->withHeaders($this->auth())->postJson(self::BASE . "/tool-agents/{$agent}/run", ['tool' => 'evidence.by_signal'])->assertJsonPath('data.run.status', 'denied');

        $runs = $this->withHeaders($this->auth())->getJson(self::BASE . '/tool-agent-runs?module=signals&limit=1')->assertOk();
        $runs->assertJsonCount(1, 'data.runs')
            ->assertJsonPath('data.summary.total', 3)
            ->assertJsonPath('data.summary.today', 3)
            ->assertJsonPath('data.summary.succeeded', 2)
            ->assertJsonPath('data.summary.denied', 1)
            ->assertJsonPath('data.summary.failed', 0)
            ->assertJsonPath('data.summary.distinct_users', 1);
        $this->assertIsInt($runs->json('data.summary.avg_duration_ms'));

        $this->withHeaders($this->auth())->getJson(self::BASE . '/tool-agent-runs?module=evidence')
            ->assertOk()->assertJsonPath('data.summary.total', 0)->assertJsonPath('data.summary.avg_duration_ms', null);
        $this->withHeaders($this->auth(self::TENANT_B))->getJson(self::BASE . '/tool-agent-runs')
            ->assertOk()->assertJsonPath('data.summary.total', 0);
    }

    /** @test */
    public function a_data_source_run_from_a_module_must_be_that_modules_source(): void
    {
        $this->signal(self::TENANT_A, 'a');

        $this->withHeaders($this->auth())->postJson(self::BASE . '/data-sources/signals.open/run', ['module' => 'signals'])
            ->assertOk()->assertJsonPath('data.data.total', 1)->assertJsonPath('data.data.returned', 1);
        $this->withHeaders($this->auth())->postJson(self::BASE . '/data-sources/signals.open/run', ['module' => 'evidence'])
            ->assertStatus(422);
        $this->withHeaders($this->auth())->postJson(self::BASE . '/data-sources/signals.open/run', ['module' => 'no_such'])
            ->assertStatus(422);

        // `total` is the source's real size, not the rows fetched.
        $this->signal(self::TENANT_A, 'b');
        $this->signal(self::TENANT_A, 'c');
        $this->withHeaders($this->auth())->postJson(self::BASE . '/data-sources/signals.open/run', ['arguments' => ['limit' => 2]])
            ->assertOk()
            ->assertJsonPath('data.data.total', 3)
            ->assertJsonPath('data.data.returned', 2)
            ->assertJsonPath('data.data.truncated', true)
            ->assertJsonCount(2, 'data.data.rows');
    }

    /** @test */
    public function seeding_examples_is_idempotent_scoped_and_goes_through_the_real_actions(): void
    {
        $this->signal(self::TENANT_A, 'tenant-a-open');
        $this->signal(self::TENANT_B, 'tenant-b-secret');

        // Dry run: nothing written.
        $before = $this->counts();
        $this->artisan('ai-stack:seed-examples', ['--tenant' => [self::TENANT_A], '--module' => ['signals', 'people'], '--dry-run' => true])
            ->assertExitCode(0);
        $this->assertSame($before, $this->counts());

        $this->artisan('ai-stack:seed-examples', ['--tenant' => [self::TENANT_A], '--module' => ['signals', 'people']])
            ->assertExitCode(0);

        $after = $this->counts();

        foreach (['signals', 'people'] as $module) {
            $this->assertSame(1, DB::table('hpbrain_ai_templates')->where('tenant_id', self::TENANT_A)->where('module_key', $module)->where('kind', 'prompt')->where('status', 'published')->count(), "{$module} prompt");
            $this->assertSame(1, DB::table('hpbrain_ai_templates')->where('tenant_id', self::TENANT_A)->where('module_key', $module)->where('kind', 'report')->count(), "{$module} report layout");
            $this->assertSame(1, DB::table('hpbrain_ai_tool_agents')->where('tenant_id', self::TENANT_A)->where('module', $module)->where('status', 'active')->count(), "{$module} agent");
            $this->assertSame(1, DB::table('hpbrain_ai_tool_agent_runs')->where('tenant_id', self::TENANT_A)->where('module', $module)->where('status', 'success')->count(), "{$module} run");
            $this->assertSame(1, DB::table('hpbrain_ai_generated_reports')->where('tenant_id', self::TENANT_A)->where('module_key', $module)->count(), "{$module} report");
            $this->assertSame(1, DB::table('hpbrain_ai_module_model_bindings')->where('tenant_id', self::TENANT_A)->where('product_module', $module)->whereNull('api_key_id')->count(), "{$module} binding");
            $this->assertGreaterThan(0, DB::table('hpbrain_ai_audit_logs')->where('tenant_id', self::TENANT_A)->where('event_type', 'like', "module.{$module}.%")->where('actor_id', AiStackExampleSeeder::ACTOR)->count());
        }

        // Every example is the system actor's and names only its own module.
        $this->assertSame(0, DB::table('hpbrain_ai_templates')->where('tenant_id', self::TENANT_A)->where('created_by', '<>', AiStackExampleSeeder::ACTOR)->count());
        $this->assertSame(['people', 'signals'], DB::table('hpbrain_ai_templates')->where('tenant_id', self::TENANT_A)->distinct()->orderBy('module_key')->pluck('module_key')->all());
        $this->assertSame('signals.open', DB::table('hpbrain_ai_templates')->where('tenant_id', self::TENANT_A)->where('module_key', 'signals')->where('kind', 'report')->value('data_source'));
        $this->assertSame('Open signal reader', DB::table('hpbrain_ai_tool_agents')->where('tenant_id', self::TENANT_A)->where('module', 'signals')->value('name'));
        $this->assertStringContainsString('presenting a signal as a confirmed finding', (string) DB::table('hpbrain_ai_templates')->where('tenant_id', self::TENANT_A)->where('module_key', 'signals')->where('kind', 'prompt')->value('system_prompt'));

        // The policy is assigned to the module id the tenant resolves.
        $signalsModuleId = DB::table('hpbrain_ai_modules')->where('tenant_id', '*')->where('module_key', 'signals')->value('id');
        $policies = $this->withHeaders($this->auth())->getJson(self::BASE . '/policies?module_key=signals')->assertOk()->json('data.policies');
        $this->assertCount(1, $policies);
        $this->assertSame('Signals — AI use policy', $policies[0]['name']);
        $this->assertSame($signalsModuleId, $policies[0]['assignments'][0]['scope_id']);

        // The report is the module's own data, row_count the real total.
        $report = DB::table('hpbrain_ai_generated_reports')->where('tenant_id', self::TENANT_A)->where('module_key', 'signals')->first();
        $this->assertSame(1, (int) $report->row_count);
        $this->assertStringContainsString('tenant-a-open', $report->html_content);
        $this->assertStringNotContainsString('tenant-b-secret', $report->html_content);
        $this->assertStringContainsString('1 record(s) in total', $report->html_content);

        // The tabs now read real rows for the module.
        $this->withHeaders($this->auth())->getJson(self::BASE . '/templates?module_key=signals&latest_only=1')
            ->assertOk()->assertJsonPath('data.counts.total', 2)->assertJsonPath('data.counts.offered', 2);
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/models')
            ->assertOk()->assertJsonPath('data.rows.1.binding.scope', 'institute');
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/guardrails')
            ->assertOk()->assertJsonPath('data.review.templates', 2)->assertJsonPath('data.review.published', 2)->assertJsonPath('data.review.requires_review', 1);
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/usage')
            ->assertOk()->assertJsonPath('data.reports.total', 1);

        // Tenant B was not touched, and cannot see A's examples.
        foreach (self::AI_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', self::TENANT_B)->count(), $table);
        }
        $this->withHeaders($this->auth(self::TENANT_B))->getJson(self::BASE . '/policies?module_key=signals')->assertOk()->assertJsonCount(0, 'data.policies');

        // A second run creates nothing.
        $this->artisan('ai-stack:seed-examples', ['--tenant' => [self::TENANT_A], '--module' => ['signals', 'people']])
            ->assertExitCode(0);
        $this->assertSame($after, $this->counts());

        // The people source is ERP-backed and mapped for tenant A, so its report is real too.
        $this->assertSame(5, (int) DB::table('hpbrain_ai_generated_reports')->where('tenant_id', self::TENANT_A)->where('module_key', 'people')->value('row_count'));
    }

    /** @test */
    public function a_tenant_without_data_gets_an_honest_empty_report_and_the_default_tenant_list_skips_it(): void
    {
        $this->signal(self::TENANT_A, 'a');

        $seeder = app(AiStackExampleSeeder::class);
        $this->assertContains(self::TENANT_A, $seeder->tenants());
        $this->assertNotContains('*', $seeder->tenants());

        // Named explicitly, a tenant with no signals still gets every example, and its
        // report honestly counts 0 rows.
        $result = $seeder->seed(self::TENANT_B, 'signals');

        $this->assertSame(AiStackExampleSeeder::CREATED, $result['report']['status'], $result['report']['detail']);
        $this->assertSame(0, (int) DB::table('hpbrain_ai_generated_reports')->where('tenant_id', self::TENANT_B)->value('row_count'));
        $this->assertSame(AiStackExampleSeeder::SKIPPED, $result['conversation']['status']);
        $this->assertSame(0, DB::table('hpbrain_ai_templates')->where('tenant_id', self::TENANT_A)->count());
    }

    /** @test */
    public function module_screens_read_only_their_own_module(): void
    {
        $this->signal(self::TENANT_A, 'preview-signal', 'critical');
        $now = '2026-09-01 00:00:00';

        // A10 — a module's prompt preview uses that module's own rows.
        $preview = $this->withHeaders($this->auth())->postJson(self::BASE . '/templates/preview', [
            'user_prompt' => '{{records}} / {{record_count}} / {{page_title}}', 'module_key' => 'signals',
        ])->assertOk();
        $this->assertStringContainsString('preview-signal', $preview->json('data.user'));
        $this->assertStringContainsString('/ 1 / Open signals', $preview->json('data.user'));
        // The console (no module) keeps the capability-model preview.
        $this->withHeaders($this->auth())->postJson(self::BASE . '/templates/preview', ['user_prompt' => '{{page_title}}'])
            ->assertOk()->assertJsonPath('data.user', 'Capability model');

        // C2 / C11 / C3 — credentials of THIS module's capabilities only.
        $own = Uuid::uuid4()->toString();
        $other = Uuid::uuid4()->toString();
        foreach ([[$own, 'signal_intelligence'], [$other, 'knowledge_ai']] as [$id, $capability]) {
            DB::table('hpbrain_ai_api_keys')->insert([
                'id' => $id, 'tenant_id' => self::TENANT_A, 'ai_module' => $capability, 'api_type' => 'gemini', 'model' => 'gemini-2.5-flash',
                'api_key' => 'sealed', 'api_limit' => '500', 'status' => 1, 'created_date' => $now, 'updated_date' => $now,
            ]);
        }

        $credentials = $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/models')->assertOk()->json('data.credentials');
        $this->assertSame([$own], array_column($credentials, 'id'));
        $this->assertSame(['id', 'provider', 'capability', 'label', 'daily_limit', 'scope', 'status'], array_keys($credentials[0]));

        $this->withHeaders($this->auth())->putJson(self::BASE . "/modules/signals/models/credentials/{$other}", ['status' => 0])->assertStatus(422);
        $this->withHeaders($this->auth())->putJson(self::BASE . "/modules/signals/models/credentials/{$own}", ['api_limit' => 600])->assertOk();

        // A tenant key that merely exists for the capability is not the module's own…
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/usage')->assertJsonPath('data.provider.bound', false);
        // …a key one of the module's bindings points at is.
        DB::table('hpbrain_ai_module_model_bindings')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::TENANT_A, 'product_module' => 'signals', 'capability' => 'signal_intelligence',
            'provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'api_key_id' => $own, 'status' => 1, 'created_date' => $now, 'updated_date' => $now,
        ]);
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/usage')
            ->assertJsonPath('data.provider.bound', true)
            ->assertJsonPath('data.provider.provider', 'gemini')
            ->assertJsonPath('data.provider.daily_limit', 600)
            ->assertJsonPath('data.provider.scope', 'institute');
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/evidence/usage')->assertJsonPath('data.provider.bound', false);

        // C5 / C7 / C4 — latest version per key, tenant shadows platform, offered only in its own module.
        $template = fn (string $tenant, string $key, int $version, string $status, string $module = 'signals') => DB::table('hpbrain_ai_templates')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => $tenant, 'template_key' => $key, 'name' => "{$key} v{$version} {$tenant}",
            'module_key' => $module, 'kind' => 'prompt', 'status' => $status, 'version' => $version, 'user_prompt' => '{{records}}',
            'requires_review' => 1, 'created_date' => $now, 'updated_date' => $now,
        ]);
        $template('*', 'hpbrain.signals.brief', 1, 'published');
        $template(self::TENANT_A, 'hpbrain.signals.brief', 1, 'archived');
        $template(self::TENANT_A, 'hpbrain.signals.brief', 2, 'published');
        $template('*', 'hpbrain.signals.platform_only', 1, 'published');
        DB::table('hpbrain_ai_suggestions')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::TENANT_A, 'module_key' => 'evidence', 'capability' => 'generative',
            'label' => 'Brief', 'action_type' => 'generate', 'action_ref' => 'hpbrain.signals.brief', 'status' => 1,
            'created_date' => $now, 'updated_date' => $now,
        ]);

        $latest = $this->withHeaders($this->auth())->getJson(self::BASE . '/templates?module_key=signals&latest_only=1')->assertOk();
        $this->assertSame(['hpbrain.signals.brief v2 ' . self::TENANT_A, 'hpbrain.signals.platform_only v1 *'], array_column($latest->json('data.templates'), 'name'));
        $this->assertFalse($latest->json('data.templates.0.offered_in_module'));
        $this->withHeaders($this->auth())->getJson(self::BASE . '/templates?module_key=signals')->assertOk()->assertJsonPath('data.counts.total', 4);
        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/guardrails')
            ->assertJsonPath('data.review.templates', 2)->assertJsonPath('data.review.published', 2)->assertJsonPath('data.review.requires_review', 2);

        // C12 — a tenant's own catalogued model is its provider default.
        DB::table('hpbrain_ai_models')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::TENANT_A, 'provider' => 'anthropic', 'model_id' => 'claude-tenant-own',
            'label' => 'Tenant own', 'sort_order' => 99, 'status' => 1, 'created_date' => $now, 'updated_date' => $now,
        ]);
        $this->assertSame('claude-tenant-own', app(\App\Domain\AiIntelligence\Configuration\ModelCatalog::class)->defaultFor('anthropic', self::TENANT_A));
        $this->assertNotSame('claude-tenant-own', app(\App\Domain\AiIntelligence\Configuration\ModelCatalog::class)->defaultFor('anthropic', self::TENANT_B));
    }

    /** @test */
    public function module_scoped_policy_listing_hides_a_forked_platform_policy(): void
    {
        $moduleId = DB::table('hpbrain_ai_modules')->where('tenant_id', '*')->where('module_key', 'signals')->value('id');
        $platform = Uuid::uuid4()->toString();

        DB::table('hpbrain_ai_policies')->insert([
            'id' => $platform, 'tenant_id' => '*', 'name' => 'Shared signals policy', 'policy_type' => 'ai_assisted', 'status' => 1,
            'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);
        DB::table('hpbrain_ai_policy_assignments')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => '*', 'policy_id' => $platform, 'scope_type' => 'module',
            'scope_id' => $moduleId, 'status' => 1, 'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);

        $this->withHeaders($this->auth())->getJson(self::BASE . '/policies?module_key=signals')->assertOk()->assertJsonCount(1, 'data.policies');

        $fork = $this->withHeaders($this->auth())->putJson(self::BASE . "/policies/{$platform}", [
            'name' => 'Our signals policy', 'policy_type' => 'ai_assisted',
            'assignments' => [['scope_type' => 'module', 'scope_id' => $moduleId]],
        ])->assertOk()->assertJsonPath('data.action', 'forked');

        $this->assertSame($platform, DB::table('hpbrain_ai_policies')->where('id', $fork->json('data.policy.id'))->value('forked_from'));

        $listed = $this->withHeaders($this->auth())->getJson(self::BASE . '/policies?module_key=signals')->assertOk()->json('data.policies');
        $this->assertSame(['Our signals policy'], array_column($listed, 'name'));

        // Another tenant still sees the platform policy; the console listing is unchanged.
        $this->withHeaders($this->auth(self::TENANT_B))->getJson(self::BASE . '/policies?module_key=signals')
            ->assertOk()->assertJsonPath('data.policies.0.name', 'Shared signals policy');
        $this->withHeaders($this->auth())->getJson(self::BASE . '/policies')->assertOk()->assertJsonCount(2, 'data.policies');
    }
}
