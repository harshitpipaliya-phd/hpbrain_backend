<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Eso\EsoCatalogue;

/**
 * What this organization should do next, and why, and what it would buy.
 *
 * EVERY RECOMMENDATION IS A DETECTED GAP OR RISK, TURNED AROUND. There is no
 * catalogue of good advice in this file. A recommendation exists because something
 * measurable was found missing or adverse, it inherits that finding's evidence and
 * confidence, and it dies when the finding does. That is the only construction that
 * satisfies Architecture Invariant 1 without a human curating a list.
 *
 * PRIORITY IS SEVERITY x CONFIDENCE x TRACTABILITY, and the third term is the one
 * most systems leave out. A severe, well-evidenced finding whose fix requires a
 * source-system change and a quarter of process work should not outrank a moderate
 * one that a single afternoon closes permanently. Tractability is set per gap kind
 * from a stated table, so it cannot be tuned per instance to reorder the list.
 *
 * BENEFIT IS LABELLED, ALWAYS. Observed, Estimated, Projected or Unknown — never a
 * bare figure. Where a current value and a closing condition are both measurable,
 * the delta is Estimated and both endpoints ship with it. Where the benefit is that
 * a class of conclusion becomes possible at all, it is Projected and says so. NO
 * MONETARY BENEFIT IS EVER PRODUCED: this organization records no cost, revenue or
 * penalty data, so a currency figure here could only be invented, and an invented
 * ROI is the single fastest way to destroy trust in everything beside it.
 */
final class RecommendationEngine
{
    /**
     * How readily each kind of finding can actually be closed.
     *
     * 1.0 means it can be closed inside this system by somebody who already has
     * access. Lower means it needs a source-system change, another team, or a
     * sustained change in behaviour — real work that no amount of severity makes
     * shorter.
     *
     * @var array<string, float>
     */
    private const TRACTABILITY = [
        'loop_never_closed'              => 1.00,
        'unowned_risk'                   => 0.95,
        'unevidenced_conclusions'        => 0.80,
        'undecided_recommendations'      => 0.90,
        'no_reusable_knowledge'          => 0.75,
        'unverified_critical_capability' => 0.70,
        'state_without_evidence_ref'     => 0.70,
        'capability_coverage'            => 0.65,
        'asserted_confidence'            => 0.55,
        'undated_evidence'               => 0.50,
        'uncorroborated_signals'         => 0.60,
        'value_integrity'                => 0.45,
        'failed_import_rows'             => 0.45,
        'duplicate_records'              => 0.40,
        'unrecorded_cause'               => 0.30,
        'unattributed_work'              => 0.30,
        'never_recorded_field'           => 0.25,
        'no_variance_field'              => 0.25,
        'no_conclusion_recorded'         => 0.20,
    ];

    /**
     * The benefit category each finding's closure delivers.
     *
     * Drawn from the categories the product can actually evidence. Deliberately
     * non-monetary throughout.
     *
     * @var array<string, string>
     */
    private const BENEFIT_CATEGORY = [
        'loop_never_closed'              => 'decision quality improvement',
        'unevidenced_conclusions'        => 'evidence completeness',
        'asserted_confidence'            => 'evidence completeness',
        'unverified_critical_capability' => 'capability improvement',
        'state_without_evidence_ref'     => 'capability improvement',
        'capability_coverage'            => 'coverage improvement',
        'value_integrity'                => 'operational consistency',
        'unrecorded_cause'               => 'coverage improvement',
        'no_conclusion_recorded'         => 'operational consistency',
        'uncorroborated_signals'         => 'evidence completeness',
        'undecided_recommendations'      => 'decision quality improvement',
        'unowned_risk'                   => 'risk reduction',
        'never_recorded_field'           => 'coverage improvement',
        'no_variance_field'              => 'coverage improvement',
        'unattributed_work'              => 'coverage improvement',
        'undated_evidence'               => 'evidence completeness',
        'no_reusable_knowledge'          => 'knowledge reinforcement',
        'failed_import_rows'             => 'evidence completeness',
        'duplicate_records'              => 'operational consistency',
    ];

