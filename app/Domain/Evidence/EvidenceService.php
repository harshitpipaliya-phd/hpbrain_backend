<?php

declare(strict_types=1);

namespace App\Domain\Evidence;

use DateTimeImmutable;

/**
 * Evidence Engine.
 *
 * Ported from api/src/evidence/evidence.service.ts. The decay model is the
 * load-bearing part: evidence does not expire, it attenuates. Freshness is an
 * exponential half-life, not a set of day bands — at H days old a piece of
 * evidence corroborates exactly half as strongly as it did when observed.
 *
 * This matters because ReasoningService multiplies each piece of evidence by
 * its freshness before it contributes to confidence. Replacing the curve with
 * buckets would change every confidence score in the system.
 */
final class EvidenceService
{
    public function __construct(
        private readonly int $halfLifeDays = 90,
    ) {
    }

    /**
     * freshness = 0.5 ^ (ageDays / halfLife), clamped so future-dated
     * observations cannot score above 1.0.
     */
    public function computeFreshness(string $observedDate, ?DateTimeImmutable $now = null): float
    {
        $now ??= new DateTimeImmutable();
        $observed = new DateTimeImmutable($observedDate);

        $ageSeconds = $now->getTimestamp() - $observed->getTimestamp();
        $ageDays = max(0.0, $ageSeconds / 86400);

        return 0.5 ** ($ageDays / $this->halfLifeDays);
    }

    /**
     * Human-readable band for the UI. Derived from the same curve, never
     * stored — storing a label would let it drift from the number that
     * actually drives reasoning.
     */
    public function freshnessLabel(float $freshness): string
    {
        return match (true) {
            $freshness >= 0.90 => 'Fresh',
            $freshness >= 0.70 => 'Recent',
            $freshness >= 0.40 => 'Aging',
            default            => 'Stale',
        };
    }
}
