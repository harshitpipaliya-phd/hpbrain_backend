<?php

declare(strict_types=1);

namespace App\Domain\Scoring;

/**
 * ONE THRESHOLD-TO-LABEL RULE, READ BY DEPARTMENT AND PERSON SCORING ALIKE.
 *
 * Both DepartmentProfile and PersonIntelligenceService turn a 0-100 score into
 * a qualitative band by walking an ordered list of (minimum score, label)
 * pairs and returning the first one the score clears, falling back to a floor
 * label when it clears none of them. That walk was written twice, with two
 * different threshold sets read from two different places in config/scoring.php
 * — this is the one implementation of the walk itself. The THRESHOLDS stay
 * wherever each caller already reads them from; nothing here decides what
 * "healthy" or "steady" means, or where the line is.
 */
final class Banding
{
    /**
     * @param  array<int, array{0: float, 1: string}>  $thresholds  Ordered
     *         highest-first: [minimum score to qualify, label].
     */
    public static function classify(float $score, array $thresholds, string $floor): string
    {
        foreach ($thresholds as [$minimum, $label]) {
            if ($score >= $minimum) {
                return $label;
            }
        }

        return $floor;
    }
}
