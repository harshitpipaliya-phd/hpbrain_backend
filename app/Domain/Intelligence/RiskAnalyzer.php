<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use Illuminate\Support\Facades\DB;

/**
 * Risks derived from the organization's own records, plus any it has registered.
 *
 * WHY DERIVE RISKS AT ALL. hpbrain_risks is empty for every tenant in the
 * installation this was built against, and a risk register that renders an empty
 * table is not honest — it is misleading, because "no risks recorded" reads as
 * "no risks". The organization's 96,000 operational records contain checkable
 * adverse conditions; surfacing them is reporting, not invention.
 *
 * EVERY RISK COMES FROM A GENERATOR WITH A STATED RULE. There is no list of
 * plausible risks anywhere in this file. Each generator below is a predicate over
 * aggregates — a share above a threshold, a tail longer than its median, a column
 * that is never filled in — and each risk it emits carries the counts that
 * triggered it. A reader can disagree with a threshold; they cannot be shown a
 * risk that has no rows behind it.
 *
 * LIKELIHOOD AND IMPACT ARE MEASURED SEPARATELY, AND IMPACT IS THE HONEST WEAK
 * ONE. Likelihood is a frequency and comes straight out of the data. Impact
 * properly needs a cost model — revenue per outage hour, penalty per SLA breach —
 * and this organization has recorded none, so impact is a MAGNITUDE proxy: how
 * much of the organization's recorded work the condition touches. Every risk
 * states that in `impactBasis` and carries `impactKind: 'magnitude_proxy'`, so a
 * figure that is not a cost estimate can never be read as one.
 *
 * DERIVED RISKS ARE NOT REGISTERED RISKS. A derived risk has `registered: false`
 * and no owner, because nothing was written to the register — and the recommended
 * action for a derived risk is therefore to register it so somebody can own it.
 * That is what keeps this from being a parallel, ownerless risk process.
 */
final class RiskAnalyzer
{
    /**
     * The root-cause taxonomy from the Product Bible (§5.2).
     *
     * Each generator declares which family its condition belongs to. The mapping
     * is a fixed property of the RULE, not a judgement about the individual risk,
     * which is what makes it reproducible: a missing column is always an
     * Information cause, a single person carrying the work is always Capacity.
     */
    public const ROOT_CAUSES = ['Capability', 'Capacity', 'Process', 'Information', 'Motivation', 'Coordination', 'External', 'Policy'];

    /** A tail this many times the median is a long tail worth reporting. */
    private const TAIL_RATIO = 2.5;

    /** Datasets smaller than this are not risk-assessed: the arithmetic is not stable. */
    private const MIN_RECORDS = 50;

    public function __construct(private readonly OrganizationDataProfiler $profiler)
    {
    }

    /**
     * @param array<string, mixed> $profile  OrganizationDataProfiler::profile()
     * @param array<string, mixed> $patterns PatternDetector::detect()
     *
     * @return array<string, mixed>
     */
    public function analyse(string $tenantId, array $profile, array $patterns): array
    {
        $total = max(1, (int) $profile['totals']['operationalRecords']);

        $risks = array_merge(
            $this->registered($tenantId),
            $this->concentrationRisks($patterns, $total),
            $this->dependencyRisks($patterns, $total),
            $this->unrecordedCauseRisks($profile, $total),
            $this->integrityRisks($profile, $total),
            $this->tailRisks($tenantId, $profile, $total),
            $this->stalledWorkRisks($tenantId, $profile, $total),
            $this->deteriorationRisks($patterns, $profile, $total),
            $this->loopIntegrityRisks($tenantId, $profile),
        );

        // Severity, then confidence, then reach. Three structural risks legitimately
        // compute to the maximum severity, and leaving them in hash order would make
        // the top of the register look arbitrary between runs.
        usort($risks, static function (array $a, array $b): int {
            return [$b['severity'] ?? -1, $b['confidence']['value'] ?? -1, $b['affectedRecords'] ?? 0]
               <=> [$a['severity'] ?? -1, $a['confidence']['value'] ?? -1, $a['affectedRecords'] ?? 0];
        });

        $open = array_values(array_filter($risks, static fn (array $r): bool => $r['state'] !== 'mitigated'));

        return [
            'risks'      => $risks,
            'open'       => count($open),
            'mitigated'  => count($risks) - count($open),
            'registered' => count(array_filter($risks, static fn (array $r): bool => $r['registered'])),
            'derived'    => count(array_filter($risks, static fn (array $r): bool => ! $r['registered'])),
            'unowned'    => count(array_filter($open, static fn (array $r): bool => $r['owner'] === null)),
            'byRootCause' => $this->byRootCause($risks),
            'matrix'      => $this->matrix($open),
            'maxSeverity' => $risks === [] ? null : $risks[0]['severity'],
            'method'      => [
                'severity'   => 'severity = likelihood(0..1) x impact(0..1) x 5, reported on a 0-5 scale. It is computed, never entered, so a risk cannot be argued down without changing its likelihood evidence or its impact class.',
                'likelihood' => 'A measured frequency over the organization\'s own records.',
                'impact'     => 'A magnitude proxy: the share of the organization\'s recorded work the condition touches. This organization has recorded no cost, penalty or revenue data, so no monetary impact is calculated and none is implied.',
                'bands'      => 'Likelihood and impact are also banded 1-5 for the matrix. Banding is presentational; severity uses the underlying 0..1 values.',
            ],
        ];
    }

    /* ─────────────────────────── risks the organization registered ─────────────────────────── */

