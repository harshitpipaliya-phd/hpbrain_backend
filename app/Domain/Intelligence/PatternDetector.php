<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * Movement: what changed, in which direction, and whether the change is real.
 *
 * A total tells a reader where the organization is. Only a series tells them
 * which way it is going, and that is the difference between a dashboard opened
 * once and one opened weekly.
 *
 * A TREND IS NOT A COMPARISON OF TWO NUMBERS. Comparing the newest period to the
 * previous one is how every dashboard manufactures alarming movement out of
 * ordinary variance. Here, direction comes from an ordinary-least-squares slope
 * over every period available, and it is reported as movement only when the
 * slope survives its own standard error — the textbook t-test for a non-zero
 * slope, |t| = |slope| / SE(slope), against a threshold of 2.
 *
 * THE FIRST ATTEMPT AT THIS TEST WAS UNPASSABLE, WHICH IS WORTH RECORDING.
 * Comparing the slope to the standard deviation OF THE SERIES looks equivalent
 * and is not: for a perfectly straight twelve-point ramp, sd(series) is about
 * range/3.46 while the slope is range/11, so the ratio tops out near 0.31. A
 * threshold of 0.35 therefore reported *every* series as flat, including one
 * that had genuinely doubled across the year. Dividing by the standard error of
 * the slope instead compares the fit to its own residuals, which is the quantity
 * that distinguishes signal from noise and which strengthens with more periods
 * rather than weakening.
 *
 * THE LAST PERIOD IS OFTEN A LIE, AND IS DROPPED. Operational data arrives by
 * import, and the newest calendar month in a dataset is routinely partial — half
 * a month of records read as a 50% collapse in volume. Any series whose final
 * period is shorter than the median period is truncated before fitting, and the
 * response says so.
 */
final class PatternDetector
{
    /**
     * Minimum periods before a slope means anything.
     *
     * Three, because two points define a line exactly and can never disagree
     * with it — a two-point "trend" has no residual and so no way to be wrong.
     */
    private const MIN_PERIODS = 3;

    /**
     * |t| a slope must reach before it is movement rather than noise.
     *
     * Two is the conventional ~95% bar at the series lengths this deals with (a
     * year of months). It ships in the response so a reader can see what `flat`
     * was decided against.
     */
    private const SIGNIFICANCE = 2.0;

    /**
     * A single value holding this share of a dataset counts as concentration.
     *
     * Not a judgement that concentration is bad — a fibre operator whose faults
     * are mostly one fault may simply have one dominant failure mode. It is a
     * statement that the organization's exposure is not spread, which is a fact
     * a reader should be given.
     */
    private const CONCENTRATION = 0.40;

    /**
     * Columns concentration is looked for on.
     *
     * `status` is excluded on purpose. Almost every operational dataset sits
     * overwhelmingly in its terminal state, so a status concentration says
     * "closed work gets closed" — a fact already reported, properly, as a closure
     * rate. Concentration is interesting when it is about the KIND of work, not
     * about its lifecycle stage.
     *
     * @var array<int, string>
     */
    private const CONCENTRATION_FIELDS = ['category', 'sub_category', 'area', 'zone'];

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    public function detect(string $tenantId, array $profile): array
    {
        $trends = [];

        foreach ($profile['datasets'] as $dataset) {
            foreach ($this->datasetTrends($tenantId, $dataset) as $trend) {
                $trends[] = $trend;
            }
        }

        // Strongest movement first, measured in significance rather than raw
        // slope so a change in call volume and a change in a closure rate can be
        // ranked against each other at all.
        usort($trends, static fn (array $a, array $b): int => abs($b['significance']) <=> abs($a['significance']));

        return [
            'trends'         => $trends,
            'moving'         => array_values(array_filter($trends, static fn (array $t): bool => $t['direction'] !== 'flat')),
            'concentrations' => $this->concentrations($profile),
            'dependencies'   => $this->dependencies($profile),
            'method'         => [
                'trend'        => 'Ordinary least squares over every complete calendar period. Direction is reported only when the slope clears its own standard error, |t| >= '.self::SIGNIFICANCE.'; otherwise flat. Series are fitted on measurements with physically impossible values excluded.',
                'partial'      => 'A final period holding fewer records than half the median period is treated as still filling and excluded from the fit.',
                'concentration' => 'A single classifier value accounting for at least '.(int) (self::CONCENTRATION * 100).'% of a dataset.',
            ],
        ];
    }

