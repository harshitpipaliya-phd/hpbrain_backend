<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\AiIntelligence\Configuration\AiConfigurationResolver;
use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsAiIntelligenceSchema;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * /api/v1/ai-intelligence — the per-module AI Stack routes (usage, guardrails,
 * activity, models, reports, data sources, tool agents): module 404s, tenant
 * isolation, the tool-agent allow-list and module re-check, and data sources
 * hard-scoped to the caller's tenant. In-memory SQLite, never the shared server.
 */
final class AiStackModuleTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsAiIntelligenceSchema;
    use BuildsErpFixture;

    /** Mapped to the ERP fixture (sub_institute_id 4), as a real tenant is. */
    private const TENANT_A = '4';

    /** No ERP mapping at all. */
    private const TENANT_B = 'tenant-stack-b';

    private const BASE = '/api/v1/ai-intelligence';

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

    private function auth(string $tenant = self::TENANT_A, string $role = 'tenant_admin', string $user = 'user-stack-1'): array
    {
        return ['Authorization' => 'Bearer ' . Jwt::issueAccess(['id' => $user, 'tenantId' => $tenant, 'role' => $role])];
    }

    private function signal(string $tenant, string $classification, string $status = 'new', string $severity = 'high'): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_signals')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'source' => 'test', 'classification' => $classification,
            'severity' => $severity, 'priority' => 'normal', 'status' => $status, 'confidence' => 0.7,
            'created_by' => 'test', 'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);

        return $id;
    }

    /** @test */
    public function unknown_or_inactive_modules_are_a_404_envelope(): void
    {
        foreach (['usage', 'guardrails', 'activity', 'models'] as $tab) {
            $this->withHeaders($this->auth())->getJson(self::BASE . "/modules/no_such_module/{$tab}")
                ->assertStatus(404)
                ->assertJsonPath('success', false)
                ->assertJsonPath('data', null);
        }

        // A tenant row that switches an area off hides it for that tenant only.
        DB::table('hpbrain_ai_modules')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::TENANT_B, 'module_key' => 'signals',
            'label' => 'Signals', 'status' => 0, 'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);

        $this->withHeaders($this->auth(self::TENANT_B))->getJson(self::BASE . '/modules/signals/usage')->assertStatus(404);

        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/usage')
            ->assertOk()
            ->assertJsonPath('data.module.key', 'signals')
            ->assertJsonPath('data.module.capabilities.agent', true)
            ->assertJsonStructure(['data' => ['module', 'conversations', 'generation', 'reports', 'provider', 'recent_turns', 'daily']]);

        $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/mental_models/guardrails')
            ->assertOk()
            ->assertJsonPath('data.module.label', 'Organizational Knowledge')
            ->assertJsonStructure(['data' => ['module', 'capabilities', 'review', 'refusals', 'refusal_counts']]);
    }

    /** @test */
    public function data_sources_read_only_the_callers_tenant(): void
    {
        $this->signal(self::TENANT_A, 'tenant-a-open');
        $this->signal(self::TENANT_A, 'tenant-a-closed', 'resolved');
        $this->signal(self::TENANT_B, 'tenant-b-secret');
        $this->signal('*', 'platform-row');

        $run = $this->withHeaders($this->auth())->postJson(self::BASE . '/data-sources/signals.open/run', [
            // `tenant_id` is not a declared argument and must not widen anything.
            'arguments' => ['tenant_id' => self::TENANT_B, 'limit' => 50],
        ]);

        $run->assertOk()
            ->assertJsonPath('data.source.module', 'signals')
            ->assertJsonPath('data.data.total', 1)
            ->assertJsonPath('data.data.rows.0.classification', 'tenant-a-open');
        $this->assertStringNotContainsString('tenant-b-secret', $run->getContent());
        $this->assertStringNotContainsString('platform-row', $run->getContent());
        $this->assertStringNotContainsString('tenant-a-closed', $run->getContent());

        $this->withHeaders($this->auth())->postJson(self::BASE . '/data-sources/signals.open/run', ['arguments' => ['limit' => 501]])
            ->assertStatus(422);
        $this->withHeaders($this->auth())->postJson(self::BASE . '/data-sources/no.such/run')->assertStatus(404);

        // ERP-backed: roster fields only, never contact details.
        $people = $this->withHeaders($this->auth())->postJson(self::BASE . '/data-sources/people.directory/run');
        $people->assertOk()->assertJsonPath('data.data.available', true)->assertJsonPath('data.data.total', 5);
        $this->assertStringNotContainsString('@x.test', $people->getContent());
        $this->assertSame(
            ['person_id', 'name', 'department', 'role', 'position', 'record_completeness', 'missing'],
            array_keys($people->json('data.data.rows.0'))
        );

        $this->withHeaders($this->auth())->postJson(self::BASE . '/data-sources/people.directory/run', ['arguments' => ['completeness' => 'incomplete']])
            ->assertOk()->assertJsonPath('data.data.total', 3);

        $this->withHeaders($this->auth())->postJson(self::BASE . '/data-sources/departments.roster/run')
            ->assertOk()->assertJsonPath('data.data.total', 3);

        // A tenant with no ERP mapping gets "unavailable", not an error or another tenant's rows.
        $this->withHeaders($this->auth(self::TENANT_B))->postJson(self::BASE . '/data-sources/people.directory/run')
            ->assertOk()
            ->assertJsonPath('data.data.available', false)
            ->assertJsonPath('data.data.total', 0);
    }

    /** @test */
    public function every_catalogued_source_runs_and_returns_its_declared_columns(): void
    {
        $this->signal(self::TENANT_A, 'tenant-a-open');

        $catalogue = app(\App\Domain\AiIntelligence\Reports\ModuleDataSourceCatalog::class);

        $this->assertCount(18, $catalogue->all());

        foreach ($catalogue->all() as $source) {
            $run = $this->withHeaders($this->auth())->postJson(self::BASE . "/data-sources/{$source['name']}/run", ['arguments' => ['limit' => 5]]);
            $run->assertOk();
            $this->assertTrue($run->json('data.data.available'), "{$source['name']} should be available");

            foreach ($run->json('data.data.rows') as $row) {
                $this->assertSame(array_column($source['columns'], 'key'), array_keys($row), "{$source['name']} columns");
            }
        }
    }

    /** @test */
    public function templates_options_list_the_ai_stack_sources_in_the_same_shape(): void
    {
        $sources = $this->withHeaders($this->auth())->getJson(self::BASE . '/templates/options')
            ->assertOk()
            ->json('data.data_sources');

        $this->assertCount(18, $sources);
        $this->assertSame(['name', 'module', 'label', 'description', 'arguments'], array_keys($sources[0]));
        $this->assertContains('knowledge.mental_models', array_column($sources, 'name'));
    }

    /** @test */
    public function tool_agents_are_tenant_owned_and_run_only_their_own_modules_tools(): void
    {
        $this->signal(self::TENANT_A, 'tenant-a-open');
        $this->signal(self::TENANT_B, 'tenant-b-secret');

        // A tool from another module is refused at creation.
        $this->withHeaders($this->auth())->postJson(self::BASE . '/tool-agents', [
            'name' => 'Cross-module', 'module' => 'signals', 'tools_allowed' => ['evidence.by_signal'],
        ])->assertStatus(422);

        $created = $this->withHeaders($this->auth())->postJson(self::BASE . '/tool-agents', [
            'name' => 'Open signal watcher', 'module' => 'signals', 'tools_allowed' => ['signals.open'],
        ]);
        $created->assertStatus(201)->assertJsonPath('data.agent.status', 'draft')->assertJsonPath('data.agent.tenant_id', self::TENANT_A);
        $agent = $created->json('data.agent.id');

        // Tenant B cannot see, change or run tenant A's agent.
        $this->withHeaders($this->auth(self::TENANT_B))->getJson(self::BASE . '/tool-agents')
            ->assertOk()->assertJsonCount(0, 'data.agents');
        $this->withHeaders($this->auth(self::TENANT_B))->patchJson(self::BASE . "/tool-agents/{$agent}", ['status' => 'active'])->assertStatus(404);
        $this->withHeaders($this->auth(self::TENANT_B))->postJson(self::BASE . "/tool-agents/{$agent}/run")->assertStatus(404);

        // A draft agent is denied.
        $this->withHeaders($this->auth())->postJson(self::BASE . "/tool-agents/{$agent}/run")
            ->assertOk()->assertJsonPath('data.run.status', 'denied');

        $this->withHeaders($this->auth())->patchJson(self::BASE . "/tool-agents/{$agent}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.agent.status', 'active');

        // Not on the allow-list.
        $this->withHeaders($this->auth())->postJson(self::BASE . "/tool-agents/{$agent}/run", ['tool' => 'evidence.by_signal'])
            ->assertOk()->assertJsonPath('data.run.status', 'denied');

        $ran = $this->withHeaders($this->auth())->postJson(self::BASE . "/tool-agents/{$agent}/run");
        $ran->assertOk()
            ->assertJsonPath('data.run.status', 'success')
            ->assertJsonPath('data.run.output.total', 1)
            ->assertJsonPath('data.run.input.tool', 'signals.open')
            ->assertJsonPath('data.run.tools_used.0', 'signals.open');
        $this->assertStringNotContainsString('tenant-b-secret', $ran->getContent());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $ran->json('data.run.started_at'));

        // A tampered row whose allow-list names another module's tool is still refused:
        // the server re-checks the module on every run.
        $tampered = Uuid::uuid4()->toString();
        DB::table('hpbrain_ai_tool_agents')->insert([
            'id' => $tampered, 'tenant_id' => self::TENANT_A, 'name' => 'Tampered', 'module' => 'signals',
            'tools_allowed' => json_encode(['evidence.by_signal']), 'status' => 'active',
            'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);
        $this->withHeaders($this->auth())->postJson(self::BASE . "/tool-agents/{$tampered}/run")
            ->assertOk()
            ->assertJsonPath('data.run.status', 'denied')
            ->assertJsonPath('data.run.error', 'Denied: that tool does not belong to this agent\'s module.');

        $this->assertSame(4, DB::table('hpbrain_ai_audit_logs')->where('tenant_id', self::TENANT_A)->where('event_type', 'module.signals.signals_agent_run')->count());

        $this->withHeaders($this->auth())->getJson(self::BASE . '/tool-agent-runs?module=signals')
            ->assertOk()->assertJsonCount(4, 'data.runs');
        $this->withHeaders($this->auth(self::TENANT_B))->getJson(self::BASE . '/tool-agent-runs')
            ->assertOk()->assertJsonCount(0, 'data.runs');

        // Archived agents leave every read.
        $this->withHeaders($this->auth())->patchJson(self::BASE . "/tool-agents/{$agent}", ['status' => 'archived'])->assertOk();
        $this->withHeaders($this->auth())->postJson(self::BASE . "/tool-agents/{$agent}/run")->assertStatus(404);
    }

    /** @test */
    public function activity_shows_only_the_callers_own_rows(): void
    {
        $this->withHeaders($this->auth())->postJson(self::BASE . '/modules/signals/activity', [
            'operation' => 'summary.generated', 'status' => 'completed', 'subject_id' => 'sig-1',
        ])->assertStatus(201)->assertJsonPath('data.recorded', true);

        // A platform audit row and another tenant's row under the same prefix.
        foreach (['*', self::TENANT_B] as $tenant) {
            DB::table('hpbrain_ai_audit_logs')->insert([
                'id' => Uuid::uuid4()->toString(), 'tenant_id' => $tenant, 'event_type' => 'module.signals.other',
                'outcome' => 'success', 'message' => "row of {$tenant}",
                'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
            ]);
        }

        $activity = $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/activity');
        $activity->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.entries.0.operation', 'summary.generated')
            ->assertJsonPath('data.entries.0.subject_id', 'sig-1');
        $this->assertStringNotContainsString('row of', $activity->getContent());

        $this->withHeaders($this->auth(self::TENANT_B))->getJson(self::BASE . '/modules/signals/activity')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.entries.0.operation', 'other');

        // A template id that is not this module's is refused.
        $this->withHeaders($this->auth())->postJson(self::BASE . '/modules/signals/activity', [
            'operation' => 'x', 'status' => 'completed', 'template_id' => Uuid::uuid4()->toString(),
        ])->assertStatus(422);
    }

    /** @test */
    public function reports_are_built_from_the_modules_own_source_and_owned_by_the_tenant(): void
    {
        $this->signal(self::TENANT_A, 'tenant-a-<b>open</b>');
        $this->signal(self::TENANT_B, 'tenant-b-secret');

        $built = $this->withHeaders($this->auth())->postJson(self::BASE . '/workspace/report', ['module' => 'signals']);
        $built->assertStatus(201)
            ->assertJsonPath('data.module', 'signals')
            ->assertJsonPath('data.source_tool', 'signals.open')
            ->assertJsonPath('data.row_count', 1);
        $id = $built->json('data.template_id');

        $report = $this->withHeaders($this->auth())->getJson(self::BASE . "/reports/{$id}");
        $report->assertOk()->assertJsonPath('data.report.figures.source', 'signals.open');
        $html = $report->json('data.report.html');
        // Escaped, never evaluated; and only this tenant's rows.
        $this->assertStringContainsString('tenant-a-&lt;b&gt;open&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('tenant-b-secret', $html);

        $this->withHeaders($this->auth(self::TENANT_B))->getJson(self::BASE . "/reports/{$id}")->assertStatus(404);
        $this->withHeaders($this->auth(self::TENANT_B))->putJson(self::BASE . "/reports/{$id}", ['title' => 'x', 'html' => 'x'])->assertStatus(404);
        $this->withHeaders($this->auth(self::TENANT_B))->postJson(self::BASE . "/reports/{$id}/regenerate")->assertStatus(404);

        $this->signal(self::TENANT_A, 'tenant-a-second');
        $this->withHeaders($this->auth())->postJson(self::BASE . "/reports/{$id}/regenerate")
            ->assertOk()->assertJsonPath('data.row_count', 2);

        // A layout bound to another module's source is refused.
        $layout = Uuid::uuid4()->toString();
        DB::table('hpbrain_ai_templates')->insert([
            'id' => $layout, 'tenant_id' => self::TENANT_A, 'template_key' => 'signals.cross', 'name' => 'Cross',
            'module_key' => 'signals', 'kind' => 'report', 'status' => 'published', 'user_prompt' => '',
            'html_layout' => '<<rows_table>>', 'data_source' => 'evidence.by_signal',
            'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);
        $this->withHeaders($this->auth())->postJson(self::BASE . '/workspace/report', ['module' => 'signals', 'template_id' => $layout])
            ->assertStatus(422);

        $this->withHeaders($this->auth())->postJson(self::BASE . '/workspace/report', ['module' => 'no_such_module'])->assertStatus(422);
    }

    /** @test */
    public function a_module_model_binding_applies_only_when_the_product_module_is_named(): void
    {
        $rows = $this->withHeaders($this->auth())->getJson(self::BASE . '/modules/signals/models')
            ->assertOk()
            ->json('data.rows');
        $this->assertSame(['conversational_ai', 'signal_intelligence'], array_column($rows, 'capability'));

        // A capability the module does not use is refused.
        $this->withHeaders($this->auth())->putJson(self::BASE . '/modules/signals/models', [
            'capability' => 'knowledge_ai', 'provider' => 'anthropic',
        ])->assertStatus(422);

        $this->withHeaders($this->auth())->putJson(self::BASE . '/modules/signals/models', [
            'capability' => 'signal_intelligence', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash',
        ])->assertOk()
            ->assertJsonPath('data.binding.scope', 'institute')
            ->assertJsonPath('data.effective.provider', 'gemini')
            ->assertJsonPath('data.effective.source', 'module_binding');

        $resolver = app(AiConfigurationResolver::class);
        $this->assertSame('module_binding', $resolver->resolve('signal_intelligence', self::TENANT_A, 'signals')->source);
        // Without the product module, resolution is exactly what it was.
        $this->assertNotSame('module_binding', $resolver->resolve('signal_intelligence', self::TENANT_A)->source);
        $this->assertSame('anthropic', $resolver->resolve('signal_intelligence', self::TENANT_A)->provider);
        // Another tenant is unaffected.
        $this->assertNotSame('module_binding', $resolver->resolve('signal_intelligence', self::TENANT_B, 'signals')->source);

        // Another tenant's credential cannot be bound.
        $foreign = Uuid::uuid4()->toString();
        DB::table('hpbrain_ai_api_keys')->insert([
            'id' => $foreign, 'tenant_id' => self::TENANT_B, 'ai_module' => 'signal_intelligence', 'api_type' => 'gemini',
            'api_key' => 'sealed', 'status' => 1, 'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);
        $this->withHeaders($this->auth())->putJson(self::BASE . '/modules/signals/models', [
            'capability' => 'signal_intelligence', 'provider' => 'gemini', 'api_key_id' => $foreign,
        ])->assertStatus(422);
        $this->withHeaders($this->auth())->putJson(self::BASE . "/modules/signals/models/credentials/{$foreign}", ['status' => 0])
            ->assertStatus(404);

        $this->withHeaders($this->auth())->deleteJson(self::BASE . '/modules/signals/models?capability=signal_intelligence')
            ->assertOk()->assertJsonPath('data.cleared', true);
        $this->assertNotSame('module_binding', app(AiConfigurationResolver::class)->resolve('signal_intelligence', self::TENANT_A, 'signals')->source);
    }
}