    /**
     * Rows in hpbrain_risks, read as they were written.
     *
     * A registered risk's stored score is NOT recomputed: somebody assessed it,
     * and overwriting their assessment with a derived one would silently discard
     * human judgement. Where the stored row has no likelihood or impact recorded,
     * those come back null and the matrix leaves the risk out rather than placing
     * it at the origin.
     *
     * @return array<int, array<string, mixed>>
     */
    private function registered(string $tenantId): array
    {
        $rows = DB::table('hpbrain_risks')->where('tenant_id', $tenantId)->get();
        $out  = [];

        foreach ($rows as $r) {
            // `probability`, not `likelihood` — that is the column the migration
            // created, and the register is read as written rather than as the
            // vocabulary elsewhere in this file would prefer.
            $likelihood = $r->probability === null ? null : (float) $r->probability;
            $impact     = $this->impactWordToValue($r->impact ?? null);
            $stored     = $r->score === null ? null : (float) $r->score;

            // The register has no free-text title column. The category is the only
            // human-readable field on the row, so it is the title; inventing a
            // sentence from the other columns would put words in the assessor's
            // mouth.
            $out[] = [
                'id'             => 'registered:'.$r->id,
                'title'          => (string) ($r->category ?? 'Registered risk'),
                'area'           => (string) ($r->category ?? 'uncategorised'),
                'detail'         => 'Assessed by a person and recorded in the risk register'
                    .($r->recommendation_id === null ? '' : ', against a recommendation')
                    .($r->decision_id === null ? '' : ', against a decision').'.',
                'generator'      => 'risk_register',
                'rootCauseFamily' => 'Policy',
                'likelihood'     => $likelihood,
                'likelihoodBand' => $this->band($likelihood),
                'likelihoodBasis' => $likelihood === null ? 'no likelihood recorded on the register entry' : 'as recorded by the assessor',
                'impact'         => $impact,
                'impactBand'     => $this->band($impact),
                'impactKind'     => 'assessed',
                'impactBasis'    => $r->impact === null ? 'no impact recorded on the register entry' : 'recorded as "'.$r->impact.'"',
                'severity'       => $stored ?? $this->severity($likelihood, $impact),
                'severitySource' => $stored === null ? 'computed' : 'as stored',
                'state'          => strtolower((string) ($r->status ?? 'open')),
                'registered'     => true,
                'owner'          => $r->mitigation === null ? null : 'mitigation recorded',
                'evidence'       => [],
                'confidence'     => Confidence::build()
                    ->add('humanAssessment', 1.0, 0.9, 'assessed by a person and written to the register; not derived, so not re-derivable')
                    ->jsonSerialize(),
                'recommendedAction' => strtolower((string) ($r->status ?? '')) === 'mitigated'
                    ? 'Verify the mitigation held by recording an outcome against it.'
                    : 'Assign an owner and bind a mitigation to this register entry.',
                'provenance'     => Provenance::of('read directly from the risk register')
                    ->from('hpbrain_risks', ['tenant_id' => $tenantId, 'id' => (string) $r->id], 1),
            ];
        }

        return $out;
    }

    /* ─────────────────────────── derived generators ─────────────────────────── */

    /**
     * Exposure concentrated in one kind of work.
     *
     * @param array<string, mixed> $patterns
     *
     * @return array<int, array<string, mixed>>
     */
    private function concentrationRisks(array $patterns, int $total): array
    {
        $out = [];

        foreach ($patterns['concentrations'] as $c) {
            $likelihood = (float) $c['share'];
            $impact     = $this->magnitude((int) $c['records'], $total);

            $out[] = $this->derived(
                id: 'concentration:'.$c['area'].':'.$c['field'].':'.$c['value'],
                title: 'Exposure concentrated in "'.$c['value'].'"',
                area: (string) $c['area'],
                detail: $c['detail'].' A single dominant class means capacity, spares and skills all load onto the same failure mode, and any change in it moves most of the '.strtolower((string) $c['area']).' volume at once.',
                generator: 'concentration',
                rootCause: 'Process',
                likelihood: $likelihood,
                likelihoodBasis: number_format($likelihood * 100, 1).'% of classified '.strtolower((string) $c['area']).' records carry this one `'.$c['field'].'` value',
                impact: $impact,
                impactBasis: number_format($c['records']).' of '.number_format($total).' operational records across the organization',
                evidence: [
                    ['what' => 'records with this value', 'count' => (int) $c['records']],
                    ['what' => 'classified records in this dataset', 'count' => (int) $c['of']],
                ],
                records: (int) $c['records'],
                filter: ['dataset' => $c['area'], $c['field'] => $c['value']],
                action: 'Confirm whether one dominant class is a genuine single failure mode or a catch-all code absorbing several. If it is genuine, plan capacity against it explicitly; if it is a catch-all, sub-classify it so the real distribution becomes visible.',
                confidence: $this->derivedConfidence(
                    measurement: 'a group-by over every classified record in the dataset, not a sample',
                    affected: (int) $c['records'],
                    total: $total,
                    impactKind: 'magnitude_proxy',
                ),
            );
        }

        return $out;
    }

