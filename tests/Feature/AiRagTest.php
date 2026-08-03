<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\EvaluationService;
use App\Services\AiFeedbackService;
use App\Services\AiAuditService;
use App\Services\TokenCostAccountingService;
use App\Services\AiQuotaEnforcer;
use App\Services\SafetyService;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class AiRagTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-rag';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-rag', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function rag_service_retrieves_documents(): void
    {
        $ragService = app(\App\Services\RagService::class);
        $result = $ragService->retrieve(self::TENANT, 'test query', []);

        $this->assertInstanceOf(\App\Domain\Ai\RetrievalResult::class, $result);
    }

    /** @test */
    public function rag_service_packages_context(): void
    {
        $ragService = app(\App\Services\RagService::class);
        $context = $ragService->packageContext([
            ['id' => 'doc-1', 'type' => 'signal', 'content' => 'test content'],
        ], ['query' => 'test', 'tenant_id' => self::TENANT]);

        $this->assertInstanceOf(\App\Domain\Ai\AssembledContext::class, $context);
        $this->assertStringContainsString('test content', $context->userPrompt);
    }

    /** @test */
    public function rag_service_filters_by_permission(): void
    {
        $ragService = app(\App\Services\RagService::class);
        $documents = [
            ['id' => 'doc-1', 'tenant_id' => self::TENANT, 'content' => 'public'],
            ['id' => 'doc-2', 'tenant_id' => 'other-tenant', 'content' => 'private'],
        ];

        $filtered = $ragService->permissionFilter(self::TENANT, $documents, ['viewer']);

        $this->assertCount(1, $filtered);
    }
}