    /**
     * Which of the four execution capabilities the action needs.
     *
     * Architecture Invariant 3 binds every recommendation to something runnable.
     * The TYPE of execution required is derivable from the kind of finding, and
     * naming it is what makes the binding mechanical rather than a fresh
     * judgement. Every recommendation therefore ships `esoType`.
     *
     * Whether it also ships an `esoId` depends on the organization's own
     * catalogue, read at composition time — see bindExecutables(). This table
     * used to be accompanied by a hardcoded `esoId: null` and a fixed sentence
     * claiming the organization had no ESO definitions, which was printed
     * unchanged to organizations that had several.
     *
     * @var array<string, string>
     */
    private const ESO_TYPE = [
        'loop_never_closed'              => 'Assessment',
        'unverified_critical_capability' => 'Assessment',
        'state_without_evidence_ref'     => 'Assessment',
        'unevidenced_conclusions'        => 'Workflow',
        'undecided_recommendations'      => 'Workflow',
        'unowned_risk'                   => 'Workflow',
        'value_integrity'                => 'Workflow',
        'failed_import_rows'             => 'Workflow',
        'duplicate_records'              => 'Workflow',
        'asserted_confidence'            => 'Workflow',
        'undated_evidence'               => 'Workflow',
        'capability_coverage'            => 'Workflow',
        'no_reusable_knowledge'          => 'Learning',
        'unrecorded_cause'               => 'Communication',
        'unattributed_work'              => 'Communication',
        'never_recorded_field'           => 'Communication',
        'no_variance_field'              => 'Communication',
        'no_conclusion_recorded'         => 'Communication',
        'uncorroborated_signals'         => 'Assessment',
    ];

    /**
     * Findings that cannot sensibly be closed before another is.
     *
     * Kept deliberately short. Every entry is a mechanical dependency, not a
     * preference about sequencing: a mental model is created by a reusable Learning,
     * which is extracted from an Outcome, so reusable knowledge genuinely cannot
     * come first. Inventing a fuller dependency graph would be modelling opinion as
     * structure.
     *
     * @var array<string, array<int, string>>
     */
    private const PREREQUISITES = [
        'no_reusable_knowledge'     => ['loop_never_closed'],
        'undecided_recommendations' => ['unevidenced_conclusions'],
    ];

    /**
     * @param array<string, mixed> $gaps
     * @param array<string, mixed> $risks
     * @param array<string, mixed> $state
     * @param array<string, mixed> $knowledge
     * @param EsoCatalogue|null    $catalogue this organization's executable objects,
     *                                        or null where no tenant context is
     *                                        available (an empty catalogue, which
     *                                        produces the honest "none exist" note)
     *
     * @return array<string, mixed>
     */
    public function recommend(array $gaps, array $risks, array $state, array $knowledge, ?EsoCatalogue $catalogue = null): array
    {
        $catalogue ??= EsoCatalogue::empty();

        $recommendations = [];

        foreach ($gaps['gaps'] as $gap) {
            $recommendations[] = $this->fromGap($gap, $state);
        }

        foreach ($this->topRisks($risks) as $risk) {
            $recommendations[] = $this->fromRisk($risk);
        }

        foreach ($this->learningOpportunities($knowledge) as $opportunity) {
            $recommendations[] = $opportunity;
        }

        // Resolve dependencies against what is actually in this list. A prerequisite
        // for a finding the organization does not have is not a dependency.
        $kindsPresent = [];

        foreach ($recommendations as $r) {
            if ($r['sourceKind'] !== null) {
                $kindsPresent[$r['sourceKind']] = $r['id'];
            }
        }

        foreach ($recommendations as &$recommendation) {
            $dependencies = [];

            foreach (self::PREREQUISITES[$recommendation['sourceKind']] ?? [] as $prerequisiteKind) {
                if (isset($kindsPresent[$prerequisiteKind])) {
                    $dependencies[] = ['recommendationId' => $kindsPresent[$prerequisiteKind], 'because' => $this->because($recommendation['sourceKind'], $prerequisiteKind)];
                }
            }

            $recommendation['dependencies'] = $dependencies;
        }
        unset($recommendation);

        usort($recommendations, static fn (array $a, array $b): int => $b['priorityScore'] <=> $a['priorityScore']);

        // Rank is assigned AFTER sorting and shipped, so "do this first" is a
        // position in one ordered list rather than a word four items share.
        foreach ($recommendations as $index => &$ranked) {
            $ranked['rank'] = $index + 1;
        }
        unset($ranked);

        $this->bindExecutables($recommendations, $catalogue);

        return [
            'recommendations' => $recommendations,
            'total'           => count($recommendations),
            'critical'        => count(array_filter($recommendations, static fn (array $r): bool => $r['priority'] === 'critical')),
            'firstAction'     => $recommendations === [] ? null : [
                'id'            => $recommendations[0]['id'],
                'recommendation' => $recommendations[0]['recommendation'],
                'why'           => $recommendations[0]['why'],
                'nextAction'    => $recommendations[0]['nextAction'],
            ],
            'method'          => [
                'priority'  => 'priorityScore = (severity / 5) x confidence x tractability. Severity and confidence are inherited from the finding; tractability is fixed per finding kind, so the ordering cannot be tuned per item.',
                'benefit'   => 'Labelled Observed, Estimated, Projected or Unknown. Estimated benefits ship both endpoints. No monetary benefit is produced anywhere, because this organization has recorded no cost, revenue or penalty data — a currency figure could only be invented.',
                'binding'   => $this->bindingMethodNote($catalogue),
                'noLlm'     => 'Every figure, ranking and category here is computed from the organization\'s records. No language model contributed to any of them.',
            ],
        ];
    }

