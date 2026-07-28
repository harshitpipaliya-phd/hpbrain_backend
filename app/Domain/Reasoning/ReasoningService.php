<?php

declare(strict_types=1);

namespace App\Domain\Reasoning;

use App\Domain\Evidence\EvidenceService;
use DateTimeImmutable;

/**
 * Reasoning Engine.
 *
 * Ported from api/src/reasoning/reasoning.service.ts.
 *
 * The product rule this class exists to enforce, stated in the Product Bible:
 * **confidence is computed, never asserted.** A caller cannot hand us a
 * confidence score. It is derived from how much evidence corroborates the
 * signal, and how fresh that evidence is.
 *
 *   confidence = base + Σ( evidence.confidence × freshness × weight )
 *
 * capped at a ceiling below 1.0, because reasoning is never fully certain —
 * that is what Outcome capture is for.
 *
 * A note for whoever maintains this: the MySQL column backing this value must
 * state explicit decimal precision. A bare NUMERIC becomes DECIMAL(10,0) in
 * MySQL and rounds every score here to a whole number, which silently
 * destroys the entire model. That bug was live in this project and is why
 * config/brain.php documents the precision requirement.
 */
final class ReasoningService
{
    public function __construct(
        private readonly EvidenceService $evidence,
        private readonly float $baseConfidence = 0.30,
        private readonly float $evidenceWeight = 0.15,
        private readonly float $ceiling = 0.95,
    ) {
    }

    /**
     * $now is injectable for the same reason EvidenceService::computeFreshness
     * takes it: freshness is measured against a clock, so without a fixed
     * reference this function is not a pure function of its input. Leaving it
     * to `new DateTimeImmutable()` made every confidence score drift with the
     * wall clock and made the equivalence tests decay a point per day.
     *
     * @param  array<int, array{confidence: float, observedDate: string}>  $evidence
     */
    public function computeConfidence(array $evidence, ?DateTimeImmutable $now = null): float
    {
        $corroboration = 0.0;

        foreach ($evidence as $item) {
            $freshness = $this->evidence->computeFreshness($item['observedDate'], $now);
            $corroboration += $item['confidence'] * $freshness * $this->evidenceWeight;
        }

        return round(min($this->ceiling, $this->baseConfidence + $corroboration), 2);
    }
}
