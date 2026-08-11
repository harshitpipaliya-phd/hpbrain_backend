<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * What is missing — named, sized, and attributable to a specific absence.
 *
 * A GAP IS NOT A LOW SCORE. It is a specific thing that does not exist: a column
 * nobody fills in, a critical capability nobody has verified, an outcome nobody
 * recorded, a signal nothing corroborates. That distinction is what makes a gap
 * closeable. "Evidence maturity is 41%" is a grade; "347 of 359 signals have
 * evidence and 12 do not" is a task.
 *
 * EVERY GAP CARRIES ITS OWN CLOSING CONDITION. `closedWhen` states what would have
 * to become true for the gap to disappear, in terms of records rather than
 * intentions. A gap that cannot state that has not been thought through, and a
 * screen full of gaps nobody can close is what makes readers stop opening it.
 *
 * SEVERITY IS REACH TIMES CONSEQUENCE, and both halves are stated. Reach is how
 * much of the organization the absence touches, measured. Consequence is how badly
 * the absence damages what can be concluded — and it is set per gap KIND from a
 * fixed table, not per instance, so it cannot be nudged to make a favourite gap
 * look urgent.
 *
 * REACH IS MEASURED AGAINST THE ORGANIZATION, NOT AGAINST ONE DATASET. This was
 * wrong in the first cut and the ranking it produced was actively misleading: a
 * `zone` column that is null on every attendance record scored reach 1.0 and
 * outranked two critical capabilities nobody has ever verified. Both statements
 * were true; only one of them was worth a manager's morning. A gap confined to a
 * dataset is now sized by that dataset's share of the organization's records, so a
 * missing column on twelve monthly summary rows sinks and a missing cause on 44,500
 * complaints rises. Gaps about the loop, the evidence base or the capability model
 * keep their own population, because those genuinely are organization-wide.
 */
final class GapDetector
{
    /**
     * How much each kind of absence damages what the organization can conclude.
     *
     * A fixed property of the KIND. The ordering is the argument: an absence that
     * makes a whole class of conclusion impossible (no outcomes at all, evidence
     * that cannot be traced) outranks one that degrades a figure (a column missing
     * on some rows), which outranks one that merely narrows coverage.
     *
     * @var array<string, float>
     */
    private const CONSEQUENCE = [
        'loop_never_closed'        => 1.00,
        'unevidenced_conclusions'  => 1.00,
        'asserted_confidence'      => 0.85,
        'unverified_critical_capability' => 0.85,
        'value_integrity'          => 0.80,
        'unrecorded_cause'         => 0.70,
        'state_without_evidence_ref' => 0.65,
        'no_conclusion_recorded'   => 0.60,
        'uncorroborated_signals'   => 0.55,
        'undecided_recommendations' => 0.50,
        'unowned_risk'             => 0.50,
        'never_recorded_field'     => 0.45,
        'no_variance_field'        => 0.45,
        'unattributed_work'        => 0.40,
        'capability_coverage'      => 0.40,
        'undated_evidence'         => 0.35,
        'no_reusable_knowledge'    => 0.35,
        'failed_import_rows'       => 0.30,
        'duplicate_records'        => 0.25,
    ];

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $knowledge
     * @param array<string, mixed> $capability
     * @param array<string, mixed> $decisions
     * @param array<string, mixed> $risks
     *
     * @return array<string, mixed>
     */
    public function detect(
        string $tenantId,
        array $profile,
        array $knowledge,
        array $capability,
        array $decisions,
        array $risks,
    ): array {
        // The denominator every dataset-scoped gap is sized against.
        $operational = max(1, (int) $profile['totals']['operationalRecords']);

        $gaps = array_merge(
            $this->loopGaps($tenantId, $profile, $decisions, $risks),
            $this->evidenceGaps($tenantId, $knowledge),
            $this->knowledgeGaps($knowledge, $operational),
            $this->capabilityGaps($capability),
            $this->dataQualityGaps($tenantId, $profile, $operational),
        );

        usort($gaps, static function (array $a, array $b): int {
            return [$b['severity'], $b['reach']] <=> [$a['severity'], $a['reach']];
        });

        $byArea = [];

        foreach ($gaps as $gap) {
            $byArea[$gap['area']] = ($byArea[$gap['area']] ?? 0) + 1;
        }

        return [
            'gaps'     => $gaps,
            'total'    => count($gaps),
            'critical' => count(array_filter($gaps, static fn (array $g): bool => $g['band'] === 'critical')),
            'high'     => count(array_filter($gaps, static fn (array $g): bool => $g['band'] === 'high')),
            'byArea'   => $byArea,
            'method'   => [
                'severity'    => 'severity = reach x consequence x 5, on a 0-5 scale. Reach is measured from the records. Consequence is fixed per gap kind, so it cannot be adjusted per instance.',
                'consequence' => 'Set by the kind of absence: absences that make a class of conclusion impossible rank above ones that degrade a figure, which rank above ones that narrow coverage.',
            ],
        ];
    }

