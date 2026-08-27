<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Universal\EntityResolver;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What data this organization actually holds — measured, not sampled.
 *
 * EVERYTHING HERE IS SQL AGGREGATION. Not one row of operational data is loaded
 * into PHP. That is a correctness requirement before it is a performance one:
 * the tenant profiled during development has 96,416 operational records, and any
 * implementation that reads "enough rows to get the idea" would produce figures
 * that describe the sample and are then presented as describing the
 * organization. Aggregates over the full population cannot drift that way.
 *
 * THE COLUMN VOCABULARY IS THE SCHEMA, NOT A GUESS. Ingestion maps every
 * customer file into the canonical columns of hpbrain_operational_records
 * (status, category, sub_category, zone, area, owner_name, supervisor_name,
 * subject_ref, metric_value, occurred_at, closed_at). Profiling those columns is
 * therefore tenant-agnostic: a telecom's fault categories and a school's fee
 * categories arrive in the same column and are profiled by the same code. No
 * dataset name, category value or business concept appears anywhere below.
 *
 * FIELDS THAT DO NOT APPLY ARE ABSENT, NOT EMPTY. A dataset whose `zone` column
 * is null on every row is reported with `nonNull: 0` rather than being dropped,
 * because "this organization records no geography for its attendance" is one of
 * the more useful findings the profile produces.
 */
final class OrganizationDataProfiler
{
    /** The canonical operational table every ingested dataset lands in. */
    public const RECORDS = 'hpbrain_operational_records';

    /**
     * Above this size, keep profiling to full-population scalar aggregates and
     * cadence. Per-value breakdowns and windowed percentiles can exceed PHP's
     * request timeout on live databases, and they are supporting detail rather
     * than the intelligence contract.
     *
     * IT IS A CEILING, NOT A SWITCH. It stood at 0 — which is not "no limit" but
     * "every dataset is over the limit", since a dataset with one record is
     * already above zero. Every per-value breakdown in the product was therefore
     * off: no top values, so no recurring patterns, so no knowledge domains and
     * no measure unit, on tenants of any size. The screens went quiet and the
     * profiler reported the silence as an omission rather than as a fault.
     *
     * Matched to LARGE_TENANT_DATASET_SUMMARY_LIMIT deliberately: a tenant above
     * that never reaches this method at all, so the two together say "profile in
     * full up to a hundred thousand records, and summarise beyond it" with one
     * number rather than two that can drift apart.
     */
    private const DETAIL_BREAKDOWN_RECORD_LIMIT = 100000;

    /** Above this, use one grouped summary query instead of one profile query per dataset. */
    private const LARGE_TENANT_DATASET_SUMMARY_LIMIT = 100000;

    /**
     * Columns that classify a record — the axes an organization's practice can
     * be described along. Ordered by how strongly each tends to name the *kind*
     * of work rather than its circumstances, which is the order the domain
     * mapper reads them in.
     */
    public const CLASSIFIERS = ['category', 'sub_category', 'status', 'area', 'zone'];

    /** Columns that attribute a record to a person. */
    public const ACTORS = ['owner_name', 'supervisor_name'];

    /**
     * Every hpbrain_* table that carries organizational intelligence, with the
     * column that dates it. Configuration, audit and identity tables are
     * deliberately excluded: they describe the installation, not the
     * organization, and counting them would inflate every completeness figure.
     *
     * @var array<string, string|null>
     */
    public const LOOP_TABLES = [
        'hpbrain_signals'                 => 'created_date',
        'hpbrain_evidence'                => 'created_date',
        'hpbrain_cases'                   => 'created_date',
        'hpbrain_hypotheses'              => 'created_date',
        'hpbrain_reasoning_steps'         => 'created_date',
        'hpbrain_recommendations'         => 'created_date',
        'hpbrain_decisions'               => 'created_date',
        'hpbrain_eso_executions'          => 'created_date',
        'hpbrain_outcomes'                => 'created_date',
        'hpbrain_learnings'               => 'created_date',
        'hpbrain_mental_models'           => 'updated_date',
        'hpbrain_risks'                   => 'created_date',
        'hpbrain_capabilities'            => 'created_date',
        'hpbrain_capability_assignments'  => 'assigned_date',
        'hpbrain_capability_proficiency'  => 'created_date',
        'hpbrain_knowledge_assets'        => 'created_date',
        'hpbrain_measurement_plans'       => 'created_date',
        'hpbrain_eso_definitions'         => 'created_date',
        'hpbrain_import_jobs'             => 'created_date',
    ];

