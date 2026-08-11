<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * Where this organization stands, along the loop it is supposed to be turning.
 *
 * ONE DIMENSION PER STAGE OF THE ORGANIZATIONAL INTELLIGENCE LOOP. Not an
 * invented maturity ladder: the stages are Reality → Signals → Evidence →
 * Knowledge → Capability → Decision → Execution → Learning, which is the model
 * every document in this product is written against. Scoring the stages rather
 * than a list of best practices is what makes the result diagnostic — a low
 * Execution score with a high Decision score localises the break to one place.
 *
 * A DIMENSION WITH NO MEASURABLE INPUT SCORES NULL, NOT ZERO, and the overall
 * figure is the weighted mean over the dimensions that could be measured, with the
 * count of the others published beside it. This matters more here than anywhere
 * else in the engine: an organization that has never recorded an outcome would
 * score 0 on Learning and drag a composite down as if it had tried and failed. It
 * has not tried. Those are different diagnoses with different first actions, and a
 * composite that cannot tell them apart is worse than no composite.
 *
 * EVERY DIMENSION PUBLISHES ITS FACTORS. The score is a weighted blend of named
 * measurements, and the blend ships with the number. A single figure nobody can
 * decompose is a claim, not intelligence.
 */
final class OrganizationalStateAnalyzer
{
    /**
     * Loop stages, in order, with the weight each carries in the composite.
     *
     * Perceive and Understand are weighted slightly below Act and Learn on
     * purpose: an organization that observes beautifully and never closes a loop
     * has not achieved the thing this product is for. The weights are published in
     * the response so a reader can disagree with them arithmetically.
     *
     * @var array<string, array{label: string, weight: float, movement: string}>
     */
    private const DIMENSIONS = [
        'data'       => ['label' => 'Data foundation', 'weight' => 0.10, 'movement' => 'Perceive'],
        'perception' => ['label' => 'Perception',      'weight' => 0.10, 'movement' => 'Perceive'],
        'evidence'   => ['label' => 'Evidence',        'weight' => 0.15, 'movement' => 'Perceive'],
        'knowledge'  => ['label' => 'Knowledge',       'weight' => 0.15, 'movement' => 'Understand'],
        'capability' => ['label' => 'Capability',      'weight' => 0.10, 'movement' => 'Understand'],
        'decision'   => ['label' => 'Decision',        'weight' => 0.15, 'movement' => 'Act'],
        'execution'  => ['label' => 'Execution',       'weight' => 0.10, 'movement' => 'Act'],
        'learning'   => ['label' => 'Learning',        'weight' => 0.15, 'movement' => 'Learn'],
    ];

    /** At or above this, a dimension is reported as a strength. */
    private const STRENGTH = 0.65;

