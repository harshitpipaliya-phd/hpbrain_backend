<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\School\DatasetRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Reads over one tenant's academic and fee operational records.
 *
 * THE DATASET NAME IS NEVER LITERAL IN THIS CLASS. It arrives from
 * DatasetRegistry, which reads the role a tenant declared on its own source.
 * Writing `where('dataset', 'lions-result-data')` here would make every screen
 * that uses it a Lions screen.
 *
 * WHAT THE COLUMNS MEAN. The academic field map put the export's own fields on
 * promoted columns, and every query here reads them rather than the JSON:
 *
 *     status       ← standard        category  ← subject
 *     sub_category ← exam            subject_ref ← enrollment_no
 *     metric_value ← obtain          quantity  ← total
 *
 * All five are covered by (tenant_id, dataset, …) composites, so a filter on any
 * of them is an index range scan rather than a scan of the tenant's slice. Only
 * `syear` and the fee export's Division / Batch / Quota live in `payload`, and
 * they are read per page of results, never grouped over the whole table.
 *
 * EVERY READ IS PAGED. A student with four years of results has a few hundred
 * rows; a standard has tens of thousands. Nothing here returns an unbounded set.
 */
final class AcademicRecordRepository
{
    public const MAX_PAGE_SIZE = 200;

    public function __construct(private readonly DatasetRegistry $datasets)
    {
    }

