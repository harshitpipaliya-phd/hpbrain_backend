<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * WHAT THE DATA IS TELLING US — in sentences, from the numbers, with no model.
 *
 * WHY THIS IS DETERMINISTIC AND NOT A PROMPT. Everything else in this engine is
 * auditable arithmetic, and a paragraph written by a language model over the top of
 * it would be the one part of the screen nobody could check. Worse, it would be the
 * part people quote. A narrative that says "helpdesk volume rose 23% while
 * completion fell" must be true in the same sense the 23% is true — because the
 * same aggregate produced both — and the only way to guarantee that is to compose
 * the sentence from the figure rather than about it.
 *
 * So every narrative below is a template with measured values substituted in, and
 * every one is EMITTED ONLY WHEN ITS PRECONDITION HOLDS. There is no sentence that
 * appears regardless, no filler, and nothing that reads as insight without a
 * measurement behind it. An organization with three narratives gets three; the
 * screen does not pad to five.
 *
 * THE FIVE-PART SHAPE — what happened, why it matters, what is at risk, what to
 * investigate, what could improve — is carried on every finding rather than
 * imposed as five separate sections, because a reader scanning a list needs each
 * item to stand alone. A finding that cannot answer one of the parts leaves it
 * null; the client renders what is there.
 *
 * VOCABULARY COMES FROM THE ORGANIZATION, NOT FROM THIS FILE. Dataset labels,
 * category values, unit names and area names are substituted verbatim from what
 * was imported. That is what makes the output read as telecom for a fibre operator
 * and as academic for a school without a single branch on industry: the nouns are
 * the customer's own.
 */
final class OperationalNarrator
{
    /** Beyond this, one member holding this share is worth remarking on. */
    private const CONCENTRATION_SHARE = 0.4;

    /** A momentum change smaller than this is not reported as movement. */
    private const MOMENTUM = 0.15;

    /**
     * @param  array<string, mixed>  $ops  OperationalIntelligence::forTenant()
     * @param  array<string, mixed>  $loop  IntelligenceLoopMetrics::forTenant()
     * @param  array<string, mixed>  $scorecard  OrganizationScorecard::forTenant()
     * @return array<int, array<string, mixed>>
     */
    public function narrate(array $ops, array $loop, array $scorecard): array
    {
        $findings = array_merge(
            $this->scaleFinding($ops),
            $this->concentrationFindings($ops),
            $this->executionFindings($ops),
            $this->recurrenceFindings($ops),
            $this->turnaroundFindings($ops),
            $this->momentumFindings($ops),
            $this->geographyFindings($ops),
            $this->signalFindings($loop),
            $this->deliberationFindings($loop),
            $this->measurementGapFindings($scorecard),
        );

        /*
          RANKED BY HOW MUCH OF THE ORGANIZATION EACH ONE SPEAKS FOR, not by how
          alarming it sounds. `weight` is the record count, signal count or
          population the finding was derived over, so a pattern across fifteen
          thousand records outranks one across ninety — which is the ordering a
          reader would choose if they could see both denominators, and they cannot.
        */
        usort($findings, function (array $a, array $b): int {
            $severity = ['high' => 3, 'medium' => 2, 'low' => 1];

            return [$severity[$b['severity']] ?? 0, $b['weight']] <=> [$severity[$a['severity']] ?? 0, $a['weight']];
        });

        return $findings;
    }

    /* ─────────────────────────── the findings ─────────────────────────── */

    /**
     * @param  array<string, mixed>  $ops
     * @return array<int, array<string, mixed>>
     */
    private function scaleFinding(array $ops): array
    {
        $records = (int) ($ops['totals']['records'] ?? 0);

        if ($records <= 0) {
            return [];
        }

        $datasets = (int) $ops['totals']['datasets'];
        $span = $ops['totals']['spanDays'];
        $largest = $ops['rankings']['datasets'][0] ?? null;

        return [[
            'key' => 'scale',
            'severity' => 'low',
            'weight' => $records,
            'title' => number_format($records).' operational records across '.$datasets.' datasets are under analysis',
            'whatHappened' => number_format($records).' records have been ingested across '.$datasets.' distinct datasets'
                .($span !== null ? ', spanning '.$this->duration((int) $span).' of activity' : '')
                .($largest !== null ? ', the largest being '.$largest['name'].' at '.$this->pct((float) $largest['share']).' of the whole' : '').'.',
            'whyItMatters' => 'Every figure on this screen is an aggregate over these rows. The breadth of what is connected sets the ceiling on what can be observed.',
            'whatIsAtRisk' => null,
            'investigate' => null,
            'improve' => null,
        ]];
    }

