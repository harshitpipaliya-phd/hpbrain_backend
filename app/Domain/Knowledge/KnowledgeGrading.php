<?php

declare(strict_types=1);

namespace App\Domain\Knowledge;

use Illuminate\Support\Carbon;

/**
 * The judgements both RETRIEVE surfaces share: how fresh a thing is, how far
 * we can stand behind it, and where it came from.
 *
 * It lives in one class because Knowledge Library and Organizational Memory
 * must never grade the same row differently. A learning that reads SUPPORTED
 * on one screen and CONFIRMED on another teaches the reader that the labels
 * mean nothing.
 *
 * NOTHING HERE INVENTS A GRADE. Every method returns UNDETERMINED when the
 * input that would let it decide is absent, and UNDETERMINED is a value the UI
 * renders as that word — never as 0%, a dash, or a hidden row.
 */
final class KnowledgeGrading
{
    /**
     * FRESH / AGING / STALE / UNDETERMINED, from the last time anyone touched it.
     *
     * @return array{state:string, days:int|null, since:string|null}
     */
    public static function freshness(?string $updated, ?string $created): array
    {
        $stamp = $updated ?: $created;

        if (! $stamp) {
            return ['state' => 'UNDETERMINED', 'days' => null, 'since' => null];
        }

        try {
            $days = (int) floor(abs((float) Carbon::parse($stamp)->diffInDays(Carbon::now())));
        } catch (\Throwable) {
            return ['state' => 'UNDETERMINED', 'days' => null, 'since' => null];
        }

        $fresh = (int) config('knowledge.freshness.fresh_days', 90);
        $aging = (int) config('knowledge.freshness.aging_days', 180);

        $state = $days <= $fresh ? 'FRESH' : ($days <= $aging ? 'AGING' : 'STALE');

        return ['state' => $state, 'days' => $days, 'since' => $stamp];
    }

    /**
     * CONFIRMED / SUPPORTED / INFERRED / UNDETERMINED.
     *
     * The evidence count is part of the input, not decoration: a 0.9 confidence
     * with nothing behind it is somebody's assertion, and it is graded one tier
     * below the same number carrying evidence rows. A null or zero confidence
     * is UNDETERMINED rather than a low score, because "we did not measure it"
     * and "we measured it as nearly nothing" are opposite statements.
     *
     * @return array{state:string, value:float|null, basis:string}
     */
    public static function confidence(mixed $raw, int $evidenceCount = 0): array
    {
        $value = is_numeric($raw) ? (float) $raw : null;

        if ($value === null || $value <= 0.0) {
            return [
                'state' => 'UNDETERMINED',
                'value' => null,
                'basis' => 'No confidence was recorded against this row.',
            ];
        }

        $confirmed = (float) config('knowledge.confidence.confirmed', 0.85);
        $supported = (float) config('knowledge.confidence.supported', 0.65);

        if ($value >= $confirmed && $evidenceCount > 0) {
            return [
                'state' => 'CONFIRMED',
                'value' => round($value, 2),
                'basis' => $evidenceCount.' evidence row(s) behind a stated confidence of '.round($value * 100).'%.',
            ];
        }

        if ($value >= $supported) {
            return [
                'state' => 'SUPPORTED',
                'value' => round($value, 2),
                'basis' => $evidenceCount > 0
                    ? $evidenceCount.' evidence row(s) behind a stated confidence of '.round($value * 100).'%.'
                    : 'A stated confidence of '.round($value * 100).'% with no evidence row attached.',
            ];
        }

        return [
            'state' => 'INFERRED',
            'value' => round($value, 2),
            'basis' => 'A stated confidence of '.round($value * 100).'% — too low to lean on without checking the source.',
        ];
    }

    /**
     * OBSERVED or SEEDED.
     *
     * A row a seeder wrote is a demonstration of the shape of the product, not
     * something the organization lived through. Both are shown; only one is
     * allowed to look like experience.
     *
     * @param  array<string, mixed>|null  $provenance  A decoded provenance blob, when the row has one.
     * @return array{state:string, actor:string|null, detail:string}
     */
    public static function provenance(?string $createdBy, ?array $provenance = null): array
    {
        $seededActors = (array) config('knowledge.provenance.seeded_actors', []);
        $flag = (string) config('knowledge.provenance.seeded_flag', 'demo');

        $actor = $createdBy !== null && trim($createdBy) !== '' ? trim($createdBy) : null;

        $byActor = $actor !== null && in_array($actor, $seededActors, true);
        $byFlag = $provenance !== null && ! empty($provenance[$flag]);

        if ($byActor || $byFlag) {
            return [
                'state' => 'SEEDED',
                'actor' => $actor,
                'detail' => 'Written by a seeder to demonstrate the shape of this screen. It is not something this organization has lived through.',
            ];
        }

        if ($actor === null) {
            return [
                'state' => 'UNDETERMINED',
                'actor' => null,
                'detail' => 'No author is recorded against this row, so its origin cannot be established.',
            ];
        }

        return [
            'state' => 'OBSERVED',
            'actor' => $actor,
            'detail' => 'Recorded by '.$actor.' from this organization\'s own activity.',
        ];
    }

    /**
     * Whether a measured outcome actually moved.
     *
     * THE REASON THIS EXISTS. Every seeded outcome in this installation carries
     * result="improved" with metrics of {baseline:0, observed:0, changePercent:0}
     * and an empty evidence_ids. Rendered literally that becomes "Improved" in
     * bold beside a confidence percentage — a claim of a result that was never
     * measured. A magnitude of zero across every metric is not an improvement
     * of zero; it is an unmeasured outcome, and it says so.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{state:string, changePercent:float|null, baseline:float|null, observed:float|null, unit:string|null, detail:string}
     */
    public static function outcomeMagnitude(?string $result, array $metrics, int $evidenceCount): array
    {
        $numeric = array_filter(
            $metrics,
            static fn ($v) => is_numeric($v) && (float) $v !== 0.0
        );

        $baseline = isset($metrics['baseline']) && is_numeric($metrics['baseline']) ? (float) $metrics['baseline'] : null;
        $observed = isset($metrics['observed']) && is_numeric($metrics['observed']) ? (float) $metrics['observed'] : null;
        $change = isset($metrics['changePercent']) && is_numeric($metrics['changePercent']) ? (float) $metrics['changePercent'] : null;
        $unit = isset($metrics['unit']) && is_string($metrics['unit']) ? $metrics['unit'] : null;

        if ($numeric === []) {
            return [
                'state' => 'UNDETERMINED',
                'changePercent' => null,
                'baseline' => $baseline,
                'observed' => $observed,
                'unit' => $unit,
                'detail' => $result
                    ? 'The outcome is recorded as "'.$result.'", but every metric behind it is zero, so the size of the change was never measured.'
                    : 'No outcome metrics were recorded, so the size of the change is unknown.',
            ];
        }

        return [
            'state' => $evidenceCount > 0 ? 'MEASURED' : 'REPORTED',
            'changePercent' => $change,
            'baseline' => $baseline,
            'observed' => $observed,
            'unit' => $unit,
            'detail' => $evidenceCount > 0
                ? 'Measured against '.$evidenceCount.' evidence row(s).'
                : 'Recorded against the plan\'s baseline, with no evidence row attached to check it.',
        ];
    }

    /** Decode a JSON column that should be a list, whatever actually arrives. */
    public static function jsonList(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