    /**
     * One page of a student's exam results.
     *
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int, dataset: ?string}
     */
    public function academicForStudent(string $tenantId, string $studentRef, int $page = 1, int $pageSize = 50): array
    {
        $dataset = $this->datasets->academic($tenantId);

        if ($dataset === null) {
            return ['data' => [], 'total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'dataset' => null];
        }

        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $page = max(1, $page);

        $query = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('dataset', $dataset)
            ->where('subject_ref', $studentRef);

        $total = (int) (clone $query)->count();

        $rows = $query
            ->orderBy('natural_key')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get(['id', 'natural_key', 'status', 'category', 'sub_category', 'metric_value', 'quantity', 'payload']);

        return [
            'data' => $rows->map(function ($r): array {
                $payload = $this->payload($r->payload);
                $obtained = $r->metric_value === null ? null : (float) $r->metric_value;
                $total = $r->quantity === null ? null : (int) $r->quantity;

                return [
                    'id'         => (string) $r->id,
                    'naturalKey' => (string) $r->natural_key,
                    'year'       => $payload['syear'] ?? null,
                    'standard'   => $r->status,
                    'subject'    => $r->category,
                    'exam'       => $r->sub_category,
                    'obtained'   => $obtained,
                    'total'      => $total,
                    'percentage' => ($obtained === null || $total === null || $total <= 0)
                        ? null
                        : round($obtained / $total * 100, 2),
                    // Stated per row rather than filtered out, so an impossible
                    // score is visible where it occurs instead of silently
                    // dropped from the student's record.
                    'anomalous'  => $obtained !== null && $total !== null && $obtained > $total,
                ];
            })->values()->all(),
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'dataset'  => $dataset,
        ];
    }

    /**
     * One page of a student's fee receipts.
     *
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int, dataset: ?string, totalPaid: ?float}
     */
    public function feesForStudent(string $tenantId, string $studentRef, int $page = 1, int $pageSize = 50): array
    {
        $dataset = $this->datasets->fees($tenantId);

        if ($dataset === null) {
            return ['data' => [], 'total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'dataset' => null, 'totalPaid' => null];
        }

        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $page = max(1, $page);

        $query = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('dataset', $dataset)
            ->where('subject_ref', $studentRef);

        $summary = (clone $query)->selectRaw('COUNT(*) n, ROUND(SUM(metric_value), 2) paid')->first();

        $rows = $query
            ->orderByDesc('occurred_at')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get(['id', 'natural_key', 'occurred_at', 'status', 'category', 'sub_category', 'owner_name', 'metric_value', 'payload']);

        return [
            'data' => $rows->map(function ($r): array {
                $payload = $this->payload($r->payload);

                return [
                    'id'          => (string) $r->id,
                    'naturalKey'  => (string) $r->natural_key,
                    'receiptDate' => $r->occurred_at,
                    'receiptNo'   => $payload['Receipt No'] ?? null,
                    'month'       => $r->sub_category,
                    'standard'    => $r->status,
                    'paymentMode' => $r->category,
                    'collectedBy' => $r->owner_name,
                    'amount'      => $r->metric_value === null ? null : round((float) $r->metric_value, 2),
                    'remarks'     => $payload['Remarks'] ?? null,
                    'bankName'    => $payload['Bank Name'] ?? null,
                    'chequeNo'    => $payload['Cheque No'] ?? null,
                ];
            })->values()->all(),
            'total'     => (int) ($summary->n ?? 0),
            'page'      => $page,
            'pageSize'  => $pageSize,
            'dataset'   => $dataset,
            'totalPaid' => ($summary->paid ?? null) === null ? null : round((float) $summary->paid, 2),
        ];
    }

    /**
     * The academic structure this tenant's dataset actually describes.
     *
     * NOT HR DEPARTMENTS, and the response says so. Lions has no rows in the
     * ERP's department table and inventing some would be fabrication; what it
     * does have is a marks export with four real dimensions — standard, year,
     * subject and exam — plus division and batch on the fee side. Those are what
     * this returns, each with the row and student counts behind it, so a reader
     * can see the shape of the school without a single made-up unit.
     *
     * Every group here is over an indexed column, and each list is capped.
     *
     * @return array<string, mixed>
     */
    public function structure(string $tenantId): array
    {
        $academic = $this->datasets->academic($tenantId);
        $fees = $this->datasets->fees($tenantId);

        $dimensions = [];

        if ($academic !== null) {
            $scope = fn () => DB::table('hpbrain_operational_records')
                ->where('tenant_id', $tenantId)
                ->where('dataset', $academic);

            /*
              NO COUNT(DISTINCT subject_ref) ANYWHERE BELOW, AND THAT IS THE
              WHOLE PERFORMANCE STORY OF THIS METHOD.

              Each dimension groups on a column covered by a
              (tenant_id, dataset, <column>) composite, so COUNT(*) is answered
              from the index without touching a single row. Adding
              COUNT(DISTINCT subject_ref) breaks that: subject_ref is not in
              those indexes, so MySQL has to fetch every one of the tenant's
              388,401 rows to evaluate it. Measured, the four dimensions together
              did not return inside five minutes — for a page showing about forty
              labels.

              Student counts have not been dropped, they have been moved to where
              they are cheap AND unambiguous: hpbrain_students already holds one
              row per student, so the standard-wise student count below is a scan
              of ~6,600 rows rather than ~388,000. The record-level dimensions
              report records, and say so.
            */
            $dimensions[] = $this->dimension(
                'standard',
                'Standard',
                'The class a result was recorded against, in the academic export\'s own vocabulary. '
                .'A student who progressed appears under each standard they were examined in.',
                $scope()->selectRaw('status label, COUNT(*) records, 0 students')
                    ->whereNotNull('status')->groupBy('status')->orderBy('status')->limit(60)->get(),
            );

            /*
              GROUPED ON THE RAW occurred_at COLUMN, NOT ON YEAR(occurred_at).

              A function of a column cannot use an index, so grouping by the year
              expression is a full scan; grouping by the column itself is an
              index-ordered read. The academic loader writes a bare year as
              1 January, so for this dataset the two produce the same groups —
              and where a dataset does carry real dates, the years are folded in
              PHP from a bounded number of groups rather than by scanning.
            */
            $dimensions[] = $this->dimension(
                'academicYear',
                'Academic year',
                'The school year the result belongs to.',
                $this->foldToYears(
                    $scope()->whereNotNull('occurred_at')
                        ->selectRaw('occurred_at label, COUNT(*) records')
                        ->groupBy('occurred_at')->orderBy('occurred_at')->limit(400)->get()
                ),
            );

            $dimensions[] = $this->dimension(
                'subject',
                'Subject',
                'The subject examined.',
                $scope()->selectRaw('category label, COUNT(*) records, 0 students')
                    ->whereNotNull('category')->groupBy('category')->orderByDesc('records')->limit(60)->get(),
            );

            $dimensions[] = $this->dimension(
                'exam',
                'Exam',
                'The kind of assessment — written paper, project, activity.',
                $scope()->selectRaw('sub_category label, COUNT(*) records, 0 students')
                    ->whereNotNull('sub_category')->groupBy('sub_category')->orderByDesc('records')->limit(40)->get(),
            );

            // Student counts, from the one-row-per-student projection. Labelled
            // "most recent" because that is exactly what it is: the standard of
            // the student's latest recorded year, not every standard they sat.
            $dimensions[] = $this->dimension(
                'standardCurrent',
                'Standard (most recent year)',
                'How many students each standard holds, counted once per student from their latest recorded year.',
                DB::table('hpbrain_students')
                    ->where('tenant_id', $tenantId)
                    ->where('in_academic', 1)
                    ->whereNotNull('academic_standard')
                    ->where('academic_standard', '!=', '')
                    ->selectRaw('academic_standard label, COUNT(*) students, 0 records')
                    ->groupBy('academic_standard')->orderBy('academic_standard')->limit(60)->get(),
            );
        }

        if ($fees !== null) {
            // Division, batch and quota are recorded per student, so they are
            // counted off the projection rather than off 10,430 receipts.
            foreach ([
                ['division', 'Division', 'The section within a standard, as recorded on the fee register.'],
                ['batch', 'Batch', 'The batch a student is registered under.'],
                ['student_quota', 'Student quota', 'The admission quota recorded against the student.'],
                ['standard', 'Standard (fee register)', 'The class as the fee register records it. This vocabulary differs from the academic export\'s and the two are not reconciled.'],
            ] as [$column, $label, $description]) {
                $dimensions[] = $this->dimension(
                    lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $column)))).'Fee',
                    $label,
                    $description,
                    DB::table('hpbrain_students')
                        ->where('tenant_id', $tenantId)
                        ->where('in_fees', 1)
                        ->whereNotNull($column)
                        ->where($column, '!=', '')
                        ->selectRaw($column.' label, COUNT(*) students, 0 records')
                        ->groupBy($column)
                        ->orderByDesc('students')
                        ->limit(60)
                        ->get(),
                );
            }
        }

        return [
            'kind'       => 'academic_structure',
            'title'      => 'Academic structure',
            'summary'    => 'These are the dimensions this organization\'s dataset is organised by. '
                          .'They are not HR departments — this organization has none recorded — and none of them is invented.',
            'datasets'   => ['academic' => $academic, 'fees' => $fees],
            'dimensions' => array_values(array_filter($dimensions, fn ($d) => $d['values'] !== [])),
        ];
    }

    /**
     * Collapse per-timestamp groups into per-year groups.
     *
     * The SQL groups on the indexed occurred_at column, which for the academic
     * loader is already one value per year. This makes the result correct for
     * any dataset that carries real dates too, without asking the database to
     * group by an expression it cannot index.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function foldToYears($rows)
    {
        $byYear = [];

        foreach ($rows as $row) {
            $year = substr((string) $row->label, 0, 4);

            if ($year === '') {
                continue;
            }

            $byYear[$year] = ($byYear[$year] ?? 0) + (int) $row->records;
        }

        ksort($byYear);

        return collect($byYear)->map(fn (int $records, string $year) => (object) [
            'label' => $year, 'records' => $records, 'students' => 0,
        ])->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function dimension(string $key, string $label, string $description, $rows): array
    {
        return [
            'key'         => $key,
            'label'       => $label,
            'description' => $description,
            'values'      => $rows
                ->filter(fn ($r) => $r->label !== null && (string) $r->label !== '')
                ->map(fn ($r) => [
                    'label'    => (string) $r->label,
                    'records'  => (int) ($r->records ?? 0),
                    'students' => (int) ($r->students ?? 0),
                ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
