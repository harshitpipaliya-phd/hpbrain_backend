<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\AssembledContext;
use App\Domain\Ai\GroundedResponse;
use App\Domain\Ai\RetrievalResult;

final class GroundingService
{
    public function ground(string $tenantId, string $response, array $evidence): GroundedResponse
    {
        $citations = [];
        $claims = [];

        foreach ($evidence as $item) {
            if (!empty($item['id'])) {
                $citations[] = ['id' => $item['id'], 'type' => $item['type'] ?? 'unknown'];
            }
        }

        return new GroundedResponse(
            content: $response,
            claims: $claims,
            gaps: [],
            citations: $citations,
        );
    }

    public function verifyCitations(string $response, array $evidence): \App\Domain\Ai\CitationVerificationResult
    {
        $verified = [];
        $unverified = [];

        foreach ($evidence as $item) {
            if (!empty($item['id']) && str_contains($response, (string) $item['id'])) {
                $verified[] = $item['id'];
            } else {
                $unverified[] = $item['id'] ?? 'unknown';
            }
        }

        return new \App\Domain\Ai\CitationVerificationResult(
            valid: $unverified === [],
            verified: $verified,
            unverified: $unverified,
        );
    }

    public function attachEvidence(string $response, array $evidenceIds): string
    {
        return $response . "\n\n[SOURCES: " . implode(', ', $evidenceIds) . "]";
    }
}