    /* ─────────────────────────── from a gap ─────────────────────────── */

    /**
     * @param array<string, mixed> $gap
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function fromGap(array $gap, array $state): array
    {
        $kind         = (string) $gap['kind'];
        $tractability = self::TRACTABILITY[$kind] ?? 0.5;
        $confidence   = $gap['confidence']['value'];
        $severity     = (float) $gap['severity'];

        $priorityScore = round(($severity / 5) * ($confidence ?? 0.5) * $tractability, 4);

        return [
            'id'            => 'rec:'.$gap['id'],
            'source'        => 'gap',
            'sourceId'      => $gap['id'],
            'sourceKind'    => $kind,
            'recommendation' => $this->imperative($gap),
            'area'          => (string) $gap['area'],
            'why'           => (string) $gap['whyItMatters'],
            'finding'       => (string) $gap['title'],
            'evidence'      => $gap['evidence'],
            'confidence'    => $gap['confidence'],
            'severity'      => $severity,
            'tractability'  => $tractability,
            'priorityScore' => $priorityScore,
            'priority'      => $this->priorityBand($priorityScore),
            'urgency'       => $this->urgency($gap, $state),
            'benefit'       => $this->benefit($gap),
            'effort'        => $this->effort($gap),
            'esoType'       => self::ESO_TYPE[$kind] ?? 'Workflow',
            'nextAction'    => (string) $gap['closedWhen'],
            'dependencies'  => [],
            'provenance'    => $gap['provenance'],
        ];
    }

    /**
     * The gap's title, restated as something a person does.
     *
     * Built from the gap's own `closedWhen` and area rather than from a sentence
     * template per kind: the closing condition already states what has to become
     * true, and phrasing it as an instruction is a presentation step, not a new
     * claim. Nothing here adds information the gap did not carry.
     *
     * @param array<string, mixed> $gap
     */
    private function imperative(array $gap): string
    {
        return match ($gap['kind']) {
            'loop_never_closed'              => 'Close the loop once: record the outcome of a decision that has already run',
            'unevidenced_conclusions'        => 'Link evidence to every recommendation, or withdraw the ones that cannot produce it',
            'asserted_confidence'            => 'Derive evidence confidence at ingestion instead of defaulting it',
            'unverified_critical_capability' => 'Assess the critical capabilities that have only ever been asserted',
            'state_without_evidence_ref'     => 'Attach an evidence reference to every capability state above Asserted',
            'capability_coverage'            => 'Extend the capability model to the parts of the organization it does not reach',
            'value_integrity'                => 'Correct the impossible measurements in '.strtolower((string) $gap['area']).' and reject them at ingestion',
            'unrecorded_cause'               => 'Make the cause field mandatory at closure for '.strtolower((string) $gap['area']),
            'no_conclusion_recorded'         => 'Capture a closing timestamp for '.strtolower((string) $gap['area']),
            'uncorroborated_signals'         => 'Corroborate or close the signals that nothing supports',
            'undecided_recommendations'      => 'Answer the recommendations still waiting for a decision',
            'unowned_risk'                   => 'Register the detected risks the organization accepts as real, with an owner each',
            'unattributed_work'              => 'Require an owner on every '.strtolower((string) $gap['area']).' record',
            'undated_evidence'               => 'Populate the observation date on evidence from the source record',
            'no_reusable_knowledge'          => 'Capture one reusable learning so knowledge starts compounding',
            'failed_import_rows'             => 'Resolve the rejected import rows or confirm them as out of scope',
            'duplicate_records'              => 'Decide whether repeated natural keys in '.strtolower((string) $gap['area']).' are updates or re-imports, and collapse them if they are',
            'never_recorded_field'           => 'Start recording '.strtolower((string) ($gap['area'])).'\'s missing classifier, or confirm the source cannot supply it',
            'no_variance_field'              => 'Fix or retire the '.strtolower((string) $gap['area']).' column that only ever holds one value',
            default                          => 'Close: '.$gap['title'],
        };
    }

