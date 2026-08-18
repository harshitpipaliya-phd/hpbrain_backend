<?php

declare(strict_types=1);

namespace App\Domain\School;

use App\Domain\Intelligence\SqlDialect;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What this school's own rows say, computed by SQL, with no model involved.
 *
 * WHY NOT AN LLM. This is the screen a professor reads as fact, so every figure
 * on it is an aggregate over the tenant's rows and is reproducible by running
 * the same query again. Nothing here is generated, phrased or ranked by a
 * language model, and no AI call happens on any page load — the requirement that
 * expensive generation stay off the request path is met by there being no
 * generation at all.
 *
 * CACHED AGAINST A FINGERPRINT, NOT A CLOCK — the same discipline
 * IntelligenceEngine uses. The key carries a hash of the tenant's import-job and
 * projection high-water marks, so an import or a rebuild invalidates it
 * instantly while every read in between is free. A TTL alone would leave the
 * screen reporting a pre-import picture for as long as it ran.
 *
 * THE TWO FILES ARE FOUR YEARS APART AND THIS SAYS SO. Lions' results cover
 * 2018-2021 and its receipts 2025-2026. Putting "average percentage" beside
 * "fees collected" without that caveat invites the reading that a student is
 * paying now and performing now, which the data cannot support. `relationship`
 * carries both ranges and an explicit non-contemporaneous flag, and the fee and
 * academic sections are never combined into a single derived score.
 *
 * WHAT IS DELIBERATELY ABSENT. The fee export has an Amount per receipt and no
 * billed, demand or due column anywhere in it. Outstanding, overdue, collection
 * rate and expected revenue are therefore not computed, not estimated, and not
 * shown; `fees.notDerivable` names them so the omission reads as a property of
 * the source rather than a missing feature.
 */
final class AcademicIntelligenceService
{
    /** Bounds the cache, never decides freshness — the fingerprint does that. */
    private const TTL_SECONDS = 21600;

    /** How many rows any "top N" list may carry back to the browser. */
    private const TOP_N = 15;

    public function __construct(private readonly DatasetRegistry $datasets)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function forTenant(string $tenantId, bool $fresh = false): array
    {
        $version = $this->dataVersion($tenantId);
        $key = 'hpbrain:school:intel:v1:'.$tenantId.':'.$version;

        if ($fresh) {
            Cache::store('file')->forget($key);
        }

        return Cache::store('file')->remember(
            $key,
            self::TTL_SECONDS,
            fn (): array => $this->compute($tenantId, $version),
        );
    }

    /**
     * A cheap hash of everything this intelligence is derived from.
     *
     * READ OFF SMALL TABLES ONLY, and that is the whole design constraint. This
     * runs on every request, including cache hits, so it must not touch
     * hpbrain_operational_records — a COUNT over one tenant's 388k-row slice
     * would put a few hundred milliseconds on every page load purely to discover
     * that nothing had changed. Import jobs and the student projection are both
     * small, and between them they move whenever the underlying records do:
     * ingestion writes a job row, `students:rebuild` stamps projected_at.
     */
    public function dataVersion(string $tenantId): string
    {
        $parts = [];

        if (Schema::hasTable('hpbrain_import_jobs')) {
            $row = DB::table('hpbrain_import_jobs')
                ->where('tenant_id', $tenantId)
                ->selectRaw('COUNT(*) n, MAX(updated_date) u, COALESCE(SUM(success_count), 0) s')
                ->first();
            $parts[] = ($row->n ?? 0).'|'.($row->u ?? '').'|'.($row->s ?? 0);
        }

        if (Schema::hasTable('hpbrain_students')) {
            $row = DB::table('hpbrain_students')
                ->where('tenant_id', $tenantId)
                ->selectRaw('COUNT(*) n, MAX(projected_at) p')
                ->first();
            $parts[] = ($row->n ?? 0).'|'.($row->p ?? '');
        }

        return substr(hash('sha256', $tenantId.':'.implode('::', $parts)), 0, 16);
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(string $tenantId, string $version): array
    {
        $startedAt = microtime(true);

        $academicDataset = $this->datasets->academic($tenantId);
        $feeDataset = $this->datasets->fees($tenantId);

        return [
            'tenantId'    => $tenantId,
            'dataVersion' => $version,
            'computedAt'  => gmdate('c'),
            'computeMs'   => (int) round((microtime(true) - $startedAt) * 1000),
            'availability' => [
                'academicDataset' => $academicDataset,
                'feeDataset'      => $feeDataset,
                'hasAcademic'     => $academicDataset !== null,
                'hasFees'         => $feeDataset !== null,
            ],
            'cohorts'      => $this->cohorts($tenantId),
            'academic'     => $academicDataset === null ? null : $this->academic($tenantId, $academicDataset),
            'fees'         => $feeDataset === null ? null : $this->fees($tenantId, $feeDataset),
            'relationship' => $this->relationship($tenantId, $academicDataset, $feeDataset),
            'derivation'   => [
                'method'   => 'Every figure is a SQL aggregate over this organization\'s own rows.',
                'llm'      => 'No language model contributed to any figure, ranking or conclusion in this response.',
                'scope'    => 'Every query is filtered to tenant_id = '.$tenantId.'. No aggregate crosses organizations.',
                'liveness' => 'Cached against a fingerprint of the source data, so an import or a projection rebuild invalidates it immediately.',
            ],
        ];
    }

    /**
     * How many students each file knows about, and how many both do.
     *
     * Straight off the projection's two flags, so this is a scan of a table with
     * one row per student rather than a full outer join of 388k against 10k.
     */
    private function cohorts(string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_students')) {
            return ['total' => 0, 'matched' => 0, 'academicOnly' => 0, 'feesOnly' => 0];
        }

        $row = DB::table('hpbrain_students')
            ->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) total')
            ->selectRaw('SUM(CASE WHEN in_academic = 1 AND in_fees = 1 THEN 1 ELSE 0 END) matched')
            ->selectRaw('SUM(CASE WHEN in_academic = 1 AND in_fees = 0 THEN 1 ELSE 0 END) academic_only')
            ->selectRaw('SUM(CASE WHEN in_fees = 1 AND in_academic = 0 THEN 1 ELSE 0 END) fees_only')
            ->first();