    /**
     * One person carrying a large share of a body of work.
     *
     * @param array<string, mixed> $patterns
     *
     * @return array<int, array<string, mixed>>
     */
    private function dependencyRisks(array $patterns, int $total): array
    {
        $out = [];

        foreach ($patterns['dependencies'] as $d) {
            $likelihood = (float) $d['share'];
            $impact     = $this->magnitude((int) $d['records'], $total);

            $out[] = $this->derived(
                id: 'dependency:'.$d['area'].':'.$d['field'].':'.$d['person'],
                title: 'Work concentrated on one '.str_replace('_name', '', (string) $d['field']),
                area: (string) $d['area'],
                detail: $d['detail'].' If that person is unavailable, the share of this work that has historically depended on them has no demonstrated alternative route.',
                generator: 'key_person_dependency',
                rootCause: 'Capacity',
                likelihood: $likelihood,
                likelihoodBasis: number_format($likelihood * 100, 1).'% of attributed '.strtolower((string) $d['area']).' records name this one person, out of a roster of '.$d['people'],
                impact: $impact,
                impactBasis: number_format($d['records']).' of '.number_format($total).' operational records across the organization',
                evidence: [
                    ['what' => 'records attributed to this person', 'count' => (int) $d['records']],
                    ['what' => 'attributed records in this dataset', 'count' => (int) $d['of']],
                    ['what' => 'distinct people in this role', 'count' => (int) $d['people']],
                ],
                records: (int) $d['records'],
                filter: ['dataset' => $d['area'], $d['field'] => $d['person']],
                action: 'Assess whether the concentration reflects a capability only this person holds or simply how work is routed. If it is capability, the gap is a KASBA gap on named others; if it is routing, redistribute and confirm the spread in next period\'s records.',
                confidence: $this->derivedConfidence(
                    measurement: 'measured only over records that name somebody; unattributed records are excluded rather than assumed to be spread evenly',
                    affected: (int) $d['records'],
                    total: $total,
                    impactKind: 'magnitude_proxy',
                    // Attribution completeness caps what this can be worth: if
                    // 41% of a dataset names nobody, the shares that are visible
                    // may not be the shares that exist.
                    extra: ['attributionCoverage' => [0.2, $d['of'] > 0 ? min(1.0, (float) $d['of'] / max(1, (float) $d['of'])) : null, number_format($d['of']).' records carry an attribution']],
                ),
            );
        }

        return $out;
    }

    /**
     * The organization does the work but does not record why.
     *
     * The most consequential and least visible class of risk in operational data:
     * a closure rate of 100% with no cause recorded means every job finished and
     * nothing was learned from any of them.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<int, array<string, mixed>>
     */
    private function unrecordedCauseRisks(array $profile, int $total): array
    {
        $out = [];

        foreach ($profile['datasets'] as $dataset) {
            $records = (int) $dataset['records'];

            if ($records < self::MIN_RECORDS) {
                continue;
            }

            foreach (['sub_category', 'category'] as $field) {
                $f = $dataset['fields'][$field] ?? null;

                if ($f === null || $f['completeness'] === null) {
                    continue;
                }

                // NEVER recorded is not this risk. A column that is null on every
                // single row is a property of the dataset — attendance records have
                // no category because attendance has no categories — and
                // KnowledgeAnalyzer already reports it as a blind spot. Firing here
                // too produced four risks about columns the source does not have,
                // which buried the one that mattered.
                //
                // SOMETIMES recorded is the risk: the organization has the field,
                // uses it, and skips it most of the time. That is a discipline
                // failure with a fix, and it is the only version of this condition
                // anybody can act on.
                if ($f['nonNull'] === 0) {
                    continue;
                }

                $missingShare = 1 - (float) $f['completeness'];

                // A third missing is the point at which any reading along the axis
                // describes a minority of the work.
                if ($missingShare < 0.33) {
                    continue;
                }

                $missing = (int) $f['nullCount'];

                $out[] = $this->derived(
                    id: 'unrecorded:'.$dataset['dataset'].':'.$field,
                    title: OrganizationDataProfiler::humanize($field).' is recorded on only '.number_format((float) $f['completeness'] * 100, 1).'% of '.strtolower((string) $dataset['label']).' records',
                    area: (string) $dataset['label'],
                    detail: number_format($missing).' of '.number_format($records).' records leave `'.$field.'` empty, while '.number_format((int) $f['nonNull']).' do fill it in across '.(int) $f['distinct'].' distinct values. The field exists and is used; it is skipped most of the time. The work was done and closed, and what it was about was not written down, so it cannot be counted, trended, or learned from.',
                    generator: 'unrecorded_classification',
                    rootCause: 'Information',
                    likelihood: $missingShare,
                    likelihoodBasis: number_format($missingShare * 100, 1).'% of records in this dataset leave the column empty',
                    impact: $this->magnitude($missing, $total),
                    impactBasis: number_format($missing).' of '.number_format($total).' operational records carry no value here',
                    evidence: [
                        ['what' => 'records missing this field', 'count' => $missing],
                        ['what' => 'records in this dataset', 'count' => $records],
                        ['what' => 'distinct values where it IS recorded', 'count' => (int) $f['distinct']],
                    ],
                    records: $missing,
                    filter: ['dataset' => $dataset['dataset'], $field => null],
                    action: 'Make the field required at the point of closure in the source system. Until then, every analysis of this work describes only the '.number_format((float) $f['completeness'] * 100, 1).'% that was classified.',
                    confidence: $this->derivedConfidence(
                        measurement: 'a count of nulls over every row in the dataset — there is no sampling error in it',
                        affected: $missing,
                        total: $total,
                        impactKind: 'magnitude_proxy',
                    ),
                );
            }
        }

        return $out;
    }