    /* ─────────────────────────── the loop itself ─────────────────────────── */

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $decisions
     * @param array<string, mixed> $risks
     *
     * @return array<int, array<string, mixed>>
     */
    private function loopGaps(string $tenantId, array $profile, array $decisions, array $risks): array
    {
        $loop = $profile['loop'];
        $rows = static fn (string $table): int => (int) ($loop[$table]['rows'] ?? 0);

        $out = [];

        $decisionCount = (int) $decisions['state']['decisions'];
        $outcomes      = $rows('hpbrain_outcomes');

        if ($decisionCount > 0 && $outcomes === 0) {
            $out[] = $this->gap(
                kind: 'loop_never_closed',
                area: 'Intelligence loop',
                title: 'No outcome has ever been recorded',
                detail: $decisionCount.' decision'.($decisionCount === 1 ? ' has' : 's have').' been taken and no outcome recorded against any of them. Architecture Invariant 8 requires the loop to close; it has not closed once.',
                whyItMatters: 'Recommendation accuracy, decision quality and every learning figure derive from outcomes. Without one, they are undetermined rather than low — the organization cannot distinguish a good decision from a lucky one.',
                closedWhen: 'At least one row exists in hpbrain_outcomes with a decision_id, a result and a confidence.',
                reach: 1.0,
                reachBasis: 'all '.$decisionCount.' decisions are unmeasured',
                evidence: [
                    ['what' => 'decisions taken', 'count' => $decisionCount, 'table' => 'hpbrain_decisions'],
                    ['what' => 'outcomes recorded', 'count' => 0, 'table' => 'hpbrain_outcomes'],
                    ['what' => 'learnings extracted', 'count' => $rows('hpbrain_learnings'), 'table' => 'hpbrain_learnings'],
                ],
                confidence: $this->census('a row count over the outcome table for this organization'),
                tenantId: $tenantId,
                table: 'hpbrain_outcomes',
            );
        }

        $unevidenced = $decisions['quality']['unevidenced'];

        if (($unevidenced['unevidenced'] ?? 0) > 0) {
            $out[] = $this->gap(
                kind: 'unevidenced_conclusions',
                area: 'Intelligence loop',
                title: $unevidenced['unevidenced'].' of '.$unevidenced['total'].' recommendations have no evidence linked',
                detail: 'Architecture Invariant 1 requires a traceable path from every recommendation to the evidence that produced it. These recommendations have no row in hpbrain_recommendation_evidence, so following one leads nowhere.',
                whyItMatters: 'An unevidenced recommendation cannot be audited, challenged, or reproduced. It is an assertion, which is the thing this product exists to replace.',
                closedWhen: 'Every recommendation has at least one row in hpbrain_recommendation_evidence, or has been withdrawn.',
                reach: (float) ($unevidenced['share'] ?? 0),
                reachBasis: $unevidenced['unevidenced'].' of '.$unevidenced['total'].' recommendations',
                evidence: [
                    ['what' => 'recommendations without evidence', 'count' => (int) $unevidenced['unevidenced'], 'table' => 'hpbrain_recommendations'],
                    ['what' => 'recommendations with evidence', 'count' => (int) ($unevidenced['evidenced'] ?? 0), 'table' => 'hpbrain_recommendation_evidence'],
                ],
                confidence: $this->census('an exact join over every recommendation for this organization', (int) $unevidenced['total']),
                tenantId: $tenantId,
                table: 'hpbrain_recommendation_evidence',
            );
        }

        $coverage = $decisions['state']['decisionCoverage'];

        if ($coverage !== null && $coverage < 1.0) {
            $recommendations = (int) $decisions['state']['recommendations'];
            $undecided       = (int) round($recommendations * (1 - $coverage));

            if ($undecided > 0) {
                $out[] = $this->gap(
                    kind: 'undecided_recommendations',
                    area: 'Intelligence loop',
                    title: $undecided.' recommendation'.($undecided === 1 ? '' : 's').' never reached a decision',
                    detail: 'These recommendations were produced and then neither approved nor rejected. A proposal nobody answered is not a rejection; it is a queue.',
                    whyItMatters: 'Unanswered proposals make the acceptance rate describe only the subset somebody got to, and they hide a governance backlog behind a healthy-looking percentage.',
                    closedWhen: 'Every recommendation has a decision recorded against it, in either direction.',
                    reach: 1 - (float) $coverage,
                    reachBasis: $undecided.' of '.$recommendations.' recommendations have no decision',
                    evidence: [
                        ['what' => 'recommendations', 'count' => $recommendations, 'table' => 'hpbrain_recommendations'],
                        ['what' => 'with a decision', 'count' => $recommendations - $undecided, 'table' => 'hpbrain_decisions'],
                    ],
                    confidence: $this->census('an exists-join from recommendations to decisions', $recommendations),
                    tenantId: $tenantId,
                    table: 'hpbrain_decisions',
                );
            }
        }

        // Derived risks cannot be owned, because nothing was written down to own.
        if (($risks['derived'] ?? 0) > 0 && ($risks['registered'] ?? 0) === 0) {
            $out[] = $this->gap(
                kind: 'unowned_risk',
                area: 'Risk',
                title: $risks['derived'].' detected risks, none registered or owned',
                detail: 'Every risk on this organization\'s register was derived on read from its operational records. None has been written to hpbrain_risks, so none has an owner, a mitigation, or a review date.',
                whyItMatters: 'A derived risk is a finding. Until it is registered it cannot be assigned, tracked, or closed, and nothing distinguishes a risk somebody decided to accept from one nobody has seen.',
                closedWhen: 'The risks the organization accepts as real exist as rows in hpbrain_risks with an owner and a mitigation.',
                reach: 1.0,
                reachBasis: 'none of the '.$risks['derived'].' detected risks is registered',
                evidence: [
                    ['what' => 'risks detected', 'count' => (int) $risks['derived'], 'table' => 'derived'],
                    ['what' => 'risks registered', 'count' => 0, 'table' => 'hpbrain_risks'],
                ],
                confidence: $this->census('a row count over the risk register'),
                tenantId: $tenantId,
                table: 'hpbrain_risks',
            );
        }

        // Volume of operational data with nothing written back as reusable knowledge.
        $operational = (int) $profile['totals']['operationalRecords'];

        if ($operational >= 1000 && $rows('hpbrain_mental_models') === 0) {
            $out[] = $this->gap(
                kind: 'no_reusable_knowledge',
                area: 'Organizational memory',
                title: 'Nothing has been written back as reusable knowledge',
                detail: number_format($operational).' operational records have been ingested and no mental model exists. Everything this organization knows is currently re-derived from raw records on every read, and none of it has been stated, versioned or reinforced.',
                whyItMatters: 'Derived knowledge disappears when the query changes. A mental model is what lets a conclusion be reinforced by the next case rather than recomputed, and it is the mechanism by which the organization compounds.',
                closedWhen: 'At least one reusable Learning has been captured, which creates and then reinforces a mental model for its domain.',
                reach: 1.0,
                reachBasis: 'no mental model exists against '.number_format($operational).' operational records',
                evidence: [
                    ['what' => 'operational records', 'count' => $operational, 'table' => OrganizationDataProfiler::RECORDS],
                    ['what' => 'mental models', 'count' => 0, 'table' => 'hpbrain_mental_models'],
                    ['what' => 'reusable learnings', 'count' => $rows('hpbrain_learnings'), 'table' => 'hpbrain_learnings'],
                ],
                confidence: $this->census('row counts over the memory tables for this organization'),
                tenantId: $tenantId,
                table: 'hpbrain_mental_models',
            );
        }

        return $out;
    }