        return [
            'total'        => (int) ($row->total ?? 0),
            'matched'      => (int) ($row->matched ?? 0),
            'academicOnly' => (int) ($row->academic_only ?? 0),
            'feesOnly'     => (int) ($row->fees_only ?? 0),
            'note'         => 'Cohorts are derived from the presence of a student\'s enrollment number in each file. '
                            .'The join key is academic.enrollment_no = fees."GR NO." — names are never matched.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function academic(string $tenantId, string $dataset): array
    {
        $scope = fn () => DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('dataset', $dataset);

        /*
          NO COUNT(DISTINCT subject_ref) IN ANY QUERY BELOW.

          subject_ref is not carried by the (tenant_id, dataset, <column>)
          composites these queries group on, so counting it distinctly forces
          MySQL to read every one of the tenant's 388,401 rows and build a
          distinct set per group. The record counts and averages below need a row
          read anyway — SUM(metric_value) cannot come from an index — but the
          distinct sets multiplied that cost several times over for a figure that
          is available far more cheaply elsewhere.

          Student counts come from hpbrain_students, which holds one row per
          student. See totalsFromProjection().
        */
        $totals = $this->totalsFromProjection($tenantId);

        // Grouped on `status`, which is where the field map put `standard`.
        $byStandard = $scope()
            ->selectRaw('status standard, COUNT(*) records')
            ->selectRaw('ROUND(SUM(metric_value) / NULLIF(SUM(quantity), 0) * 100, 2) avg_pct')
            ->groupBy('status')
            ->orderBy('status')
            ->limit(60)
            ->get();

        $bySubject = $scope()
            ->selectRaw('category subject, COUNT(*) records')
            ->selectRaw('ROUND(SUM(metric_value) / NULLIF(SUM(quantity), 0) * 100, 2) avg_pct')
            ->groupBy('category')
            ->orderByDesc('records')
            ->limit(60)
            ->get();

        $byExam = $scope()
            ->selectRaw('sub_category exam, COUNT(*) records')
            ->selectRaw('ROUND(SUM(metric_value) / NULLIF(SUM(quantity), 0) * 100, 2) avg_pct')
            ->groupBy('sub_category')
            ->orderByDesc('records')
            ->limit(40)
            ->get();

        /*
          Grouped on the raw occurred_at column, not on YEAR(occurred_at).

          A function of a column cannot use an index. occurred_at holds the same
          fact as the payload's syear — it is written from it at ingest — and the
          academic loader writes a bare year as 1 January, so grouping on the
          column yields exactly the years while remaining index-ordered. Real
          dates fold to years in PHP from a bounded number of groups.

          Correct only for rows whose occurred_at came from the fixed
          dateTime(); dataset:repair-occurred-at backfills the rest.
        */
        $byYear = $this->foldYears($scope()
            ->whereNotNull('occurred_at')
            ->selectRaw('occurred_at bucket, COUNT(*) records, SUM(metric_value) obtained, SUM(quantity) marks')
            ->groupBy('occurred_at')
            ->orderBy('occurred_at')
            ->limit(400)
            ->get());

        return [
            'dataset'    => $dataset,
            'records'    => $totals['records'],
            'students'   => $totals['students'],
            'subjects'   => $bySubject->count(),
            'exams'      => $byExam->count(),
            'standards'  => $byStandard->count(),
            'avgPercentage' => $totals['avgPercentage'],
            'byStandard' => $this->rows($byStandard),
            'bySubject'  => $this->rows($bySubject),
            'byExam'     => $this->rows($byExam),
            'byYear'     => $byYear,
            'topPerformers' => $this->performers($tenantId, 'desc'),
            'lowPerformers' => $this->performers($tenantId, 'asc'),
            'anomalies'  => $this->anomalies($tenantId, $dataset),
            'measure'    => 'Percentages are SUM(marks obtained) / SUM(marks available) × 100, so a 120-mark paper '
                          .'weighs four times a 30-mark activity. They are not the mean of per-paper percentages.',
        ];
    }

    /**
     * Record count, student count and overall average — from the projection.
     *
     * All three are already stored per student by `students:rebuild`, so this is
     * one scan of a table with a few thousand rows instead of an aggregate over
     * a few hundred thousand.
     *
     * The average is SUM(obtained) / SUM(available) across students, which is
     * the SAME arithmetic as computing it over the raw rows: summing per-student
     * totals and then dividing gives an identical result to dividing the grand
     * totals. It is not the mean of per-student percentages, which would weight
     * a child with eleven papers equally with one who sat a hundred and thirty.
     *
     * @return array{records: int, students: int, avgPercentage: float|null}
     */
    private function totalsFromProjection(string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_students')) {
            return ['records' => 0, 'students' => 0, 'avgPercentage' => null];
        }

        $row = DB::table('hpbrain_students')
            ->where('tenant_id', $tenantId)
            ->where('in_academic', 1)
            ->selectRaw('COUNT(*) students, COALESCE(SUM(academic_records), 0) records')
            ->selectRaw('SUM(total_obtained) obtained, SUM(total_marks) marks')
            ->first();

        $marks = (float) ($row->marks ?? 0);

        return [
            'records'       => (int) ($row->records ?? 0),
            'students'      => (int) ($row->students ?? 0),
            'avgPercentage' => $marks > 0 ? round((float) $row->obtained / $marks * 100, 2) : null,
        ];
    }

