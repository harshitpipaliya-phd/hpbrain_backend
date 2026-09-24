<?php

declare(strict_types=1);

namespace App\Domain\Eso;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Did this ESO actually work?
 *
 * THE QUESTION THIS ANSWERS IS NOT "DID IT RUN". hpbrain_eso_executions can
 * only ever tell you that somebody carried out an action and marked it done.
 * Whether the organization is measurably better off afterwards is a different
 * claim, resting on a different set of rows, and conflating the two produces
 * the single most damaging number this product could print: a "success rate"
 * computed from completion counts, which looks exactly like a real efficacy
 * figure and is not one.
 *
 * WHAT MAKES ONE EXECUTION COUNTABLE. An execution contributes to efficacy only
 * when every one of these is true:
 *
 *   1. it reached 'completed' — a failed or rolled-back run is evidence about
 *      the run, not about the intervention;
 *   2. its decision has a measurement plan carrying BOTH a baseline_value and a
 *      target_value, and the two differ (no denominator, no ratio);
 *   3. an outcome exists for that decision;
 *   4. that outcome's metrics contain a reading for the plan's own
 *      baseline_metric.
 *
 * Anything short of all four is INSUFFICIENT_EVIDENCE, and the reason is
 * reported per execution so a reader can see exactly what is missing rather
 * than being told a number cannot be produced.
 *
 * THE ARITHMETIC IS ONE LINE AND IT IS DELIBERATE.
 *
 *     progress = (actual - baseline) / (target - baseline)
 *
 * That is the fraction of the agreed distance the organization actually
 * travelled. It handles a target BELOW the baseline (reduce a queue, cut a
 * failure rate) without a special case, because both numerator and denominator
 * change sign together. 1.0 means the target was reached; 0 means nothing
 * moved; negative means it moved the wrong way. Nothing is normalised,
 * weighted, smoothed or calibrated — every one of those would be a judgement
 * this class is in no position to make on the organization's behalf.
 *
 * CONFIDENCE IS READ, NEVER DERIVED. The confidence attached to a result here
 * is the mean of the confidences whoever recorded the outcomes actually typed.
 * It is not inferred from sample size, agreement or spread; a computed
 * confidence would be a claim about how much to trust the figure, made by the
 * same code that produced the figure.
 */
final class EsoEfficacy
{
    /** No execution has ever run, so there is nothing to judge. */
    public const NOT_MEASURABLE = 'NOT_MEASURABLE';

    /** Executions exist, but none carries the evidence a score needs. */
    public const INSUFFICIENT_EVIDENCE = 'INSUFFICIENT_EVIDENCE';

    /** At least one execution contributed a real before/after reading. */
    public const MEASURABLE = 'MEASURABLE';

    public const SUCCESS = 'SUCCESS';

    public const PARTIAL = 'PARTIAL';

    public const FAILED = 'FAILED';

    /** The sentence shown wherever a track record would go but cannot be computed. */
    public const UNMEASURABLE_MESSAGE = 'Outcome evidence unavailable — efficacy not measurable.';