    public function __construct(private readonly EntityResolver $resolver)
    {
    }

    /**
     * The full profile for one organization.
     *
     * @return array<string, mixed>
     */
    public function profile(string $tenantId): array
    {
        $datasets = $this->datasets($tenantId);
        $loop     = $this->loopTables($tenantId);

        return [
            'tenantId'   => $tenantId,
            'generatedAt' => gmdate('c'),
            'sourceSystems' => $this->sourceSystems($tenantId),
            'erp'        => $this->erpEntities($tenantId),
            'loop'       => $loop,
            'datasets'   => $datasets,
            'totals'     => [
                'operationalRecords' => array_sum(array_column($datasets, 'records')),
                'datasets'           => count($datasets),
                'loopRecords'        => array_sum(array_column($loop, 'rows')),
            ],
        ];
    }

    /* ─────────────────────────── operational datasets ─────────────────────────── */

    /**
     * Every ingested dataset this organization holds, profiled column by column.
     *
     * @return array<int, array<string, mixed>>
     */
    public function datasets(string $tenantId): array
    {
        $total = (int) DB::table(self::RECORDS)
            ->where('tenant_id', $tenantId)
            ->count();

        if ($total > self::LARGE_TENANT_DATASET_SUMMARY_LIMIT) {
            return $this->summarizedDatasets($total);
        }

        $names = DB::table(self::RECORDS)
            ->where('tenant_id', $tenantId)
            ->select('dataset')->distinct()->orderBy('dataset')
            ->pluck('dataset')->all();

        $out = [];

        foreach ($names as $dataset) {
            $out[] = $this->dataset($tenantId, (string) $dataset);
        }

        // Largest first: the dataset an organization has most of is the one its
        // practice is most evidenced by, and the one every downstream reader
        // wants at the top.
        usort($out, static fn (array $a, array $b): int => $b['records'] <=> $a['records']);

        return $out;
    }

    /**
     * Request-safe dataset profiles for tenants whose operational record volume
     * makes per-dataset drilldowns too expensive for a web screen.
     *
     * @return array<int, array<string, mixed>>
     */
    private function summarizedDatasets(int $total): array
    {
        $detailOmitted = [
            'reason' => 'Tenant is above the request-safe dataset profiling limit; source-level detail is available from ingestion sources and operational records.',
            'limit' => self::LARGE_TENANT_DATASET_SUMMARY_LIMIT,
        ];

        $fields = [];

        foreach (array_merge(self::CLASSIFIERS, self::ACTORS, ['subject_ref']) as $column) {
            $fields[$column] = [
                'field' => $column,
                'nonNull' => null,
                'nullCount' => null,
                'completeness' => null,
                'distinct' => 0,
                'invariant' => false,
                'topValues' => [],
                'topValuesOmitted' => $detailOmitted,
            ];
        }

        return [[
            'dataset' => 'operational_records',
            'label' => 'Operational Records',
            'records' => $total,
            'firstAt' => null,
            'lastAt' => null,
            'ingestedAt' => null,
            'spanDays' => null,
            'sourceFiles' => null,
            'sourceFilesOmitted' => $detailOmitted,
            'importJobs' => null,
            'importJobsOmitted' => $detailOmitted,
            'duplicateKeys' => null,
            'duplicateKeysOmitted' => $detailOmitted,
            'closedCount' => 0,
            'closureRate' => null,
            'hasPayload' => null,
            'fields' => $fields,
            'measure' => null,
            'monthly' => [[
                'omitted' => true,
                'reason' => 'Tenant is above the request-safe dataset profiling limit.',
                'limit' => self::LARGE_TENANT_DATASET_SUMMARY_LIMIT,
            ]],
        ]];
    }

