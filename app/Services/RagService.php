<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\AiResponse;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AssembledContext;
use App\Domain\Ai\RetrievalResult;
use App\Domain\Ai\GroundedResponse;

final class RagService
{
    public function __construct(
        private readonly RetrievalService $retrieval,
        private readonly RerankService $rerank,
        private readonly GroundingService $grounding,
        private readonly ContextAssemblyService $contextAssembly,
        private readonly SafetyService $safety,
        private readonly QuotaService $quota,
        private readonly AiQuotaEnforcer $quotaEnforcer,
    ) {
    }

    public function retrieve(string $tenantId, string $query, array $options = []): RetrievalResult
    {
        $entityTypes = $options['entity_types'] ?? ['signal', 'evidence', 'decision', 'capability'];
        $documents = $this->retrieval->searchEntities($tenantId, $query, $entityTypes);

        if (!empty($options['rerank'])) {
            $documents = $this->rerank->rerank($tenantId, $query, $documents, $options['model'] ?? '');
        }

        return new RetrievalResult(
            documents: $documents,
            total: count($documents),
            sources: array_unique(array_column($documents, 'type')),
        );
    }

    public function permissionFilter(string $tenantId, array $documents, array $userPermissions): array
    {
        return array_values(array_filter($documents, function (array $doc) use ($tenantId, $userPermissions) {
            $docTenant = $doc['tenant_id'] ?? null;

            return $docTenant === $tenantId || in_array('admin', $userPermissions, true);
        }));
    }

    public function packageContext(array $documents, array $options): AssembledContext
    {
        $query = $options['query'] ?? '';
        $tenantId = $options['tenant_id'] ?? '';

        return $this->contextAssembly->assemble($tenantId, $query, $documents);
    }

    public function ground(string $tenantId, string $response, array $evidence): GroundedResponse
    {
        return $this->grounding->ground($tenantId, $response, $evidence);
    }
}
