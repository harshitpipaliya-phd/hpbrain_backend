<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiQuotaExceededException;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * Regression: AiQuotaEnforcer/QuotaService had a tenant-scoped quota check
 * fully built, unit-tested in isolation, and called by exactly zero
 * production code paths — every one of the six classes that called
 * AiGateway::complete() (RecommendVerb, EvaluateVerb, CoachVerb,
 * AiController, ExecutiveIntelligenceInterpreter, DatasetAnalysisService)
 * spent tokens with no cap ever consulted. AiGateway::complete() is the one
 * place every caller already goes through for the audit write; wiring the
 * check there closes the gap for all of them at once, past and future,
 * without touching six separate files.
 */
final class AiGatewayQuotaTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-quota';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        config(['brain.ai.provider' => 'test-provider']);
    }

    private function bindProvider(): AiProvider
    {
        $provider = new class implements AiProvider
        {
            public int $calls = 0;

            public function complete(AiRequest $request): AiResponse
            {
                $this->calls++;

                return new AiResponse(content: '{}', model: 'test-model', inputTokens: 100, outputTokens: 50, latencyMs: 5);
            }
        };

        $this->app->instance(AiProvider::class, $provider);

        return $provider;
    }

    private function seedQuota(string $feature, int $limit, int $used): void
    {
        DB::table('hpbrain_ai_quotas')->insert([
            'id' => 'quota-'.$feature, 'tenant_id' => self::TENANT, 'quota_type' => 'feature',
            'quota_key' => $feature, 'limit_value' => $limit, 'current_usage' => $used,
            'reset_period' => 'monthly', 'is_active' => true, 'created_by' => 'test',
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);
    }

    public function test_an_exhausted_quota_blocks_the_call_before_the_provider_is_ever_reached(): void
    {
        $provider = $this->bindProvider();
        $this->seedQuota('signal-reasoner', limit: 100, used: 100);

        $gateway = app(AiGateway::class);

        try {
            $gateway->complete(new AiRequest(model: 'test-model', systemPrompt: 's', userPrompt: 'u'), self::TENANT, 'user-1', 'signal-reasoner');
            self::fail('AiQuotaExceededException was not thrown.');
        } catch (AiQuotaExceededException $e) {
            self::assertSame(self::TENANT, $e->tenantId);
            self::assertSame('signal-reasoner', $e->feature);
        }

        self::assertSame(0, $provider->calls, 'The provider must never be called once the quota check refuses the request.');

        $execution = DB::table('hpbrain_ai_executions')->where('tenant_id', self::TENANT)->first();
        self::assertNotNull($execution, 'A refused call must still be traceable (INVARIANT 7).');
        self::assertSame('quota_exceeded', $execution->status);
        self::assertNotEmpty($execution->error);
    }

    public function test_a_call_within_quota_reaches_the_provider_and_advances_usage(): void
    {
        $provider = $this->bindProvider();
        $this->seedQuota('signal-reasoner', limit: 1000, used: 0);

        $gateway = app(AiGateway::class);
        $gateway->complete(new AiRequest(model: 'test-model', systemPrompt: 's', userPrompt: 'u'), self::TENANT, 'user-1', 'signal-reasoner');

        self::assertSame(1, $provider->calls);

        $quota = DB::table('hpbrain_ai_quotas')->where('tenant_id', self::TENANT)->where('quota_key', 'signal-reasoner')->first();
        self::assertSame(150, $quota->current_usage, '100 input + 50 output tokens must be added to the running total.');

        $execution = DB::table('hpbrain_ai_executions')->where('tenant_id', self::TENANT)->first();
        self::assertSame('completed', $execution->status);
    }

    public function test_a_feature_with_no_configured_quota_is_metered_not_blocked(): void
    {
        $provider = $this->bindProvider();

        $gateway = app(AiGateway::class);
        $gateway->complete(new AiRequest(model: 'test-model', systemPrompt: 's', userPrompt: 'u'), self::TENANT, 'user-1', 'never-configured-feature');

        self::assertSame(1, $provider->calls, 'An unconfigured feature must not be silently capped at zero.');

        $quota = DB::table('hpbrain_ai_quotas')->where('tenant_id', self::TENANT)->where('quota_key', 'never-configured-feature')->first();
        self::assertNotNull($quota, 'Usage on a previously-unconfigured feature must still be recorded, not lost.');
        self::assertSame(150, $quota->current_usage);
    }

    public function test_a_provider_failure_after_the_quota_check_is_still_recorded_and_rethrown(): void
    {
        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function complete(AiRequest $request): AiResponse
            {
                throw new RuntimeException('upstream 503');
            }
        });

        $this->seedQuota('signal-reasoner', limit: 1000, used: 0);
        $gateway = app(AiGateway::class);

        $this->expectException(RuntimeException::class);

        try {
            $gateway->complete(new AiRequest(model: 'test-model', systemPrompt: 's', userPrompt: 'u'), self::TENANT, 'user-1', 'signal-reasoner');
        } finally {
            // Quota usage must not advance for a call that never produced tokens.
            $quota = DB::table('hpbrain_ai_quotas')->where('tenant_id', self::TENANT)->first();
            self::assertSame(0, $quota->current_usage);
        }
    }

    public function test_quota_is_scoped_per_tenant(): void
    {
        $this->bindProvider();
        $this->seedQuota('signal-reasoner', limit: 10, used: 10);

        DB::table('hpbrain_ai_quotas')->insert([
            'id' => 'quota-other', 'tenant_id' => 'tenant-other', 'quota_type' => 'feature',
            'quota_key' => 'signal-reasoner', 'limit_value' => 1000, 'current_usage' => 0,
            'reset_period' => 'monthly', 'is_active' => true, 'created_by' => 'test',
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);

        $gateway = app(AiGateway::class);

        // tenant-other's healthy quota must not rescue tenant-quota's exhausted one.
        $this->expectException(AiQuotaExceededException::class);
        $gateway->complete(new AiRequest(model: 'test-model', systemPrompt: 's', userPrompt: 'u'), self::TENANT, 'user-1', 'signal-reasoner');
    }
}
