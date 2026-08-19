<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * Reads over hpbrain_students — the one-row-per-student projection.
 *
 * EVERY METHOD IS BOUNDED AND TENANT-SCOPED. There is no list-everything call
 * here, deliberately: the People screen for a school with 388,401 academic rows
 * must never be one `->get()` away from putting the whole cohort in a browser.
 * Page size is capped by the controller and again by the repository.
 *
 * WHY THE LIST QUERY TOUCHES ONE TABLE. The counts, totals and averages a row
 * displays are columns on the projection, written once by `students:rebuild`.
 * Computing them per row instead would be a correlated aggregate over
 * hpbrain_operational_records for every student on the page — the N+1 that this
 * table exists to remove.
 */
final class StudentRepository
{
    /** Hard ceiling on rows per page, whatever the caller asks for. */
    public const MAX_PAGE_SIZE = 100;

    /** Columns the list and search views return. Never `SELECT *`. */
    private const LIST_COLUMNS = [
        'id', 'student_ref', 'student_name', 'standard', 'division', 'batch',
        'student_quota', 'unique_id', 'academic_standard', 'source_dataset',
        'in_academic', 'in_fees', 'academic_records', 'fee_records',
        'avg_percentage', 'subjects_count', 'total_paid',
        'first_academic_year', 'last_academic_year',
        'first_receipt_date', 'last_receipt_date',
    ];

    private function table(): string
    {
        return 'hpbrain_students';
    }

    /**
     * One page of students, filtered and searched in SQL.
     *
     * @param  array<string, string|null>  $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function paginate(string $tenantId, array $filters = [], int $page = 1, int $pageSize = 25): array
    {
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $page = max(1, $page);

        $query = DB::table($this->table())->where('tenant_id', $tenantId);

        foreach (['standard', 'division', 'batch', 'student_quota', 'academic_standard'] as $column) {
            $value = $filters[$column] ?? null;

            if ($value !== null && $value !== '' && $value !== 'all') {
                $query->where($column, $value);
            }
        }

        // The cohort filter is the matched / fee-only / result-only split, and
        // it reads the two stored flags rather than joining the two datasets.
        match ($filters['cohort'] ?? null) {
            'matched'      => $query->where('in_academic', 1)->where('in_fees', 1),
            'academicOnly' => $query->where('in_academic', 1)->where('in_fees', 0),
            'feesOnly'     => $query->where('in_fees', 1)->where('in_academic', 0),
            default        => null,
        };

        /*
          THE SECTION FILTER, applied as SQL rather than as a list of standards.

          `sectionPredicate` arrives from App\Domain\School\AcademicSections and
          is the SAME grade expression that produced the section's headline
          count. Resolving the band to a list of spellings here instead — IX, X,
          CBSE-9, CBSE-10 — would drift the moment a source spelled one of them
          differently, and the card would then disagree with the list beneath
          it. One definition, used by both.
        */
        $section = $filters['sectionPredicate'] ?? null;