    /**
     * One dataset: shape, completeness, timespan, outcome measure, cadence.
     *
     * @return array<string, mixed>
     */
    public function dataset(string $tenantId, string $dataset): array
    {
        $base = static fn () => DB::table(self::RECORDS)
            ->where('tenant_id', $tenantId)->where('dataset', $dataset);

        /*
            ONE QUERY FOR EVERY SCALAR — BUT NOT FOR THE DISTINCT COUNTS.

            Non-null counts, extremes, sums and averages all fall out of a single
            index range scan, so they belong together and are cheap. `COUNT
            (DISTINCT …)` does not: it needs the values grouped, and a statement
            asking for eight of them over eight different columns can use an
            index for none of them. MySQL falls back to a temporary table per
            column over the whole matched slice.

            MEASURED ON THE DEVELOPMENT DATABASE, one tenant's 27,000-row
            school_fee dataset, with the per-column indexes in place:

                the combined aggregate, distinct counts included    152.86s
                the same aggregate without them                       ~0.6s
                one indexed COUNT(DISTINCT col), per column     0.03s – 0.86s

            Splitting them out is therefore not "a query per column instead of
            one" — it is eight index scans instead of eight temporary tables,
            and it is the difference between a profile that completes inside a
            request and one that does not. The comment that stood here argued
            the opposite, correctly, at a time when the columns had no indexes
            to scan.
        */
        $columns = array_merge(self::CLASSIFIERS, self::ACTORS, ['subject_ref', 'metric_value', 'metric_unit', 'quantity', 'closed_at', 'occurred_at', 'payload']);
        $selects = ['COUNT(*) AS records'];

        foreach ($columns as $column) {
            $selects[] = "COUNT(`{$column}`) AS nn_{$column}";
        }

        $selects[] = 'MIN(occurred_at) AS first_at';
        $selects[] = 'MAX(occurred_at) AS last_at';
        $selects[] = 'MAX(created_date) AS ingested_at';
        $selects[] = 'AVG(metric_value) AS metric_avg';
        $selects[] = 'MIN(metric_value) AS metric_min';
        $selects[] = 'MAX(metric_value) AS metric_max';
        $selects[] = SqlDialect::stdDev('metric_value').' AS metric_sd';
        // Negative durations are impossible and therefore diagnostic: they mean
        // the source system recorded a close before an open. Counted here so the
        // gap detector can report them instead of quietly averaging them in.
        $selects[] = 'SUM(CASE WHEN metric_value < 0 THEN 1 ELSE 0 END) AS metric_negative';

        $agg = (array) $base()->selectRaw(implode(', ', $selects))->first();
        $records = (int) ($agg['records'] ?? 0);

        /*
            The distinct counts, one indexed statement each.

            `source_file` and `import_job_id` are in the list even though the
            table has no (tenant_id, dataset, …) index for either: they carry a
            handful of values per dataset, so grouping them is cheap once the
            statement is small enough for the optimiser to reach the tenant
            index at all. `natural_key` is unique table-wide and answers from
            its own unique index.
        */
        $distinctOf = array_merge(
            self::CLASSIFIERS,
            self::ACTORS,
            ['subject_ref', 'metric_unit', 'source_file', 'import_job_id', 'natural_key'],
        );

        foreach ($distinctOf as $column) {
            $agg['dc_'.$column] = (int) $base()->selectRaw("COUNT(DISTINCT `{$column}`) AS n")->value('n');
        }

        // The three the rest of this method reads under their older names.
        $agg['source_files'] = $agg['dc_source_file'];
        $agg['import_jobs'] = $agg['dc_import_job_id'];
        // A natural key repeated across rows means the source re-stated the same
        // subject; the difference between the two counts is duplication.
        $agg['distinct_natural_keys'] = $agg['dc_natural_key'];
        $detailBreakdownsAvailable = $records <= self::DETAIL_BREAKDOWN_RECORD_LIMIT;

        $profiled = array_merge(self::CLASSIFIERS, self::ACTORS, ['subject_ref']);

        // Which columns are worth a value breakdown. A column with no values has
        // nothing to break down, and one with hundreds of distinct values is an
        // identifier — `subject_ref` carries 12,467 distinct values on the largest
        // profiled dataset — where every value would be its own "top value".
        $withValues = array_values(array_filter(
            $profiled,
            static function (string $column) use ($agg, $detailBreakdownsAvailable): bool {
                if (! $detailBreakdownsAvailable) {
                    return false;
                }

                $distinct = (int) ($agg['dc_'.$column] ?? 0);

                return $distinct > 0 && $distinct <= 400;
            },
        ));

        $topValues = $this->topValuesForFields($tenantId, $dataset, $withValues);

        $fields = [];

        foreach ($profiled as $column) {
            $nonNull  = (int) ($agg['nn_'.$column] ?? 0);
            $distinct = (int) ($agg['dc_'.$column] ?? 0);

            $fields[$column] = [
                'field'        => $column,
                'nonNull'      => $nonNull,
                'nullCount'    => $records - $nonNull,
                'completeness' => $records === 0 ? null : round($nonNull / $records, 4),
                'distinct'     => $distinct,
                // One distinct value over thousands of rows records nothing: the
                // column is present but carries no information, and a reader who
                // sees only "100% complete" would conclude the opposite.
                'invariant'    => $nonNull > 0 && $distinct === 1,
                'topValues'    => $topValues[$column] ?? [],
                'topValuesOmitted' => $detailBreakdownsAvailable ? null : [
                    'reason' => 'Dataset is above the request-safe detailed breakdown limit; full counts and completeness are still computed.',
                    'limit'  => self::DETAIL_BREAKDOWN_RECORD_LIMIT,
                ],
            ];
        }

        $metricCount = (int) ($agg['nn_metric_value'] ?? 0);

        $percentiles = $this->percentiles($tenantId, $dataset, $metricCount);

        return [
            'dataset'       => $dataset,
            'label'         => self::humanize($dataset),
            'records'       => $records,
            'firstAt'       => $agg['first_at'] ?? null,
            'lastAt'        => $agg['last_at'] ?? null,
            'ingestedAt'    => $agg['ingested_at'] ?? null,
            'spanDays'      => $this->spanDays($agg['first_at'] ?? null, $agg['last_at'] ?? null),
            'sourceFiles'   => (int) ($agg['source_files'] ?? 0),
            'importJobs'    => (int) ($agg['import_jobs'] ?? 0),
            'duplicateKeys' => max(0, $records - (int) ($agg['distinct_natural_keys'] ?? 0)),
            'closedCount'   => (int) ($agg['nn_closed_at'] ?? 0),
            'closureRate'   => $records === 0 ? null : round(((int) ($agg['nn_closed_at'] ?? 0)) / $records, 4),
            'hasPayload'    => (int) ($agg['nn_payload'] ?? 0) > 0,
            'fields'        => $fields,
            'measure'       => $metricCount === 0 ? null : [
                'unit'      => $detailBreakdownsAvailable ? $this->dominantUnit($tenantId, $dataset) : null,
                'unitOmitted' => $detailBreakdownsAvailable ? null : [
                    'reason' => 'Dataset is above the request-safe detailed breakdown limit.',
                    'limit' => self::DETAIL_BREAKDOWN_RECORD_LIMIT,
                ],
                'count'     => $metricCount,
                'coverage'  => $records === 0 ? null : round($metricCount / $records, 4),
                'mean'      => $this->num($agg['metric_avg'] ?? null),
                'min'       => $this->num($agg['metric_min'] ?? null),
                'max'       => $this->num($agg['metric_max'] ?? null),
                'stdDev'    => $this->num($agg['metric_sd'] ?? null),
                'negatives' => (int) ($agg['metric_negative'] ?? 0),
            ] + $percentiles,
            'monthly'       => $detailBreakdownsAvailable
                ? $this->monthly($tenantId, $dataset)
                : [[
                    'omitted' => true,
                    'reason' => 'Dataset is above the request-safe detailed breakdown limit; firstAt and lastAt still report the full observed span.',
                    'limit' => self::DETAIL_BREAKDOWN_RECORD_LIMIT,
                ]],
        ];
    }

