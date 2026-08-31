<?php

declare(strict_types=1);

namespace App\Domain\Organization;

/**
 * ONE DEPARTMENT, EVERYTHING KNOWN ABOUT IT.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 *
 * The department detail screen asked eleven questions — how is this unit
 * performing, what work does it handle, how busy is it, how does it compare —
 * and the page could answer one of them. Everything else read "Not measured",
 * because the screen was assembled client-side from a metrics row that carried
 * counts and nothing derived from them.
 *
 * This composes the answer server-side, from aggregates that are ALREADY
 * COMPUTED AND CACHED. It issues no query of its own: DepartmentIntelligence-
 * Metrics holds the per-unit counts and the tenant totals, and the operational
 * aggregate it forwards holds the trend, momentum, turnaround and dataset mix.
 * On a tenant with 225,103 operational records this is arithmetic over a few
 * hundred array entries, not a second pass over the table.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * UNIVERSAL BY CONSTRUCTION
 *
 * Nothing here knows a tenant, an industry, or a department name. Every figure
 * is derived from counts the metrics service publishes for any organization,
 * and every dimension is gated on whether that organization records data of the
 * kind at all. A telecom operator, a school and a hospital differ only in which
 * dimensions survive the gate and which datasets appear in the work mix.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * THE SCORING RULE
 *
 * ONLY MEASURABLE DIMENSIONS PARTICIPATE. A dimension the organization cannot
 * record leaves the mean entirely — it is never averaged in as a zero, which is
 * what made a department with a complete roster and no capability module read
 * as 33%. The divisor is the surviving weight, and the response states how many
 * dimensions of the total survived so a reader can judge how much the number
 * rests on.
 */
final class DepartmentProfile
{
    /**
     * The dimensions, and what each is worth.
     *
     * Execution and operational performance carry the most because they measure
     * what the unit DID; data confidence carries least because it measures how
     * well the organization records, not how well the unit works.
     *
     * @var array<string, array{label: string, weight: float}>
     */
    private const DIMENSIONS = [
        'operational' => ['label' => 'Operational performance', 'weight' => 1.5],
        'workload'    => ['label' => 'Workload health',         'weight' => 1.25],
        'execution'   => ['label' => 'Execution reliability',   'weight' => 1.25],
        'people'      => ['label' => 'People coverage',         'weight' => 1.0],
        'service'     => ['label' => 'Service health',          'weight' => 1.0],
        'signal'      => ['label' => 'Signal health',           'weight' => 1.0],
        'confidence'  => ['label' => 'Data confidence',         'weight' => 0.75],
    ];

    /** Below this many classified records a rate is arithmetic, not evidence. */
    private const RATE_FLOOR = 30;

    /** Backlog above this share of a unit's work reads as pressure, not flow. */
    private const BACKLOG_PRESSURE = 0.35;

    public function __construct(private readonly DepartmentIntelligenceMetrics $metrics)
    {
    }

    /**
     * @return array<string, mixed>|null null when the unit is not this tenant's
     */
    public function forDepartment(string $tenant, string $departmentId): ?array
    {
        $all = $this->metrics->forTenant($tenant);
        $departments = $all['departments'] ?? [];

        if (! array_key_exists($departmentId, $departments)) {
            return null;
        }

        $m = $departments[$departmentId];
        $support = $all['support'] ?? [];
        $tenantTotals = $all['tenant'] ?? [];

        $dimensions = $this->dimensions($m, $support);
        $measured = array_values(array_filter($dimensions, static fn ($d) => $d['score'] !== null));
        $score = $this->composite($measured);
        $position = $this->position($departmentId, $departments, $support);
        $performance = $this->performance($m);
        $workload = $this->workload($m);
        $people = $this->people($m);
        $contribution = $this->contribution($m, $tenantTotals, $position);
        $health = $this->health($score, $dimensions, $m);

        return [
            'departmentId' => $departmentId,
            'score' => $score,
            'status' => $health['status'],
            'statusLabel' => $health['label'],
            'measuredCount' => count($measured),
            'dimensionCount' => count($dimensions),
            'confidence' => $this->confidence(count($measured), count($dimensions)),
            'dimensions' => $dimensions,
            'pulse' => $this->pulse($m, $score, $performance, $workload),
            'performance' => $performance,
            'workload' => $workload,
            'people' => $people,
            'trend' => $this->trend($m),
            'contribution' => $contribution,
            'position' => $position,
            'work' => $this->work($m),
            'signals' => $this->signals($m, $tenantTotals, $support),
            'evidence' => $this->evidence($m, $tenantTotals, $support),
            'cases' => $this->cases($m, $tenantTotals, $support),
            'health' => $health,
            'narrative' => $this->narrative($m, $performance, $workload, $contribution, $position),
            'nextAction' => $this->nextAction($m, $performance, $workload, $dimensions),
            'unclaimedWork' => $m['unclaimedWork'] ?? null,
        ];
    }