    /* ─────────────────────────── evidence ─────────────────────────── */

    /**
     * @param array<string, mixed> $knowledge
     *
     * @return array<int, array<string, mixed>>
     */
    private function evidenceGaps(string $tenantId, array $knowledge): array
    {
        $evidence = $knowledge['evidence'];
        $out      = [];

        $signals     = (int) $evidence['signals'];
        $uncovered   = (int) $evidence['signalsUncovered'];

        if ($signals > 0 && $uncovered > 0) {
            $out[] = $this->gap(
                kind: 'uncorroborated_signals',
                area: 'Evidence',
                title: $uncovered.' signal'.($uncovered === 1 ? '' : 's').' have nothing corroborating them',
                detail: 'The Brain noticed these and never established whether they were real. A signal with no evidence is an observation nobody checked.',
                whyItMatters: 'Reasoning over uncorroborated signals produces conclusions whose confidence cannot rise above the signal itself, and there is no way to tell a genuine early warning from noise.',
                closedWhen: 'Every signal has at least one row in hpbrain_evidence referencing it, or has been closed as noise.',
                reach: $uncovered / $signals,
                reachBasis: $uncovered.' of '.$signals.' signals',
                evidence: [
                    ['what' => 'signals', 'count' => $signals, 'table' => 'hpbrain_signals'],
                    ['what' => 'signals with evidence', 'count' => (int) $evidence['signalsCovered'], 'table' => 'hpbrain_evidence'],
                ],
                confidence: $this->census('distinct signal_id over the evidence table against the signal count', $signals),
                tenantId: $tenantId,
                table: 'hpbrain_evidence',
            );
        }

        $undated = (int) $evidence['undated'];
        $total   = (int) $evidence['evidence'];

        if ($total > 0 && $undated > 0) {
            $halfLife = (int) config('brain.evidence.freshness_half_life_days', 90);

            $out[] = $this->gap(
                kind: 'undated_evidence',
                area: 'Evidence',
                title: $undated.' of '.$total.' evidence rows carry no observation date',
                detail: 'The rows record when they were ingested but not when the thing they describe was observed, so their true age is unknown.',
                whyItMatters: 'Evidence freshness decays on a '.$halfLife.'-day half-life, and freshness weights every corroboration. Undated evidence has to be weighted by its ingestion date instead, which overstates how current it is when a historical file is loaded.',
                closedWhen: 'observed_date is populated on every evidence row, from the source record rather than from the import time.',
                reach: $undated / $total,
                reachBasis: $undated.' of '.$total.' evidence rows',
                evidence: [
                    ['what' => 'evidence rows with no observation date', 'count' => $undated, 'table' => 'hpbrain_evidence'],
                    ['what' => 'evidence rows', 'count' => $total, 'table' => 'hpbrain_evidence'],
                ],
                confidence: $this->census('a null count over the evidence table', $total),
                tenantId: $tenantId,
                table: 'hpbrain_evidence',
            );
        }

        $bands = $evidence['confidenceBands'];

        if ($total >= 50 && count($bands) === 1) {
            $band = array_key_first($bands);

            $out[] = $this->gap(
                kind: 'asserted_confidence',
                area: 'Evidence',
                title: 'Evidence confidence is asserted, not derived',
                detail: 'All '.number_format($total).' evidence rows fall in a single confidence band ('.$band.'), which means whatever wrote them set one value rather than deriving it from the source.',
                whyItMatters: 'Confidence is an input to every reasoning step downstream. A constant carries no information, so it cannot separate strong evidence from weak, and every confidence-weighted figure in the system is effectively unweighted.',
                closedWhen: 'Evidence confidence varies with source reliability and freshness at the point of ingestion.',
                reach: 1.0,
                reachBasis: 'all '.number_format($total).' evidence rows share one band',
                evidence: [
                    ['what' => 'evidence rows', 'count' => $total, 'table' => 'hpbrain_evidence'],
                    ['what' => 'distinct confidence bands', 'count' => 1, 'table' => 'hpbrain_evidence'],
                ],
                confidence: $this->census('a banded group-by over every evidence row', $total),
                tenantId: $tenantId,
                table: 'hpbrain_evidence',
            );
        }

        return $out;
    }

