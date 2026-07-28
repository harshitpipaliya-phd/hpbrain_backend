<?php

declare(strict_types=1);

namespace App\Domain\Undetermined;

use JsonSerializable;

/**
 * UNDETERMINED as a first-class result type.
 *
 * Architecture Invariant: "The Brain never hides uncertainty. UNDETERMINED is a
 * valid, visible state." ADR-004 goes further — it is a RETURN TYPE, not an
 * exception and not an empty string, and downstream code must branch on it
 * explicitly.
 *
 * This existed in neither the TypeScript nor the first Laravel build: the token
 * `UNDETERMINED` appeared zero times in either codebase, which meant the system
 * was structurally incapable of saying "I don't know". Code that fabricates
 * confidence — a default score, a silent fallback, an invented root cause — is
 * a defect, not a convenience.
 *
 * The gaps carried on an undetermined result are the point: they name the
 * evidence that WOULD resolve the question, which is what makes the honest
 * answer actionable rather than merely humble.
 */
final class VerbResult implements JsonSerializable
{
    private function __construct(
        public readonly string $state,
        public readonly mixed $value,
        public readonly array $gaps,
        public readonly array $evidenceRefs,
        public readonly ?float $confidence,
    ) {
    }

    public static function decided(mixed $value, array $evidenceRefs = [], ?float $confidence = null): self
    {
        return new self('DECIDED', $value, [], $evidenceRefs, $confidence);
    }

    /**
     * @param  array<int, string>  $gaps  What evidence is missing. Must not be empty —
     *                                    "undetermined for unstated reasons" is not honesty.
     */
    public static function undetermined(array $gaps, array $evidenceRefs = []): self
    {
        if ($gaps === []) {
            $gaps = ['unspecified_evidence_gap'];
        }

        return new self('UNDETERMINED', null, array_values($gaps), $evidenceRefs, null);
    }

    public function isUndetermined(): bool
    {
        return $this->state === 'UNDETERMINED';
    }

    /**
     * HTTP 200, deliberately. An undetermined answer is a SUCCESSFUL response
     * carrying an honest result — not a client error and not a server error.
     * Returning 4xx/5xx here would train callers to treat honesty as failure.
     */
    public function jsonSerialize(): array
    {
        return $this->isUndetermined()
            ? ['state' => 'UNDETERMINED', 'gaps' => $this->gaps, 'evidenceRefs' => $this->evidenceRefs]
            : ['state' => 'DECIDED', 'value' => $this->value,
               'confidence' => $this->confidence, 'evidenceRefs' => $this->evidenceRefs];
    }
}
