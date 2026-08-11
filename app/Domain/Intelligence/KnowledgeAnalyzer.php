<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use Illuminate\Support\Facades\DB;

/**
 * What this organization genuinely knows, and how hard-earned that knowledge is.
 *
 * WHAT COUNTS AS KNOWLEDGE HERE. Not what somebody wrote down — what the
 * organization has demonstrably done, repeatedly, and concluded. A knowledge
 * domain is a body of work with records behind it; a pattern within that domain
 * is a situation the organization has met often enough, across enough separate
 * months, that recognising it is a practice rather than an anecdote. That is why
 * a pattern requires recurrence ACROSS PERIODS and not merely a row count: fifty
 * records of the same fault in one week is one incident, and calling it
 * organizational knowledge would be the first lie this screen told.
 *
 * WHY THE PRIMARY AXIS IS CHOSEN BY COLUMN ORDER AND NOT BY STATISTICS. Each
 * dataset is classified along several columns, and the domain has to be described
 * along the one that names the KIND of work. Entropy picks the column that splits
 * the data most evenly, which for the tenant profiled during development is
 * `zone` — 68 geographic areas. Geography is where the work happened, not what
 * the work was, so an information-theoretic choice produces a confidently wrong
 * answer. OrganizationDataProfiler::CLASSIFIERS is ordered from "names the work"
 * to "names the circumstances", and the first column that clears the evidence
 * floor wins. The order is a modelling decision, stated once, rather than a
 * per-customer configuration.
 *
 * DOMAINS WITH NOTHING CLASSIFIED ARE STILL RETURNED. A dataset of 2,980 records
 * whose every classifier is null or single-valued has no patterns, and reporting
 * it with `patterns: 0` and a named reason is the finding. Dropping it would turn
 * the most actionable gap on the screen into silence.
 *
 * NOTHING IS PERSISTED. Every figure is computed from the organization's current
 * rows on read, so acting on the organization changes what this says next time.
 */
final class KnowledgeAnalyzer
{
    /**
     * A classifier has to be recorded on at least this share of a dataset's rows
     * before the domain can be described along it. Below half, the axis describes
     * the minority of work that happened to be classified, which is a different
     * claim from describing the practice.
     */
    private const MIN_AXIS_COMPLETENESS = 0.5;

    /** Minimum records for a value to be a candidate pattern. */
    private const MIN_PATTERN_RECORDS = 3;

    /**
     * Minimum distinct calendar months a value must appear in.
     *
     * Two, not one: recurrence is the whole difference between a pattern and an
     * incident. Datasets whose span is shorter than two months are exempted
     * below, because otherwise a genuinely new source could never have patterns.
     */
    private const MIN_PATTERN_PERIODS = 2;

    /**
     * Records at which volume stops adding to confidence.
     *
     * Not a cliff — Confidence::volumeAdequacy saturates logarithmically toward
     * this figure, so it sets the scale at which a practice reads as thoroughly
     * evidenced rather than a threshold anything jumps over.
     */
    private const VOLUME_TARGET = 1000;

    /** Below this, a domain's knowledge is reported as fragile. */
    private const FRAGILE_CONFIDENCE = 0.50;

    /** Fewer distinct recurring patterns than this is fragile regardless of volume. */
    private const FRAGILE_PATTERNS = 3;

    public function __construct(private readonly OrganizationDataProfiler $profiler)
    {
    }

