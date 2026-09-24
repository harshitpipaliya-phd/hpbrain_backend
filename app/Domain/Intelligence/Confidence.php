<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * Confidence, computed from named components and never asserted.
 *
 * THE PROBLEM THIS SOLVES. Every analytics layer eventually grows a line like
 * `'confidence' => 0.82`. It is indistinguishable in the response from a figure
 * that was derived, it cannot be audited, and it is always slightly flattering.
 * This class makes the flattering version impossible to write: a Confidence has
 * no constructor that takes a number, only components that each have to come
 * from somewhere.
 *
 * WEIGHTS REDISTRIBUTE OVER WHAT EXISTS. A component whose input is missing is
 * added as null and drops out of both the numerator and the denominator, so its
 * weight is shared across the components that could be measured. The rejected
 * alternative — scoring a missing component 0 — reports "we looked and found
 * nothing" when the truth is "we have never looked", and those two support
 * opposite decisions. The count of dropped components ships alongside the score
 * so a reader can see how much of the intended basis was actually available.
 *
 * A Confidence with NO measurable component at all is `null`, not zero. That is
 * the UNDETERMINED state the Product Bible requires, and callers must render it
 * as such rather than as a bar at the far left.
 */
final class Confidence implements \JsonSerializable
{
    /** @var array<int, array{key: string, weight: float, value: float|null, basis: string}> */
    private array $components = [];

    private function __construct()
    {
    }

    public static function build(): self
    {
        return new self();
    }

    /**
     * Add one weighted component.
     *
     * @param float|null $value 0..1, or null when the input for it does not
     *                          exist for this organization. Values outside 0..1
     *                          are clamped: a component that computes 1.4 is a
     *                          bug in the caller, but silently exporting >1
     *                          confidence would be a worse one.
     * @param string     $basis How this component was measured, in words.
     */
    public function add(string $key, float $weight, ?float $value, string $basis): self
    {
        $this->components[] = [
            'key'    => $key,
            'weight' => $weight,
            'value'  => $value === null ? null : max(0.0, min(1.0, $value)),
            'basis'  => $basis,
        ];

        return $this;
    }

    /** The score, or null when nothing it is made of could be measured. */
    public function value(): ?float
    {
        $weight = 0.0;
        $total  = 0.0;

        foreach ($this->components as $c) {
            if ($c['value'] === null) {
                continue;
            }
            $weight += $c['weight'];
            $total  += $c['weight'] * $c['value'];
        }

        if ($weight <= 0.0) {
            return null;
        }

        return round($total / $weight, 4);
    }

    /** Components whose input was missing — the reason a score is lower-basis than intended. */
    public function unmeasured(): array
    {
        return array_values(array_map(
            static fn (array $c): string => $c['key'],
            array_filter($this->components, static fn (array $c): bool => $c['value'] === null),
        ));
    }

    /**
     * Confidence bands, used for wording only.
     *
     * The number always ships too. A band alone hides whether 0.71 or 0.94 was
     * behind "high", and those justify different actions.
     */
    public static function band(?float $value): string
    {
        if ($value === null) {
            return 'undetermined';
        }

        return match (true) {
            $value >= 0.75 => 'high',
            $value >= 0.50 => 'moderate',
            $value >= 0.25 => 'low',
            default        => 'very low',
        };
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $value = $this->value();

        return [
            'value'      => $value,
            'band'       => self::band($value),
            'components' => $this->components,
            'unmeasured' => $this->unmeasured(),
        ];
    }

    /* ───────────────── reusable component measurements ───────────────── */

    /**
     * How much a body of records supports a general claim, on a saturating
     * curve rather than a straight line.
     *
     * ln(1+n)/ln(1+target) is used because the tenth record adds far more to
     * what the organization can be said to know than the ten-thousandth does.
     * A linear ratio would leave a domain with 900 closed records looking 10%
     * confident against a 10,000 target, which understates it badly; a step
     * threshold would make one extra record flip the reading.
     */
    public static function volumeAdequacy(int $records, int $target): ?float
    {
        if ($records <= 0) {
            return null;
        }

        return min(1.0, log(1 + $records) / log(1 + max(2, $target)));
    }

    /**
     * Exponential half-life decay on the age of the newest record.
     *
     * Ported from the rule already in config('brain.evidence') so freshness
     * means the same thing here as it does in the Evidence service: at one
     * half-life old, a body of records corroborates half as strongly.
     */
    public static function freshness(?string $latestObservedAt, ?int $halfLifeDays = null): ?float
    {
        if ($latestObservedAt === null || $latestObservedAt === '') {
            return null;
        }

        $timestamp = strtotime($latestObservedAt);

        if ($timestamp === false) {
            return null;
        }

        $halfLife = $halfLifeDays ?? (int) config('brain.evidence.freshness_half_life_days', 90);
        $ageDays  = max(0.0, (time() - $timestamp) / 86400);

        return 0.5 ** ($ageDays / max(1, $halfLife));
    }
}