    /* ─────────────────────────── knowledge ─────────────────────────── */

    /**
     * Blind spots, promoted from observations into sized gaps.
     *
     * @param array<string, mixed> $knowledge
     *
     * @return array<int, array<string, mixed>>
     */
    private function knowledgeGaps(array $knowledge, int $operational): array
    {
        $out = [];

        foreach ($knowledge['blindSpots'] as $spot) {
            $kind = match ($spot['kind']) {
                'mostly_unrecorded' => 'unrecorded_cause',
                'no_variance'       => 'no_variance_field',
                'no_conclusion'     => 'no_conclusion_recorded',
                default             => 'never_recorded_field',
            };

            $out[] = $this->gap(
                kind: $kind,
                area: (string) $spot['area'],
                title: (string) $spot['title'],
                detail: (string) $spot['detail'],
                whyItMatters: match ($spot['kind']) {
                    'mostly_unrecorded' => 'Any analysis along this axis describes only the minority of work that was classified, and presents it as describing all of it.',
                    'no_variance'       => 'A column that cannot vary records no observation. It reads as complete data on every completeness check while carrying none.',
                    'no_conclusion'     => 'Without a closing date nothing in this dataset can show whether the work finished or how long it took, so no duration, throughput or backlog figure is available for it at all.',
                    default             => 'This work cannot be analysed along that axis at all, so any pattern that lives there is invisible rather than absent.',
                },
                closedWhen: $spot['kind'] === 'no_conclusion'
                    ? 'The source system supplies a closing timestamp for this dataset and ingestion maps it.'
                    : 'The `'.$spot['field'].'` column is populated at source for this dataset, with values that distinguish cases.',
                // Share of the ORGANIZATION's records, not of the dataset. The
                // dataset-relative share is retained in the basis string, because a
                // reader looking at one dataset still wants it.
                reach: (int) $spot['records'] / $operational,
                reachBasis: number_format((int) $spot['records']).' records affected — '
                    .number_format((float) $spot['share'] * 100, 1).'% of this dataset, '
                    .number_format(((int) $spot['records'] / $operational) * 100, 1).'% of the organization\'s operational records',
                evidence: [
                    ['what' => 'records affected', 'count' => (int) $spot['records'], 'table' => OrganizationDataProfiler::RECORDS],
                ],
                confidence: $this->census('a null and distinct-value count over every row in the dataset', (int) $spot['records']),
                tenantId: null,
                table: OrganizationDataProfiler::RECORDS,
                field: (string) $spot['field'],
            );
        }

        return $out;
    }