    /**
     * Fold per-timestamp buckets into per-year rows, averaging correctly.
     *
     * The percentage is recomputed from the summed numerators and denominators
     * rather than averaged from the buckets' own percentages — averaging
     * averages would silently weight a sparse year the same as a full one.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function foldYears($rows): array
    {
        $years = [];

        foreach ($rows as $row) {
            $year = substr((string) $row->bucket, 0, 4);

            if ($year === '') {
                continue;
            }

            $years[$year] ??= ['syear' => $year, 'records' => 0, 'obtained' => 0.0, 'marks' => 0.0];
            $years[$year]['records'] += (int) $row->records;
            $years[$year]['obtained'] += (float) $row->obtained;
            $years[$year]['marks'] += (float) $row->marks;
        }

        ksort($years);

        return array_values(array_map(fn (array $y): array => [
            'syear'   => $y['syear'],
            'records' => $y['records'],
            'avgPct'  => $y['marks'] > 0 ? round($y['obtained'] / $y['marks'] * 100, 2) : null,
        ], $years));
    }

    /**
     * Best and worst students, read off the projection.
     *
     * Bounded two ways: only students with enough papers to be meaningful, and
     * only TOP_N rows. A single-paper student on 100% is not the school's top
     * performer and publishing them as one would be the kind of confident-looking
     * nonsense this codebase exists to avoid.
     */
    private function performers(string $tenantId, string $direction): array
    {
        if (! Schema::hasTable('hpbrain_students')) {
            return [];
        }

        $rows = DB::table('hpbrain_students')
            ->where('tenant_id', $tenantId)
            ->where('in_academic', 1)
            ->whereNotNull('avg_percentage')
            ->where('academic_records', '>=', 10)
            ->orderBy('avg_percentage', $direction)
            ->orderBy('student_ref')
            ->limit(self::TOP_N)
            ->get(['id', 'student_ref', 'student_name', 'academic_standard', 'avg_percentage',
                   'academic_records', 'subjects_count', 'first_academic_year', 'last_academic_year']);

        return $rows->map(fn ($r) => [
            'id'            => (string) $r->id,
            'studentRef'    => (string) $r->student_ref,
            'studentName'   => (string) $r->student_name,
            'standard'      => $r->academic_standard,
            'avgPercentage' => $r->avg_percentage === null ? null : round((float) $r->avg_percentage, 2),
            'records'       => (int) $r->academic_records,
            'subjects'      => (int) $r->subjects_count,
            'years'         => trim((string) $r->first_academic_year.'–'.(string) $r->last_academic_year, '–'),
        ])->values()->all();
    }