    /**
     * Value frequencies for one classifier, biggest first.
     *
     * Capped, and the cap is reported. A response that silently returns the top
     * twelve of two hundred values reads as complete and is not; `otherValues`
     * and `otherRecords` are what make the truncation visible.
     *
     * @return array<string, mixed>
     */
    /**
     * Value breakdowns for several columns of one dataset, in ONE round trip.
     *
     * WHY THE UNION IS WORTH THE SQL. Profiling a dataset needs a breakdown of up to
     * seven columns, and the natural implementation issues one GROUP BY each. Against
     * the deployment this was built for — a remote MySQL where the whole computation
     * executed in 446ms of server time and took 19 seconds of wall clock — query
     * COUNT is the cost, not query work. Five datasets at seven columns is 35 round
     * trips collapsed to five.
     *
     * Each branch keeps its own ORDER BY and LIMIT, which is why every branch is
     * parenthesised: without the parentheses MySQL applies a single ORDER BY to the
     * combined result and the per-column caps stop meaning anything.
     *
     * @param array<int, string> $columns
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function topValuesForFields(string $tenantId, string $dataset, array $columns, int $limit = 60): array
    {
        if ($columns === []) {
            return [];
        }

        $branches = [];
        $bindings = [];

        foreach ($columns as $column) {
            $branches[] = "(SELECT ? AS field, `{$column}` AS value, COUNT(*) AS records, COUNT(closed_at) AS closed,
                                   MAX(occurred_at) AS last_at, MIN(occurred_at) AS first_at, AVG(metric_value) AS mean_metric
                              FROM `".self::RECORDS."`
                             WHERE tenant_id = ? AND dataset = ? AND `{$column}` IS NOT NULL
                          GROUP BY `{$column}`
                          ORDER BY records DESC
                             LIMIT {$limit})";
            $bindings[] = $column;
            $bindings[] = $tenantId;
            $bindings[] = $dataset;
        }

        try {
            $rows = DB::select(implode(' UNION ALL ', $branches), $bindings);
        } catch (Throwable) {
            // Any engine that will not take the union gets the one-query-per-column
            // path. Slower, identical output — a profile is not worth failing over a
            // SQL dialect.
            $out = [];

            foreach ($columns as $column) {
                $out[$column] = $this->topValues($tenantId, $dataset, $column, $limit);
            }

            return $out;
        }

        $out = array_fill_keys($columns, []);

        foreach ($rows as $row) {
            $out[(string) $row->field][] = [
                'value'      => (string) $row->value,
                'records'    => (int) $row->records,
                'closed'     => (int) $row->closed,
                'firstAt'    => $row->first_at,
                'lastAt'     => $row->last_at,
                'meanMetric' => $this->num($row->mean_metric),
            ];
        }

        return $out;
    }

    public function topValues(string $tenantId, string $dataset, string $column, int $limit = 60): array
    {
        $rows = DB::table(self::RECORDS)
            ->where('tenant_id', $tenantId)->where('dataset', $dataset)
            ->whereNotNull($column)
            ->selectRaw("`{$column}` AS value, COUNT(*) AS records, COUNT(closed_at) AS closed, MAX(occurred_at) AS last_at, MIN(occurred_at) AS first_at, AVG(metric_value) AS mean_metric")
            ->groupBy($column)
            ->orderByDesc('records')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'value'      => (string) $r->value,
            'records'    => (int) $r->records,
            'closed'     => (int) $r->closed,
            'firstAt'    => $r->first_at,
            'lastAt'     => $r->last_at,
            'meanMetric' => $this->num($r->mean_metric),
        ])->all();
    }

    /**
     * Records per calendar month, with the outcome measure per month.
     *
     * The cadence is what makes movement visible: a total tells a reader where
     * the organization is, and only a series tells them which way it is going.
     *
     * Two means are returned per period, one over every measured value and one
     * over the physically possible ones. In the data profiled during development
     * a single complaint carrying a resolution time of minus 1.1 million hours
     * moved that month's mean by three orders of magnitude, which would have been
     * published as a dramatic improvement in resolution time.
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthly(string $tenantId, string $dataset): array
    {
        return DB::table(self::RECORDS)
            ->where('tenant_id', $tenantId)->where('dataset', $dataset)
            ->whereNotNull('occurred_at')
            ->selectRaw(
                SqlDialect::yearMonth('occurred_at')." AS period,
                 COUNT(*) AS records,
                 COUNT(closed_at) AS closed,
                 AVG(metric_value) AS mean_metric,
                 AVG(CASE WHEN metric_value >= 0 THEN metric_value END) AS mean_metric_valid,
                 COUNT(CASE WHEN metric_value >= 0 THEN 1 END) AS measured_valid",
            )
            ->groupBy('period')->orderBy('period')
            ->get()
            ->map(fn ($r) => [
                'period'     => (string) $r->period,
                'records'    => (int) $r->records,
                'closed'     => (int) $r->closed,
                'meanMetric' => $this->num($r->mean_metric),
                // The same mean with impossible values excluded. Both are
                // published: the raw one is what the source system says, and the
                // difference between them is how badly one corrupt row distorts
                // the picture. Trend fitting uses the cleaned series and says so;
                // the gap detector reports the excluded rows rather than hiding
                // that the cleaning happened.
                'meanMetricValid' => $this->num($r->mean_metric_valid),
                'measuredValid'   => (int) $r->measured_valid,
            ])->all();
    }

    /**
     * Median and p95 of the outcome measure.
     *
     * The mean alone cannot distinguish a process that usually takes two days
     * from one that usually takes two days and occasionally takes a year; the
     * distance between the median and p95 is where that lives. Rows with no
     * measure are excluded rather than read as zero.
     *
     * @return array<string, float|null>
     */
    /**
     * The median and 95th percentile of this dataset's measurements.
     *
     * TWO ORDERED READS, NOT A WINDOW FUNCTION.
     *
     * This was `PERCENTILE_CONT(…) WITHIN GROUP (ORDER BY metric_value) OVER ()`,
     * and it had two problems that pulled in opposite directions and hid each
     * other. `PERCENTILE_CONT` is a MariaDB extension: on MySQL it does not
     * exist, the statement raises, the catch below returns nulls, and every
     * MySQL deployment silently showed no median and no p95 with nothing to say
     * why. On MariaDB, where it does run, `OVER ()` evaluates the window across
     * every matching row before the LIMIT can discard them — measured at over
     * five minutes for a dataset of twenty-seven thousand on the development
     * database, which is well past any web request.
     *
     * Selecting the value at the rank instead is one index-ordered scan with an
     * early stop, portable to every engine, and asks the database for exactly
     * the two rows wanted. It returns the DISCRETE percentile — an actual
     * observed value — where the window function interpolated between the two
     * either side. That is a real difference and a defensible one: an
     * interpolated median of a set of durations is a number no record has, and
     * for a figure a reader may go and check against the source rows, the
     * observed value is the more useful answer.
     *
     * @param  int  $metricCount  non-null metric values, already counted by the
     *                            caller's aggregate — asking again would be a
     *                            third pass for a number in hand.
     * @return array{median: float|null, p95: float|null}
     */
    private function percentiles(string $tenantId, string $dataset, int $metricCount): array
    {
        if ($metricCount === 0) {
            return ['median' => null, 'p95' => null];
        }

        $at = function (float $fraction) use ($tenantId, $dataset, $metricCount): ?float {
            // Zero-based rank, clamped inside the population: the 95th
            // percentile of four values is the fourth, not a row past the end.
            $offset = min($metricCount - 1, (int) floor($fraction * ($metricCount - 1)));

            $value = DB::table(self::RECORDS)
                ->where('tenant_id', $tenantId)
                ->where('dataset', $dataset)
                ->whereNotNull('metric_value')
                ->orderBy('metric_value')
                ->offset($offset)
                ->limit(1)
                ->value('metric_value');

            return $this->num($value);
        };

        return ['median' => $at(0.5), 'p95' => $at(0.95)];
    }

