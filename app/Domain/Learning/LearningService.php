<?php

declare(strict_types=1);

namespace App\Domain\Learning;

/**
 * Learning Engine. Ported from api/src/learning/learning.service.ts.
 *
 * The reusability gate is the point of this class. A successful outcome at
 * reasonable confidence becomes reusable organizational knowledge. A failed or
 * low-confidence outcome is still RECORDED — the loop must learn from failure
 * too — but is not marked reusable, so it is never surfaced as a pattern to
 * repeat.
 *
 * Known gap carried over from the Node build, deliberately not papered over
 * here: ADR-005 requires an idempotent handler that derives a Learning
 * automatically on OutcomeRecorded, and requires reasoning to ground on prior
 * Learnings. Neither exists yet in either implementation. Until they do, the
 * flywheel does not turn.
 */
final class LearningService
{
    public function __construct(
        private readonly float $reusableConfidenceFloor = 0.50,
    ) {
    }

    public function isReusable(string $outcomeResult, float $confidence): bool
    {
        return $outcomeResult === 'success' && $confidence >= $this->reusableConfidenceFloor;
    }
}