    /**
     * Efficacy for one ESO, with the workings.
     *
     * Every query below is filtered on $tenantId, including both sides of each
     * join. An efficacy figure assembled from another tenant's outcomes would
     * be both a leak and a lie.
     *
     * @return array<string, mixed>
     */
    public static function forDefinition(string $tenantId, string $esoId): array
    {
        $executions = self::executions($tenantId, $esoId);

        if ($executions === []) {
            return self::empty(self::NOT_MEASURABLE, 'This ESO has never been executed, so there is nothing to judge it on.');
        }

        $plans = self::plansByDecision($tenantId, self::decisionIds($executions));
        $outcomes = self::outcomesByDecision($tenantId, self::decisionIds($executions));

        $contributions = [];

        foreach ($executions as $execution) {
            $contributions[] = self::assess($execution, $plans, $outcomes);
        }

        $counted = array_values(array_filter($contributions, static fn (array $c): bool => $c['counted']));

        if ($counted === []) {
            return [
                'status' => self::INSUFFICIENT_EVIDENCE,
                'message' => self::UNMEASURABLE_MESSAGE,
                'explanation' => self::shortfall($contributions),
                'score' => null,
                'verdict' => null,
                'sampleSize' => 0,
                'executionsConsidered' => count($contributions),
                'confidence' => null,
                'metric' => null,
                'contributions' => $contributions,
            ];
        }

        $mean = array_sum(array_column($counted, 'score')) / count($counted);

        // Read, not derived. Outcomes that recorded no confidence are left out
        // of the mean rather than being given a default, which would be a
        // number nobody wrote down.
        $confidences = array_values(array_filter(
            array_column($counted, 'outcomeConfidence'),
            static fn ($v): bool => $v !== null,
        ));

        return [
            'status' => self::MEASURABLE,
            'message' => null,
            'explanation' => self::explain($counted, $contributions, $mean),
            'score' => round($mean, 4),
            'verdict' => self::verdict($mean),
            'sampleSize' => count($counted),
            'executionsConsidered' => count($contributions),
            'confidence' => $confidences === [] ? null : round(array_sum($confidences) / count($confidences), 4),
            'metric' => $counted[0]['metric'],
            'contributions' => $contributions,
        ];
    }

    /**
     * Efficacy for every ESO in a tenant, keyed by definition id.
     *
     * Used by the catalogue, which needs one row per definition and must not
     * issue a per-definition query to get it.
     *
     * @param  array<int, string>  $esoIds
     * @return array<string, array<string, mixed>>
     */
    public static function forTenant(string $tenantId, array $esoIds): array
    {
        $out = [];

        foreach ($esoIds as $esoId) {
            $out[$esoId] = self::forDefinition($tenantId, $esoId);
        }

        return $out;
    }

    /**
     * One execution, judged.
     *
     * `counted` is the whole point: it is false whenever any part of the chain
     * is absent, and `reason` says which part. A screen can therefore tell a
     * reader "this run has no outcome yet" rather than showing a zero.
     *
     * @param  array<string, object>  $plans
     * @param  array<string, object>  $outcomes
     * @return array<string, mixed>
     */
    private static function assess(object $execution, array $plans, array $outcomes): array
    {
        $base = [
            'executionId' => (string) $execution->id,
            'decisionId' => $execution->decision_id === null ? null : (string) $execution->decision_id,
            'status' => (string) $execution->status,
            'executedBy' => $execution->executed_by === null ? null : (string) $execution->executed_by,
            'completedDate' => $execution->completed_date,
            'counted' => false,
            'score' => null,
            'verdict' => null,
            'metric' => null,
            'baseline' => null,
            'target' => null,
            'actual' => null,
            'unit' => null,
            'outcomeResult' => null,
            'outcomeConfidence' => null,
            'outcomeId' => null,
        ];

        $status = strtolower((string) $execution->status);

        if ($status !== 'completed') {
            return array_merge($base, ['reason' => $status === 'rolled_back'
                ? 'Rolled back, so it is evidence about the run rather than about the intervention.'
                : 'Not completed ('.$status.'), so it cannot show what the intervention achieved.']);
        }

        $decisionId = $execution->decision_id === null ? null : (string) $execution->decision_id;

        if ($decisionId === null) {
            return array_merge($base, ['reason' => 'Not linked to a decision, so no measurement plan governs it.']);
        }

        $outcome = $outcomes[$decisionId] ?? null;

        if ($outcome === null) {
            return array_merge($base, ['reason' => 'Outcome not yet recorded.']);
        }

        $base['outcomeId'] = (string) $outcome->id;
        $base['outcomeResult'] = (string) $outcome->result;
        $base['outcomeConfidence'] = $outcome->confidence === null ? null : (float) $outcome->confidence;

        $plan = $plans[$decisionId] ?? null;

        if ($plan === null) {
            return array_merge($base, ['reason' => 'No measurement plan for this decision, so there is nothing to measure against.']);
        }

        $metric = (string) $plan->baseline_metric;
        $baseline = $plan->baseline_value === null ? null : (float) $plan->baseline_value;
        $target = $plan->target_value === null ? null : (float) $plan->target_value;

        $base['metric'] = $metric;
        $base['baseline'] = $baseline;
        $base['target'] = $target;
        $base['unit'] = $plan->metric_unit === null ? null : (string) $plan->metric_unit;

        if ($baseline === null || $target === null) {
            return array_merge($base, ['reason' => 'The measurement plan records no '
                .($baseline === null && $target === null ? 'baseline or target' : ($baseline === null ? 'baseline' : 'target'))
                .' value, so the change cannot be expressed as a proportion of anything.']);
        }

        if ($baseline === $target) {
            return array_merge($base, ['reason' => 'The plan\'s baseline and target are identical, so there is no distance to have travelled.']);
        }

        $actual = self::reading($outcome->metrics ?? null, $metric);

        if ($actual === null) {
            return array_merge($base, ['reason' => 'The recorded outcome carries no reading for "'.$metric.'", the metric this run was planned against.']);
        }

        $base['actual'] = $actual;

        $progress = ($actual - $baseline) / ($target - $baseline);
        $score = max(0.0, min(1.0, $progress));

        return array_merge($base, [
            'counted' => true,
            'score' => round($score, 4),
            'progress' => round($progress, 4),
            'verdict' => self::verdict($progress),
            'reason' => null,
        ]);
    }

