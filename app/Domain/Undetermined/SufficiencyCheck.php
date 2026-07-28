<?php

declare(strict_types=1);

namespace App\Domain\Undetermined;

/**
 * The UODM sufficiency gate.
 *
 * A case may only produce a decision when all seven framing questions are
 * answered. If any is unanswered the honest return is UNDETERMINED naming the
 * unanswered ones — never a decision at reduced confidence.
 */
final class SufficiencyCheck
{
    public const QUESTIONS = [
        'what_changed',
        'who_is_affected',
        'when_did_it_start',
        'how_large_is_the_gap',
        'what_evidence_supports_it',
        'what_would_falsify_it',
        'what_is_the_root_cause_family',
    ];

    /** @return array<int, string> the unanswered questions */
    public function unanswered(array $caseFrame): array
    {
        $missing = [];

        foreach (self::QUESTIONS as $q) {
            $answer = $caseFrame[$q] ?? null;

            if ($answer === null || $answer === '' || $answer === []) {
                $missing[] = $q;
            }
        }

        return $missing;
    }

    /**
     * Grounding must exist too. A verb with no grounding evidence returns
     * UNDETERMINED rather than generating from nothing (ADR-004: "ungrounded
     * generation is prohibited").
     */
    public function evaluate(array $caseFrame, array $groundingEvidence): VerbResult
    {
        if ($groundingEvidence === []) {
            return VerbResult::undetermined(['no_grounding_evidence']);
        }

        $missing = $this->unanswered($caseFrame);

        if ($missing !== []) {
            return VerbResult::undetermined($missing, array_column($groundingEvidence, 'id'));
        }

        return VerbResult::decided(true, array_column($groundingEvidence, 'id'));
    }
}