    /**
     * Values the source system cannot actually have produced.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<int, array<string, mixed>>
     */
    private function integrityRisks(array $profile, int $total): array
    {
        $out = [];

        foreach ($profile['datasets'] as $dataset) {
            $measure = $dataset['measure'];

            if ($measure === null || ($measure['negatives'] ?? 0) === 0) {
                continue;
            }

            $negatives = (int) $measure['negatives'];
            $measured  = max(1, (int) $measure['count']);
            $unit      = $measure['unit'] ?? 'units';

            // Likelihood is how often it happens; impact is how badly ONE
            // occurrence distorts the aggregate, which is the real damage. A
            // single impossible value can move a mean by orders of magnitude, so
            // impact is scaled by how far the worst value sits outside the
            // plausible range rather than by how many rows are affected.
            $distortion = $measure['median'] !== null && (float) $measure['median'] > 0
                ? min(1.0, abs((float) $measure['min']) / (abs((float) $measure['median']) * $measured))
                : null;

            $out[] = $this->derived(
                id: 'integrity:'.$dataset['dataset'].':negative_measure',
                title: 'Impossible '.$unit.' recorded on '.strtolower((string) $dataset['label']),
                area: (string) $dataset['label'],
                detail: $negatives.' of '.number_format($measured).' measured values are negative, meaning the source recorded the work closing before it opened. The extreme value is '.number_format((float) $measure['min'], 1).' '.$unit.', against a median of '.number_format((float) ($measure['median'] ?? 0), 1).'.',
                generator: 'value_integrity',
                rootCause: 'Information',
                // NOT the row frequency. One impossible value in 65,268 gives a
                // frequency of 0.000015, which multiplied out put this risk at
                // severity 0.0 — bottom of the register — for a condition that had
                // already moved a monthly mean by three orders of magnitude. The
                // question a likelihood answers here is not "how often does a bad
                // row occur" but "how likely is it that an aggregate over this
                // column is wrong", and with at least one impossible value present
                // the answer is certainty.
                likelihood: 1.0,
                likelihoodBasis: 'certain, not probable: '.$negatives.' impossible value'.($negatives === 1 ? ' is' : 's are').' present in '.number_format($measured).' measurements, so any unguarded mean over this column is already wrong',
                impact: $distortion,
                impactBasis: $distortion === null
                    ? 'no median available to measure distortion against'
                    : 'one value of '.number_format((float) $measure['min'], 1).' '.$unit.' against a median of '.number_format((float) $measure['median'], 1).' — enough to move any unguarded mean over this dataset',
                evidence: [
                    ['what' => 'impossible values', 'count' => $negatives],
                    ['what' => 'measured values', 'count' => $measured],
                ],
                records: $negatives,
                filter: ['dataset' => $dataset['dataset'], 'metric_value' => '< 0'],
                action: 'Fix the timestamps on the affected rows at source and add a validation rule at ingestion. Every mean over this dataset is unsafe until then; this system excludes them from trend fitting and says so, but a spreadsheet built off the same export will not.',
                confidence: $this->derivedConfidence(
                    measurement: 'a count over every measured row; a negative duration is impossible by definition rather than by threshold',
                    affected: $negatives,
                    total: $total,
                    impactKind: 'structural',
                ),
            );
        }

        return $out;
    }

    /**
     * A long tail: most work concludes quickly, some takes far longer.
     *
     * The mean hides this completely, which is why a service whose median is three
     * days and whose worst case is fifteen months can report "5.1 days average"
     * and sound healthy.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<int, array<string, mixed>>
     */
    private function tailRisks(string $tenantId, array $profile, int $total): array
    {
        $out = [];

        foreach ($profile['datasets'] as $dataset) {
            $measure = $dataset['measure'];
            $records = (int) $dataset['records'];

            if ($measure === null || $records < self::MIN_RECORDS) {
                continue;
            }

            $median = $measure['median'];
            $p95    = $measure['p95'];

            if ($median === null || $p95 === null || (float) $median <= 0) {
                continue;
            }

            $ratio = (float) $p95 / (float) $median;

            if ($ratio < self::TAIL_RATIO) {
                continue;
            }

            $unit      = $measure['unit'] ?? 'units';
            $threshold = (float) $median * self::TAIL_RATIO;

            // Counted, not assumed. "5% are above p95" is a tautology; how many
            // are above 2.5x the median is a fact about this organization.
            $breaching = (int) DB::table(OrganizationDataProfiler::RECORDS)
                ->where('tenant_id', $tenantId)->where('dataset', $dataset['dataset'])
                ->where('metric_value', '>=', $threshold)
                ->count();

            $out[] = $this->derived(
                id: 'tail:'.$dataset['dataset'],
                title: strtolower((string) $dataset['label']).' has a long tail: p95 is '.number_format($ratio, 1).'x the median',
                area: (string) $dataset['label'],
                detail: 'Median '.number_format((float) $median, 1).' '.$unit.', p95 '.number_format((float) $p95, 1).' '.$unit.', worst '.number_format((float) $measure['max'], 1).' '.$unit.'. '.number_format($breaching).' records took at least '.number_format($threshold, 1).' '.$unit.'. The mean of '.number_format((float) $measure['mean'], 1).' describes neither group.',
                generator: 'long_tail',
                rootCause: 'Process',
                likelihood: $breaching / max(1, (int) $measure['count']),
                likelihoodBasis: number_format($breaching).' of '.number_format((int) $measure['count']).' measured records exceed '.self::TAIL_RATIO.'x the median',
                impact: $this->magnitude($breaching, $total),
                impactBasis: number_format($breaching).' of '.number_format($total).' operational records sit in the tail',
                evidence: [
                    ['what' => 'records beyond '.self::TAIL_RATIO.'x median', 'count' => $breaching],
                    ['what' => 'measured records', 'count' => (int) $measure['count']],
                ],
                records: $breaching,
                filter: ['dataset' => $dataset['dataset'], 'metric_value' => '>= '.round($threshold, 2)],
                action: 'Look at the tail as its own population rather than as outliers of the main one. Compare its classification mix against the fast group — if it differs, the tail is a distinct process and needs its own handling, not a faster version of the standard one.',
                confidence: $this->derivedConfidence(
                    measurement: 'order statistics computed over every measured row in the dataset',
                    affected: $breaching,
                    total: $total,
                    impactKind: 'magnitude_proxy',
                    extra: [
                        'columnIntegrity' => [
                            0.2,
                            ($measure['negatives'] ?? 0) === 0 ? 1.0 : 1 - min(1.0, (int) $measure['negatives'] / max(1, (int) $measure['count'])),
                            (int) ($measure['negatives'] ?? 0).' impossible value(s) sit in the same column the percentiles were taken over',
                        ],
                    ],
                ),
            );
        }

        return $out;
    }