    /* ─────────────────────────── capability ─────────────────────────── */

    /**
     * @param array<string, mixed> $capability
     *
     * @return array<int, array<string, mixed>>
     */
    private function capabilityGaps(array $capability): array
    {
        if (($capability['measurable'] ?? false) !== true) {
            return [];
        }

        $out = [];
        $critical = $capability['criticalUnassessed'];

        if ((int) $critical['unverified'] > 0) {
            $names = implode(', ', array_map(static fn (array $i): string => $i['name'], array_slice($critical['items'], 0, 4)));

            $out[] = $this->gap(
                kind: 'unverified_critical_capability',
                area: 'Capability',
                title: $critical['unverified'].' of '.$critical['critical'].' critical capabilities have never been verified',
                detail: 'These capabilities are declared critical or high, and no proficiency state against them has advanced past Asserted: '.$names.'. Everything recorded about them is a claim.',
                whyItMatters: 'Architecture Invariant 6 requires the Brain to know how firmly it knows what it knows. A critical capability at Asserted means the organization is relying on something nobody has checked, and the level attached to it looks identical to a measured one.',
                closedWhen: 'Each critical capability has a proficiency record whose state is Assessed or above, with an evidence reference.',
                reach: (int) $critical['critical'] === 0 ? 0.0 : (int) $critical['unverified'] / (int) $critical['critical'],
                reachBasis: $critical['unverified'].' of '.$critical['critical'].' critical or high capabilities',
                evidence: array_merge(
                    [['what' => 'critical or high capabilities', 'count' => (int) $critical['critical'], 'table' => 'hpbrain_capabilities']],
                    array_map(static fn (array $i): array => ['what' => $i['name'].' — '.$i['why'], 'count' => (int) $i['proficiencyRecords'], 'table' => 'hpbrain_capability_proficiency'], array_slice($critical['items'], 0, 6)),
                ),
                confidence: $this->census('a join from capabilities through assignments to proficiency states', (int) $critical['critical']),
                tenantId: null,
                table: 'hpbrain_capability_proficiency',
            );
        }

        $proficiency = $capability['proficiency'];

        // States above Asserted claim a measurement, and CapabilityState demands an
        // evidenceRef for exactly that reason. Rows that advanced without one are a
        // structural inconsistency, not a data-entry omission.
        if ((int) $proficiency['evidenced'] > 0 && (int) $proficiency['withEvidenceRef'] === 0) {
            $out[] = $this->gap(
                kind: 'state_without_evidence_ref',
                area: 'Capability',
                title: $proficiency['evidenced'].' capability states claim evidence and reference none',
                detail: $proficiency['evidenced'].' proficiency records sit above Asserted — Inferred, Assessed, Demonstrated or Mastered — and not one carries an evidence_ref. The state asserts a measurement whose source cannot be produced.',
                whyItMatters: 'The six-state model exists so an assertion cannot be mistaken for a fact. A state above Asserted with no evidence reference defeats that: it is an assertion wearing the label of a measurement.',
                closedWhen: 'Every proficiency record above Asserted carries an evidence_ref to the assessment that justified it.',
                reach: 1.0,
                reachBasis: 'all '.$proficiency['evidenced'].' advanced states lack a reference',
                evidence: [
                    ['what' => 'states above Asserted', 'count' => (int) $proficiency['evidenced'], 'table' => 'hpbrain_capability_proficiency'],
                    ['what' => 'with an evidence reference', 'count' => 0, 'table' => 'hpbrain_capability_proficiency'],
                ],
                confidence: $this->census('a non-null count of evidence_ref against the state ladder', (int) $proficiency['assessed']),
                tenantId: null,
                table: 'hpbrain_capability_proficiency',
            );
        }

        $coverage = $capability['unitCoverage'];

        if ($coverage['unitShare'] !== null && $coverage['unitShare'] < 0.5) {
            $uncovered = (int) $coverage['units'] - (int) $coverage['unitsCovered'];

            $out[] = $this->gap(
                kind: 'capability_coverage',
                area: 'Capability',
                title: $uncovered.' of '.$coverage['units'].' organizational units have no capability assigned',
                detail: 'Capability is mapped onto '.$coverage['unitsCovered'].' unit'.((int) $coverage['unitsCovered'] === 1 ? '' : 's').' out of '.$coverage['units'].' in this tenant\'s system of record.',
                whyItMatters: 'A unit with no capability mapped cannot have a capability gap detected against it. Its readiness is not good or bad — it is unexamined, and it will never appear in a diagnosis.',
                closedWhen: 'Every unit that does work the organization reasons about has at least one capability assigned to it.',
                reach: 1 - (float) $coverage['unitShare'],
                reachBasis: $uncovered.' of '.$coverage['units'].' units',
                evidence: [
                    ['what' => 'units in the system of record', 'count' => (int) $coverage['units'], 'table' => 'system of record'],
                    ['what' => 'units with a capability assigned', 'count' => (int) $coverage['unitsCovered'], 'table' => 'hpbrain_capability_assignments'],
                ],
                confidence: $this->census('distinct assignment targets against the unit count in the system of record', (int) $coverage['units']),
                tenantId: null,
                table: 'hpbrain_capability_assignments',
            );
        }

        if ((int) $capability['unassigned']['count'] > 0) {
            $out[] = $this->gap(
                kind: 'capability_coverage',
                area: 'Capability',
                title: $capability['unassigned']['count'].' capabilities are defined and assigned to nobody',
                detail: 'These capabilities exist as definitions with no assignment to a person or a unit: '.implode(', ', array_map(static fn (array $i): string => $i['name'], array_slice($capability['unassigned']['items'], 0, 5))).'.',
                whyItMatters: 'An unassigned capability is a definition, not a capability. Nothing can be assessed against it and no gap can be found in it.',
                closedWhen: 'Each defined capability is assigned to at least one person or unit, or retired.',
                reach: (int) $capability['capabilities'] === 0 ? 0.0 : (int) $capability['unassigned']['count'] / (int) $capability['capabilities'],
                reachBasis: $capability['unassigned']['count'].' of '.$capability['capabilities'].' capabilities',
                evidence: [
                    ['what' => 'unassigned capabilities', 'count' => (int) $capability['unassigned']['count'], 'table' => 'hpbrain_capabilities'],
                ],
                confidence: $this->census('a not-exists join from capabilities to assignments', (int) $capability['capabilities']),
                tenantId: null,
                table: 'hpbrain_capability_assignments',
            );
        }

        return $out;
    }