    private function dominantUnit(string $tenantId, string $dataset): ?string
    {
        $unit = DB::table(self::RECORDS)
            ->where('tenant_id', $tenantId)->where('dataset', $dataset)
            ->whereNotNull('metric_unit')
            ->selectRaw('metric_unit, COUNT(*) AS n')
            ->groupBy('metric_unit')->orderByDesc('n')->limit(1)->value('metric_unit');

        return $unit === null ? null : (string) $unit;
    }

    /* ─────────────────────────── loop + ERP ─────────────────────────── */

    /**
     * Row counts and recency for every intelligence-bearing Brain table.
     *
     * An empty table is INCLUDED with rows = 0. The whole point of this block is
     * to make the shape of what is missing visible — a loop that perceives and
     * decides but has never recorded an outcome is the single most important
     * thing to be able to say about an organization's intelligence, and it is
     * only sayable if the zero is reported.
     *
     * @return array<string, array<string, mixed>>
     */
    public function loopTables(string $tenantId): array
    {
        $counts = $this->loopCounts($tenantId);
        $out    = [];

        foreach (self::LOOP_TABLES as $table => $dateColumn) {
            $out[$table] = $counts[$table] ?? ['rows' => null, 'lastAt' => null, 'unavailable' => 'not queried'];
        }

        return $out;
    }