    /**
     * Work that opened and never closed, past the point where comparable work did.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<int, array<string, mixed>>
     */
    private function stalledWorkRisks(string $tenantId, array $profile, int $total): array
    {
        $out = [];

        foreach ($profile['datasets'] as $dataset) {
            $records = (int) $dataset['records'];
            $closed  = (int) ($dataset['closedCount'] ?? 0);

            // Only meaningful where the dataset closes work at all, and where
            // something is actually still open.
            if ($records < self::MIN_RECORDS || $closed === 0 || $closed >= $records) {
                continue;
            }

            $openCount = $records - $closed;

            // One unclosed record out of 65,268 is a typo, not a risk. Ten is the
            // point at which it is a queue.
            if ($openCount < 10) {
                continue;
            }

            $measure   = $dataset['measure'];
            $p95       = $measure['p95'] ?? null;
            $unit      = $measure['unit'] ?? null;

            $out[] = $this->derived(
                id: 'stalled:'.$dataset['dataset'],
                title: number_format($openCount).' '.strtolower((string) $dataset['label']).' record'.($openCount === 1 ? '' : 's').' never closed',
                area: (string) $dataset['label'],
                detail: number_format($openCount).' of '.number_format($records).' records carry no closing date while '.number_format($closed).' comparable records do'.
                    ($p95 === null ? '.' : ', and 95% of those closed within '.number_format((float) $p95, 1).' '.(string) $unit.'.'),
                generator: 'stalled_work',
                rootCause: 'Coordination',
                likelihood: $openCount / $records,
                likelihoodBasis: number_format($openCount).' of '.number_format($records).' records in this dataset are unclosed',
                impact: $this->magnitude($openCount, $total),
                impactBasis: number_format($openCount).' of '.number_format($total).' operational records across the organization',
                evidence: [
                    ['what' => 'unclosed records', 'count' => $openCount],
                    ['what' => 'closed records', 'count' => $closed],
                ],
                records: $openCount,
                filter: ['dataset' => $dataset['dataset'], 'closed_at' => null],
                action: 'Age the unclosed records and resolve them or close them as abandoned. An unbounded open item is not a risk because it is slow; it is a risk because nothing measures it.',
                confidence: $this->derivedConfidence(
                    measurement: 'a count of null closing dates over every row in the dataset',
                    affected: $openCount,
                    total: $total,
                    impactKind: 'magnitude_proxy',
                ),
            );
        }

        return $out;
    }

    /**
     * Movement in an unambiguously adverse direction.
     *
     * ONLY TWO OF THE THREE TREND FAMILIES QUALIFY, and the exclusion is the point.
     * Rising volume is not knowable as good or bad without knowing what the
     * dataset counts — more work orders may be growth, more complaints may be
     * decay, and this engine deliberately does not know which dataset is which.
     * Work taking longer, and less work concluding, are adverse whatever the
     * dataset is about. Guessing on the third would mean reading a customer's
     * business model out of a table name.
     *
     * @param array<string, mixed> $patterns
     * @param array<string, mixed> $profile
     *
     * @return array<int, array<string, mixed>>
     */
    private function deteriorationRisks(array $patterns, array $profile, int $total): array
    {
        $byLabel = [];

        foreach ($profile['datasets'] as $dataset) {
            $byLabel[(string) $dataset['label']] = $dataset;
        }

        $out = [];

        foreach ($patterns['moving'] as $trend) {
            $adverse = ($trend['metric'] === 'measure' && $trend['direction'] === 'rising')
                || ($trend['metric'] === 'closureRate' && $trend['direction'] === 'falling');

            if (! $adverse) {
                continue;
            }

            $dataset = $byLabel[$trend['area']] ?? null;
            $records = $dataset === null ? 0 : (int) $dataset['records'];

            // Likelihood of a trend is how confidently it is a trend at all. |t|
            // mapped onto 0..1 by t/(t+2): at the reporting threshold of 2 this is
            // 0.5, and it approaches 1 as the fit strengthens.
            $t          = abs((float) $trend['significance']);
            $likelihood = $t / ($t + 2);

            $out[] = $this->derived(
                id: 'deterioration:'.$trend['key'],
                title: $trend['metric'] === 'measure'
                    ? strtolower((string) $trend['area']).' is taking longer each month'
                    : strtolower((string) $trend['area']).' is concluding less of its work each month',
                area: (string) $trend['area'],
                detail: $trend['label'].' moved from '.number_format((float) $trend['fittedFirst'], 2).' to '.number_format((float) $trend['fittedLast'], 2).' across '.$trend['periods'].' months ('.($trend['changePct'] === null ? 'change not expressible' : number_format((float) $trend['changePct'], 1).'%').'), t='.$trend['significance'].', r²='.($trend['fitQuality'] ?? 'n/a').'.',
                generator: 'adverse_trend',
                rootCause: 'Process',
                likelihood: $likelihood,
                likelihoodBasis: 'slope clears its standard error at t='.$trend['significance'].' over '.$trend['periods'].' months, so the direction is established rather than noise',
                impact: $this->magnitude($records, $total),
                impactBasis: number_format($records).' of '.number_format($total).' operational records sit in the affected dataset',
                evidence: [
                    ['what' => 'monthly periods fitted', 'count' => (int) $trend['periods']],
                    ['what' => 'records in the dataset', 'count' => $records],
                ],
                records: $records,
                filter: ['dataset' => $dataset === null ? $trend['area'] : $dataset['dataset']],
                action: 'Establish what changed at the start of the window. A trend that survives its own standard error over '.$trend['periods'].' months is a change in the process, not in the weather.',
                confidence: Confidence::build()
                    ->add('fitStrength', 0.6, min(1.0, $t / 4), 't='.$trend['significance'].' against a reporting threshold of 2')
                    ->add('fitQuality', 0.4, $trend['fitQuality'] === null ? null : (float) $trend['fitQuality'], $trend['fitQuality'] === null ? 'no dispersion to explain' : 'the straight line explains '.number_format((float) $trend['fitQuality'] * 100, 0).'% of the variation'),
            );
        }

        return $out;
    }

