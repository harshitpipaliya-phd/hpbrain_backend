<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AiWorkspaceService;
use App\Services\QuotaService;
use App\Services\SafetyService;
use App\Services\TokenCostAccountingService;
use App\Services\EvaluationService;
use App\Services\AiFeedbackService;
use App\Services\AiAuditService;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class AiQuotaTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-quota';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-quota', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function quota_service_checks_limits(): void
    {
        $quotaService = app(QuotaService::class);
        $result = $quotaService->check(self::TENANT, 'user-1', 'ai.chat');

        $this->assertTrue($result->allowed);
    }

    /** @test */
    public function quota_service_records_usage(): void
    {
        $quotaService = app(QuotaService::class);
        $quotaService->recordUsage(self::TENANT, 'user-1', 'ai.chat', 100, 50);

        $result = $quotaService->check(self::TENANT, 'user-1', 'ai.chat');
        $this->assertTrue($result->used >= 100);
    }
}