    /* ─────────────────────────── from a risk ─────────────────────────── */

    /**
     * The top derived risks, as recommendations to investigate them.
     *
     * Capped, and the cap is reported by the caller through `total`. Only risks
     * whose severity is meaningful are promoted — a register entry at severity 0.02
     * does not need to become an action item, and turning all of them into
     * recommendations is how an action list becomes wallpaper.
     *
     * @param array<string, mixed> $risks
     *
     * @return array<int, array<string, mixed>>
     */
    private function topRisks(array $risks): array
    {
        $eligible = array_values(array_filter(
            $risks['risks'],
            static fn (array $r): bool => $r['state'] !== 'mitigated'
                && $r['severity'] !== null
                && (float) $r['severity'] >= 1.0
                // Structural loop risks already arrive as gaps with fuller closing
                // conditions; promoting them twice would double the top of the list.
                && ! in_array($r['generator'], ['invariant_1', 'invariant_8', 'asserted_confidence', 'value_integrity'], true),
        ));

        return array_slice($eligible, 0, 6);
    }

    /**
     * @param array<string, mixed> $risk
     *
     * @return array<string, mixed>
     */
    private function fromRisk(array $risk): array
    {
        $confidence   = $risk['confidence']['value'];
        $severity     = (float) $risk['severity'];
        // A derived risk's tractability is the tractability of registering and
        // investigating it, which is high — the underlying condition may be hard to
        // fix, but the recommended action here is to establish and own it.
        $tractability = 0.85;

        $priorityScore = round(($severity / 5) * ($confidence ?? 0.5) * $tractability, 4);

        return [
            'id'            => 'rec:'.$risk['id'],
            'source'        => 'risk',
            'sourceId'      => $risk['id'],
            'sourceKind'    => null,
            'recommendation' => $risk['recommendedAction'],
            'area'          => (string) $risk['area'],
            'why'           => (string) $risk['detail'],
            'finding'       => (string) $risk['title'],
            'evidence'      => $risk['evidence'],
            'confidence'    => $risk['confidence'],
            'severity'      => $severity,
            'tractability'  => $tractability,
            'priorityScore' => $priorityScore,
            'priority'      => $this->priorityBand($priorityScore),
            'urgency'       => $risk['generator'] === 'adverse_trend' ? 'rising' : 'steady',
            'benefit'       => [
                'category'    => 'risk reduction',
                'label'       => 'Projected',
                'statement'   => 'Registering this risk makes it ownable: severity '.number_format($severity, 2).' of 5 becomes something with an owner, a mitigation and a review, instead of a finding recomputed on every read.',
                'currentValue' => null,
                'targetValue' => null,
                'unit'        => null,
                'why'         => 'Projected, not estimated: the size of the reduction depends on the mitigation chosen, and no mitigation has been recorded yet.',
            ],
            'effort'        => ['measurable' => false, 'basis' => 'Registering a risk is a single record. The mitigation behind it cannot be sized until it is chosen.'],
            'esoType'       => 'Workflow',
            'nextAction'    => 'Create the register entry with likelihood '.number_format((float) ($risk['likelihood'] ?? 0), 2).' and impact class '.($risk['impactBand'] ?? 'unknown').', assign an owner, then choose a mitigation.',
            'dependencies'  => [],
            'provenance'    => $risk['provenance'],
        ];
    }

