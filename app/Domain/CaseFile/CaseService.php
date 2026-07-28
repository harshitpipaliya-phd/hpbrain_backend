<?php

declare(strict_types=1);

namespace App\Domain\CaseFile;

use InvalidArgumentException;

/**
 * Case Engine. Ported from api/src/case/case.service.ts.
 *
 * "A signal alone is not actionable" — the investigative thread that carries a
 * signal to a confirmed root cause. The transition table is the guard: a case
 * may fall back from hypothesized to investigating when a hypothesis is
 * rejected, and may only resolve with a confirmed hypothesis attached.
 */
final class CaseService
{
    /** @var array<string, array<int, string>> */
    private array $transitions;

    public function __construct(?array $transitions = null)
    {
        $this->transitions = $transitions ?? config('brain.case_transitions');
    }

    public function assertTransition(string $from, string $to, ?string $resolvedHypothesisId = null): void
    {
        if (! in_array($to, $this->transitions[$from] ?? [], true)) {
            throw new InvalidArgumentException("invalid_transition: {$from} -> {$to}");
        }

        if ($to === 'resolved' && ($resolvedHypothesisId === null || $resolvedHypothesisId === '')) {
            throw new InvalidArgumentException('resolved_requires_hypothesis');
        }
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, $this->transitions[$from] ?? [], true);
    }
}