    /* ─────────────────────────── data quality ─────────────────────────── */

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<int, array<string, mixed>>
     */
    private function dataQualityGaps(string $tenantId, array $profile, int $operational): array
    {
        $out = [];

        foreach ($profile['datasets'] as $dataset) {
            $records = (int) $dataset['records'];

            if ($records < 50) {
                continue;
            }

            $duplicates = (int) $dataset['duplicateKeys'];

            if ($duplicates > 0) {
                $out[] = $this->gap(
                    kind: 'duplicate_records',
                    area: (string) $dataset['label'],
                    title: number_format($duplicates).' '.strtolower((string) $dataset['label']).' records repeat a natural key',
                    detail: 'The dataset holds '.number_format($records).' rows across '.number_format($records - $duplicates).' distinct natural keys, so '.number_format($duplicates).' rows restate a subject already present.',
                    whyItMatters: 'Repeated keys inflate every count taken over the dataset. Whether they are genuine updates or re-imports changes what every figure here means, and nothing currently distinguishes the two.',
                    closedWhen: 'Either the repeats are collapsed at ingestion, or the dataset declares that a natural key legitimately recurs and counts are taken over distinct keys.',
                    reach: $duplicates / $operational,
                    reachBasis: number_format($duplicates).' of '.number_format($records).' rows in this dataset, out of '.number_format($operational).' operational records',
                    evidence: [
                        ['what' => 'rows', 'count' => $records, 'table' => OrganizationDataProfiler::RECORDS],
                        ['what' => 'distinct natural keys', 'count' => $records - $duplicates, 'table' => OrganizationDataProfiler::RECORDS],
                    ],
                    confidence: $this->census('COUNT(*) against COUNT(DISTINCT natural_key) over the dataset', $records),
                    tenantId: $tenantId,
                    table: OrganizationDataProfiler::RECORDS,
                );
            }

            $measure = $dataset['measure'];

            if ($measure !== null && (int) ($measure['negatives'] ?? 0) > 0) {
                $out[] = $this->gap(
                    kind: 'value_integrity',
                    area: (string) $dataset['label'],
                    title: (int) $measure['negatives'].' impossible '.($measure['unit'] ?? 'value').' measurement'.((int) $measure['negatives'] === 1 ? '' : 's').' on '.strtolower((string) $dataset['label']),
                    detail: 'The measured column holds negative durations — the source recorded work closing before it opened. The extreme value is '.number_format((float) $measure['min'], 1).' against a median of '.number_format((float) ($measure['median'] ?? 0), 1).'.',
                    whyItMatters: 'One impossible value is enough to move a mean by orders of magnitude. This system excludes them from trend fitting and says so, but any spreadsheet built from the same export will not.',
                    closedWhen: 'The affected timestamps are corrected at source and ingestion rejects negative durations.',
                    // NOT the row frequency, deliberately, and for the same reason
                    // RiskAnalyzer does not use it either: one impossible value in
                    // 65,268 gives a share of 0.000015, which put this gap at
                    // severity 0.00 — last place — for a condition that had already
                    // moved a monthly mean by three orders of magnitude. Reach here
                    // is the share of ANALYSES over this column that are
                    // compromised, and with one impossible value present that is
                    // all of them.
                    reach: 1.0,
                    reachBasis: 'every mean, trend and comparison over this column is affected by '
                        .(int) $measure['negatives'].' impossible value'.((int) $measure['negatives'] === 1 ? '' : 's')
                        .' among '.number_format((int) $measure['count']).' measurements',
                    evidence: [
                        ['what' => 'impossible values', 'count' => (int) $measure['negatives'], 'table' => OrganizationDataProfiler::RECORDS],
                        ['what' => 'measured values', 'count' => (int) $measure['count'], 'table' => OrganizationDataProfiler::RECORDS],
                    ],
                    confidence: $this->census('a sign check over every measured row in the dataset', (int) $measure['count']),
                    tenantId: $tenantId,
                    table: OrganizationDataProfiler::RECORDS,
                );
            }

            foreach (OrganizationDataProfiler::ACTORS as $field) {
                $f = $dataset['fields'][$field] ?? null;

                if ($f === null || $f['completeness'] === null || $f['nonNull'] === 0 || $f['completeness'] >= 0.8) {
                    continue;
                }

                $out[] = $this->gap(
                    kind: 'unattributed_work',
                    area: (string) $dataset['label'],
                    title: number_format((int) $f['nullCount']).' '.strtolower((string) $dataset['label']).' records name no '.str_replace('_name', '', $field),
                    detail: number_format((int) $f['nullCount']).' of '.number_format($records).' records leave `'.$field.'` empty, while '.number_format((int) $f['nonNull']).' name one of '.(int) $f['distinct'].' people.',
                    whyItMatters: 'Any per-person reading of this work — workload, dependency, capability evidence — describes only the attributed portion. A concentration that looks like 35% of the work could be 20% or 60% of it.',
                    closedWhen: '`'.$field.'` is populated on every record at source.',
                    reach: (int) $f['nullCount'] / $operational,
                    reachBasis: number_format((int) $f['nullCount']).' of '.number_format($records).' records in this dataset ('
                        .number_format((1 - (float) $f['completeness']) * 100, 1).'% of it), out of '.number_format($operational).' operational records',
                    evidence: [
                        ['what' => 'records with no attribution', 'count' => (int) $f['nullCount'], 'table' => OrganizationDataProfiler::RECORDS],
                        ['what' => 'records attributed', 'count' => (int) $f['nonNull'], 'table' => OrganizationDataProfiler::RECORDS],
                    ],
                    confidence: $this->census('a null count over every row in the dataset', $records),
                    tenantId: $tenantId,
                    table: OrganizationDataProfiler::RECORDS,
                    field: $field,
                );
            }
        }

        $failed = 0;

        foreach ($profile['sourceSystems'] as $source) {
            $failed += (int) $source['rowsFailed'];
        }

        if ($failed > 0) {
            $committed = array_sum(array_column($profile['sourceSystems'], 'rowsCommitted'));

            $out[] = $this->gap(
                kind: 'failed_import_rows',
                area: 'Ingestion',
                title: number_format($failed).' rows were rejected during import and never arrived',
                detail: number_format($failed).' rows failed against '.number_format((int) $committed).' committed across this organization\'s import jobs. Whatever they described is absent from every figure in this system.',
                whyItMatters: 'Rejected rows are a silent sample bias. If the failures cluster — one zone, one month, one category — then the dataset is not merely smaller than the source, it is differently shaped.',
                closedWhen: 'The error reports on the affected import jobs are resolved and the rows re-ingested, or the rejections are confirmed as legitimately out of scope.',
                reach: $committed + $failed <= 0 ? 0.0 : $failed / ($committed + $failed),
                reachBasis: number_format($failed).' of '.number_format((int) $committed + $failed).' rows offered',
                evidence: [
                    ['what' => 'rows rejected', 'count' => $failed, 'table' => 'hpbrain_import_jobs'],
                    ['what' => 'rows committed', 'count' => (int) $committed, 'table' => 'hpbrain_import_jobs'],
                ],
                confidence: $this->census('SUM(error_count) and SUM(success_count) over every import job for this organization', $failed + (int) $committed),
                tenantId: $tenantId,
                table: 'hpbrain_import_jobs',
            );
        }

        return $out;
    }