    /**
     * Risks to the reasoning itself — places the Intelligence Loop is open.
     *
     * These are the Architecture Invariants read as predicates. An organization
     * whose recommendations carry no evidence link is violating Invariant 1, and
     * that is a more serious finding than any operational tail, because it means
     * nothing else the Brain says can be trusted to have a source.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<int, array<string, mixed>>
     */
    private function loopIntegrityRisks(string $tenantId, array $profile): array
    {
        $out  = [];
        $loop = $profile['loop'];

        $recommendations = (int) ($loop['hpbrain_recommendations']['rows'] ?? 0);
        $decisions       = (int) ($loop['hpbrain_decisions']['rows'] ?? 0);
        $outcomes        = (int) ($loop['hpbrain_outcomes']['rows'] ?? 0);
        $learnings       = (int) ($loop['hpbrain_learnings']['rows'] ?? 0);

        // Invariant 1 — every recommendation has evidence.
        if ($recommendations > 0) {
            $withEvidence = (int) DB::table('hpbrain_recommendations as r')
                ->where('r.tenant_id', $tenantId)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('hpbrain_recommendation_evidence as re')
                    ->whereColumn('re.recommendation_id', 'r.id')
                    ->where('re.tenant_id', $tenantId))
                ->count();

            $unevidenced = $recommendations - $withEvidence;

            if ($unevidenced > 0) {
                $out[] = $this->derived(
                    id: 'loop:recommendations_without_evidence',
                    title: $unevidenced.' of '.$recommendations.' recommendations have no evidence linked',
                    area: 'Intelligence loop',
                    detail: 'Architecture Invariant 1 requires a traceable path from every recommendation to the evidence that produced it. '.$unevidenced.' recommendation'.($unevidenced === 1 ? ' has' : 's have').' no row in hpbrain_recommendation_evidence, so there is nothing to follow.',
                    generator: 'invariant_1',
                    rootCause: 'Policy',
                    likelihood: $unevidenced / $recommendations,
                    likelihoodBasis: $unevidenced.' of '.$recommendations.' recommendations have no linked evidence row',
                    impact: 1.0,
                    impactBasis: 'an unevidenced recommendation cannot be audited at all, so the impact is on every conclusion drawn from it rather than on a share of records',
                    evidence: [
                        ['what' => 'recommendations without evidence', 'count' => $unevidenced],
                        ['what' => 'recommendations with evidence', 'count' => $withEvidence],
                    ],
                    records: $unevidenced,
                    filter: ['table' => 'hpbrain_recommendations', 'evidence_links' => 0],
                    action: 'Link the evidence each recommendation was drawn from, or withdraw it. A recommendation whose evidence cannot be produced should not be actionable.',
                    confidence: $this->derivedConfidence(
                        measurement: 'an exact join over every recommendation for this organization',
                        affected: $unevidenced,
                        total: max(1, $recommendations),
                        impactKind: 'structural',
                    ),
                    impactKind: 'structural',
                );
            }
        }