    /* ─────────────────────────── from knowledge exposure ─────────────────────────── */

    /**
     * The domains worth strengthening, from the knowledge analyzer's own ranking.
     *
     * Exposure — volume times the shortfall in confidence — is already computed
     * there; this turns the top of that list into actions against the specific
     * confidence component holding each domain back, which is what makes the action
     * concrete rather than "improve this domain".
     *
     * @param array<string, mixed> $knowledge
     *
     * @return array<int, array<string, mixed>>
     */
    private function learningOpportunities(array $knowledge): array
    {
        $out   = [];
        $total = max(1, (int) $knowledge['state']['reinforcement']);

        foreach (array_slice($knowledge['learnNext'], 0, 3) as $opportunity) {
            $weakest = $opportunity['weakestComponent'];

            if ($weakest === null) {
                continue;
            }

            $confidence = $opportunity['confidence'];
            // Exposure normalised against the organization's own record base, so
            // this competes with gaps on a comparable 0-5 scale rather than on raw
            // record counts.
            $severity   = round(min(1.0, (int) $opportunity['exposure'] / $total) * 5, 2);
            $tractability = 0.5;

            $priorityScore = round(($severity / 5) * ($confidence ?? 0.5) * $tractability, 4);

            $out[] = [
                'id'            => 'rec:knowledge:'.$opportunity['key'],
                'source'        => 'knowledge',
                'sourceId'      => (string) $opportunity['key'],
                'sourceKind'    => null,
                'recommendation' => 'Strengthen what the organization knows about '.strtolower((string) $opportunity['domain']).', starting with '.$weakest['key'],
                'area'          => (string) $opportunity['domain'],
                'why'           => $opportunity['reason'].' The component holding it back is `'.$weakest['key'].'`: '.$weakest['basis'].'.',
                'finding'       => 'Knowledge exposure in '.$opportunity['domain'],
                'evidence'      => [
                    ['what' => 'records of work in this domain', 'count' => (int) $opportunity['records'], 'table' => OrganizationDataProfiler::RECORDS],
                    ['what' => 'exposure (records x confidence shortfall)', 'count' => (int) $opportunity['exposure'], 'table' => 'derived'],
                ],
                'confidence'    => ['value' => $confidence, 'band' => Confidence::band($confidence), 'components' => [], 'unmeasured' => []],
                'severity'      => $severity,
                'tractability'  => $tractability,
                'priorityScore' => $priorityScore,
                'priority'      => $this->priorityBand($priorityScore),
                'urgency'       => 'steady',
                'benefit'       => [
                    'category'     => 'knowledge reinforcement',
                    'label'        => 'Estimated',
                    'statement'    => 'Raising `'.$weakest['key'].'` on this domain would lift its confidence from '.($confidence === null ? 'undetermined' : number_format((float) $confidence, 2)).' toward the '.number_format((float) ($weakest['weight'] ?? 0) * 100, 0).'% of the score that component carries.',
                    'currentValue' => $confidence,
                    'targetValue'  => null,
                    'unit'         => 'confidence (0-1)',
                    'why'          => 'Estimated: the current value is measured and the weight of the component is known, but the achievable level depends on what the source system can be made to record.',
                ],
                'effort'        => ['measurable' => false, 'basis' => 'Depends on whether the weak component is a recording change, a process change, or simply more elapsed time.'],
                'esoType'       => 'Learning',
                'nextAction'    => 'Address `'.$weakest['key'].'` for this domain, then re-read this screen — the confidence is recomputed from records on every request, so the change will show without any manual update.',
                'dependencies'  => [],
                'provenance'    => Provenance::of('exposure = records x (1 - confidence), ranked; weakest component = largest weighted shortfall')
                    ->from(OrganizationDataProfiler::RECORDS, ['domain' => $opportunity['key']], (int) $opportunity['records']),
            ];
        }

        return $out;
    }