    /**
     * @param array<string, mixed> $profile Output of OrganizationDataProfiler::profile().
     *
     * @return array<string, mixed>
     */
    public function analyse(string $tenantId, array $profile): array
    {
        $domains = array_merge(
            $this->operationalDomains($tenantId, $profile),
            $this->mentalModelDomains($tenantId),
        );

        // Best-evidenced first. Confidence, then volume as the tie-break, because
        // two domains at 0.61 are not equally established if one rests on 40
        // records and the other on 40,000.
        usort($domains, static function (array $a, array $b): int {
            return [$b['confidence']['value'] ?? -1, $b['reinforcement']]
               <=> [$a['confidence']['value'] ?? -1, $a['reinforcement']];
        });

        $measured = array_values(array_filter($domains, static fn (array $d): bool => $d['confidence']['value'] !== null));

        return [
            'domains'       => $domains,
            'state'         => $this->state($domains, $measured),
            'evidence'      => $this->evidenceCoverage($tenantId),
            'blindSpots'    => $this->blindSpots($profile),
            'learnNext'     => $this->learnNext($domains),
            'definitions'   => [
                'domain'        => 'A body of recorded work in one ingested dataset, described along the first classifier column that is recorded on at least '.(int) (self::MIN_AXIS_COMPLETENESS * 100).'% of its rows.',
                'pattern'       => 'A distinct value on that axis appearing in at least '.self::MIN_PATTERN_RECORDS.' records across at least '.self::MIN_PATTERN_PERIODS.' separate calendar months. Recurrence across periods is required so a single incident cluster is not counted as knowledge.',
                'reinforcement' => 'Records supporting the domain\'s patterns that reached a recorded conclusion (closed_at present). Where a dataset records no conclusion at all, the observed record count is used instead and labelled as such.',
                'confidence'    => 'A weighted blend of volume, classification completeness, conclusion rate, recency, outcome consistency and value integrity. Components with no input are dropped and their weight redistributed — never scored zero.',
                'fragile'       => 'Confidence below '.self::FRAGILE_CONFIDENCE.', or fewer than '.self::FRAGILE_PATTERNS.' recurring patterns.',
            ],
        ];
    }

