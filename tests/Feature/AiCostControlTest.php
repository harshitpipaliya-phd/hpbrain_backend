<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\ModelCapabilityRegistry;
use App\Services\AiQuotaEnforcer;
use App\Services\SafetyService;
use App\Services\TokenCostAccountingService;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class AiCostControlTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-cost';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-cost', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function token_cost_accounting_records_usage(): void
    {
        $service = app(TokenCostAccountingService::class);
        $service->record(self::TENANT, 'user-1', 'gpt-4', 100, 50, 'Signal', 'signal-1');

        $cost = $service->getMonthlyCost(self::TENANT, 'org-1');

        $this->assertArrayHasKey('totalCost', $cost);
    }

    /** @test */
    public function quota_enforcer_blocks_over_limit(): void
    {
        \Illuminate\Support\Facades\DB::table('hpbrain_ai_quotas')->insert([
            'id' => 'quota-1', 'tenant_id' => self::TENANT, 'quota_type' => 'feature',
            'quota_key' => 'ai.chat', 'limit_value' => 100, 'current_usage' => 100,
            'reset_period' => 'monthly', 'is_active' => true, 'created_by' => 'test',
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);

        $enforcer = app(AiQuotaEnforcer::class);
        $result = $enforcer->checkBeforeCall(self::TENANT, 'user-1', 'ai.chat', 10);

        $this->assertFalse($result->allowed);
    }
}
