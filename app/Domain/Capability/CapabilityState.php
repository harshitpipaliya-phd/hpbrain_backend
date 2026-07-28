<?php

declare(strict_types=1);

namespace App\Domain\Capability;

use InvalidArgumentException;

/**
 * The six-state capability model (Architecture Invariant 6).
 *
 *   Unknown -> Asserted -> Inferred -> Assessed -> Demonstrated -> Mastered
 *   (Behaviour and Attitude use Observed in place of Demonstrated.)
 *
 * WHY THIS EXISTS SEPARATELY FROM THE 0-5 LEVEL.
 * A level answers "how good is this person". A state answers "how firmly do we
 * know that". They are not interchangeable, and only the second lets the Brain
 * be honest: a self-asserted 4 and an assessment-backed 4 are the same number
 * and completely different claims. Both prior builds stored levels only, which
 * is why the system could present a claim as a fact.
 *
 * TWO RULES, ENFORCED HERE RATHER THAN BY CONVENTION:
 *   1. State advances only on evidence. Every advance carries an evidenceRef.
 *   2. State never regresses or inflates silently. A downgrade is possible but
 *      must be explicit and reasoned — it is not a side effect of a write.
 */
final class CapabilityState
{
    public const UNKNOWN      = 'Unknown';
    public const ASSERTED     = 'Asserted';
    public const INFERRED     = 'Inferred';
    public const ASSESSED     = 'Assessed';
    public const DEMONSTRATED = 'Demonstrated';
    public const OBSERVED     = 'Observed';      // Behaviour / Attitude analogue
    public const MASTERED     = 'Mastered';

    private const RANK = [
        self::UNKNOWN => 0, self::ASSERTED => 1, self::INFERRED => 2,
        self::ASSESSED => 3, self::DEMONSTRATED => 4, self::OBSERVED => 4, self::MASTERED => 5,
    ];

    /** Behaviour and Attitude are observed, not demonstrated. */
    private const OBSERVED_DIMENSIONS = ['behaviour', 'attitude'];

    public static function all(): array
    {
        return array_keys(self::RANK);
    }

    public static function rank(string $state): int
    {
        return self::RANK[$state] ?? throw new InvalidArgumentException("unknown_capability_state: {$state}");
    }

    public static function forDimension(string $state, string $dimension): string
    {
        if ($state === self::DEMONSTRATED && in_array(strtolower($dimension), self::OBSERVED_DIMENSIONS, true)) {
            return self::OBSERVED;
        }

        return $state;
    }

    /**
     * The guarded transition. Returns the new state, or throws.
     *
     * @param  string|null  $evidenceRef  Required for any advance. Provenance is
     *                                    mandatory on every capability write
     *                                    (ADR-003) — an advance without a
     *                                    traceable reason is an assertion
     *                                    dressed as a measurement.
     */
    public static function advance(
        string $from,
        string $to,
        ?string $evidenceRef,
        bool $allowDowngrade = false,
        ?string $downgradeReason = null,
    ): string {
        $fromRank = self::rank($from);
        $toRank   = self::rank($to);

        if ($toRank === $fromRank) {
            return $to;
        }

        if ($toRank > $fromRank) {
            if ($evidenceRef === null || $evidenceRef === '') {
                throw new InvalidArgumentException(
                    "capability_state_advance_requires_evidence: {$from} -> {$to}"
                );
            }

            return $to;
        }

        // Downgrade: possible, but never silent.
        if (! $allowDowngrade || $downgradeReason === null || $downgradeReason === '') {
            throw new InvalidArgumentException(
                "capability_state_regression_requires_explicit_reason: {$from} -> {$to}"
            );
        }

        return $to;
    }

    /**
     * Backward compatibility with the existing 0-5 numeric proficiency data.
     *
     * Existing rows are mapped, never deleted. The mapping is deliberately
     * CONSERVATIVE: a stored level tells us someone recorded a number, not how
     * that number was arrived at, so the highest state it can justify on its own
     * is Assessed. Demonstrated and Mastered require evidence the legacy rows do
     * not carry, and inventing them would be exactly the confidence fabrication
     * this model exists to prevent.
     */
    public static function fromLegacyLevel(?float $level, bool $hasEvidenceRef = false): string
    {
        if ($level === null) {
            return self::UNKNOWN;
        }

        if (! $hasEvidenceRef) {
            // No provenance on the legacy row — it is a claim, not a measurement.
            return $level > 0 ? self::ASSERTED : self::UNKNOWN;
        }

        return match (true) {
            $level <= 0 => self::UNKNOWN,
            $level < 2  => self::INFERRED,
            default     => self::ASSESSED,
        };
    }
}