    /* ─────────────────────────── domains from operational data ─────────────────────────── */

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<int, array<string, mixed>>
     */
    private function operationalDomains(string $tenantId, array $profile): array
    {
        $out = [];

        foreach ($profile['datasets'] as $dataset) {
            $out[] = $this->operationalDomain($tenantId, $dataset);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $dataset
     *
     * @return array<string, mixed>
     */
    private function operationalDomain(string $tenantId, array $dataset): array
    {
        $records = (int) $dataset['records'];
        $axis    = $this->primaryAxis($dataset);

        $patterns    = [];
        $unsupported = 0;

        if ($axis !== null) {
            [$patterns, $unsupported] = $this->patterns($tenantId, (string) $dataset['dataset'], $axis, $dataset);
        }

        $patternRecords = array_sum(array_column($patterns, 'records'));
        $patternClosed  = array_sum(array_column($patterns, 'closed'));

        // Reinforcement is concluded work. A dataset that records no conclusion
        // (attendance has nothing to close) reports observations instead, and the
        // basis string says which of the two the number is — a reader comparing
        // two domains has to know that.
        $concludes     = ($dataset['closedCount'] ?? 0) > 0;
        $reinforcement = $concludes ? $patternClosed : $patternRecords;

        $measure = $dataset['measure'];

        $confidence = Confidence::build()
            ->add(
                'volume', 0.25,
                Confidence::volumeAdequacy($reinforcement, self::VOLUME_TARGET),
                $reinforcement.' '.($concludes ? 'concluded' : 'observed').' records supporting recurring patterns, on a log curve saturating at '.self::VOLUME_TARGET,
            )
            ->add(
                'classification', 0.20,
                $axis === null ? null : $axis['completeness'],
                $axis === null
                    ? 'no classifier column is recorded on enough rows to describe this work'
                    : '`'.$axis['field'].'` recorded on '.$this->pct($axis['completeness']).' of rows',
            )
            ->add(
                'conclusion', 0.20,
                $concludes ? $dataset['closureRate'] : null,
                $concludes
                    ? $this->pct($dataset['closureRate']).' of records reached a recorded conclusion'
                    : 'this dataset records no conclusion, so conclusion rate is not measurable',
            )
            ->add(
                'recency', 0.15,
                Confidence::freshness($dataset['lastAt'] ?? null),
                $dataset['lastAt'] === null
                    ? 'no record carries a date'
                    : 'newest record '.$this->ageDays($dataset['lastAt']).' days old, half-life '.(int) config('brain.evidence.freshness_half_life_days', 90).' days',
            )
            ->add(
                'consistency', 0.15,
                $this->consistency($measure),
                $measure === null || $measure['median'] === null || $measure['p95'] === null
                    ? 'no outcome measure on this dataset, so predictability is not measurable'
                    : 'spread between median '.$measure['median'].' and p95 '.$measure['p95'].' '.($measure['unit'] ?? ''),
            )
            ->add(
                'integrity', 0.05,
                $measure === null || ($measure['count'] ?? 0) === 0
                    ? null
                    : 1 - (($measure['negatives'] ?? 0) / max(1, (int) $measure['count'])),
                $measure === null
                    ? 'no measured value to check'
                    : (int) ($measure['negatives'] ?? 0).' of '.(int) $measure['count'].' measured values are impossible (negative duration)',
            );

        $confidenceValue = $confidence->value();
        $topShare = $patternRecords > 0 && $patterns !== [] ? $patterns[0]['records'] / $patternRecords : null;

        $fragileReasons = [];

        if ($confidenceValue !== null && $confidenceValue < self::FRAGILE_CONFIDENCE) {
            $fragileReasons[] = 'confidence '.number_format($confidenceValue, 2).' is below '.self::FRAGILE_CONFIDENCE;
        }

        if (count($patterns) < self::FRAGILE_PATTERNS) {
            $fragileReasons[] = count($patterns) === 0
                ? 'no recurring pattern could be established'
                : 'only '.count($patterns).' recurring pattern'.(count($patterns) === 1 ? '' : 's');
        }

        if ($confidenceValue === null) {
            $fragileReasons[] = 'nothing this domain\'s confidence is built from could be measured';
        }

        $provenance = Provenance::of(
            'patterns = distinct `'.($axis['field'] ?? 'none').'` values with >= '.self::MIN_PATTERN_RECORDS.' records in >= '.self::MIN_PATTERN_PERIODS.' months; reinforcement = '.($concludes ? 'closed' : 'observed').' records among them',
        )->from(
            OrganizationDataProfiler::RECORDS,
            ['tenant_id' => $tenantId, 'dataset' => $dataset['dataset']],
            $records,
        );

        return [
            'key'                => 'dataset:'.$dataset['dataset'],
            'domain'             => (string) $dataset['label'],
            'source'             => 'operational_records',
            'axis'               => $axis === null ? null : $axis['field'],
            'axisLabel'          => $axis === null ? null : OrganizationDataProfiler::humanize($axis['field']),
            'records'            => $records,
            'patterns'           => count($patterns),
            'patternDetail'      => array_slice($patterns, 0, 25),
            'unsupportedValues'  => $unsupported,
            'reinforcement'      => $reinforcement,
            'reinforcementBasis' => $concludes ? 'concluded records' : 'observed records (this dataset records no conclusion)',
            'coverage'           => $records === 0 ? null : round($patternRecords / $records, 4),
            'concentration'      => $topShare === null ? null : round($topShare, 4),
            'topPattern'         => $patterns === [] ? null : $patterns[0]['value'],
            'firstAt'            => $dataset['firstAt'],
            'lastAt'             => $dataset['lastAt'],
            'measure'            => $measure,
            // Serialised here rather than carried as an object so every reader
            // of a domain — the state summary, learnNext, the recommendation
            // engine, and the JSON response — sees the same shape.
            'confidence'         => $confidence->jsonSerialize(),
            'fragile'            => $fragileReasons !== [],
            'fragileReasons'     => $fragileReasons,
            'provenance'         => $provenance,
        ];
    }

    /**
     * The column this domain's knowledge is described along.
     *
     * First qualifying classifier in the profiler's declared order. A column that
     * is complete but single-valued is rejected: it is recorded on every row and
     * distinguishes nothing, which is worse than a null because it looks like
     * data. A column with nearly as many values as rows is rejected too — that is
     * an identifier, and every value would be its own "pattern".
     *
     * @param array<string, mixed> $dataset
     *
     * @return array<string, mixed>|null
     */
    private function primaryAxis(array $dataset): ?array
    {
        $records = (int) $dataset['records'];

        foreach (OrganizationDataProfiler::CLASSIFIERS as $field) {
            $f = $dataset['fields'][$field] ?? null;

            if ($f === null || $f['completeness'] === null) {
                continue;
            }

            if ($f['completeness'] < self::MIN_AXIS_COMPLETENESS) {
                continue;
            }

            if ($f['distinct'] < 2) {
                continue;
            }

            // An identifier-like column: more than one value per three records.
            if ($records > 0 && $f['distinct'] > max(2, (int) ($records / 3))) {
                continue;
            }

            return $f;
        }

        return null;
    }

    /**
     * Recurring values on the axis, with the ones that failed recurrence counted.
     *
     * @param array<string, mixed> $axis
     * @param array<string, mixed> $dataset
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function patterns(string $tenantId, string $dataset, array $axis, array $datasetProfile): array
    {
        $field = $axis['field'];

        $rows = DB::table(OrganizationDataProfiler::RECORDS)
            ->where('tenant_id', $tenantId)->where('dataset', $dataset)
            ->whereNotNull($field)
            ->selectRaw(
                "`{$field}` AS value,
                 COUNT(*) AS records,
                 COUNT(closed_at) AS closed,
                 COUNT(DISTINCT ".SqlDialect::yearMonth('occurred_at').") AS periods,
                 MIN(occurred_at) AS first_at,
                 MAX(occurred_at) AS last_at,
                 AVG(metric_value) AS mean_metric,
                 COUNT(DISTINCT owner_name) AS owners",
            )
            ->groupBy($field)
            ->orderByDesc('records')
            ->get();

        // A dataset covering less than two months cannot demonstrate recurrence
        // across months, and holding it to that bar would report every new source
        // as having no knowledge at all. Its patterns are then supported by count
        // alone, which the response says via `periodsRequired`.
        $spanDays        = $datasetProfile['spanDays'];
        $periodsRequired = ($spanDays !== null && $spanDays < 45) ? 1 : self::MIN_PATTERN_PERIODS;

        $patterns    = [];
        $unsupported = 0;

        foreach ($rows as $r) {
            $records = (int) $r->records;
            $periods = (int) $r->periods;

            if ($records < self::MIN_PATTERN_RECORDS || $periods < $periodsRequired) {
                $unsupported++;

                continue;
            }

            $patterns[] = [
                'value'      => (string) $r->value,
                'records'    => $records,
                'closed'     => (int) $r->closed,
                'periods'    => $periods,
                'firstAt'    => $r->first_at,
                'lastAt'     => $r->last_at,
                'meanMetric' => $r->mean_metric === null ? null : round((float) $r->mean_metric, 3),
                'owners'     => (int) $r->owners,
            ];
        }

        return [$patterns, $unsupported];
    }

    /* ─────────────────────────── domains the Brain recorded itself ─────────────────────────── */

    /**
     * Mental models, when the organization has any.
     *
     * These are the Brain's own written-down knowledge — a Learning flagged
     * reusable reinforces one. For every tenant in the installation profiled
     * during development the table is empty, which is exactly why the operational
     * derivation above exists; this block is not dead code, it is the path a
     * tenant reaches once the loop has closed at least once.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mentalModelDomains(string $tenantId): array
    {
        $models = DB::table('hpbrain_mental_models')->where('tenant_id', $tenantId)->get();

        if ($models->isEmpty()) {
            return [];
        }

        $learningCounts = DB::table('hpbrain_learnings')
            ->where('tenant_id', $tenantId)->whereNotNull('mental_model_id')
            ->selectRaw('mental_model_id, COUNT(*) AS n')
            ->groupBy('mental_model_id')->pluck('n', 'mental_model_id');

        $byDomain = [];

        foreach ($models as $m) {
            $domain = (string) ($m->domain ?? 'general');
            $rules  = json_decode((string) ($m->rules ?? '{}'), true);
            $stated = is_array($rules['patterns'] ?? null) ? count($rules['patterns']) : 0;

            $byDomain[$domain] ??= [
                'models' => 0, 'confidenceSum' => 0.0, 'reinforcement' => 0,
                'learnings' => 0, 'statedPatterns' => 0, 'lastAt' => null,
            ];

            $byDomain[$domain]['models']++;
            $byDomain[$domain]['confidenceSum']  += (float) ($m->confidence ?? 0);
            $byDomain[$domain]['reinforcement']  += (int) ($m->reinforcement_count ?? 0);
            $byDomain[$domain]['learnings']      += (int) ($learningCounts[$m->id] ?? 0);
            $byDomain[$domain]['statedPatterns'] += $stated;
            $byDomain[$domain]['lastAt'] = max($byDomain[$domain]['lastAt'], (string) ($m->updated_date ?? ''));
        }

        $out = [];

        foreach ($byDomain as $domain => $d) {
            $stored = $d['models'] === 0 ? null : $d['confidenceSum'] / $d['models'];

            // The stored confidence is carried as its own component rather than
            // used as the answer, so a model asserted once at 0.9 cannot outrank
            // one earned over twenty reinforcements.
            $confidence = Confidence::build()
                ->add('storedConfidence', 0.40, $stored, 'mean confidence across '.$d['models'].' mental model(s) in this domain, as maintained by the Learning service')
                ->add('reinforcement', 0.35, Confidence::volumeAdequacy($d['reinforcement'], 50), $d['reinforcement'].' reinforcement(s) recorded')
                ->add('recency', 0.25, Confidence::freshness($d['lastAt'] ?: null), $d['lastAt'] === '' ? 'never updated' : 'last updated '.$this->ageDays($d['lastAt']).' days ago');

            $patterns = max($d['statedPatterns'], $d['learnings']);
            $value    = $confidence->value();

            $reasons = [];

            if ($value !== null && $value < self::FRAGILE_CONFIDENCE) {
                $reasons[] = 'confidence '.number_format($value, 2).' is below '.self::FRAGILE_CONFIDENCE;
            }

            if ($patterns < self::FRAGILE_PATTERNS) {
                $reasons[] = 'only '.$patterns.' pattern'.($patterns === 1 ? '' : 's').' recorded against this model';
            }

            $out[] = [
                'key'                => 'mental_model:'.$domain,
                'domain'             => OrganizationDataProfiler::humanize($domain),
                'source'             => 'mental_models',
                'axis'               => null,
                'axisLabel'          => null,
                'records'            => $d['learnings'],
                'patterns'           => $patterns,
                'patternDetail'      => [],
                'unsupportedValues'  => 0,
                'reinforcement'      => $d['reinforcement'],
                'reinforcementBasis' => 'reinforcement_count on the mental model',
                'coverage'           => null,
                'concentration'      => null,
                'topPattern'         => null,
                'firstAt'            => null,
                'lastAt'             => $d['lastAt'] ?: null,
                'measure'            => null,
                'confidence'         => $confidence->jsonSerialize(),
                'fragile'            => $reasons !== [],
                'fragileReasons'     => $reasons,
                'provenance'         => Provenance::of('grouped by hpbrain_mental_models.domain; patterns = max(stated rules.patterns, linked reusable learnings)')
                    ->from('hpbrain_mental_models', ['tenant_id' => $tenantId, 'domain' => $domain], $d['models'])
                    ->from('hpbrain_learnings', ['tenant_id' => $tenantId, 'mental_model_id' => 'in domain'], $d['learnings']),
            ];
        }

        return $out;
    }

    /* ─────────────────────────── aggregate readings ─────────────────────────── */

    /**
     * @param array<int, array<string, mixed>> $domains
     * @param array<int, array<string, mixed>> $measured
     *
     * @return array<string, mixed>
     */
    private function state(array $domains, array $measured): array
    {
        $values     = array_map(static fn (array $d): float => (float) $d['confidence']['value'], $measured);
        $wellEarned = array_values(array_filter($measured, static fn (array $d): bool => ! $d['fragile']));
        $fragile    = array_values(array_filter($domains, static fn (array $d): bool => $d['fragile']));

        return [
            'domains'            => count($domains),
            'domainsMeasured'    => count($measured),
            'wellEarned'         => count($wellEarned),
            'fragile'            => count($fragile),
            'patterns'           => array_sum(array_column($domains, 'patterns')),
            'reinforcement'      => array_sum(array_column($domains, 'reinforcement')),
            'meanConfidence'     => $values === [] ? null : round(array_sum($values) / count($values), 4),
            'strongestDomain'    => $measured === [] ? null : $measured[0]['domain'],
            // The most exposed domain is not the weakest one — it is the weakest
            // one the organization does a lot of. Ranked by volume x (1 - conf).
            'mostExposedDomain'  => $this->mostExposed($measured),
        ];
    }

    /** @param array<int, array<string, mixed>> $measured */
    private function mostExposed(array $measured): ?string
    {
        $best  = null;
        $score = -1.0;

        foreach ($measured as $d) {
            $exposure = $d['records'] * (1 - (float) $d['confidence']['value']);

            if ($exposure > $score) {
                $score = $exposure;
                $best  = $d['domain'];
            }
        }

        return $best;
    }

    /**
     * Signals and evidence: how much of what the organization noticed is backed.
     *
     * @return array<string, mixed>
     */
    private function evidenceCoverage(string $tenantId): array
    {
        $signals = (int) DB::table('hpbrain_signals')->where('tenant_id', $tenantId)->count();

        $evidence = (array) DB::table('hpbrain_evidence')->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) AS n, AVG(confidence) AS mean_conf, COUNT(DISTINCT signal_id) AS signals_covered, MAX(created_date) AS last_at, SUM(CASE WHEN observed_date IS NULL THEN 1 ELSE 0 END) AS undated')
            ->first();

        $evidenceCount  = (int) ($evidence['n'] ?? 0);
        $signalsCovered = (int) ($evidence['signals_covered'] ?? 0);

        $bands = DB::table('hpbrain_evidence')->where('tenant_id', $tenantId)
            ->selectRaw("CASE WHEN confidence IS NULL THEN 'unstated'
                              WHEN confidence >= 0.75 THEN 'high'
                              WHEN confidence >= 0.5  THEN 'moderate'
                              WHEN confidence >= 0.25 THEN 'low'
                              ELSE 'very low' END AS band, COUNT(*) AS n")
            ->groupBy('band')->pluck('n', 'band')->all();

        return [
            'signals'          => $signals,
            'evidence'         => $evidenceCount,
            'signalsCovered'   => $signalsCovered,
            // Signals with nothing corroborating them: the Brain noticed
            // something and never established whether it was real.
            'signalsUncovered' => max(0, $signals - $signalsCovered),
            'coverage'         => $signals === 0 ? null : round(min(1.0, $signalsCovered / $signals), 4),
            'meanConfidence'   => $evidence['mean_conf'] === null ? null : round((float) $evidence['mean_conf'], 4),
            'perSignal'        => $signalsCovered === 0 ? null : round($evidenceCount / $signalsCovered, 2),
            'undated'          => (int) ($evidence['undated'] ?? 0),
            'lastAt'           => $evidence['last_at'] ?? null,
            'confidenceBands'  => $bands,
            'provenance'       => Provenance::of('coverage = distinct hpbrain_evidence.signal_id / count(hpbrain_signals)')
                ->from('hpbrain_signals', ['tenant_id' => $tenantId], $signals)
                ->from('hpbrain_evidence', ['tenant_id' => $tenantId], $evidenceCount),
        ];
    }

    /**
     * Where the organization records nothing, and what that costs it.
     *
     * Three checkable kinds, each a fact about columns rather than an opinion:
     * a classifier that is null on most rows, a classifier that has exactly one
     * value across thousands of rows, and a dataset with no conclusion recorded.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<int, array<string, mixed>>
     */
    private function blindSpots(array $profile): array
    {
        $out = [];

        foreach ($profile['datasets'] as $dataset) {
            $records = (int) $dataset['records'];

            if ($records === 0) {
                continue;
            }

            foreach (OrganizationDataProfiler::CLASSIFIERS as $field) {
                $f = $dataset['fields'][$field] ?? null;

                if ($f === null) {
                    continue;
                }

                if ($f['nonNull'] === 0) {
                    $out[] = [
                        'kind'    => 'never_recorded',
                        'area'    => $dataset['label'],
                        'field'   => $field,
                        'title'   => OrganizationDataProfiler::humanize($field).' is never recorded on '.$dataset['label'],
                        'detail'  => 'All '.number_format($records).' records leave `'.$field.'` empty, so this work cannot be analysed along that axis at all.',
                        'records' => $records,
                        'share'   => 1.0,
                    ];

                    continue;
                }

                if ($f['invariant']) {
                    $only = $f['topValues'][0]['value'] ?? 'a single value';
                    $out[] = [
                        'kind'    => 'no_variance',
                        'area'    => $dataset['label'],
                        'field'   => $field,
                        'title'   => OrganizationDataProfiler::humanize($field).' on '.$dataset['label'].' only ever says "'.$only.'"',
                        'detail'  => 'The column is populated on every one of '.number_format($records).' records and has exactly one value. A field that cannot vary records no observation, and reads as complete data while carrying none.',
                        'records' => $records,
                        'share'   => 1.0,
                    ];

                    continue;
                }

                if ($f['completeness'] !== null && $f['completeness'] < self::MIN_AXIS_COMPLETENESS) {
                    $out[] = [
                        'kind'    => 'mostly_unrecorded',
                        'area'    => $dataset['label'],
                        'field'   => $field,
                        'title'   => OrganizationDataProfiler::humanize($field).' is missing on '.$this->pct(1 - $f['completeness']).' of '.$dataset['label'].' records',
                        'detail'  => number_format($f['nullCount']).' of '.number_format($records).' records leave `'.$field.'` empty, so any reading along that axis describes only the '.$this->pct($f['completeness']).' that was classified.',
                        'records' => $f['nullCount'],
                        'share'   => round(1 - $f['completeness'], 4),
                    ];
                }
            }

            if (($dataset['closedCount'] ?? 0) === 0 && $records >= 100) {
                $out[] = [
                    'kind'    => 'no_conclusion',
                    'area'    => $dataset['label'],
                    'field'   => 'closed_at',
                    'title'   => $dataset['label'].' records no conclusion',
                    'detail'  => 'None of '.number_format($records).' records carries a closing date, so nothing in this dataset can show whether the work it describes finished, or how long it took.',
                    'records' => $records,
                    'share'   => 1.0,
                ];
            }
        }

        // Biggest first, by the number of records the gap affects — a blind spot
        // over 44,000 rows outranks a total absence over 200.
        usort($out, static fn (array $a, array $b): int => $b['records'] <=> $a['records']);

        return $out;
    }

    /**
     * What the organization should learn next.
     *
     * Ranked by exposure — how much work sits in a domain multiplied by how
     * little is established about it — because effort spent raising confidence in
     * a domain the organization barely touches buys nothing. Domains whose
     * confidence could not be measured at all come first: not knowing whether
     * you know something is worse than knowing you do not.
     *
     * @param array<int, array<string, mixed>> $domains
     *
     * @return array<int, array<string, mixed>>
     */
    private function learnNext(array $domains): array
    {
        $out = [];

        foreach ($domains as $d) {
            $confidence = $d['confidence']['value'];
            $exposure   = $confidence === null
                ? $d['records']
                : $d['records'] * (1 - (float) $confidence);

            if ($exposure <= 0) {
                continue;
            }

            $weakest = $this->weakestComponent($d['confidence']);

            $out[] = [
                'domain'     => $d['domain'],
                'key'        => $d['key'],
                'exposure'   => (int) round($exposure),
                'records'    => $d['records'],
                'confidence' => $confidence,
                'reason'     => $confidence === null
                    ? 'Nothing this domain\'s confidence rests on could be measured, over '.number_format($d['records']).' records.'
                    : number_format($d['records']).' records of work at only '.number_format((float) $confidence, 2).' confidence.',
                'weakestComponent' => $weakest,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['exposure'] <=> $a['exposure']);

        return array_slice($out, 0, 8);
    }

    /**
     * The confidence component holding a domain back the most.
     *
     * Weighted shortfall, so a heavily-weighted component at 0.6 can outrank a
     * lightly-weighted one at 0.1 — the point is which one is worth fixing, not
     * which is lowest.
     *
     * @param array<string, mixed> $confidence Serialised Confidence.
     *
     * @return array<string, mixed>|null
     */
    private function weakestComponent(array $confidence): ?array
    {
        $worst     = null;
        $shortfall = 0.0;

        foreach ($confidence['components'] as $c) {
            if ($c['value'] === null) {
                // An unmeasurable component is the strongest candidate there is:
                // its whole weight is unavailable.
                if ($c['weight'] > $shortfall) {
                    $shortfall = $c['weight'];
                    $worst     = $c;
                }

                continue;
            }

            $gap = $c['weight'] * (1 - $c['value']);

            if ($gap > $shortfall) {
                $shortfall = $gap;
                $worst     = $c;
            }
        }

        return $worst === null ? null : [
            'key'   => $worst['key'],
            'value' => $worst['value'],
            'basis' => $worst['basis'],
        ];
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    /**
     * How predictably a practice concludes, from the median-to-p95 spread.
     *
     * (p95 - median) / (p95 + median) is a robust, scale-free dispersion: it does
     * not care whether the unit is hours or days, and unlike a standard deviation
     * it is not destroyed by the single impossible value that real operational
     * data always contains. 1 minus that is predictability.
     *
     * @param array<string, mixed>|null $measure
     */
    private function consistency(?array $measure): ?float
    {
        if ($measure === null) {
            return null;
        }

        $median = $measure['median'];
        $p95    = $measure['p95'];

        if ($median === null || $p95 === null) {
            return null;
        }

        $sum = abs((float) $p95) + abs((float) $median);

        if ($sum <= 0.0) {
            // Every measured value is zero. Perfectly consistent, and true.
            return 1.0;
        }

        return 1 - min(1.0, abs((float) $p95 - (float) $median) / $sum);
    }

    private function pct(?float $value): string
    {
        return $value === null ? 'an unknown share' : number_format($value * 100, 1).'%';
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