    private static function verdict(float $progress): string
    {
        if ($progress >= 1.0) {
            return self::SUCCESS;
        }

        return $progress > 0.0 ? self::PARTIAL : self::FAILED;
    }

    /**
     * The reading for one metric out of an outcome's metrics JSON.
     *
     * Accepts `{"collection rate": 0.74}` and `{"collection rate": {"actual": 0.74}}`,
     * because both shapes are already written by different callers. Key
     * matching ignores case and separators — "collection rate" and
     * "collection_rate" are a transcription difference, not two metrics — but
     * nothing else, so two genuinely different metric names never collide.
     */
    private static function reading(mixed $metrics, string $metric): ?float
    {
        $decoded = is_array($metrics) ? $metrics : json_decode((string) $metrics, true);

        if (! is_array($decoded)) {
            return null;
        }

        $wanted = self::normalize($metric);

        foreach ($decoded as $key => $value) {
            if (self::normalize((string) $key) !== $wanted) {
                continue;
            }

            if (is_numeric($value)) {
                return (float) $value;
            }

            if (is_array($value)) {
                foreach (['actual', 'realized', 'value', 'after'] as $field) {
                    if (isset($value[$field]) && is_numeric($value[$field])) {
                        return (float) $value[$field];
                    }
                }
            }
        }

        return null;
    }