    /**
     * Every loop table's count and high-water timestamp, in ONE round trip.
     *
     * WHY THIS IS A UNION AND NOT A LOOP. The obvious implementation issues a
     * COUNT and a MAX per table — 38 queries across the 19 tables — and
     * dataVersion() needs the same figures on every single request, cache hit or
     * not. Against the deployment this was built for, a remote MySQL where a bare
     * `SELECT 1` was measured at close to three seconds, that is minutes of
     * latency for data that fits in one result set. The union makes the whole
     * block one trip, and the fingerprint that keys the cache one more.
     *
     * A TABLE THAT DOES NOT EXIST WOULD FAIL THE WHOLE UNION, so the fallback
     * matters: on any error this drops back to per-table queries, and reports the
     * individual failures as `unavailable` rather than blanking the block. "The
     * migration has not run" and "the organization has done none of this" are
     * different findings, and only one of them is about the organization.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loopCounts(string $tenantId): array
    {
        // Memoised for the lifetime of this instance, which IntelligenceServiceProvider
        // binds scoped() — one request. dataVersion() and profile() both need these
        // figures, and resolving them twice per read doubles the floor cost of every
        // intelligence request on a high-latency connection.
        if (isset($this->loopCountCache[$tenantId])) {
            return $this->loopCountCache[$tenantId];
        }

        $parts    = [];
        $bindings = [];

        foreach (self::LOOP_TABLES as $table => $dateColumn) {
            $latest = $dateColumn === null ? 'NULL' : "MAX(`{$dateColumn}`)";
            $parts[] = "SELECT ? AS src, COUNT(*) AS rows_count, {$latest} AS last_at FROM `{$table}` WHERE tenant_id = ?";
            $bindings[] = $table;
            $bindings[] = $tenantId;
        }

        try {
            $rows = DB::select(implode(' UNION ALL ', $parts), $bindings);

            $out = [];

            foreach ($rows as $row) {
                $out[(string) $row->src] = [
                    'rows'   => (int) $row->rows_count,
                    'lastAt' => $row->last_at,
                ];
            }

            return $this->loopCountCache[$tenantId] = $out;
        } catch (Throwable) {
            return $this->loopCountCache[$tenantId] = $this->loopCountsIndividually($tenantId);
        }
    }

    /**
     * The slow path, used only when the union fails.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loopCountsIndividually(string $tenantId): array
    {
        $out = [];

        foreach (self::LOOP_TABLES as $table => $dateColumn) {
            try {
                $row = (array) DB::table($table)->where('tenant_id', $tenantId)
                    ->selectRaw('COUNT(*) AS rows_count'.($dateColumn === null ? '' : ", MAX(`{$dateColumn}`) AS last_at"))
                    ->first();

                $out[$table] = ['rows' => (int) ($row['rows_count'] ?? 0), 'lastAt' => $row['last_at'] ?? null];
            } catch (Throwable $e) {
                $out[$table] = ['rows' => null, 'lastAt' => null, 'unavailable' => $e->getMessage()];
            }
        }

        return $out;
    }

    /**
     * Counts for the entities this tenant keeps in its system of record.
     *
     * Read through EntityResolver, which fails closed: an unmapped entity is
     * reported as unmapped rather than silently read from another tenant's
     * tables. That property is the reason this does not query the ERP directly.
     *
     * @return array<string, array<string, mixed>>
     */
    public function erpEntities(string $tenantId): array
    {
        $out = [];

        foreach (['Organization', 'OrganizationUnit', 'Person', 'Position'] as $entity) {
            if (! $this->resolver->has($tenantId, $entity)) {
                $out[$entity] = ['mapped' => false, 'count' => null];

                continue;
            }

            try {
                $source = $this->resolver->resolve($tenantId, $entity);
                $query  = DB::table($source->table)->where($source->tenantKey, $tenantId);

                if ($this->hasColumn($source->table, 'deleted_at')) {
                    $query->whereNull('deleted_at');
                }

                $out[$entity] = ['mapped' => true, 'table' => $source->table, 'count' => (int) $query->count()];
            } catch (Throwable $e) {
                $out[$entity] = ['mapped' => true, 'count' => null, 'error' => $e->getMessage()];
            }
        }

        return $out;
    }