    /* ─────────────────────────── benefit, effort, priority ─────────────────────────── */

    /**
     * What closing the gap buys, labelled by how well the claim is supported.
     *
     * Estimated where a current value is measured and the closing condition defines
     * the target. Projected where the benefit is that something becomes possible at
     * all — those cannot carry a delta, because there is no current value to
     * improve on.
     *
     * @param array<string, mixed> $gap
     *
     * @return array<string, mixed>
     */
    private function benefit(array $gap): array
    {
        $category = self::BENEFIT_CATEGORY[$gap['kind']] ?? 'coverage improvement';
        $reach    = (float) $gap['reach'];

        // Findings where nothing currently exists: the benefit is that a class of
        // conclusion becomes available, and there is no baseline to express a delta
        // against. Projected, and explicitly so.
        $becomesPossible = [
            'loop_never_closed'     => 'Recommendation accuracy, decision quality and every learning figure become measurable for the first time. They are currently undetermined, not low.',
            'no_reusable_knowledge' => 'Knowledge starts compounding instead of being re-derived: a mental model can be reinforced by the next case rather than recomputed from raw records.',
            'unowned_risk'          => 'Detected risks become ownable, trackable and closable, and an accepted risk becomes distinguishable from an unseen one.',
            'no_conclusion_recorded' => 'Duration, throughput and backlog become measurable for this dataset. None of them is available at all today.',
        ];

        if (isset($becomesPossible[$gap['kind']])) {
            return [
                'category'     => $category,
                'label'        => 'Projected',
                'statement'    => $becomesPossible[$gap['kind']],
                'currentValue' => null,
                'targetValue'  => null,
                'unit'         => null,
                'why'          => 'Projected: the benefit is that a class of conclusion becomes possible. There is no current value to express an improvement against, and inventing one would be a fabrication.',
            ];
        }

        // Everything else has a measured shortfall, so the delta is the reach.
        $affected = (int) array_sum(array_column($gap['evidence'], 'count'));

        return [
            'category'     => $category,
            'label'        => 'Estimated',
            'statement'    => 'Closing this would bring '.number_format((float) ($reach * 100), 1).'% of the affected population back into scope: '.$gap['reachBasis'].'.',
            'currentValue' => round(1 - $reach, 4),
            'targetValue'  => 1.0,
            'unit'         => 'share of the affected population that is usable',
            'why'          => 'Estimated: both endpoints are measured from the records — the current share and the complete one. What is estimated is that the fix reaches all of the affected rows rather than some of them.',
            'affectedRecords' => $affected,
        ];
    }

    /**
     * Effort, only where the records genuinely size it.
     *
     * A record count is an honest unit of effort for a remediation that touches
     * rows. It is not one for a process change, and returning "3 days" for those
     * would be a guess wearing a number's clothes.
     *
     * @param array<string, mixed> $gap
     *
     * @return array<string, mixed>
     */
    private function effort(array $gap): array
    {
        $rowScoped = ['value_integrity', 'failed_import_rows', 'duplicate_records', 'uncorroborated_signals', 'undated_evidence', 'unevidenced_conclusions', 'undecided_recommendations', 'unverified_critical_capability', 'state_without_evidence_ref'];

        if (! in_array($gap['kind'], $rowScoped, true)) {
            return [
                'measurable' => false,
                'basis'      => 'This needs a change in what the source system records or in how people work, and neither is sizeable from row counts. No estimate is offered rather than a guessed one.',
            ];
        }

        $records = (int) array_sum(array_map(
            static fn (array $e): int => (int) $e['count'],
            array_slice($gap['evidence'], 0, 1),
        ));

        return [
            'measurable' => true,
            'unit'       => 'records to remediate',
            'value'      => $records,
            'basis'      => number_format($records).' record'.($records === 1 ? '' : 's').' need attention, from the gap\'s own evidence. This sizes the remediation, not the process change that stops it recurring.',
        ];
    }