    /* ─────────────────────────── trends ─────────────────────────── */

    /**
     * The three series every dated dataset can support: how much work arrives,
     * how much of it concludes, and how long it takes.
     *
     * @param array<string, mixed> $dataset
     *
     * @return array<int, array<string, mixed>>
     */
    private function datasetTrends(string $tenantId, array $dataset): array
    {
        $monthly = $this->dropPartialTail($dataset['monthly']);

        if (count($monthly) < self::MIN_PERIODS) {
            return [];
        }

        $periods = array_column($monthly, 'period');
        $out     = [];

        $volume = $this->fit(array_map(static fn (array $m): ?float => (float) $m['records'], $monthly));

        if ($volume !== null) {
            $out[] = $this->trend(
                $tenantId, $dataset, 'volume',
                'Records per month on '.$dataset['label'],
                $volume, $periods, array_map(static fn (array $m): ?float => (float) $m['records'], $monthly),
                'records/month', 'more work arriving',
            );
        }

        // Closure rate only exists where the dataset records a conclusion at all.
        if (($dataset['closedCount'] ?? 0) > 0) {
            $series = array_map(
                static fn (array $m): ?float => $m['records'] > 0 ? $m['closed'] / $m['records'] : null,
                $monthly,
            );
            $closure = $this->fit($series);

            if ($closure !== null) {
                $out[] = $this->trend(
                    $tenantId, $dataset, 'closureRate',
                    'Share of '.$dataset['label'].' records concluded, per month',
                    $closure, $periods, $series, 'share/month', 'more work being concluded',
                );
            }
        }

        if ($dataset['measure'] !== null) {
            // meanMetricValid, not meanMetric. One complaint in the profiled data
            // carries a resolution time of minus 1.1 million hours; including it
            // put a 1,000% "improvement" on this trend, which is exactly the kind
            // of confident nonsense this product exists not to publish.
            $series = array_map(static fn (array $m): ?float => $m['meanMetricValid'] === null ? null : (float) $m['meanMetricValid'], $monthly);
            $measure = $this->fit($series);

            if ($measure !== null) {
                $unit = $dataset['measure']['unit'] ?? 'units';
                $out[] = $this->trend(
                    $tenantId, $dataset, 'measure',
                    'Mean '.$unit.' per '.strtolower($dataset['label']).' record, per month',
                    $measure, $periods, $series, $unit.'/month', 'each item taking longer',
                );
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>      $dataset
     * @param array<string, float|null> $fit
     * @param array<int, string>        $periods
     * @param array<int, float|null>    $series
     *
     * @return array<string, mixed>
     */
    private function trend(
        string $tenantId,
        array $dataset,
        string $metric,
        string $label,
        array $fit,
        array $periods,
        array $series,
        string $unit,
        string $risingMeans,
    ): array {
        $significance = $fit['significance'];
        $direction    = abs($significance) < self::SIGNIFICANCE ? 'flat' : ($fit['slope'] > 0 ? 'rising' : 'falling');

        $values = array_values(array_filter($series, static fn (?float $v): bool => $v !== null));

        // Endpoints are read off the FITTED line, not off the first and last
        // observations. One unusual month at either end of the window would
        // otherwise set the headline change, which is how "up 89%" gets published
        // about a series that is statistically flat.
        $lastIndex = count($series) - 1;
        $first     = $fit['intercept'];
        $last      = $fit['intercept'] + $fit['slope'] * $lastIndex;

        return [
            'key'          => 'dataset:'.$dataset['dataset'].':'.$metric,
            'area'         => (string) $dataset['label'],
            'metric'       => $metric,
            'label'        => $label,
            'unit'         => $unit,
            'direction'    => $direction,
            'slope'        => round($fit['slope'], 6),
            'significance' => round($significance, 3),
            'periods'      => count($values),
            'periodLabels' => $periods,
            'series'       => array_map(static fn (?float $v): ?float => $v === null ? null : round($v, 4), $series),
            'observedMin'  => $values === [] ? null : round(min($values), 4),
            'observedMax'  => $values === [] ? null : round(max($values), 4),
            // How much of the variation the straight line actually explains. A
            // significant slope with a low r-squared is a real drift inside a
            // noisy series, and a reader is entitled to know which of the two
            // they are looking at.
            'fitQuality'   => $fit['r2'] === null ? null : round($fit['r2'], 3),
            'fittedFirst'  => round($first, 4),
            'fittedLast'   => round($last, 4),
            // Fitted end against fitted start, across the whole window. Null when
            // the fitted start is ~0, because a percentage change from zero is not
            // a number.
            'changePct'    => abs($first) < 1e-9 ? null : round((($last - $first) / abs($first)) * 100, 1),
            'risingMeans'  => $risingMeans,
            'provenance'   => Provenance::of('OLS slope over '.count($values).' monthly aggregates; significance = slope / standardError(slope), reported as movement at |t| >= '.self::SIGNIFICANCE)
                ->from(
                    OrganizationDataProfiler::RECORDS,
                    ['tenant_id' => $tenantId, 'dataset' => $dataset['dataset'], 'grouped_by' => "DATE_FORMAT(occurred_at,'%Y-%m')"],
                    (int) $dataset['records'],
                ),
        ];
    }

    /**
     * Least squares over an evenly-spaced series, with nulls skipped.
     *
     * Nulls are skipped rather than interpolated. An interpolated point is a
     * fabricated observation that then contributes to the slope it was invented
     * to support, and a reader has no way to tell it apart from a measured one.
     *
     * @param array<int, float|null> $series
     *
     * @return array{slope: float, intercept: float, significance: float, r2: float|null, n: int}|null
     */
    private function fit(array $series): ?array
    {
        $xs = [];
        $ys = [];

        foreach ($series as $i => $value) {
            if ($value === null) {
                continue;
            }
            $xs[] = (float) $i;
            $ys[] = $value;
        }

        $n = count($ys);

        if ($n < self::MIN_PERIODS) {
            return null;
        }

        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;

        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $meanX;
            $dy = $ys[$i] - $meanY;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }

        if ($sxx <= 0.0) {
            return null;
        }

        $slope     = $sxy / $sxx;
        $intercept = $meanY - $slope * $meanX;

        $sse = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $residual = $ys[$i] - ($intercept + $slope * $xs[$i]);
            $sse += $residual * $residual;
        }

        $r2 = $syy <= 1e-12 ? null : max(0.0, 1 - $sse / $syy);

        if ($sse <= 1e-12) {
            // The fit is exact. A dead-flat series has slope 0 and no movement; a
            // perfectly straight ramp has all the movement there is. Testing the
            // slope handles both without inventing a t of infinity.
            $significance = abs($slope) <= 1e-12 ? 0.0 : self::SIGNIFICANCE;

            return ['slope' => $slope, 'intercept' => $intercept, 'significance' => $significance, 'r2' => $r2, 'n' => $n];
        }

        $standardError = sqrt(($sse / ($n - 2)) / $sxx);

        return [
            'slope'        => $slope,
            'intercept'    => $intercept,
            'significance' => $standardError <= 1e-12 ? 0.0 : $slope / $standardError,
            'r2'           => $r2,
            'n'            => $n,
        ];
    }

    /**
     * Drop a trailing period that is still filling.
     *
     * @param array<int, array<string, mixed>> $monthly
     *
     * @return array<int, array<string, mixed>>
     */
    private function dropPartialTail(array $monthly): array
    {
        if (count($monthly) < 2) {
            return $monthly;
        }

        $counts = array_column($monthly, 'records');
        sort($counts);
        $median = $counts[(int) floor(count($counts) / 2)];

        $lastCount = (int) $monthly[count($monthly) - 1]['records'];

        if ($median > 0 && $lastCount < $median / 2) {
            array_pop($monthly);
        }

        return $monthly;
    }

    /* ─────────────────────────── concentration and dependency ─────────────────────────── */

    /**
     * Where a single value dominates a dataset.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<int, array<string, mixed>>
     */
    private function concentrations(array $profile): array
    {
        $out = [];

        foreach ($profile['datasets'] as $dataset) {
            $records = (int) $dataset['records'];

            if ($records < 50) {
                continue;
            }

            // Values already reported for this dataset. Real data reuses the same
            // vocabulary across columns — one profiled dataset carries "Closed" in
            // both `status` and `sub_category` — and reporting the identical fact
            // twice under two field names reads as two findings.
            $seen = [];

            // Values the dataset's own `status` column uses. Operational sources
            // routinely mirror the lifecycle state into a second column — one
            // profiled dataset carries "Closed" in both `status` and
            // `sub_category` — and "94% of work orders are Closed" is a closure
            // rate wearing a concentration's clothes. Excluding `status` from the
            // field list is not enough on its own; the vocabulary has to be
            // excluded too, wherever it turns up.
            $lifecycle = [];

            foreach ($dataset['fields']['status']['topValues'] ?? [] as $statusValue) {
                $lifecycle[mb_strtolower(trim((string) $statusValue['value']))] = true;
            }

            foreach (self::CONCENTRATION_FIELDS as $field) {
                $f = $dataset['fields'][$field] ?? null;

                // A column with one value is a blind spot, not a concentration;
                // KnowledgeAnalyzer reports it as such and reporting it twice in
                // different words would double-count the same fact.
                if ($f === null || $f['topValues'] === [] || $f['distinct'] < 2) {
                    continue;
                }

                $top   = $f['topValues'][0];
                $share = $top['records'] / max(1, $f['nonNull']);

                if ($share < self::CONCENTRATION) {
                    continue;
                }

                $normalised = mb_strtolower(trim((string) $top['value']));

                if (isset($seen[$normalised]) || isset($lifecycle[$normalised])) {
                    continue;
                }

                $seen[$normalised] = true;

                $out[] = [
                    'area'    => (string) $dataset['label'],
                    'field'   => $field,
                    'value'   => (string) $top['value'],
                    'records' => (int) $top['records'],
                    'of'      => (int) $f['nonNull'],
                    'share'   => round($share, 4),
                    'title'   => number_format($share * 100, 1).'% of '.strtolower($dataset['label']).' records are "'.$top['value'].'"',
                    'detail'  => number_format($top['records']).' of '.number_format($f['nonNull']).' classified records share one `'.$field.'` value, so the organization\'s exposure in this area is concentrated rather than spread.',
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => $b['records'] <=> $a['records']);

        return $out;
    }

    /**
     * Concentration on PEOPLE rather than on categories.
     *
     * The same arithmetic, read differently: when one owner appears on a large
     * share of a dataset, the organization's ability to do that work depends on
     * that person continuing to be available. That is a capacity dependency, and
     * it is invisible in every per-category view.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<int, array<string, mixed>>
     */
    private function dependencies(array $profile): array
    {
        $out = [];

        foreach ($profile['datasets'] as $dataset) {
            $records = (int) $dataset['records'];

            if ($records < 50) {
                continue;
            }

            foreach (OrganizationDataProfiler::ACTORS as $field) {
                $f = $dataset['fields'][$field] ?? null;

                if ($f === null || $f['topValues'] === [] || $f['nonNull'] === 0) {
                    continue;
                }

                $top   = $f['topValues'][0];
                $share = $top['records'] / $f['nonNull'];

                // Two people cannot spread work more than two ways, so a high
                // share across a tiny roster is arithmetic rather than a finding.
                if ($f['distinct'] < 3 || $share < 0.25) {
                    continue;
                }

                $out[] = [
                    'area'    => (string) $dataset['label'],
                    'field'   => $field,
                    'person'  => (string) $top['value'],
                    'records' => (int) $top['records'],
                    'of'      => (int) $f['nonNull'],
                    'people'  => (int) $f['distinct'],
                    'share'   => round($share, 4),
                    'title'   => 'One '.str_replace('_name', '', $field).' carries '.number_format($share * 100, 1).'% of '.strtolower($dataset['label']).' records',
                    'detail'  => '"'.$top['value'].'" appears on '.number_format($top['records']).' of '.number_format($f['nonNull']).' attributed records, across a roster of '.$f['distinct'].'.',
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => $b['share'] <=> $a['share']);

        return $out;
    }
}