    private static function normalize(string $value): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim($value)));
    }

    /**
     * Why nothing could be scored, in the reader's terms.
     *
     * Counts the distinct obstacles rather than listing every execution: "3
     * executions have no outcome recorded" is actionable, and thirty lines
     * saying the same thing are not.
     *
     * @param  array<int, array<string, mixed>>  $contributions
     */
    private static function shortfall(array $contributions): string
    {
        $reasons = [];

        foreach ($contributions as $c) {
            $reason = (string) ($c['reason'] ?? 'Not countable.');
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }

        arsort($reasons);

        $parts = [];

        foreach ($reasons as $reason => $n) {
            $parts[] = $n.' execution'.($n === 1 ? '' : 's').': '.lcfirst($reason);
        }

        return 'No execution of this ESO carries the before-and-after evidence a score needs. '.implode(' ', $parts);
    }

    /**
     * How the figure was reached, in one paragraph.
     *
     * @param  array<int, array<string, mixed>>  $counted
     * @param  array<int, array<string, mixed>>  $all
     */
    private static function explain(array $counted, array $all, float $mean): string
    {
        $first = $counted[0];
        $unit = $first['unit'] ? ' '.$first['unit'] : '';
        $excluded = count($all) - count($counted);

        return 'Measured on "'.$first['metric'].'", planned to move from '.self::number($first['baseline']).$unit
            .' to '.self::number($first['target']).$unit.'. Across '.count($counted).' execution'.(count($counted) === 1 ? '' : 's')
            .' with a recorded before-and-after reading, the organization travelled '.round($mean * 100, 1).'% of that agreed distance'
            .($excluded > 0 ? '. '.$excluded.' further execution'.($excluded === 1 ? ' was' : 's were').' excluded for want of outcome evidence' : '')
            .'. The figure is the mean of (actual − baseline) ÷ (target − baseline) per execution, clamped to 0–1; nothing is weighted or estimated.';
    }

    private static function number(mixed $value): string
    {
        if ($value === null) {
            return 'an unrecorded value';
        }

        $float = (float) $value;

        return rtrim(rtrim(number_format($float, 4, '.', ''), '0'), '.') ?: '0';
    }

    /** @return array<string, mixed> */
    private static function empty(string $status, string $explanation): array
    {
        return [
            'status' => $status,
            'message' => $status === self::NOT_MEASURABLE ? null : self::UNMEASURABLE_MESSAGE,
            'explanation' => $explanation,
            'score' => null,
            'verdict' => null,
            'sampleSize' => 0,
            'executionsConsidered' => 0,
            'confidence' => null,
            'metric' => null,
            'contributions' => [],
        ];
    }

    /** @return array<int, object> */
    private static function executions(string $tenantId, string $esoId): array
    {
        if (! Schema::hasTable('hpbrain_eso_executions')) {
            return [];
        }

        return DB::table('hpbrain_eso_executions')
            ->where('tenant_id', $tenantId)
            ->where(fn ($w) => $w->where('eso_id', $esoId)->orWhere('eso_definition_id', $esoId))
            ->orderByDesc('created_date')
            ->limit(200)
            ->get(['id', 'decision_id', 'status', 'executed_by', 'completed_date', 'created_date'])
            ->all();
    }

    /**
     * @param  array<int, object>  $executions
     * @return array<int, string>
     */
    private static function decisionIds(array $executions): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (object $e): string => (string) ($e->decision_id ?? ''),
            $executions,
        ))));
    }

    /**
     * The plan that governed each decision.
     *
     * The EARLIEST plan per decision, matching the ordering EsoExecutionController
     * uses to authorise the run. Judging a run against a plan written after it
     * started is the post-hoc justification Invariant 4 exists to prevent, and
     * it would be no less wrong here than at the point of execution.
     *
     * @param  array<int, string>  $decisionIds
     * @return array<string, object>
     */
    private static function plansByDecision(string $tenantId, array $decisionIds): array
    {
        if ($decisionIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('hpbrain_measurement_plans')
            ->where('tenant_id', $tenantId)
            ->whereIn('decision_id', $decisionIds)
            ->orderBy('created_date')
            ->get() as $plan) {
            $out[(string) $plan->decision_id] ??= $plan;
        }

        return $out;
    }

    /**
     * The outcome recorded for each decision — the LATEST, because a
     * re-measurement supersedes the reading it corrects.
     *
     * @param  array<int, string>  $decisionIds
     * @return array<string, object>
     */
    private static function outcomesByDecision(string $tenantId, array $decisionIds): array
    {
        if ($decisionIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('hpbrain_outcomes')
            ->where('tenant_id', $tenantId)
            ->whereIn('decision_id', $decisionIds)
            ->orderBy('created_date')
            ->get() as $outcome) {
            $out[(string) $outcome->decision_id] = $outcome;
        }

        return $out;
    }
}