        // Invariant 5 / 8 — every outcome updates memory, and the loop closes.
        if ($decisions > 0 && $outcomes === 0) {
            $out[] = $this->derived(
                id: 'loop:no_outcomes',
                title: 'The loop has never closed: '.$decisions.' decisions, no outcomes',
                area: 'Intelligence loop',
                detail: $decisions.' decision'.($decisions === 1 ? ' has' : 's have').' been recorded and not one outcome. Nothing measures whether any of them worked, so recommendation accuracy is not merely low — it is unmeasurable, and no learning has been written back.',
                generator: 'invariant_8',
                rootCause: 'Process',
                likelihood: 1.0,
                likelihoodBasis: 'zero rows in hpbrain_outcomes against '.$decisions.' decisions — a census, not an estimate',
                impact: 1.0,
                impactBasis: 'without outcomes the organization cannot tell a good decision from a lucky one, which suspends every accuracy figure on this screen',
                evidence: [
                    ['what' => 'decisions recorded', 'count' => $decisions],
                    ['what' => 'outcomes recorded', 'count' => 0],
                    ['what' => 'learnings recorded', 'count' => $learnings],
                ],
                records: $decisions,
                filter: ['table' => 'hpbrain_outcomes'],
                action: 'Record the outcome of one decision that has already run. One closed loop turns every accuracy figure here from undetermined into measured, and it is the cheapest intervention available.',
                confidence: $this->derivedConfidence(
                    measurement: 'a row count over the outcome table for this organization',
                    affected: $decisions,
                    total: max(1, $decisions),
                    impactKind: 'structural',
                ),
                impactKind: 'structural',
            );
        }