        if (is_array($section)) {
            $query->whereRaw($section['sql'], $section['bindings']);
        }

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $this->applySearch($query, $search);
        }

        $this->applySubjectFilter($query, $tenantId, $filters['subject'] ?? null, $filters['dataset'] ?? null);

        $total = (int) (clone $query)->count();

        $sort = $this->sortColumn($filters['sort'] ?? null);
        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $rows = $query
            ->orderBy($sort, $direction)
            // A stable tiebreak, so paging through equal values cannot show the
            // same student twice and skip another.
            ->orderBy('student_ref')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get(self::LIST_COLUMNS);

        return [
            'data'     => $rows->map(fn ($r) => $this->present((array) $r))->all(),
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Search that uses an index when it can.
     *
     * A numeric term is almost always an enrollment / GR number, and a PREFIX
     * match on student_ref uses idx_students_tenant_ref. A leading-wildcard
     * match on the name cannot use an index, but it runs over one row per
     * student — thousands, not hundreds of thousands — which is the reason the
     * search is pointed at the projection rather than at the records.
     */
    private function applySearch(\Illuminate\Database\Query\Builder $query, string $search): void
    {
        $escaped = addcslashes($search, '%_\\');

        $query->where(function ($w) use ($escaped): void {
            $w->where('student_ref', 'like', $escaped.'%')
              ->orWhere('student_name', 'like', '%'.$escaped.'%')
              ->orWhere('unique_id', 'like', $escaped.'%')
              ->orWhere('standard', 'like', $escaped.'%')
              ->orWhere('academic_standard', 'like', $escaped.'%')
              ->orWhere('division', 'like', $escaped.'%')
              // A four-digit term is almost always a year, and matching it
              // against the student's recorded span is what makes "2019" find
              // the students who were examined that year.
              ->orWhere('first_academic_year', 'like', $escaped.'%')
              ->orWhere('last_academic_year', 'like', $escaped.'%');
        });
    }

    /**
     * Narrow to students who have a record in a given subject.
     *
     * WHY EXISTS AND NOT A JOIN. A student has many result rows, so joining
     * hpbrain_operational_records into the list query would multiply every
     * student by their paper count and break both the page size and the total.
     * EXISTS is a semi-join: it stops at the first matching row per student and
     * cannot duplicate one.
     *
     * The subquery is covered by (tenant_id, dataset, category) and by
     * (tenant_id, dataset, subject_ref) — the two indexes the category migration
     * and the original table already provide — so this is an index probe per
     * candidate rather than a scan.
     *
     * The dataset is passed in rather than named here, for the same reason
     * AcademicRecordRepository takes it from DatasetRegistry: a repository that
     * knows one tenant's dataset name only serves that tenant.
     */
    private function applySubjectFilter(
        \Illuminate\Database\Query\Builder $query,
        string $tenantId,
        ?string $subject,
        ?string $dataset,
    ): void {
        if ($subject === null || $subject === '' || $subject === 'all' || $dataset === null || $dataset === '') {
            return;
        }

        $query->whereExists(function ($sub) use ($tenantId, $subject, $dataset): void {
            $sub->selectRaw('1')
                ->from('hpbrain_operational_records as r')
                ->whereColumn('r.subject_ref', 'hpbrain_students.student_ref')
                ->where('r.tenant_id', $tenantId)
                ->where('r.dataset', $dataset)
                ->where('r.category', $subject);
        });
    }

    /** Allow-listed so a query string can never order by an unindexed or unknown column. */
    private function sortColumn(?string $requested): string
    {
        $allowed = [
            'student_name', 'student_ref', 'standard', 'academic_standard',
            'avg_percentage', 'academic_records', 'fee_records', 'total_paid',
            'last_academic_year', 'last_receipt_date',
        ];

        return in_array($requested, $allowed, true) ? $requested : 'student_name';
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $id): ?array
    {
        $row = DB::table($this->table())
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        return $row === null ? null : $this->present((array) $row);
    }

    /** @return array<string, mixed>|null */
    public function findByRef(string $tenantId, string $studentRef): ?array
    {
        $row = DB::table($this->table())
            ->where('tenant_id', $tenantId)
            ->where('student_ref', $studentRef)
            ->first();

        return $row === null ? null : $this->present((array) $row);
    }

    public function countFor(string $tenantId): int
    {
        return (int) DB::table($this->table())->where('tenant_id', $tenantId)->count();
    }

    /**
     * Distinct values for one filter dropdown.
     *
     * @return array<int, string>
     */
    public function distinctValues(string $tenantId, string $column, int $limit = 200): array
    {
        $allowed = ['standard', 'division', 'batch', 'student_quota', 'academic_standard', 'source_dataset'];

        if (! in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException("Cannot list distinct values for '{$column}' on students.");
        }

        return DB::table($this->table())
            ->where('tenant_id', $tenantId)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->limit($limit)
            ->pluck($column)
            ->map(fn ($v) => (string) $v)
            ->values()
            ->all();
    }

    /**
     * Typed and camelCased for the client; nulls stay null.
     *
     * Numbers are cast here rather than left as the driver's strings so the UI
     * never has to decide whether "0" means zero or missing.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $int = fn (string $k) => isset($row[$k]) ? (int) $row[$k] : 0;
        $float = fn (string $k) => isset($row[$k]) && $row[$k] !== null ? round((float) $row[$k], 2) : null;

        return [
            'id'                => (string) ($row['id'] ?? ''),
            'tenantId'          => (string) ($row['tenant_id'] ?? ''),
            'studentRef'        => (string) ($row['student_ref'] ?? ''),
            'studentName'       => (string) ($row['student_name'] ?? ''),
            'standard'          => $row['standard'] ?? null,
            'division'          => $row['division'] ?? null,
            'batch'             => $row['batch'] ?? null,
            'studentQuota'      => $row['student_quota'] ?? null,
            'uniqueId'          => $row['unique_id'] ?? null,
            'academicStandard'  => $row['academic_standard'] ?? null,
            'sourceDataset'     => $row['source_dataset'] ?? null,
            'inAcademic'        => (bool) ($row['in_academic'] ?? false),
            'inFees'            => (bool) ($row['in_fees'] ?? false),
            'academicRecords'   => $int('academic_records'),
            'feeRecords'        => $int('fee_records'),
            'subjectsCount'     => $int('subjects_count'),
            'avgPercentage'     => $float('avg_percentage'),
            'totalObtained'     => $float('total_obtained'),
            'totalMarks'        => $float('total_marks'),
            'totalPaid'         => $float('total_paid'),
            'firstAcademicYear' => $row['first_academic_year'] ?? null,
            'lastAcademicYear'  => $row['last_academic_year'] ?? null,
            'firstReceiptDate'  => $row['first_receipt_date'] ?? null,
            'lastReceiptDate'   => $row['last_receipt_date'] ?? null,
            'projectedAt'       => $row['projected_at'] ?? null,
        ];
    }
}