    /* ─────────────────────────── shape ─────────────────────────── */

    /**
     * @param array<int, array<string, mixed>> $evidence
     *
     * @return array<string, mixed>
     */
    private function gap(
        string $kind,
        string $area,
        string $title,
        string $detail,
        string $whyItMatters,
        string $closedWhen,
        float $reach,
        string $reachBasis,
        array $evidence,
        Confidence $confidence,
        ?string $tenantId,
        string $table,
        ?string $field = null,
    ): array {
        $reach       = max(0.0, min(1.0, $reach));
        $consequence = self::CONSEQUENCE[$kind] ?? 0.4;
        $severity    = round($reach * $consequence * 5, 2);

        return [
            'id'           => 'gap:'.$kind.':'.substr(hash('sha256', $area.'|'.$title), 0, 10),
            'kind'         => $kind,
            'area'         => $area,
            'title'        => $title,
            'detail'       => $detail,
            'whyItMatters' => $whyItMatters,
            'closedWhen'   => $closedWhen,
            'reach'        => round($reach, 4),
            'reachBasis'   => $reachBasis,
            'consequence'  => $consequence,
            'severity'     => $severity,
            'band'         => $this->band($severity),
            'evidence'     => $evidence,
            'confidence'   => $confidence->jsonSerialize(),
            'provenance'   => Provenance::of('severity = reach x consequence('.$consequence.') x 5')
                ->from(
                    $table,
                    array_filter(['tenant_id' => $tenantId, 'field' => $field, 'area' => $area], static fn ($v) => $v !== null),
                    (int) array_sum(array_column($evidence, 'count')),
                ),
        ];
    }