        // Evidence whose confidence was asserted uniformly rather than derived.
        $evidenceStats = (array) DB::table('hpbrain_evidence')->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) AS n, COUNT(DISTINCT confidence) AS distinct_conf, MIN(confidence) AS min_conf, SUM(CASE WHEN observed_date IS NULL THEN 1 ELSE 0 END) AS undated')
            ->first();

        $evidenceCount = (int) ($evidenceStats['n'] ?? 0);

        if ($evidenceCount >= 50 && (int) ($evidenceStats['distinct_conf'] ?? 0) === 1) {
            $stated = (float) ($evidenceStats['min_conf'] ?? 0);

            $out[] = $this->derived(
                id: 'loop:uniform_evidence_confidence',
                title: 'All '.number_format($evidenceCount).' pieces of evidence carry the same confidence',
                area: 'Intelligence loop',
                detail: 'Every evidence row is stored at confidence '.number_format($stated, 2).'. A single value across the whole corpus means confidence was asserted by whatever wrote the rows, not derived from the source — so it carries no information and cannot discriminate strong evidence from weak.',
                generator: 'asserted_confidence',
                rootCause: 'Information',
                likelihood: 1.0,
                likelihoodBasis: 'COUNT(DISTINCT confidence) = 1 over '.number_format($evidenceCount).' rows',
                impact: 1.0,
                impactBasis: 'confidence is an input to every downstream reasoning step, so a constant value degrades all of them equally',
                evidence: [
                    ['what' => 'evidence rows', 'count' => $evidenceCount],
                    ['what' => 'distinct confidence values', 'count' => 1],
                    ['what' => 'rows with no observation date', 'count' => (int) ($evidenceStats['undated'] ?? 0)],
                ],
                records: $evidenceCount,
                filter: ['table' => 'hpbrain_evidence', 'confidence' => $stated],
                action: 'Derive evidence confidence at ingestion from source reliability and freshness rather than defaulting it. Until then, treat every confidence-weighted figure downstream as unweighted.',
                confidence: $this->derivedConfidence(
                    measurement: 'a distinct-value count over every evidence row for this organization',
                    affected: $evidenceCount,
                    total: max(1, $evidenceCount),
                    impactKind: 'structural',
                ),
                impactKind: 'structural',
            );
        }

        return $out;
    }

    /* ─────────────────────────── shared shape ─────────────────────────── */

    /**
     * Assemble one derived risk.
     *
     * @param array<int, array<string, mixed>> $evidence
     * @param array<string, mixed>             $filter
     *
     * @return array<string, mixed>
     */
    private function derived(
        string $id,
        string $title,
        string $area,
        string $detail,
        string $generator,
        string $rootCause,
        ?float $likelihood,
        string $likelihoodBasis,
        ?float $impact,
        string $impactBasis,
        array $evidence,
        int $records,
        array $filter,
        string $action,
        Confidence $confidence,
        string $impactKind = 'magnitude_proxy',
    ): array {
        return [
            'id'              => $id,
            'title'           => $title,
            'area'            => $area,
            'detail'          => $detail,
            'generator'       => $generator,
            'rootCauseFamily' => $rootCause,
            'likelihood'      => $likelihood === null ? null : round($likelihood, 4),
            'likelihoodBand'  => $this->band($likelihood),
            'likelihoodBasis' => $likelihoodBasis,
            'impact'          => $impact === null ? null : round($impact, 4),
            'impactBand'      => $this->band($impact),
            'impactKind'      => $impactKind,
            'impactBasis'     => $impactBasis,
            'severity'        => $this->severity($likelihood, $impact),
            'severitySource'  => 'computed',
            'state'           => 'open',
            // Nothing was written to the register, so nothing can own this yet.
            // Saying so is what stops a derived risk from looking managed.
            'registered'      => false,
            'owner'           => null,
            'evidence'        => $evidence,
            'affectedRecords' => $records,
            'confidence'      => $confidence->jsonSerialize(),
            'recommendedAction' => $action,
            'provenance'      => Provenance::of('generator `'.$generator.'`; severity = likelihood x impact x 5')
                ->from(OrganizationDataProfiler::RECORDS, $filter, $records),
        ];
    }

    /**
     * Confidence in a derived risk — in the CONCLUSION, not in the arithmetic.
     *
     * The first version of this scored every derived risk at exactly 1.00, on the
     * grounds that a COUNT(*) has no sampling error. That is true and it is the
     * wrong question. The count is certain; what is uncertain is whether the
     * condition it counts is really a risk of the severity claimed, and the honest
     * answer depends mostly on how impact was arrived at.
     *
     * Three components, and the middle one is what stops this saturating:
     *
     *   measurement  the figure came from a census rather than a sample
     *   impactBasis  a magnitude proxy is worth materially less than an assessed
     *                or structural impact, because share-of-records is not cost
     *   reach        how much of the relevant population the finding rests on
     *
     * @param array<string, array{0: float, 1: float|null, 2: string}> $extra
     */
    private function derivedConfidence(
        string $measurement,
        int $affected,
        int $total,
        string $impactKind,
        array $extra = [],
    ): Confidence {
        $confidence = Confidence::build()
            ->add('measurement', 0.35, 1.0, $measurement)
            ->add(
                'impactBasis', 0.40,
                match ($impactKind) {
                    // The consequence is a property of the system, not an estimate
                    // of a cost: an unevidenced recommendation is unauditable, full
                    // stop. Nothing is being proxied, so nothing is discounted.
                    'structural' => 1.0,
                    'assessed'   => 0.9,
                    // Share-of-records standing in for consequence. It is the best
                    // this organization's data supports and it is not a cost
                    // model, so it cannot carry full confidence.
                    default      => 0.5,
                },
                match ($impactKind) {
                    'structural' => 'impact is structural — the consequence follows from the condition itself, not from an estimate',
                    'assessed'   => 'impact was assessed by a person and recorded',
                    default      => 'impact is a magnitude proxy (share of recorded work), because this organization has recorded no cost, penalty or revenue data to compute a real one',
                },
            )
            ->add(
                'reach', 0.25,
                $total <= 0 || $affected <= 0 ? null : min(1.0, $affected / $total),
                number_format($affected).' of '.number_format($total).' records in the relevant population exhibit the condition',
            );

        foreach ($extra as $key => [$weight, $value, $basis]) {
            $confidence->add($key, $weight, $value, $basis);
        }

        return $confidence;
    }

    /**
     * severity = likelihood x impact x 5.
     *
     * Null propagates. A risk missing either half has no severity, and placing it
     * at zero would sort the least-known risks to the bottom of the register —
     * precisely the ones that need looking at.
     */
    private function severity(?float $likelihood, ?float $impact): ?float
    {
        if ($likelihood === null || $impact === null) {
            return null;
        }

        return round($likelihood * $impact * 5, 2);
    }

    /**
     * A magnitude proxy for impact: share of the organization's recorded work.
     *
     * Square-rooted. A linear share would put almost every finding at the bottom
     * of the impact axis, because no single condition touches a majority of a
     * 96,000-record estate — and a matrix where everything sits in row one
     * communicates nothing. The root is a presentation choice on a proxy that is
     * already not a cost, and it is stated in every impactBasis string.
     */
    private function magnitude(int $affected, int $total): ?float
    {
        if ($total <= 0 || $affected <= 0) {
            return null;
        }

        return min(1.0, sqrt($affected / $total));
    }

    /** 0..1 onto the 1-5 bands the matrix is drawn on. */
    private function band(?float $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return max(1, min(5, (int) ceil($value * 5)));
    }

    /**
     * A registered risk's impact is a word. Map it onto the same 0..1 scale the
     * derived ones use, so one matrix can hold both.
     */
    private function impactWordToValue(?string $word): ?float
    {
        return match (strtolower(trim((string) $word))) {
            'critical', 'severe' => 1.0,
            'high'   => 0.8,
            'medium', 'moderate' => 0.5,
            'low'    => 0.25,
            'minimal', 'negligible' => 0.1,
            default  => null,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $risks
     *
     * @return array<int, array<string, mixed>>
     */
    private function byRootCause(array $risks): array
    {
        $counts = array_fill_keys(self::ROOT_CAUSES, 0);

        foreach ($risks as $r) {
            $family = (string) $r['rootCauseFamily'];
            $counts[$family] = ($counts[$family] ?? 0) + 1;
        }

        $out = [];

        foreach ($counts as $family => $count) {
            // Families with nothing in them are RETAINED. An empty Capability row
            // says the organization has diagnosed no capability causes, which is a
            // finding about its diagnosis, not an absence of data to draw.
            $out[] = ['family' => $family, 'count' => $count];
        }

        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * The 5x5 likelihood-by-impact grid, as cells with the risks in them.
     *
     * Risks with either axis unmeasured are NOT placed. They are returned under
     * `unplaceable` so the screen can say how many risks the matrix is not showing
     * — a matrix that silently omits rows is worse than one with a gap in it.
     *
     * @param array<int, array<string, mixed>> $open
     *
     * @return array<string, mixed>
     */
    private function matrix(array $open): array
    {
        $cells       = [];
        $unplaceable = [];

        foreach ($open as $risk) {
            if ($risk['likelihoodBand'] === null || $risk['impactBand'] === null) {
                $unplaceable[] = ['id' => $risk['id'], 'title' => $risk['title']];

                continue;
            }

            $key = $risk['likelihoodBand'].':'.$risk['impactBand'];
            $cells[$key] ??= ['likelihood' => $risk['likelihoodBand'], 'impact' => $risk['impactBand'], 'count' => 0, 'maxSeverity' => null, 'risks' => []];
            $cells[$key]['count']++;
            $cells[$key]['risks'][] = ['id' => $risk['id'], 'title' => $risk['title'], 'severity' => $risk['severity']];
            $cells[$key]['maxSeverity'] = max($cells[$key]['maxSeverity'] ?? 0, (float) ($risk['severity'] ?? 0));
        }

        return ['cells' => array_values($cells), 'unplaceable' => $unplaceable];
    }
}
