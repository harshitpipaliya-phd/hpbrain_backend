<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AiWorkspaceService;
use App\Services\EvaluationService;
use App\Services\AiFeedbackService;
use App\Services\AiAuditService;
use App\Services\SafetyService;
use App\Services\TokenCostAccountingService;
use App\Services\AiQuotaEnforcer;
use App\Domain\Ai\ModelCapabilityRegistry;
use App\Domain\Ai\PromptRegistry;
// These five live in App\Services, not App\Domain\Ai. Only ContextAssembly-
// Service was actually resolved by a case here, so the other four imports were
// wrong but silent.
use App\Services\ContextAssemblyService;
use App\Services\RagService;
use App\Services\RetrievalService;
use App\Services\RerankService;
use App\Services\GroundingService;
use App\Services\QuotaService;
use App\Services\AiCacheService;
use App\Services\AiProviderRegistry;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class AiFallbackTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-fallback';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-fallback', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function provider_registry_returns_fallback_chain(): void
    {
        $chain = \App\Services\AiProviderRegistry::getFallbackChain();

        $this->assertIsArray($chain);
    }

    /** @test */
    public function safety_service_passes_clean_content(): void
    {
        $service = app(SafetyService::class);
        $result = $service->filter('Safe content here');

        $this->assertTrue($result->safe);
    }

    /** @test */
    public function context_assembly_service_assembles_context(): void
    {
        $service = app(ContextAssemblyService::class);
        $context = $service->assemble(self::TENANT, 'What is the status?', [
            ['type' => 'signal', 'content' => 'Signal content', 'id' => 'sig-1'],
        ]);

        $this->assertStringContainsString('Signal content', $context->userPrompt);
        $this->assertStringContainsString('What is the status?', $context->userPrompt);
    }
}