    /** Below this, a dimension is reported as a weakness. */
    private const WEAKNESS = 0.40;

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $knowledge
     * @param array<string, mixed> $capability
     * @param array<string, mixed> $decisions
     * @param array<string, mixed> $risks
     * @param array<string, mixed> $gaps
     *
     * @return array<string, mixed>
     */
    public function analyse(
        string $tenantId,
        array $profile,
        array $knowledge,
        array $capability,
        array $decisions,
        array $risks,
        array $gaps,
    ): array {
        $scores = [
            'data'       => $this->data($profile),
            'perception' => $this->perception($tenantId, $profile),
            'evidence'   => $this->evidence($knowledge),
            'knowledge'  => $this->knowledge($knowledge, $profile),
            'capability' => $this->capability($capability),
            'decision'   => $this->decision($decisions),
            'execution'  => $this->execution($profile, $decisions),
            'learning'   => $this->learning($profile),
        ];

        $dimensions = [];
        $weighted   = 0.0;
        $weight     = 0.0;

        foreach (self::DIMENSIONS as $key => $meta) {
            /** @var Confidence $confidence */
            $confidence = $scores[$key]['confidence'];
            $value      = $confidence->value();

            if ($value !== null) {
                $weighted += $meta['weight'] * $value;
                $weight   += $meta['weight'];
            }

            $dimensions[] = [
                'key'        => $key,
                'label'      => $meta['label'],
                'movement'   => $meta['movement'],
                'weight'     => $meta['weight'],
                'score'      => $value,
                'band'       => Confidence::band($value),
                'stage'      => $this->stage($value),
                'factors'    => $confidence->jsonSerialize()['components'],
                'unmeasured' => $confidence->unmeasured(),
                'why'        => $scores[$key]['why'],
                'blocking'   => $scores[$key]['blocking'] ?? null,
            ];
        }

        $overall = $weight <= 0.0 ? null : round($weighted / $weight, 4);

        $measured   = array_values(array_filter($dimensions, static fn (array $d): bool => $d['score'] !== null));
        $strengths  = array_values(array_filter($measured, static fn (array $d): bool => $d['score'] >= self::STRENGTH));
        $weaknesses = array_values(array_filter($measured, static fn (array $d): bool => $d['score'] < self::WEAKNESS));
        $unmeasured = array_values(array_filter($dimensions, static fn (array $d): bool => $d['score'] === null));

        usort($strengths, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        usort($weaknesses, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        return [
            'overall' => [
                'score'  => $overall,
                'band'   => Confidence::band($overall),
                'stage'  => $this->stage($overall),
                'weightMeasured'   => round($weight, 2),
                'weightUnmeasured' => round(array_sum(array_column(self::DIMENSIONS, 'weight')) - $weight, 2),
                'dimensionsMeasured'   => count($measured),
                'dimensionsUnmeasured' => count($unmeasured),
                'why' => $overall === null
                    ? 'Nothing this organization\'s state is built from could be measured.'
                    : 'Weighted mean over the '.count($measured).' of '.count(self::DIMENSIONS).' loop dimensions that could be measured, carrying '.round($weight * 100).'% of the intended weight. The '.count($unmeasured).' unmeasured dimension'.(count($unmeasured) === 1 ? '' : 's').' '.(count($unmeasured) === 1 ? 'is' : 'are').' excluded rather than scored zero, because never having tried is not the same as having failed.',
            ],
            'dimensions' => $dimensions,
            'byMovement' => $this->byMovement($dimensions),
            'strengths'  => $strengths,
            'weaknesses' => $weaknesses,
            'unmeasured' => $unmeasured,
            'headline'   => $this->headline($profile, $knowledge, $risks, $gaps, $dimensions),
            'method'     => [
                'dimensions' => 'One dimension per stage of the Organizational Intelligence Loop. Each is a weighted blend of named measurements over this organization\'s own records; the factors ship with every score.',
                'composite'  => 'Weighted mean over measurable dimensions only. A dimension with no input is null and drops out of both numerator and denominator, taking its weight with it.',
                'stages'     => 'Absent (<0.2) · Emerging (<0.4) · Developing (<0.6) · Established (<0.8) · Compounding (>=0.8). Names describe the loop, not a vendor maturity model.',
            ],
        ];
    }

    /* ─────────────────────────── the eight dimensions ─────────────────────────── */

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private function data(array $profile): array
    {
        $datasets = $profile['datasets'];
        $records  = (int) $profile['totals']['operationalRecords'];

        // Mean classifier completeness across every dataset, weighted by size so a
        // twelve-row summary table cannot outvote 65,000 complaints.
        $completenessWeighted = 0.0;
        $completenessWeight   = 0;
        $latest               = null;

        foreach ($datasets as $dataset) {
            $rows   = (int) $dataset['records'];
            $values = [];

            foreach (OrganizationDataProfiler::CLASSIFIERS as $field) {
                $f = $dataset['fields'][$field] ?? null;

                // Columns the source does not have at all are excluded. Scoring a
                // dataset down for lacking a column it was never going to carry
                // measures the schema, not the organization.
                if ($f === null || $f['nonNull'] === 0) {
                    continue;
                }

                $values[] = (float) $f['completeness'];
            }

            if ($values !== []) {
                $completenessWeighted += $rows * (array_sum($values) / count($values));
                $completenessWeight   += $rows;
            }

            if ($dataset['lastAt'] !== null && ($latest === null || $dataset['lastAt'] > $latest)) {
                $latest = $dataset['lastAt'];
            }
        }

        $sourceCount = count($profile['sourceSystems']);

        return [
            'why' => 'How much the organization has recorded, how completely, how recently, and through how many routes.',
            'confidence' => Confidence::build()
                ->add('volume', 0.30, Confidence::volumeAdequacy($records, 50000), number_format($records).' operational records across '.count($datasets).' dataset'.(count($datasets) === 1 ? '' : 's'))
                ->add('completeness', 0.35, $completenessWeight === 0 ? null : $completenessWeighted / $completenessWeight, 'size-weighted mean completeness of the classifier columns the sources actually populate')
                ->add('freshness', 0.20, Confidence::freshness($latest), $latest === null ? 'no dated record' : 'newest observation '.$this->ageDays($latest).' days old')
                ->add('routes', 0.15, $sourceCount === 0 ? null : min(1.0, $sourceCount / 3), $sourceCount.' distinct import route'.($sourceCount === 1 ? '' : 's').'; a single historical route means the picture cannot refresh itself'),
        ];
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private function perception(string $tenantId, array $profile): array
    {
        $signals = (int) ($profile['loop']['hpbrain_signals']['rows'] ?? 0);
        $records = (int) $profile['totals']['operationalRecords'];
        $lastAt  = $profile['loop']['hpbrain_signals']['lastAt'] ?? null;

        // How much of what arrived was ever looked at. Capped at 1: a tenant can
        // legitimately raise more signals than it has operational records, because
        // signals also come from sources this does not count.
        $attention = $records === 0 ? null : min(1.0, $signals / max(1, $records / 100));

        return [
            'why' => 'Whether the organization is noticing things at all, and how recently it last noticed one.',
            'blocking' => $signals === 0 ? 'No signal has ever been raised, so nothing downstream in the loop has an input.' : null,
            'confidence' => Confidence::build()
                ->add('volume', 0.40, Confidence::volumeAdequacy($signals, 500), number_format($signals).' signal'.($signals === 1 ? '' : 's').' raised')
                ->add('attention', 0.35, $attention, $records === 0 ? 'no operational records to compare against' : number_format($signals).' signals against '.number_format($records).' operational records, scored against a reference of one signal per hundred records')
                ->add('freshness', 0.25, Confidence::freshness($lastAt), $lastAt === null ? 'no signal has a date' : 'newest signal '.$this->ageDays($lastAt).' days old'),
        ];
    }

    /**
     * @param array<string, mixed> $knowledge
     *
     * @return array<string, mixed>
     */
    private function evidence(array $knowledge): array
    {
        $evidence = $knowledge['evidence'];
        $count    = (int) $evidence['evidence'];
        $bands    = $evidence['confidenceBands'];

        // Confidence that never varies carries no information, whatever its value.
        // A corpus stored entirely at 1.00 is not strong evidence; it is unweighted
        // evidence, and this is the component that says so.
        $discrimination = $count === 0 ? null : ($count < 50 ? null : min(1.0, (count($bands) - 1) / 2));

        return [
            'why' => 'Whether what the organization noticed is corroborated, dated, and weighted by anything.',
            'blocking' => $count === 0 ? 'No evidence exists, so no conclusion drawn for this organization can be traced to a source.' : null,
            'confidence' => Confidence::build()
                ->add('coverage', 0.35, $evidence['coverage'], $evidence['coverage'] === null ? 'no signals to cover' : number_format((int) $evidence['signalsCovered']).' of '.number_format((int) $evidence['signals']).' signals have at least one piece of evidence')
                ->add('volume', 0.20, Confidence::volumeAdequacy($count, 500), number_format($count).' evidence row'.($count === 1 ? '' : 's'))
                ->add('discrimination', 0.25, $discrimination, $count < 50 ? 'too few rows to judge whether confidence varies' : 'evidence confidence spans '.count($bands).' band'.(count($bands) === 1 ? ' — a single band means confidence was asserted rather than derived, so it cannot separate strong evidence from weak' : 's'))
                ->add('dating', 0.20, $count === 0 ? null : 1 - ((int) $evidence['undated'] / $count), number_format((int) $evidence['undated']).' of '.number_format($count).' rows carry no observation date, so their true age — and therefore their freshness weight — is unknown'),
        ];
    }

    /**
     * @param array<string, mixed> $knowledge
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private function knowledge(array $knowledge, array $profile): array
    {
        $state  = $knowledge['state'];
        $models = (int) ($profile['loop']['hpbrain_mental_models']['rows'] ?? 0);
        $blind  = count($knowledge['blindSpots']);
        $domains = (int) $state['domains'];

        return [
            'why' => 'How much of what the organization does is described by knowledge that was earned rather than asserted.',
            'confidence' => Confidence::build()
                ->add('domainConfidence', 0.40, $state['meanConfidence'], $state['meanConfidence'] === null ? 'no domain\'s confidence could be measured' : 'mean confidence '.number_format((float) $state['meanConfidence'], 2).' across '.$state['domainsMeasured'].' measurable domain'.($state['domainsMeasured'] === 1 ? '' : 's'))
                ->add('wellEarned', 0.25, $domains === 0 ? null : (int) $state['wellEarned'] / $domains, $state['wellEarned'].' of '.$domains.' domains are neither low-confidence nor thin on recurring patterns')
                ->add('written', 0.20, $models === 0 ? 0.0 : min(1.0, $models / 5), $models === 0 ? 'nothing has been written back as a mental model, so all knowledge here is re-derived on every read and none of it compounds' : $models.' mental model'.($models === 1 ? '' : 's').' recorded')
                ->add('blindSpots', 0.15, $domains === 0 ? null : max(0.0, 1 - ($blind / max(1, $domains * 3))), $blind.' blind spot'.($blind === 1 ? '' : 's').' detected across '.$domains.' domain'.($domains === 1 ? '' : 's')),
        ];
    }

    /**
     * @param array<string, mixed> $capability
     *
     * @return array<string, mixed>
     */
    private function capability(array $capability): array
    {
        if (($capability['measurable'] ?? false) !== true) {
            return [
                'why' => 'No capability has been defined for this organization.',
                'blocking' => 'Without capability definitions, no capability gap can be diagnosed and no KASBA state exists to advance.',
                'confidence' => Confidence::build()->add('definitions', 1.0, null, 'no capability is defined for this organization'),
            ];
        }

        // The capability analyzer already computes this exact blend, with its own
        // published components. Recomputing it here in different words would create
        // a second definition of capability maturity that could disagree with the
        // Capability screen — so the components are lifted rather than reinvented.
        $confidence = Confidence::build();

        foreach ($capability['confidence']['components'] as $component) {
            $confidence->add($component['key'], (float) $component['weight'], $component['value'], (string) $component['basis']);
        }

        $coverage = $capability['unitCoverage'];
        $confidence->add('structuralCoverage', 0.25, $coverage['unitShare'], $coverage['unitShare'] === null
            ? 'no unit count available from the system of record to measure coverage against'
            : $coverage['unitsCovered'].' of '.$coverage['units'].' organizational units have at least one capability assigned');

        return [
            'why' => 'How much of what the organization claims it can do has been verified rather than asserted, and how much of its structure is mapped at all.',
            'confidence' => $confidence,
        ];
    }

    /**
     * @param array<string, mixed> $decisions
     *
     * @return array<string, mixed>
     */
    private function decision(array $decisions): array
    {
        $state       = $decisions['state'];
        $quality     = $decisions['quality'];
        $total       = (int) $state['decisions'];
        $unevidenced = $quality['unevidenced'];

        return [
            'why' => 'Whether decisions are being taken, on evidence, promptly, and with their reasoning recorded.',
            'blocking' => $total === 0 ? 'No decision has been recorded, so nothing downstream — execution, outcome, learning — has an input.' : null,
            'confidence' => Confidence::build()
                ->add('throughput', 0.25, Confidence::volumeAdequacy($total, 100), $total.' decision'.($total === 1 ? '' : 's').' recorded')
                ->add('coverage', 0.25, $state['decisionCoverage'], $state['decisionCoverage'] === null ? 'no recommendations to decide on' : number_format((float) $state['decisionCoverage'] * 100, 1).'% of recommendations reached a decision in either direction')
                ->add('evidenceBacked', 0.30, ($unevidenced['total'] ?? 0) === 0 ? null : 1 - (float) ($unevidenced['share'] ?? 0), ($unevidenced['total'] ?? 0) === 0 ? 'no recommendations to check' : (int) ($unevidenced['evidenced'] ?? 0).' of '.(int) $unevidenced['total'].' recommendations have a traceable evidence path, as Architecture Invariant 1 requires')
                ->add('reasoned', 0.20, $total === 0 ? null : 1 - ((int) $quality['withoutRationale'] / $total), (int) $quality['withoutRationale'].' of '.$total.' decisions carry no rationale'),
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $decisions
     *
     * @return array<string, mixed>
     */
    private function execution(array $profile, array $decisions): array
    {
        $rows = static fn (string $table): int => (int) ($profile['loop'][$table]['rows'] ?? 0);

        $approved   = (int) $decisions['state']['pipeline']['approved'];
        $executions = $rows('hpbrain_eso_executions');
        $definitions = $rows('hpbrain_eso_definitions');
        $plans      = $rows('hpbrain_measurement_plans');

        return [
            'why' => 'Whether approved decisions turn into runnable, measured action.',
            'blocking' => $definitions === 0
                ? 'No executable objects are defined, so Architecture Invariant 3 — every recommendation bound to something runnable — cannot be satisfied for any recommendation.'
                : null,
            'confidence' => Confidence::build()
                ->add('bindable', 0.35, $definitions === 0 ? 0.0 : min(1.0, $definitions / 5), $definitions === 0 ? 'no executable objects are defined, so nothing can be bound to a recommendation' : $definitions.' executable object definition'.($definitions === 1 ? '' : 's'))
                ->add('executed', 0.40, $approved === 0 ? null : min(1.0, $executions / $approved), $approved === 0 ? 'no approved decision to execute against' : $executions.' execution'.($executions === 1 ? '' : 's').' against '.$approved.' approved decision'.($approved === 1 ? '' : 's'))
                ->add('measured', 0.25, $executions === 0 ? ($plans > 0 ? 1.0 : null) : min(1.0, $plans / $executions), $executions === 0 ? ($plans > 0 ? $plans.' measurement plan(s) exist ahead of any execution, which is the right order' : 'nothing has executed, so measurement coverage is not yet measurable') : $plans.' measurement plan'.($plans === 1 ? '' : 's').' against '.$executions.' execution'.($executions === 1 ? '' : 's').'; Architecture Invariant 4 requires the plan to exist first'),
        ];
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private function learning(array $profile): array
    {
        $rows = static fn (string $table): int => (int) ($profile['loop'][$table]['rows'] ?? 0);

        $outcomes  = $rows('hpbrain_outcomes');
        $learnings = $rows('hpbrain_learnings');
        $models    = $rows('hpbrain_mental_models');

        if ($outcomes === 0) {
            // Deliberately NOT scored zero. Every component is null, so Learning
            // comes back undetermined and takes its weight out of the composite.
            // The organization has not failed to learn; it has not yet closed a
            // loop, and the headline says exactly that.
            return [
                'why' => 'Whether measured results are written back so the next decision is better than the last.',
                'blocking' => 'No outcome has been recorded, so there is nothing to learn from yet. This is undetermined rather than zero — the loop has not been attempted, not attempted and failed.',
                'confidence' => Confidence::build()
                    ->add('outcomes',  0.40, null, 'no outcome has been recorded for this organization')
                    ->add('extracted', 0.35, null, 'a learning is extracted from an outcome, and there are none')
                    ->add('retained',  0.25, null, 'a mental model is reinforced by a reusable learning, and there are none'),
            ];
        }

        return [
            'why' => 'Whether measured results are written back so the next decision is better than the last.',
            'confidence' => Confidence::build()
                ->add('outcomes',  0.40, Confidence::volumeAdequacy($outcomes, 50), $outcomes.' outcome'.($outcomes === 1 ? '' : 's').' recorded')
                ->add('extracted', 0.35, min(1.0, $learnings / max(1, $outcomes)), $learnings.' learning'.($learnings === 1 ? '' : 's').' extracted from '.$outcomes.' outcome'.($outcomes === 1 ? '' : 's'))
                ->add('retained',  0.25, $learnings === 0 ? null : min(1.0, $models / max(1, $learnings)), $models.' mental model'.($models === 1 ? '' : 's').' hold what was learned'),
        ];
    }

    /* ─────────────────────────── readings ─────────────────────────── */

    /**
     * The four movements of the loop, each the mean of its stages.
     *
     * @param array<int, array<string, mixed>> $dimensions
     *
     * @return array<int, array<string, mixed>>
     */
    private function byMovement(array $dimensions): array
    {
        $groups = [];

        foreach ($dimensions as $d) {
            $groups[$d['movement']][] = $d;
        }

        $out = [];

        foreach (['Perceive', 'Understand', 'Act', 'Learn'] as $movement) {
            $members = $groups[$movement] ?? [];
            $scored  = array_values(array_filter($members, static fn (array $d): bool => $d['score'] !== null));
            $values  = array_map(static fn (array $d): float => (float) $d['score'], $scored);

            $out[] = [
                'movement'   => $movement,
                'score'      => $values === [] ? null : round(array_sum($values) / count($values), 4),
                'dimensions' => array_map(static fn (array $d): string => $d['label'], $members),
                'unmeasured' => count($members) - count($scored),
            ];
        }

        return $out;
    }

    /**
     * The two or three sentences a leader should read first.
     *
     * Assembled from measured figures only. Where a claim would need a figure the
     * organization has not recorded, the sentence is not written — the Consequence
     * layer is allowed to be short, and an invented sentence is indistinguishable
     * from a derived one.
     *
     * @param array<string, mixed>             $profile
     * @param array<string, mixed>             $knowledge
     * @param array<string, mixed>             $risks
     * @param array<string, mixed>             $gaps
     * @param array<int, array<string, mixed>> $dimensions
     *
     * @return array<int, string>
     */
    private function headline(array $profile, array $knowledge, array $risks, array $gaps, array $dimensions): array
    {
        $out = [];

        $records  = (int) $profile['totals']['operationalRecords'];
        $datasets = (int) $profile['totals']['datasets'];

        if ($records > 0) {
            $out[] = 'The organization holds '.number_format($records).' operational records across '.$datasets.' dataset'.($datasets === 1 ? '' : 's').', from which '.(int) $knowledge['state']['patterns'].' recurring patterns in '.(int) $knowledge['state']['domains'].' domains have been established.';
        }

        $unmeasured = array_values(array_filter($dimensions, static fn (array $d): bool => $d['score'] === null));

        if ($unmeasured !== []) {
            $labels = array_map(static fn (array $d): string => $d['label'], $unmeasured);
            $out[] = $this->list($labels)
                .(count($labels) === 1 ? ' cannot' : ' cannot').' be measured at all: '
                .implode(' ', array_filter(array_map(static fn (array $d): ?string => $d['blocking'], $unmeasured)))
                .' '.(count($labels) === 1 ? 'That dimension is' : 'Those dimensions are').' excluded from the composite rather than scored zero.';
        }

        $weakest = null;

        foreach ($dimensions as $d) {
            if ($d['score'] === null) {
                continue;
            }
            if ($weakest === null || $d['score'] < $weakest['score']) {
                $weakest = $d;
            }
        }

        if ($weakest !== null) {
            $out[] = 'The weakest measurable stage is '.$weakest['label'].' at '.number_format((float) $weakest['score'], 2).'. '.$weakest['why'];
        }

        if (($gaps['critical'] ?? 0) > 0) {
            $critical = (int) $gaps['critical'];
            $open     = (int) $risks['open'];
            $unowned  = (int) $risks['unowned'];

            $out[] = $critical.' gap'.($critical === 1 ? ' rates' : 's rate').' as critical and '
                .$open.' risk'.($open === 1 ? ' is' : 's are').' open'
                // Only claimed when it is true of all of them. The first version
                // asserted "none of them registered" unconditionally, which would
                // have been a false statement about an organization that had
                // registered some.
                .($unowned === $open && $open > 0
                    ? ', none of them registered to an owner.'
                    : ($unowned > 0 ? ', '.$unowned.' of them with no owner.' : ', each with an owner.'));
        }

        return $out;
    }

    /**
     * "A", "A and B", "A, B and C". Presentation only.
     *
     * @param array<int, string> $items
     */
    private function list(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    /**
     * Loop stage names, not a vendor maturity ladder.
     *
     * "Compounding" is the top rung on purpose: the product's thesis is that a
     * closed loop makes the organization smarter after every decision, and the
     * highest state is therefore not "optimised" but "getting better by itself".
     */
    private function stage(?float $score): string
    {
        if ($score === null) {
            return 'undetermined';
        }

        return match (true) {
            $score >= 0.80 => 'compounding',
            $score >= 0.60 => 'established',
            $score >= 0.40 => 'developing',
            $score >= 0.20 => 'emerging',
            default        => 'absent',
        };
    }

    private function ageDays(?string $timestamp): int
    {
        if ($timestamp === null) {
            return 0;
        }

        $t = strtotime($timestamp);

        return $t === false ? 0 : (int) max(0, floor((time() - $t) / 86400));
    }
}