    /**
     * Which systems the organization's data actually came from.
     *
     * Provenance at the highest level: an organization whose entire evidence
     * base arrived from one historical spreadsheet import is in a different
     * position from one wired to a live source, and the difference is not
     * visible in any row count.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sourceSystems(string $tenantId): array
    {
        return DB::table('hpbrain_import_jobs')
            ->where('tenant_id', $tenantId)
            ->selectRaw('import_type, sync_type, COUNT(*) AS jobs, SUM(success_count) AS rows_committed, SUM(error_count) AS rows_failed, MAX(created_date) AS last_at')
            ->groupBy('import_type', 'sync_type')
            ->orderByDesc('last_at')
            ->get()
            ->map(fn ($r) => [
                'importType'    => (string) ($r->import_type ?? 'unknown'),
                'syncType'      => $r->sync_type === null ? null : (string) $r->sync_type,
                'jobs'          => (int) $r->jobs,
                'rowsCommitted' => (int) $r->rows_committed,
                'rowsFailed'    => (int) $r->rows_failed,
                'lastAt'        => $r->last_at,
            ])->all();
    }

    /* ─────────────────────────── cache fingerprint ─────────────────────────── */

    /**
     * A fingerprint of everything the intelligence is computed from.
     *
     * Cache invalidation by TTL would leave a screen reporting a pre-import
     * picture of the organization for however long the TTL was, and the point of
     * this product is that acting changes what the screens say. Keying the cache
     * on counts and high-water timestamps means an import, a decision or a new
     * piece of evidence changes the key, so the next read recomputes and every
     * read in between is served from memory.
     */
    public function dataVersion(string $tenantId): string
    {
        /*
          TWO ROUND TRIPS, and it matters that it is two rather than twenty: this
          runs on every request including cache hits, so its cost is the floor on
          every intelligence read in the application.

          THE INDEX IS WHAT MAKES THE FIRST OF THEM CHEAP. Every other index on
          hpbrain_operational_records leads (tenant_id, dataset, …), and none
          carries updated_date — so with `dataset` unconstrained this query read
          the tenant's entire slice. On the live database that was observed as
          six concurrent copies of this statement, each past 950 seconds, from
          ordinary navigation. 2026_08_18_000400 adds (tenant_id, updated_date),
          which turns MAX() into a single backward seek and COUNT(*) into an
          index-only scan. If this query is ever slow again, check that index
          before rewriting anything here.

          MEMOISED PER INSTANCE, like loopCounts() directly below it and for the
          same reason: several services resolve this profiler from the container
          within one request and each asks for the version, so without the memo
          the floor cost is paid several times over per page.
        */
        if (isset($this->dataVersionCache[$tenantId])) {
            return $this->dataVersionCache[$tenantId];
        }

        $operational = (array) DB::table(self::RECORDS)->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) AS n, MAX(updated_date) AS t')->first();