    /**
     * @param  array<string, mixed>  $ops
     * @return array<int, array<string, mixed>>
     */
    private function concentrationFindings(array $ops): array
    {
        $out = [];

        $axes = [
            'departments' => ['unit', 'units', 'Workload is concentrated'],
            'categories' => ['category', 'categories', 'Activity is concentrated'],
            'zones' => ['area', 'areas', 'Activity is concentrated'],
        ];

        foreach ($axes as $axis => [$noun, $plural, $lead]) {
            $c = $ops['rankings']['concentration'][$axis] ?? null;
            $top = $ops['rankings'][$axis][0] ?? null;

            if ($c === null || ($c['supported'] ?? false) !== true || $top === null) {
                continue;
            }

            if ((float) $c['topShare'] < self::CONCENTRATION_SHARE || (int) $c['members'] < 3) {
                continue;
            }

            $out[] = [
                'key' => 'concentration.'.$axis,
                'severity' => (float) $c['topShare'] >= 0.6 ? 'medium' : 'low',
                'weight' => (int) $top['records'],
                'title' => $lead.' in '.$top['name'],
                'whatHappened' => $top['name'].' accounts for '.$this->pct((float) $top['share']).' of recorded activity '
                    .'across '.$c['members'].' '.$plural.', which is '.$c['band'].' on a Herfindahl index of '.$c['index'].'.',
                'whyItMatters' => 'A single '.$noun.' carrying most of the recorded work concentrates both the load and the risk: '
                    .'a disruption there affects the majority of what this organization does, and every organization-wide average is '
                    .'largely a description of that one '.$noun.'.',
                'whatIsAtRisk' => 'Organization-level rates read mostly as '.$top['name'].'\'s rates, so a problem confined to a smaller '
                    .$noun.' will not move them.',
                'investigate' => 'Compare '.$top['name'].'\'s completion and turnaround against the rest before treating the organization average as representative.',
                'improve' => null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $ops
     * @return array<int, array<string, mixed>>
     */
    private function executionFindings(array $ops): array
    {
        $execution = $ops['execution'] ?? [];

        if (($execution['supported'] ?? false) !== true) {
            return [];
        }

        $out = [];
        $completion = (float) $execution['completionRate'];
        $backlog = (int) $execution['backlog'];

        $out[] = [
            'key' => 'execution.completion',
            'severity' => $completion < 0.6 ? 'high' : ($completion < 0.8 ? 'medium' : 'low'),
            'weight' => (int) $execution['classified'],
            'title' => $this->pct($completion).' of classified work has reached a completed state',
            'whatHappened' => number_format((int) $execution['completed']).' of '.number_format((int) $execution['classified'])
                .' records whose status this engine can resolve are complete; '.number_format($backlog).' remain open or in progress'
                .((int) $execution['cancelled'] > 0 ? ' and '.number_format((int) $execution['cancelled']).' were cancelled' : '').'.',
            'whyItMatters' => 'Completion against recorded work is the closest thing this data holds to a delivery rate, and it is measured '
                .'over '.count($execution['contributingDatasets']).' datasets rather than asserted.',
            'whatIsAtRisk' => $backlog > 0
                ? number_format($backlog).' records represent work the organization has committed to and not yet closed.'
                : null,
            'investigate' => $completion < 0.8
                ? 'Break the backlog down by unit and by category to find whether it is spread or sitting in one place.'
                : null,
            'improve' => null,
        ];

        // Datasets whose completion is materially worse than the organization's.
        foreach (array_slice($ops['datasets'], 0, 12) as $dataset) {
            if (($dataset['execution']['supported'] ?? false) !== true) {
                continue;
            }

            $rate = (float) $dataset['execution']['completionRate'];

            if ($rate >= $completion - 0.2 || $dataset['records'] < 200) {
                continue;
            }

            $out[] = [
                'key' => 'execution.dataset.'.$dataset['dataset'],
                'severity' => 'medium',
                'weight' => (int) $dataset['records'],
                'title' => $dataset['label'].' completes at '.$this->pct($rate).', well below the organization\'s '.$this->pct($completion),
                'whatHappened' => $dataset['label'].' holds '.number_format((int) $dataset['records']).' records, of which '
                    .$this->pct($rate).' are complete against '.$this->pct($completion).' across the organization.',
                'whyItMatters' => 'A dataset that lags the organization by this margin is either worked differently or is not being closed out in the source system.',
                'whatIsAtRisk' => number_format((int) $dataset['execution']['backlog']).' records in this dataset are still open.',
                'investigate' => 'Check whether '.$dataset['label'].' records are genuinely open or simply never closed in the source system.',
                'improve' => null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $ops
     * @return array<int, array<string, mixed>>
     */
    private function recurrenceFindings(array $ops): array
    {
        $service = $ops['service'] ?? [];

        if (($service['supported'] ?? false) !== true || $service['highestRepeatDataset'] === null) {
            return [];
        }

        $worst = $service['highestRepeatDataset'];
        $rate = (float) $worst['repeatRate'];

        if ($rate < 0.1 || (int) $worst['subjects'] < 50) {
            return [];
        }

        return [[
            'key' => 'service.recurrence',
            'severity' => $rate >= 0.3 ? 'high' : 'medium',
            'weight' => (int) $worst['repeated'],
            'title' => $this->pct($rate).' of subjects in '.$worst['dataset'].' appear more than once',
            'whatHappened' => number_format((int) $worst['repeated']).' of '.number_format((int) $worst['subjects'])
                .' distinct subjects in '.$worst['dataset'].' have more than one record against them.',
            'whyItMatters' => 'A subject that comes back is work done twice. Repeat activity against the same subject is the clearest '
                .'signal in this data that a first attempt did not resolve the underlying condition.',
            'whatIsAtRisk' => 'Effort spent on repeats is effort not available for new work, and the underlying cause stays in place.',
            'investigate' => 'Take the subjects with the most records and check whether they share a category, an area or an owner.',
            'improve' => 'Resolving the recurring cause removes the repeats rather than the symptoms.',
        ]];
    }

    /**
     * @param  array<string, mixed>  $ops
     * @return array<int, array<string, mixed>>
     */
    private function turnaroundFindings(array $ops): array
    {
        $responsiveness = $ops['responsiveness'] ?? [];

        if (($responsiveness['supported'] ?? false) !== true) {
            return [];
        }

        $withinDay = (float) $responsiveness['withinDayRate'];

        $out = [[
            'key' => 'responsiveness',
            'severity' => $withinDay < 0.5 ? 'medium' : 'low',
            'weight' => (int) $responsiveness['measured'],
            'title' => $this->pct($withinDay).' of measurable work closes within a day',
            'whatHappened' => number_format((int) $responsiveness['measured']).' records carry both an opening and a later closing timestamp; '
                .$this->pct($withinDay).' of them closed inside 24 hours, with an average of '
                .$this->hours((float) $responsiveness['averageHours']).'.',
            'whyItMatters' => 'Elapsed time is measured only where the source records both ends of the work, so this speaks for that population and no more.',
            'whatIsAtRisk' => $withinDay < 0.5 ? 'More than half of closed work takes longer than a day, which is where service commitments are usually lost.' : null,
            'investigate' => null,
            'improve' => null,
        ]];

        // The slowest dataset with a meaningful population.
        $slowest = null;

        foreach ($responsiveness['byDataset'] as $row) {
            if ((int) $row['measured'] < 200) {
                continue;
            }

            if ($slowest === null || (float) $row['averageHours'] > (float) $slowest['averageHours']) {
                $slowest = $row;
            }
        }

        if ($slowest !== null && (float) $slowest['averageHours'] > (float) $responsiveness['averageHours'] * 1.5) {
            $out[] = [
                'key' => 'responsiveness.slowest',
                'severity' => 'medium',
                'weight' => (int) $slowest['measured'],
                'title' => $slowest['dataset'].' takes '.$this->hours((float) $slowest['averageHours']).' on average to close',
                'whatHappened' => $slowest['dataset'].' averages '.$this->hours((float) $slowest['averageHours'])
                    .' across '.number_format((int) $slowest['measured']).' measured records, against '
                    .$this->hours((float) $responsiveness['averageHours']).' organization-wide.',
                'whyItMatters' => 'This dataset is the organization\'s slowest measurable workflow by a wide margin.',
                'whatIsAtRisk' => 'Anything downstream of it inherits the delay.',
                'investigate' => 'Check whether the delay is in the work or in when the source system records the closure.',
                'improve' => null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $ops
     * @return array<int, array<string, mixed>>
     */
    private function momentumFindings(array $ops): array
    {
        $out = [];
        $overall = $ops['trend']['momentum'] ?? [];

        if (($overall['supported'] ?? false) === true && abs((float) $overall['change']) >= self::MOMENTUM) {
            $direction = (float) $overall['change'] > 0 ? 'risen' : 'fallen';

            $out[] = [
                'key' => 'momentum.total',
                'severity' => 'low',
                'weight' => (int) round((float) $overall['recentMonthlyAverage']),
                'title' => 'Recorded volume has '.$direction.' '.$this->pct(abs((float) $overall['change'])).' over the last quarter',
                'whatHappened' => 'The last three complete months averaged '.number_format((float) $overall['recentMonthlyAverage'])
                    .' records a month against '.number_format((float) $overall['priorMonthlyAverage']).' in the three before them.',
                'whyItMatters' => 'A shift of this size in recorded volume is either a change in the business or a change in what is being recorded, and the two need distinguishing.',
                'whatIsAtRisk' => null,
                'investigate' => 'Confirm whether an import covering a new period or a new source landed in that window before reading it as a business change.',
                'improve' => null,
            ];
        }

        foreach (array_slice($ops['trend']['byDataset'] ?? [], 0, 6) as $series) {
            $m = $series['momentum'] ?? [];

            if (($m['supported'] ?? false) !== true || abs((float) $m['change']) < 0.3) {
                continue;
            }

            $direction = (float) $m['change'] > 0 ? 'rising' : 'falling';

            $out[] = [
                'key' => 'momentum.'.$series['dataset'],
                'severity' => 'low',
                'weight' => (int) round((float) $m['recentMonthlyAverage']),
                'title' => $series['label'].' volume is '.$direction.' '.$this->pct(abs((float) $m['change'])).' quarter on quarter',
                'whatHappened' => $series['label'].' averaged '.number_format((float) $m['recentMonthlyAverage'])
                    .' records a month over the last three complete months, against '.number_format((float) $m['priorMonthlyAverage']).' before.',
                'whyItMatters' => 'Movement in one dataset while others hold steady points at a change in that specific workflow.',
                'whatIsAtRisk' => null,
                'investigate' => null,
                'improve' => null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $ops
     * @return array<int, array<string, mixed>>
     */
    private function geographyFindings(array $ops): array
    {
        $zones = $ops['rankings']['zones'] ?? [];

        if (count($zones) < 3) {
            return [];
        }

        $top = $zones[0];
        $evenShare = 1 / max(1, (int) ($ops['rankings']['concentration']['zones']['members'] ?? count($zones)));

        // Only worth saying when the leader carries materially more than an even
        // split would give it.
        if ((float) $top['share'] < $evenShare * 2.5) {
            return [];
        }

        return [[
            'key' => 'geography',
            'severity' => 'medium',
            'weight' => (int) $top['records'],
            'title' => $top['name'].' generates '.$this->pct((float) $top['share']).' of geographically tagged activity',
            'whatHappened' => $top['name'].' carries '.number_format((int) $top['records']).' records — '
                .$this->pct((float) $top['share']).' of everything tagged to an area, across '
                .($ops['rankings']['concentration']['zones']['members'] ?? count($zones)).' areas.',
            'whyItMatters' => 'Geographic concentration in operational records usually reflects either where the customer base is or where the '
                .'problems are, and those two readings call for opposite responses.',
            'whatIsAtRisk' => 'If the concentration is fault-driven rather than volume-driven, the same area will keep consuming capacity.',
            'investigate' => 'Compare this area\'s share of activity against its share of subjects; a mismatch separates a busy area from a troubled one.',
            'improve' => null,
        ]];
    }

    /**
     * @param  array<string, mixed>  $loop
     * @return array<int, array<string, mixed>>
     */
    private function signalFindings(array $loop): array
    {
        $signals = $loop['signals'] ?? [];

        if (($signals['supported'] ?? false) !== true) {
            return [];
        }

        $out = [];

        if ((int) $signals['highSeverityOpen'] > 0) {
            $out[] = [
                'key' => 'signals.high',
                'severity' => 'high',
                'weight' => (int) $signals['total'] * 1000,
                'title' => (int) $signals['highSeverityOpen'].' high-severity signal'
                    .((int) $signals['highSeverityOpen'] === 1 ? '' : 's').' remain open',
                'whatHappened' => (int) $signals['total'].' signals have been detected across '
                    .(int) $signals['distinctRules'].' distinct rules; '.(int) $signals['highSeverityOpen']
                    .' are open at high or critical severity.',
                'whyItMatters' => 'These are the detections the rule set rated most consequential, and none has been closed out.',
                'whatIsAtRisk' => 'The condition each one describes is still present in the data.',
                'investigate' => 'Start with the high-severity signals that already carry evidence — they need triage, not collection.',
                'improve' => null,
            ];
        }

        if ((int) $signals['ungrounded'] > 0) {
            $out[] = [
                'key' => 'signals.ungrounded',
                'severity' => 'medium',
                'weight' => (int) $signals['ungrounded'] * 100,
                'title' => (int) $signals['ungrounded'].' signal'.((int) $signals['ungrounded'] === 1 ? '' : 's')
                    .' cite no supporting record',
                'whatHappened' => (int) $signals['grounded'].' of '.(int) $signals['total']
                    .' signals cite at least one observed source record; the rest carry none.',
                'whyItMatters' => 'A signal with no evidence cannot be verified against the data it came from, so it cannot safely be acted on.',
                'whatIsAtRisk' => 'Acting on an ungrounded signal means acting on a rule, not on an observation.',
                'investigate' => 'Confirm whether the detector had rows to cite or simply did not record them.',
                'improve' => null,
            ];
        }

        $groundedWithEvidence = array_values(array_filter($signals['signals'] ?? [], fn ($s) => $s['grounded'] && ! $s['resolved']));

        if ($groundedWithEvidence !== []) {
            $names = array_slice(array_column($groundedWithEvidence, 'title'), 0, 3);

            $out[] = [
                'key' => 'signals.ready',
                'severity' => 'medium',
                'weight' => count($groundedWithEvidence) * 100,
                'title' => count($groundedWithEvidence).' open signal'.(count($groundedWithEvidence) === 1 ? '' : 's')
                    .' are grounded in evidence and ready to investigate',
                'whatHappened' => 'Open and evidenced: '.implode(', ', $names).(count($groundedWithEvidence) > 3 ? ', and others' : '').'.',
                'whyItMatters' => 'These are the detections where the supporting observations already exist, so the next step is judgement rather than data collection.',
                'whatIsAtRisk' => null,
                'investigate' => 'Open an investigation against each and record a hypothesis; the evidence is already attached.',
                'improve' => null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $loop
     * @return array<int, array<string, mixed>>
     */
    private function deliberationFindings(array $loop): array
    {
        $cases = $loop['cases'] ?? [];

        if (($cases['supported'] ?? false) !== true || (int) $cases['awaitingHypothesis'] === 0) {
            return [];
        }

        return [[
            'key' => 'cases.awaiting',
            'severity' => 'medium',
            'weight' => (int) $cases['awaitingHypothesis'] * 100,
            'title' => (int) $cases['awaitingHypothesis'].' investigation'.((int) $cases['awaitingHypothesis'] === 1 ? '' : 's')
                .' are open with no proposed cause',
            'whatHappened' => (int) $cases['total'].' investigations exist, averaging '
                .($cases['averageAgeDays'] ?? 0).' days old; '.(int) $cases['awaitingHypothesis'].' have not yet reached a hypothesis.',
            'whyItMatters' => 'An investigation without a hypothesis cannot produce a recommendation, so the loop stops here — '
                .'which is exactly why nothing downstream of it has any rows.',
            'whatIsAtRisk' => 'Detections are accumulating faster than they are being reasoned about.',
            'investigate' => 'Record a candidate cause on the oldest open investigation to move it forward.',
            'improve' => null,
        ]];
    }

    /**
     * @param  array<string, mixed>  $scorecard
     * @return array<int, array<string, mixed>>
     */
    private function measurementGapFindings(array $scorecard): array
    {
        $unmeasured = $scorecard['unmeasured'] ?? [];

        if ($unmeasured === []) {
            return [];
        }

        $labels = array_column($unmeasured, 'label');

        return [[
            'key' => 'coverage.unmeasured',
            'severity' => 'low',
            'weight' => 1,
            'title' => count($unmeasured).' intelligence dimension'.(count($unmeasured) === 1 ? '' : 's')
                .' cannot yet be measured from connected sources',
            'whatHappened' => implode(', ', $labels).' '.(count($labels) === 1 ? 'is' : 'are')
                .' excluded from the organization score because the connected data does not support '
                .(count($labels) === 1 ? 'it' : 'them').'.',
            'whyItMatters' => 'These are not failing scores — they are absent measurements. Scoring them as zero would invent a '
                .'failing grade out of data nobody has connected.',
            'whatIsAtRisk' => null,
            'investigate' => null,
            'improve' => $unmeasured[0]['nextStep'] ?? null,
        ]];
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    private function pct(float $ratio): string
    {
        return round($ratio * 100, 1).'%';
    }

    private function hours(float $hours): string
    {
        if ($hours < 48) {
            return round($hours, 1).' hours';
        }

        return round($hours / 24, 1).' days';
    }

    private function duration(int $days): string
    {
        if ($days < 90) {
            return $days.' days';
        }

        if ($days < 730) {
            return round($days / 30.44).' months';
        }

        return round($days / 365.25, 1).' years';
    }
}
