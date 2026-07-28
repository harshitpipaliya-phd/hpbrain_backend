<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

/**
 * Recommendation Engine. Ported from
 * api/src/recommendation/recommendation.service.ts.
 *
 * Two rules here are product behaviour, not implementation detail:
 *
 * 1. CONFIDENCE IS INHERITED, NEVER SUPPLIED. A recommendation takes the
 *    confidence of the ReasoningStep that produced it. Below the low-confidence
 *    floor the category is FORCED to 'watch' regardless of what the caller
 *    asked for — uncorroborated intelligence must never present as a firm
 *    claim.
 *
 * 2. URGENCY IS DERIVED, NOT SUPPLIED. An unresolved compliance risk at
 *    moderate confidence is more urgent than a territory expansion at high
 *    confidence; the caller does not get to assert otherwise.
 */
final class RecommendationService
{
    public function __construct(
        private readonly float $lowConfidenceFloor = 0.40,
    ) {
    }

    public function resolveCategory(string $requested, float $confidence): string
    {
        return $confidence < $this->lowConfidenceFloor ? 'watch' : $requested;
    }

    public function derivePriority(float $confidence): string
    {
        return match (true) {
            $confidence >= 0.70 => 'high',
            $confidence >= 0.40 => 'medium',
            default             => 'low',
        };
    }

    public function deriveUrgency(string $category, float $confidence): string
    {
        if ($category === 'compliance' && $confidence >= 0.60) {
            return 'immediate';
        }

        if ($category === 'risk' && $confidence >= 0.70) {
            return 'high';
        }

        if ($category === 'watch') {
            return 'low';
        }

        return $confidence >= 0.70 ? 'high' : 'normal';
    }
}
