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
 * The flywheel now has both halves. ProcessLoopEvents derives a Learning
 * idempotently from OutcomeRecorded, and MemoryGrounding feeds reusable
 * learnings back into the implemented verbs. This class keeps the small policy
 * decision those writers share: which outcomes are safe to offer as reusable
 * organizational knowledge.
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