    /**
     * Every dimension, scored or explicitly not.
     *
     * A dimension returns null when the organization does not record data of
     * that kind, or when the unit's sample is too small for a rate to mean
     * anything. Both are reported with the reason, and neither is a zero.
     *
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $support
     * @return array<int, array<string, mixed>>
     */
    private function dimensions(array $m, array $support): array
    {
        $out = [];

        $records = $m['operationalRecords'] ?? null;
        $classified = $m['operationalClassified'] ?? null;
        $completion = $m['operationalCompletionRate'] ?? null;
        $people = (int) ($m['people'] ?? 0);

        // ---- operational performance: what share of measurable work completed
        $out[] = $this->dimension(
            'operational',
            $completion === null ? null : (float) $completion * 100,
            $completion !== null
                ? sprintf('%s%% of %s classified records completed.', round((float) $completion * 100), number_format((int) $classified))
                : ($records === null
                    ? 'This organization does not attribute operational records to a department, so completion cannot be measured here.'
                    : sprintf('Only %s classified records — below the %d needed for a rate to mean anything.', number_format((int) $classified), self::RATE_FLOOR)),
        );

        // ---- workload health: backlog as a share of the unit's own work
        $backlog = $m['operationalBacklog'] ?? null;
        $backlogShare = ($records !== null && $records > 0 && $backlog !== null) ? $backlog / $records : null;
        $out[] = $this->dimension(
            'workload',
            $backlogShare === null ? null : max(0, 100 - ($backlogShare / self::BACKLOG_PRESSURE) * 100),
            $backlogShare === null
                ? 'No imported record names this unit, so open workload cannot be measured.'
                : sprintf('%s of %s records are still open (%s%%).', number_format((int) $backlog), number_format((int) $records), round($backlogShare * 100)),
        );

        // ---- execution reliability: work that ended in a result, not a cancel
        $cancellation = $m['operationalCancellationRate'] ?? null;
        $out[] = $this->dimension(
            'execution',
            $cancellation === null ? null : max(0, 100 - (float) $cancellation * 100 * 2),
            $cancellation === null
                ? 'Cancellation cannot be measured without enough classified records.'
                : sprintf('%s%% of classified work was cancelled rather than completed.', round((float) $cancellation * 100, 1)),
        );

        // ---- people coverage: how completely the roster is recorded
        $probes = array_values(array_filter([
            $m['peopleWithRole'] ?? null,
            $m['peopleWithContact'] ?? null,
            $m['peopleWithReference'] ?? null,
        ], static fn ($v) => $v !== null));

        $coverage = ($people > 0 && $probes !== [])
            ? array_sum(array_map(static fn ($v) => min(1, $v / $people), $probes)) / count($probes) * 100
            : null;

        $out[] = $this->dimension(
            'people',
            $coverage,
            $coverage === null
                ? ($people === 0
                    ? 'No people are assigned to this unit, so there is no roster to measure.'
                    : 'This roster carries none of the fields coverage is measured from.')
                : sprintf('%d of %s recorded fields are filled across %s people.', count($probes), count($probes), number_format($people)),
        );

        // ---- service health: how quickly work closes
        $turnaround = $m['operationalTurnaroundHours'] ?? null;
        $measuredTurn = (int) ($m['operationalTurnaroundMeasured'] ?? 0);
        $out[] = $this->dimension(
            'service',
            ($turnaround === null || $measuredTurn < self::RATE_FLOOR)
                ? null
                : max(0, 100 - min(100, ((float) $turnaround / 168) * 100)),
            ($turnaround === null || $measuredTurn < self::RATE_FLOOR)
                ? 'Too few records carry both an opened and a closed timestamp for turnaround to be measured.'
                : sprintf('Work closes in %s hours on average, measured over %s records.', number_format((float) $turnaround, 1), number_format($measuredTurn)),
        );

        // ---- signal health: what is open against this unit
        $signalsSupported = (bool) ($support['signals'] ?? false);
        $signalsTotal = (int) ($m['signalsTotal'] ?? 0);
        $signalsOpen = (int) ($m['signalsOpen'] ?? 0);
        $out[] = $this->dimension(
            'signal',
            ! $signalsSupported ? null : ($signalsTotal === 0 ? 100 : max(0, 100 - ($signalsOpen / max(1, $signalsTotal)) * 100)),
            ! $signalsSupported
                ? 'This organization does not attribute signals to departments, so signal health cannot be measured.'
                : ($signalsTotal === 0
                    ? 'No signal has been raised against this unit.'
                    : sprintf('%d of %d signals against this unit are still open.', $signalsOpen, $signalsTotal)),
        );

        // ---- data confidence: how much of the model this unit can answer
        $answerable = 0;
        $possible = 0;

        foreach (['operational', 'signals', 'evidence', 'cases', 'capability', 'activity'] as $flag) {
            $possible++;

            if (($support[$flag] ?? false) === true) {
                $answerable++;
            }
        }

        $out[] = $this->dimension(
            'confidence',
            $possible > 0 ? ($answerable / $possible) * 100 : null,
            sprintf('This organization records %d of %d kinds of data this model reads.', $answerable, $possible),
        );

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function dimension(string $key, ?float $score, string $basis): array
    {
        $rounded = $score === null ? null : (int) round(max(0, min(100, $score)));

        return [
            'key' => $key,
            'label' => self::DIMENSIONS[$key]['label'],
            'weight' => self::DIMENSIONS[$key]['weight'],
            'score' => $rounded,
            'status' => $rounded === null ? null : $this->band($rounded),
            'basis' => $basis,
        ];
    }

    /**
     * The composite, over the survivors only.
     *
     * @param  array<int, array<string, mixed>>  $measured
     */
    private function composite(array $measured): ?int
    {
        if ($measured === []) {
            return null;
        }

        $weight = array_sum(array_column($measured, 'weight'));

        if ($weight <= 0) {
            return null;
        }

        $sum = 0.0;

        foreach ($measured as $d) {
            $sum += $d['weight'] * $d['score'];
        }

        return (int) round($sum / $weight);
    }

    private function band(int $score): string
    {
        return match (true) {
            $score >= 85 => 'healthy',
            $score >= 70 => 'good',
            $score >= 50 => 'watch',
            default => 'critical',
        };
    }

    private function confidence(int $measured, int $total): string
    {
        if ($total === 0) {
            return 'none';
        }

        $ratio = $measured / $total;

        return match (true) {
            $ratio >= 0.8 => 'high',
            $ratio >= 0.5 => 'medium',
            $ratio > 0 => 'low',
            default => 'none',
        };
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function performance(array $m): array
    {
        $records = $m['operationalRecords'] ?? null;
        $completed = $m['operationalCompleted'] ?? null;
        $classified = $m['operationalClassified'] ?? null;
        $people = (int) ($m['people'] ?? 0);

        return [
            'supported' => $records !== null,
            'records' => $records,
            'completed' => $completed,
            'cancelled' => $m['operationalCancelled'] ?? null,
            'backlog' => $m['operationalBacklog'] ?? null,
            'classified' => $classified,
            'completionRate' => $m['operationalCompletionRate'] ?? null,
            'cancellationRate' => $m['operationalCancellationRate'] ?? null,
            'turnaroundHours' => $m['operationalTurnaroundHours'] ?? null,
            'turnaroundMeasured' => (int) ($m['operationalTurnaroundMeasured'] ?? 0),
            // Records per person is only meaningful where BOTH are known, and a
            // unit with no people would divide by zero into an infinity that
            // reads as spectacular productivity.
            'perPerson' => ($records !== null && $people > 0) ? round($records / $people, 1) : null,
            'momentum' => $m['operationalMomentum'] ?? null,
            'reason' => $records === null
                ? 'No imported record for this organization names this unit as its owning department.'
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function workload(array $m): array
    {
        $records = $m['operationalRecords'] ?? null;

        if ($records === null || $records === 0) {
            return ['supported' => false, 'total' => $records, 'segments' => [], 'perPerson' => null, 'reason' => 'No imported work is attributed to this unit.'];
        }

        $completed = (int) ($m['operationalCompleted'] ?? 0);
        $cancelled = (int) ($m['operationalCancelled'] ?? 0);
        $backlog = (int) ($m['operationalBacklog'] ?? 0);
        $unknown = max(0, $records - $completed - $cancelled - $backlog);
        $people = (int) ($m['people'] ?? 0);

        $segments = [
            ['key' => 'completed', 'label' => 'Completed', 'count' => $completed],
            ['key' => 'open', 'label' => 'Open', 'count' => $backlog],
            ['key' => 'cancelled', 'label' => 'Cancelled', 'count' => $cancelled],
            ['key' => 'unclassified', 'label' => 'Unclassified', 'count' => $unknown],
        ];

        foreach ($segments as $i => $s) {
            $segments[$i]['share'] = round($s['count'] / $records, 4);
        }

        return [
            'supported' => true,
            'total' => $records,
            'active' => $backlog,
            'segments' => array_values(array_filter($segments, static fn ($s) => $s['count'] > 0)),
            'perPerson' => $people > 0 ? round($records / $people, 1) : null,
            'reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function people(array $m): array
    {
        $people = (int) ($m['people'] ?? 0);

        $fields = [];

        foreach ([
            'peopleWithRole' => 'A role',
            'peopleWithContact' => 'Contact details',
            'peopleWithReference' => 'A reference',
        ] as $key => $label) {
            if (($m[$key] ?? null) === null) {
                continue;
            }

            $fields[] = [
                'label' => $label,
                'have' => (int) $m[$key],
                'missing' => max(0, $people - (int) $m[$key]),
                'share' => $people > 0 ? round(min(1, $m[$key] / $people), 4) : null,
            ];
        }

        return [
            'total' => $people,
            'fields' => $fields,
            'assessed' => (int) ($m['capabilityAssessedPeople'] ?? 0),
            'perPerson' => ($m['operationalRecords'] ?? null) !== null && $people > 0
                ? round($m['operationalRecords'] / $people, 1)
                : null,
            // Individual attribution needs a per-person link the roster does not
            // carry; saying so is better than a leaderboard built on a guess.
            'individualReason' => 'Individual performance is not measurable from the connected source, which attributes work to units rather than to people.',
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function trend(array $m): array
    {
        $series = $m['operationalTrend'] ?? [];

        return [
            'supported' => $series !== [],
            'series' => $series,
            'momentum' => $m['operationalMomentum'] ?? null,
            'reason' => $series === [] ? 'No dated record names this unit, so activity over time cannot be plotted.' : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function work(array $m): array
    {
        return [
            'primaryDataset' => $m['operationalPrimaryDataset'] ?? null,
            'breakdown' => $m['operationalDatasetBreakdown'] ?? [],
            'datasets' => $m['operationalDatasets'] ?? null,
        ];
    }

    /**
     * Where this unit sits among its peers.
     *
     * Ranks are computed over the units this reader can actually see, so "#3 of
     * 13" always refers to a list they can get back to.
     *
     * @param  array<string, array<string, mixed>>  $departments
     * @param  array<string, mixed>  $support
     * @return array<string, mixed>
     */
    private function position(string $id, array $departments, array $support): array
    {
        $rank = function (callable $value) use ($id, $departments): array {
            $scored = [];

            foreach ($departments as $key => $row) {
                $v = $value($row);

                if ($v !== null) {
                    $scored[$key] = $v;
                }
            }

            if (! array_key_exists($id, $scored)) {
                return ['rank' => null, 'of' => count($scored), 'value' => null];
            }

            arsort($scored);

            /*
              KEYS COMPARED AS STRINGS, DELIBERATELY.

              Department ids are numeric strings, and PHP silently casts a
              numeric string array key to an int. A strict array_search for
              '2050' against keys that are now int 2050 matches nothing, and
              every unit reported "rank —" while holding a perfectly good
              position. Casting both sides is the fix; dropping the strict flag
              would instead make '0' match every non-numeric key.
            */
            $position = array_search((string) $id, array_map(strval(...), array_keys($scored)), true);

            return [
                'rank' => $position === false ? null : $position + 1,
                'of' => count($scored),
                'value' => $scored[$id],
            ];
        };

        $peers = [];

        foreach ($departments as $key => $row) {
            $dims = $this->dimensions($row, $support);
            $measured = array_values(array_filter($dims, static fn ($d) => $d['score'] !== null));
            $composite = $this->composite($measured);

            if ($composite !== null) {
                $peers[$key] = $composite;
            }
        }

        $average = $peers === [] ? null : (int) round(array_sum($peers) / count($peers));
        $own = $peers[$id] ?? null;

        arsort($peers);
        $scoreRankIndex = array_search((string) $id, array_map(strval(...), array_keys($peers)), true);

        return [
            'size' => $rank(static fn ($r) => ($r['people'] ?? 0) > 0 ? (int) $r['people'] : null),
            'activity' => $rank(static fn ($r) => $r['operationalRecords'] ?? null),
            'score' => [
                'rank' => $scoreRankIndex === false ? null : $scoreRankIndex + 1,
                'of' => count($peers),
                'value' => $own,
            ],
            'organizationAverage' => $average,
            'difference' => ($own !== null && $average !== null) ? $own - $average : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $tenantTotals
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>
     */
    private function contribution(array $m, array $tenantTotals, array $position): array
    {
        $records = $m['operationalRecords'] ?? null;
        $orgRecords = (int) ($tenantTotals['operationalAttributed'] ?? 0);
        $people = (int) ($m['people'] ?? 0);
        $orgPeople = (int) ($tenantTotals['people'] ?? 0);

        return [
            'records' => $records,
            'recordShare' => ($records !== null && $orgRecords > 0) ? round($records / $orgRecords, 4) : null,
            'organizationRecords' => $orgRecords,
            'people' => $people,
            'peopleShare' => $orgPeople > 0 ? round($people / $orgPeople, 4) : null,
            'organizationPeople' => $orgPeople,
            'activityRank' => $position['activity']['rank'] ?? null,
            'activityOf' => $position['activity']['of'] ?? null,
            'scoreDifference' => $position['difference'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $tenantTotals
     * @param  array<string, mixed>  $support
     * @return array<string, mixed>
     */
    private function signals(array $m, array $tenantTotals, array $support): array
    {
        $supported = (bool) ($support['signals'] ?? false);
        $orgTotal = (int) ($tenantTotals['signalsTotal'] ?? 0);

        return [
            'supported' => $supported,
            'organizationTotal' => $orgTotal,
            'total' => (int) ($m['signalsTotal'] ?? 0),
            'open' => (int) ($m['signalsOpen'] ?? 0),
            'openHigh' => (int) ($m['signalsOpenHigh'] ?? 0),
            'resolved' => (int) ($m['signalsResolved'] ?? 0),
            'reason' => $supported
                ? null
                : ($orgTotal > 0
                    ? sprintf('%d signals exist for this organization, but none records the department it concerns, so none can be attributed to this unit.', $orgTotal)
                    : 'No signal has been raised anywhere in this organization yet.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $tenantTotals
     * @param  array<string, mixed>  $support
     * @return array<string, mixed>
     */
    private function evidence(array $m, array $tenantTotals, array $support): array
    {
        $supported = (bool) ($support['evidence'] ?? false);
        $orgTotal = (int) ($tenantTotals['evidenceTotal'] ?? 0);

        return [
            'supported' => $supported,
            'organizationTotal' => $orgTotal,
            'total' => (int) ($m['evidenceCount'] ?? 0),
            'reason' => $supported
                ? null
                : ($orgTotal > 0
                    ? sprintf('%d evidence records exist for this organization. Evidence reaches a department through the signal it supports, and no signal here names one.', $orgTotal)
                    : 'No evidence has been recorded anywhere in this organization yet.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $tenantTotals
     * @param  array<string, mixed>  $support
     * @return array<string, mixed>
     */
    private function cases(array $m, array $tenantTotals, array $support): array
    {
        $supported = (bool) ($support['cases'] ?? false);
        $orgTotal = (int) ($tenantTotals['casesTotal'] ?? 0);

        return [
            'supported' => $supported,
            'organizationTotal' => $orgTotal,
            'total' => (int) ($m['casesTotal'] ?? 0),
            'open' => (int) ($m['casesOpen'] ?? 0),
            'reason' => $supported
                ? null
                : ($orgTotal > 0
                    ? sprintf('%d investigations exist for this organization, but none is attributed to a department.', $orgTotal)
                    : 'No investigation has been opened anywhere in this organization yet.'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $dimensions
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function health(?int $score, array $dimensions, array $m): array
    {
        if ($score === null) {
            return [
                'status' => 'unknown',
                'label' => 'Not measurable',
                'lines' => ['Nothing this model reads is recorded for this unit yet.'],
            ];
        }

        $status = $this->band($score);
        $lines = [];

        foreach ($dimensions as $d) {
            if ($d['score'] === null) {
                continue;
            }

            if ($d['score'] < 50) {
                $lines[] = sprintf('%s is weak at %d%%. %s', $d['label'], $d['score'], $d['basis']);
            }
        }

        if ($lines === []) {
            foreach ($dimensions as $d) {
                if ($d['score'] !== null && $d['score'] >= 85) {
                    $lines[] = sprintf('%s is strong at %d%%.', $d['label'], $d['score']);
                }
            }
        }

        if ($lines === []) {
            $lines[] = 'Every measurable dimension sits in the middle of its range.';
        }

        return [
            'status' => $status,
            'label' => match ($status) {
                'healthy' => 'Healthy',
                'good' => 'Good',
                'watch' => 'Watch',
                default => 'Needs attention',
            },
            'lines' => array_slice($lines, 0, 4),
        ];
    }

    /**
     * The narrative, generated from the figures rather than written for them.
     *
     * Every sentence names the number it came from, so a reader can check it
     * against the panels above. Nothing is emitted where the input is null —
     * an observation with a blank in it is worse than one fewer observation.
     *
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $performance
     * @param  array<string, mixed>  $workload
     * @param  array<string, mixed>  $contribution
     * @param  array<string, mixed>  $position
     * @return array<int, array{kind: string, text: string}>
     */
    private function narrative(array $m, array $performance, array $workload, array $contribution, array $position): array
    {
        $out = [];
        $pct = static fn (?float $v): string => $v === null ? '' : round($v * 100, 1).'%';

        // ---- observation
        if ($contribution['peopleShare'] !== null || $contribution['recordShare'] !== null) {
            $parts = [];

            if ($contribution['peopleShare'] !== null) {
                $parts[] = sprintf('%s of the recorded workforce', $pct($contribution['peopleShare']));
            }

            if ($contribution['recordShare'] !== null) {
                $parts[] = sprintf('%s of attributed operational activity', $pct($contribution['recordShare']));
            }

            $out[] = ['kind' => 'observation', 'text' => 'This unit accounts for '.implode(' and ', $parts).'.'];
        }

        if ($performance['completionRate'] !== null) {
            $out[] = [
                'kind' => 'observation',
                'text' => sprintf(
                    'It has completed %s of %s classified records, a completion rate of %s.',
                    number_format((int) $performance['completed']),
                    number_format((int) $performance['classified']),
                    $pct((float) $performance['completionRate']),
                ),
            ];
        }

        // ---- risk
        if ($workload['supported'] && $workload['total'] > 0) {
            $openShare = $workload['active'] / $workload['total'];

            if ($openShare >= self::BACKLOG_PRESSURE) {
                $out[] = [
                    'kind' => 'risk',
                    'text' => sprintf(
                        '%s of this unit\'s work is still open — %s of %s records — which is high enough that new work is arriving faster than it closes.',
                        $pct($openShare),
                        number_format((int) $workload['active']),
                        number_format((int) $workload['total']),
                    ),
                ];
            }
        }

        if (($m['signalsOpenHigh'] ?? 0) > 0) {
            $out[] = [
                'kind' => 'risk',
                'text' => sprintf('%d high-severity signals against this unit are still open.', (int) $m['signalsOpenHigh']),
            ];
        }

        if (($m['unclaimedWork'] ?? null) !== null) {
            $out[] = [
                'kind' => 'risk',
                'text' => sprintf(
                    'This unit holds the people while %s imported records are booked against "%s", a separate row on the register. Every measure of this unit\'s work is blind until the two are reconciled.',
                    number_format((int) $m['unclaimedWork']['records']),
                    (string) $m['unclaimedWork']['label'],
                ),
            ];
        }

        // ---- opportunity
        foreach (['peopleWithRole' => 'a role', 'peopleWithReference' => 'a reference', 'peopleWithContact' => 'contact details'] as $key => $label) {
            $have = $m[$key] ?? null;
            $people = (int) ($m['people'] ?? 0);

            if ($have !== null && $people > 0 && $have < $people) {
                $out[] = [
                    'kind' => 'opportunity',
                    'text' => sprintf('%s of %s people have no %s recorded. Completing it raises people coverage directly.', number_format($people - (int) $have), number_format($people), $label),
                ];
                break;
            }
        }

        if ($performance['perPerson'] !== null) {
            $out[] = [
                'kind' => 'opportunity',
                'text' => sprintf('Each person here carries %s records on average. Comparing that against peer units shows where capacity is unevenly loaded.', $performance['perPerson']),
            ];
        }

        // ---- trend
        $momentum = $performance['momentum'] ?? null;

        if (is_array($momentum) && ($momentum['changePercent'] ?? null) !== null) {
            $change = (float) $momentum['changePercent'];
            $out[] = [
                'kind' => 'trend',
                'text' => sprintf('Activity has %s %s%% against the previous period.', $change >= 0 ? 'risen' : 'fallen', number_format(abs($change), 1)),
            ];
        }

        if (($position['difference'] ?? null) !== null) {
            $diff = (int) $position['difference'];
            $out[] = [
                'kind' => 'trend',
                'text' => sprintf('Its intelligence score sits %d points %s the organization average.', abs($diff), $diff >= 0 ? 'above' : 'below'),
            ];
        }

        return $out;
    }

    /**
     * One thing to do next, chosen by what is most wrong.
     *
     * Ordered by consequence rather than by convenience: a split register makes
     * every other measure meaningless, so it outranks a backlog, which outranks
     * a missing roster field.
     *
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $performance
     * @param  array<string, mixed>  $workload
     * @param  array<int, array<string, mixed>>  $dimensions
     * @return array<string, mixed>
     */
    private function nextAction(array $m, array $performance, array $workload, array $dimensions): array
    {
        if (($m['unclaimedWork'] ?? null) !== null) {
            return [
                'title' => 'Reconcile this unit with the row carrying its work',
                'detail' => sprintf(
                    '%s records are booked against "%s" while the people sit here. Until the source system records one row per department, this unit\'s execution and workload health cannot be measured at all.',
                    number_format((int) $m['unclaimedWork']['records']),
                    (string) $m['unclaimedWork']['label'],
                ),
                'target' => 'activity',
            ];
        }

        if ($workload['supported'] && $workload['total'] > 0 && ($workload['active'] / $workload['total']) >= self::BACKLOG_PRESSURE) {
            return [
                'title' => 'Work down the open backlog',
                'detail' => sprintf('%s of %s records are open. Clearing the oldest first is what moves the completion rate.', number_format((int) $workload['active']), number_format((int) $workload['total'])),
                'target' => 'activity',
            ];
        }

        if ((int) ($m['signalsOpen'] ?? 0) > 0) {
            return [
                'title' => 'Resolve the open signals against this unit',
                'detail' => sprintf('%d signals are open, %d of them high severity.', (int) $m['signalsOpen'], (int) ($m['signalsOpenHigh'] ?? 0)),
                'target' => 'signals',
            ];
        }

        foreach ($dimensions as $d) {
            if ($d['score'] !== null && $d['score'] < 50) {
                return [
                    'title' => sprintf('Raise %s', lcfirst($d['label'])),
                    'detail' => $d['basis'],
                    'target' => 'people',
                ];
            }
        }

        $unmeasured = array_values(array_filter($dimensions, static fn ($d) => $d['score'] === null));

        if ($unmeasured !== []) {
            usort($unmeasured, static fn ($a, $b) => $b['weight'] <=> $a['weight']);

            return [
                'title' => sprintf('Start recording %s', lcfirst($unmeasured[0]['label'])),
                'detail' => $unmeasured[0]['basis'],
                'target' => 'people',
            ];
        }

        return [
            'title' => 'Hold the current position',
            'detail' => 'Every measurable dimension is within range and no signal is open against this unit.',
            'target' => 'activity',
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $performance
     * @param  array<string, mixed>  $workload
     * @return array<int, array<string, mixed>>
     */
    private function pulse(array $m, ?int $score, array $performance, array $workload): array
    {
        $people = (int) ($m['people'] ?? 0);

        return [
            ['key' => 'people', 'label' => 'People', 'value' => $people, 'format' => 'count',
             'reason' => $people === 0 ? 'No one is assigned to this unit in the source system.' : null],

            ['key' => 'activity', 'label' => 'Activity', 'value' => $performance['records'], 'format' => 'count',
             'reason' => $performance['reason']],

            ['key' => 'backlog', 'label' => 'Active workload', 'value' => $performance['backlog'], 'format' => 'count',
             'reason' => $performance['reason']],

            ['key' => 'completion', 'label' => 'Completion', 'value' => $performance['completionRate'], 'format' => 'rate',
             'reason' => $performance['completionRate'] === null ? 'Too few classified records for a completion rate.' : null],

            ['key' => 'perPerson', 'label' => 'Per person', 'value' => $performance['perPerson'], 'format' => 'decimal',
             'reason' => $performance['perPerson'] === null ? 'Needs both a headcount and attributed work.' : null],

            ['key' => 'score', 'label' => 'Intelligence', 'value' => $score, 'format' => 'score',
             'reason' => $score === null ? 'No dimension of this model is measurable for this unit.' : null],
        ];
    }
}