    /**
     * How time-sensitive the finding is.
     *
     * `rising` only where a measured trend says the condition is getting worse.
     * Everything else is `steady` — a severe condition that is not deteriorating is
     * urgent in the sense of important, and conflating the two makes the word
     * useless.
     *
     * @param array<string, mixed> $gap
     * @param array<string, mixed> $state
     */
    private function urgency(array $gap, array $state): string
    {
        // A gap in a dimension that is blocking the loop entirely cannot wait for a
        // trend to confirm it: nothing downstream of it can move at all.
        foreach ($state['dimensions'] as $dimension) {
            if ($dimension['blocking'] !== null && $dimension['score'] === null && $gap['band'] === 'critical') {
                return 'blocking';
            }
        }

        return 'steady';
    }

    private function priorityBand(float $score): string
    {
        return match (true) {
            $score >= 0.60 => 'critical',
            $score >= 0.35 => 'high',
            $score >= 0.15 => 'medium',
            default        => 'low',
        };
    }

    /**
     * Bind every recommendation to a real executable object, where one exists.
     *
     * ONE WRITER. This is the only place `esoId` and `esoNote` are set. They
     * were previously written at three separate construction sites, each with
     * the same hardcoded null and the same fixed sentence asserting the
     * organization had no ESO definitions — a claim the code was in no position
     * to make, and which was false for every tenant that had authored any. With
     * one writer the three kinds of recommendation cannot describe the
     * catalogue differently.
     *
     * The match itself is EsoCatalogue's: an ESO whose own gap_types name this
     * recommendation's finding. Nothing here guesses.
     *
     * @param array<int, array<string, mixed>> $recommendations
     */
    private function bindExecutables(array &$recommendations, EsoCatalogue $catalogue): void
    {
        foreach ($recommendations as &$recommendation) {
            $recommendation += $catalogue->bindingFor(
                $recommendation['sourceKind'] ?? null,
                (string) ($recommendation['esoType'] ?? 'Workflow'),
            );
        }

        unset($recommendation);
    }

    /**
     * How binding worked on this read, stated against what the catalogue
     * actually holds rather than against an assumption baked in at authoring
     * time.
     */
    private function bindingMethodNote(EsoCatalogue $catalogue): string
    {
        $base = 'Each recommendation names the execution type it needs (Assessment, Learning, Workflow or Communication).';

        if ($catalogue->total() === 0) {
            return $base.' Binding to a specific executable object requires definitions in hpbrain_eso_definitions, and this organization has none.';
        }

        return $base.' It is bound to a specific executable object only where an ESO in this organization\'s catalogue ('
            .$catalogue->total().' defined, '.$catalogue->inServiceCount().' in service) declares that finding in its own gap types. '
            .'No match is inferred from wording or category, so an unbound recommendation means no ESO claims this finding — not that none could.';
    }

    private function because(?string $kind, string $prerequisite): string
    {
        return match ([$kind, $prerequisite]) {
            ['no_reusable_knowledge', 'loop_never_closed'] => 'A mental model is created and reinforced by a reusable Learning, and a Learning is extracted from an Outcome. Without one recorded outcome there is nothing for knowledge to be written back from.',
            ['undecided_recommendations', 'unevidenced_conclusions'] => 'Deciding on a recommendation whose evidence cannot be produced records an approval on trust, which is the condition the other finding is about.',
            default => 'Cannot be completed until the prerequisite finding is closed.',
        };
    }
}