        $parts = ['oprec:'.($operational['n'] ?? 0).':'.($operational['t'] ?? '-')];

        foreach ($this->loopCounts($tenantId) as $table => $counts) {
            $parts[] = $table.':'.($counts['rows'] ?? 'unavailable').':'.($counts['lastAt'] ?? '-');
        }

        return $this->dataVersionCache[$tenantId] = substr(hash('sha256', implode('|', $parts)), 0, 20);
    }

    /**
     * Per-request memo of the fingerprint, keyed by tenant.
     *
     * Safe because a profiler instance does not outlive the request that
     * resolved it, so it cannot serve a version from before a write in a later
     * request.
     *
     * @var array<string, string>
     */
    private array $dataVersionCache = [];

    /* ─────────────────────────── helpers ─────────────────────────── */

    /** @var array<string, array<string, true>> */
    private array $columnCache = [];

    /**
     * Loop-table counts, per tenant, for this instance's lifetime.
     *
     * Safe only because IntelligenceServiceProvider binds this class scoped(). If it
     * were ever made a singleton this memo would serve a pre-import picture of the
     * organization for the life of the worker process.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $loopCountCache = [];

    private function hasColumn(string $table, string $column): bool
    {
        if (! isset($this->columnCache[$table])) {
            $this->columnCache[$table] = [];

            foreach (DB::select('SHOW COLUMNS FROM `'.$table.'`') as $row) {
                $this->columnCache[$table][$row->Field] = true;
            }
        }

        return isset($this->columnCache[$table][$column]);
    }

    private function num(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 4);
    }

    private function spanDays(?string $from, ?string $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        $a = strtotime($from);
        $b = strtotime($to);

        return $a === false || $b === false ? null : (int) floor(($b - $a) / 86400);
    }

    /**
     * `work_order` → `Work order`. Presentation only.
     *
     * The label is derived from the identifier the customer's own mapping chose,
     * never from a lookup table of business names — a hardcoded map is how a
     * generic engine acquires one customer's vocabulary.
     */
    public static function humanize(string $key): string
    {
        return ucfirst(trim(preg_replace('/[_\-.]+/', ' ', $key) ?? $key));
    }
}