    /**
     * Data-quality facts, stated as counts with examples.
     *
     * These are observations about the FILE, not judgements about students, and
     * they are reported because a marks export that contains an impossible score
     * is telling you something true about the source system.
     */
    private function anomalies(string $tenantId, string $dataset): array
    {
        $scope = fn () => DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('dataset', $dataset);

        $counts = $scope()
            ->selectRaw('SUM(CASE WHEN metric_value > quantity THEN 1 ELSE 0 END) over_total')
            ->selectRaw('SUM(CASE WHEN quantity IS NULL OR quantity = 0 THEN 1 ELSE 0 END) no_total')
            ->selectRaw('SUM(CASE WHEN metric_value IS NULL THEN 1 ELSE 0 END) no_marks')
            ->first();

        $examples = $scope()
            ->whereColumn('metric_value', '>', 'quantity')
            ->orderByDesc(DB::raw('metric_value - quantity'))
            ->limit(5)
            ->get(['natural_key', 'subject_ref', 'status', 'category', 'sub_category', 'metric_value', 'quantity'])
            ->map(fn ($r) => [
                'naturalKey' => (string) $r->natural_key,
                'studentRef' => (string) $r->subject_ref,
                'standard'   => $r->status,
                'subject'    => $r->category,
                'exam'       => $r->sub_category,
                'obtained'   => (float) $r->metric_value,
                'total'      => $r->quantity === null ? null : (int) $r->quantity,
            ])->values()->all();

        return [
            'obtainedExceedsTotal' => (int) ($counts->over_total ?? 0),
            'missingTotal'         => (int) ($counts->no_total ?? 0),
            'missingObtained'      => (int) ($counts->no_marks ?? 0),
            'examples'             => $examples,
            'note'                 => 'Counted, not corrected. These rows are left exactly as the source system supplied them.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fees(string $tenantId, string $dataset): array
    {
        $scope = fn () => DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('dataset', $dataset);

        $totals = $scope()
            ->selectRaw('COUNT(*) receipts, COUNT(DISTINCT subject_ref) students, ROUND(SUM(metric_value), 2) collected')
            ->selectRaw('ROUND(AVG(metric_value), 2) avg_receipt, MIN(occurred_at) first_receipt, MAX(occurred_at) last_receipt')
            ->first();

        // `category` is where the field map put Payment Mode, and it is indexed.
        $byMode = $scope()
            ->selectRaw('category mode, COUNT(*) receipts, ROUND(SUM(metric_value), 2) collected')
            ->groupBy('category')
            ->orderByDesc('collected')
            ->limit(30)
            ->get();

        $byStandard = $scope()
            ->selectRaw('status standard, COUNT(*) receipts, COUNT(DISTINCT subject_ref) students, ROUND(SUM(metric_value), 2) collected')
            ->groupBy('status')
            ->orderByDesc('collected')
            ->limit(40)
            ->get();

        $period = SqlDialect::yearMonth('occurred_at');
        $byMonth = $scope()
            ->whereNotNull('occurred_at')
            ->selectRaw("{$period} period, COUNT(*) receipts, ROUND(SUM(metric_value), 2) collected")
            ->groupByRaw($period)
            ->orderByRaw($period)
            ->limit(48)
            ->get();

        $byCollector = $scope()
            ->whereNotNull('owner_name')
            ->selectRaw('owner_name collector, COUNT(*) receipts, ROUND(SUM(metric_value), 2) collected')
            ->groupBy('owner_name')
            ->orderByDesc('collected')
            ->limit(self::TOP_N)
            ->get();

        $topPayers = ! Schema::hasTable('hpbrain_students') ? [] : DB::table('hpbrain_students')
            ->where('tenant_id', $tenantId)
            ->where('in_fees', 1)
            ->whereNotNull('total_paid')
            ->orderByDesc('total_paid')
            ->limit(self::TOP_N)
            ->get(['id', 'student_ref', 'student_name', 'standard', 'division', 'total_paid', 'fee_records'])
            ->map(fn ($r) => [
                'id'          => (string) $r->id,
                'studentRef'  => (string) $r->student_ref,
                'studentName' => (string) $r->student_name,
                'standard'    => $r->standard,
                'division'    => $r->division,
                'totalPaid'   => round((float) $r->total_paid, 2),
                'receipts'    => (int) $r->fee_records,
            ])->values()->all();

        return [
            'dataset'        => $dataset,
            'receipts'       => (int) ($totals->receipts ?? 0),
            'students'       => (int) ($totals->students ?? 0),
            'totalCollected' => $totals->collected === null ? null : round((float) $totals->collected, 2),
            'averageReceipt' => $totals->avg_receipt === null ? null : round((float) $totals->avg_receipt, 2),
            'firstReceipt'   => $totals->first_receipt ?? null,
            'lastReceipt'    => $totals->last_receipt ?? null,
            'byMode'         => $this->rows($byMode),
            'byStandard'     => $this->rows($byStandard),
            'byMonth'        => $this->rows($byMonth),
            'byCollector'    => $this->rows($byCollector),
            'topPayers'      => $topPayers,
            // Named so the absence reads as a property of the source rather than
            // a gap in this screen.
            'notDerivable'   => [
                'outstanding'    => 'The receipt export has no billed or demand column, so nothing owed can be computed.',
                'overdue'        => 'No due date is recorded against any receipt.',
                'collectionRate' => 'A rate needs a denominator the source does not contain.',
                'expectedRevenue'=> 'Not derivable from receipts alone.',
            ],
        ];
    }

    /**
     * What the two files can and cannot say about each other.
     *
     * The date ranges are the load-bearing part. Reading a 2018 exam result
     * beside a 2026 receipt as "this student is struggling and behind on fees"
     * is a conclusion about two different periods of a child's life, and the
     * flag here is what stops the UI drawing it.
     */
    private function relationship(string $tenantId, ?string $academicDataset, ?string $feeDataset): array
    {
        if ($academicDataset === null || $feeDataset === null) {
            return ['available' => false, 'reason' => 'This organization does not have both an academic and a fee dataset.'];
        }

        $years = ! Schema::hasTable('hpbrain_students') ? null : DB::table('hpbrain_students')
            ->where('tenant_id', $tenantId)
            ->selectRaw('MIN(first_academic_year) a_from, MAX(last_academic_year) a_to')
            ->selectRaw('MIN(first_receipt_date) f_from, MAX(last_receipt_date) f_to')
            ->first();

        $academicTo = $years->a_to ?? null;
        $feeFrom = $years->f_from ?? null;

        // Non-contemporaneous when the last exam year ends before the first
        // receipt year begins. Computed from the data, never assumed.
        $overlaps = $academicTo !== null && $feeFrom !== null
            && (int) substr((string) $feeFrom, 0, 4) <= (int) $academicTo;

        return [
            'available'   => true,
            'joinKey'     => 'academic.enrollment_no = fees."GR NO." (stored as subject_ref on both datasets)',
            'academicYears' => [$years->a_from ?? null, $academicTo],
            'feeYears'    => [
                $feeFrom === null ? null : substr((string) $feeFrom, 0, 4),
                ($years->f_to ?? null) === null ? null : substr((string) $years->f_to, 0, 4),
            ],
            'contemporaneous' => $overlaps,
            'caution'     => $overlaps
                ? 'The two datasets cover overlapping periods, so a combined reading is time-consistent.'
                : 'The academic and fee records cover DIFFERENT periods. Any statement combining them is historical, '
                  .'not a description of a student\'s current performance or current fee position.',
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rows($rows): array
    {
        return $rows->map(function ($r): array {
            $out = [];

            foreach ((array) $r as $key => $value) {
                $out[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))))] =
                    is_numeric($value) && str_contains((string) $value, '.') ? round((float) $value, 2) : $value;
            }

            return $out;
        })->values()->all();
    }
}
