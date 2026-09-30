<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * Paid AI is an explicit, authorised act — never a side effect of looking.
 *
 * A provider is configured and every outbound HTTP request is faked, so the
 * assertion is on the thing that costs money: whether a request LEFT the
 * application. Before this policy a Viewer could open an organization-intelligence
 * page, or POST /ai/evidence/summarize, and spend a model call; `?fresh=1` bypassed
 * the cache and could repeat it on every request.
 */
final class PaidAiAuthorizationTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-paid-a';

    private const OTHER = 'tenant-paid-b';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();

        config([
            'brain.ai.provider' => 'anthropic',
            'brain.ai.model' => 'claude-sonnet-5',
            'brain.ai.anthropic.api_key' => 'sk-ant-test-0000000000000000',
        ]);

        Cache::store('file')->flush();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"executive_summary":"Interpretation text.","summary":"Evidence summary.","evidenceRefs":[]}']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'stop_reason' => 'end_turn',
                'model' => 'claude-sonnet-5',
            ]),
        ]);
    }

    private function as(string $role, string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer ' . Jwt::issueAccess(['id' => "user-{$role}", 'tenantId' => $tenant, 'role' => $role])];
    }

    /** @return array{0: string, 1: string} signal id, evidence id */
    private function signalWithEvidence(string $tenant = self::TENANT): array
    {
        $signal = Uuid::uuid4()->toString();
        $evidence = Uuid::uuid4()->toString();

        DB::table('hpbrain_signals')->insert([
            'id' => $signal, 'tenant_id' => $tenant, 'source' => 'test', 'classification' => 'fee_risk',
            'severity' => 'medium', 'status' => 'new', 'created_by' => 'test',
            'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);
        DB::table('hpbrain_evidence')->insert([
            'id' => $evidence, 'tenant_id' => $tenant, 'signal_id' => $signal, 'source' => 'test',
            'content' => 'Arrears rose 12% in Grade 9.', 'provenance' => '{}', 'hash' => sha1('x'),
            'created_by' => 'test', 'created_date' => '2026-09-01 00:00:00',
        ]);

        return [$signal, $evidence];
    }

    /**
     * @test
     * @dataProvider everyRole
     */
    public function reading_organization_intelligence_never_reaches_a_provider(string $role): void
    {
        foreach (['state', 'knowledge', 'decisions', 'recommendations', 'risks', 'gaps'] as $screen) {
            // fresh=1 must not turn a read into a paid call either.
            foreach (['', '?fresh=1'] as $query) {
                $response = $this->withHeaders($this->as($role))
                    ->getJson("/api/v1/organization-intelligence/" . self::TENANT . "/{$screen}{$query}");
                $response->assertOk();

                if ($response->json('interpretation') !== null) {
                    $this->assertSame('unavailable', $response->json('interpretation.status'));
                    $this->assertSame('interpretation_not_generated', $response->json('interpretation.reason'));
                }
            }
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('hpbrain_ai_executions')->count());
    }

    /** @return array<string, array{string}> */
    public static function everyRole(): array
    {
        return ['viewer' => ['viewer'], 'analyst' => ['analyst'], 'manager' => ['manager'], 'tenant_admin' => ['tenant_admin']];
    }

    /** @test */
    public function a_viewer_cannot_request_an_interpretation(): void
    {
        $this->withHeaders($this->as('viewer'))
            ->postJson('/api/v1/organization-intelligence/' . self::TENANT . '/interpretation')
            ->assertStatus(403)
            ->assertJsonMissingPath('interpretation');

        $this->withHeaders($this->as('viewer'))
            ->postJson('/api/v1/organization-intelligence/' . self::TENANT . '/interpretation?fresh=1')
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    /** @test */
    public function an_authorised_role_can_request_one_and_a_retry_does_not_buy_a_second(): void
    {
        $url = '/api/v1/organization-intelligence/' . self::TENANT . '/interpretation';

        $first = $this->withHeaders($this->as('analyst'))->postJson($url)->assertOk();
        $this->assertSame('available', $first->json('interpretation.status'));
        Http::assertSentCount(1);

        // A double click or a retry: served from the per-data-version cache.
        $this->withHeaders($this->as('analyst'))->postJson($url)->assertOk()
            ->assertJsonPath('interpretation.status', 'available');
        Http::assertSentCount(1);

        // The generated interpretation is then visible to a read-only role, at no cost.
        $this->withHeaders($this->as('viewer'))
            ->getJson('/api/v1/organization-intelligence/' . self::TENANT . '/decisions')
            ->assertOk()
            ->assertJsonPath('interpretation.status', 'available');
        Http::assertSentCount(1);

        // Deliberate regeneration is the only thing that spends again.
        $this->withHeaders($this->as('analyst'))->postJson($url . '?fresh=1')->assertOk();
        Http::assertSentCount(2);
    }

    /** @test */
    public function an_interpretation_is_tenant_scoped(): void
    {
        $this->withHeaders($this->as('analyst'))
            ->postJson('/api/v1/organization-intelligence/' . self::TENANT . '/interpretation')->assertOk();

        // Another tenant neither sees it nor can address this tenant's route.
        $this->withHeaders($this->as('viewer', self::OTHER))
            ->getJson('/api/v1/organization-intelligence/' . self::OTHER . '/decisions')
            ->assertOk()
            ->assertJsonPath('interpretation.reason', 'interpretation_not_generated');

        $this->withHeaders($this->as('analyst', self::OTHER))
            ->postJson('/api/v1/organization-intelligence/' . self::TENANT . '/interpretation')
            ->assertStatus(403)
            ->assertJsonPath('error', 'tenant_mismatch');

        Http::assertSentCount(1);
    }

    /** @test */
    public function a_viewer_cannot_summarise_evidence_with_a_provider(): void
    {
        [$signal] = $this->signalWithEvidence();

        $this->withHeaders($this->as('viewer'))
            ->postJson('/api/v1/ai/evidence/summarize', ['signalId' => $signal])
            ->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('hpbrain_ai_executions')->count());
    }

    /** @test */
    public function an_authorised_summary_is_one_provider_call_however_often_it_is_retried(): void
    {
        [$signal, $evidence] = $this->signalWithEvidence();

        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders($this->as('analyst'))
                ->postJson('/api/v1/ai/evidence/summarize', ['signalId' => $signal])
                ->assertOk()
                ->assertJsonPath('state', 'DECIDED');
        }

        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('hpbrain_ai_executions')->where('tenant_id', self::TENANT)->count());
        $this->assertNotEmpty($evidence);
    }

    /** @test */
    public function summarising_another_tenants_signal_finds_no_evidence_and_spends_nothing(): void
    {
        [$signal] = $this->signalWithEvidence(self::OTHER);

        $this->withHeaders($this->as('analyst'))
            ->postJson('/api/v1/ai/evidence/summarize', ['signalId' => $signal])
            ->assertOk()
            ->assertJsonPath('state', 'UNDETERMINED');

        Http::assertNothingSent();
    }

    /** @test */
    public function a_viewer_cannot_write_or_regenerate_in_the_ai_workspace(): void
    {
        $session = Uuid::uuid4()->toString();
        $message = Uuid::uuid4()->toString();
        $viewer = $this->as('viewer');

        $this->withHeaders($viewer)->postJson("/api/v1/ai/workspace/sessions/{$session}/messages", ['content' => 'hi'])->assertStatus(403);
        $this->withHeaders($viewer)->postJson("/api/v1/ai/workspace/sessions/{$session}/messages/{$message}/regenerate")->assertStatus(403);
        $this->withHeaders($viewer)->postJson("/api/v1/ai/workspace/sessions/{$session}/messages/{$message}/explain")->assertStatus(403);

        Http::assertNothingSent();
    }

    /** @test */
    public function the_conversation_and_generation_verbs_stay_closed_to_a_viewer(): void
    {
        $viewer = $this->as('viewer');
        $id = Uuid::uuid4()->toString();

        $this->withHeaders($viewer)->postJson('/api/v1/conversations/sessions', ['tenantId' => self::TENANT, 'title' => 'x'])->assertStatus(403);
        $this->withHeaders($viewer)->postJson("/api/v1/conversations/sessions/" . self::TENANT . "/{$id}/messages", ['content' => 'why?'])->assertStatus(403);
        $this->withHeaders($viewer)->postJson('/api/v1/reasoning-engine/' . self::TENANT . '/recommend', ['signalId' => $id])->assertStatus(403);
        $this->withHeaders($viewer)->postJson('/api/v1/reasoning-engine/' . self::TENANT . '/evaluate', ['signalId' => $id])->assertStatus(403);

        Http::assertNothingSent();
    }

    /** @test */
    public function explain_and_assess_are_read_verbs_that_make_no_provider_call(): void
    {
        [$signal] = $this->signalWithEvidence();

        foreach (['explain', 'assess'] as $verb) {
            $status = $this->withHeaders($this->as('viewer'))
                ->postJson('/api/v1/reasoning-engine/' . self::TENANT . "/{$verb}", ['signalId' => $signal, 'capabilityId' => $signal])
                ->getStatusCode();

            // Reachable by a Viewer (they are questions, not spend) — and still no provider call.
            $this->assertNotSame(403, $status, "{$verb} should stay a read verb");
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('hpbrain_ai_executions')->count());
    }
}