    private function band(float $severity): string
    {
        return match (true) {
            $severity >= 3.5 => 'critical',
            $severity >= 2.0 => 'high',
            $severity >= 1.0 => 'medium',
            default          => 'low',
        };
    }

    /**
     * A gap measured by counting every row, rather than by sampling.
     *
     * Three components, and the first cut had only two — which made every gap in
     * the system report exactly 0.88, a number that looked derived and was
     * effectively a constant. A confidence that never varies is decoration.
     *
     *   measurement     the count is exact, not sampled
     *   interpretation  whether the absence matters as much as the consequence
     *                   table says is a stated modelling judgement, and it is the
     *                   weaker half of every gap here
     *   basis           how many rows the finding rests on: a gap counted over
     *                   twelve monthly summaries deserves less belief than the
     *                   same gap counted over 65,000 complaints
     *
     * @param int|null $rowsCounted Population the finding was measured over. Null
     *                              where the finding is structural (a table is
     *                              empty) and there is no population to size.
     */
    private function census(string $measurement, ?int $rowsCounted = null): Confidence
    {
        return Confidence::build()
            ->add('measurement', 0.5, 1.0, $measurement)
            ->add('interpretation', 0.3, 0.7, 'the count is exact; the consequence weighting attached to this kind of absence is a stated modelling judgement, not a measurement')
            ->add(
                'basis', 0.2,
                $rowsCounted === null ? null : Confidence::volumeAdequacy($rowsCounted, 1000),
                $rowsCounted === null
                    ? 'structural finding — an absent table has no population to measure over'
                    : number_format($rowsCounted).' rows were examined to establish this',
            );
    }
}
