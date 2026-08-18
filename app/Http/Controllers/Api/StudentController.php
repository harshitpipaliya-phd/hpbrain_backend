<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\School\AcademicIntelligenceService;
use App\Domain\School\DatasetRegistry;
use App\Http\Controllers\Controller;
use App\Repositories\AcademicRecordRepository;
use App\Repositories\StudentRepository;
use App\Services\TenantScopedCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Students — the people a school's dataset describes, as opposed to the people
 * its HR system employs.
 *
 * WHY THIS IS NOT PersonController. A Person in this system is an ERP master
 * record: an employee row in tbluser, with a department, a manager and a login.
 * Lions has exactly one of those — an administrator — which is why its People
 * screen showed one row for an organization with thousands of children. The
 * children are not in the ERP at all; they exist only as the subject of 388,401
 * exam-result rows and 10,430 fee receipts. Writing them into the ERP's person
 * table to make the screen look right would corrupt master data every other
 * organization depends on, so they are served from their own projection instead.
 *
 * THE TENANT COMES FROM THE TOKEN, NEVER FROM THE PATH. Every method resolves
 * the tenant with authTenantId() and ignores the {tenantId} segment for
 * authorization. The segment stays in the URL because it is the shape every
 * other route in this application uses and clients are written against it, but a
 * caller who edits it gets their own data back rather than someone else's.
 *
 * NOTHING HERE IS SIZED BY THE DATASET. Every list is a page, every aggregate is
 * computed by the database, and the largest response is capped at a couple of
 * hundred rows. That is what makes the screens open at the same speed for a
 * school with four hundred thousand records as for one with four hundred.
 */
final class StudentController extends Controller
{
    /** Bounds the structure cache; the fingerprint in the key decides freshness. */
    private const STRUCTURE_TTL_SECONDS = 21600;

    public function __construct(
        private readonly StudentRepository $students,
        private readonly AcademicRecordRepository $records,
        private readonly AcademicIntelligenceService $intelligence,
        private readonly DatasetRegistry $datasets,
        private readonly TenantScopedCache $cache,
    ) {
    }

    /**
     * GET students/{tenantId} — one page of students.
     *
     * Filtering, searching, sorting and paging all happen in SQL. The client
     * sends a page number and gets a page; it never receives a cohort to filter
     * for itself.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->authTenantId($request);

        $result = $this->students->paginate(
            $tenantId,
            [
                'q'                 => $request->query('q'),
                'standard'          => $request->query('standard'),
                'academic_standard' => $request->query('academic_standard'),
                'division'          => $request->query('division'),
                'batch'             => $request->query('batch'),
                'student_quota'     => $request->query('quota'),
                'cohort'            => $request->query('cohort'),
                'subject'           => $request->query('subject'),
                // Resolved here rather than in the repository, so the subject
                // filter works for any tenant that has declared an academic
                // dataset and is simply inert for one that has not.
                'dataset'           => $this->datasets->academic($tenantId),
                'sort'              => $request->query('sort'),
                'direction'         => $request->query('direction'),
            ],
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 25),
        );

        return response()->json($result);
    }

    /**
     * GET students/{tenantId}/summary — the KPI header, and nothing heavier.
     *
     * This is what the People screen loads FIRST. It answers "how many students,
     * in which cohorts, from which files" off the projection alone, so the page
     * can paint its summary before the first page of rows arrives and long
     * before anyone asks for intelligence.
     */
    public function summary(Request $request): JsonResponse
    {
        $tenantId = $this->authTenantId($request);

        $counts = \Illuminate\Support\Facades\DB::table('hpbrain_students')
            ->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) total')
            ->selectRaw('SUM(CASE WHEN in_academic = 1 AND in_fees = 1 THEN 1 ELSE 0 END) matched')
            ->selectRaw('SUM(CASE WHEN in_academic = 1 AND in_fees = 0 THEN 1 ELSE 0 END) academic_only')
            ->selectRaw('SUM(CASE WHEN in_fees = 1 AND in_academic = 0 THEN 1 ELSE 0 END) fees_only')
            ->selectRaw('SUM(academic_records) academic_records, SUM(fee_records) fee_records')
            ->selectRaw('ROUND(SUM(total_paid), 2) total_paid, MAX(projected_at) projected_at')
            ->first();

        return response()->json([
            'total'           => (int) ($counts->total ?? 0),
            'matched'         => (int) ($counts->matched ?? 0),
            'academicOnly'    => (int) ($counts->academic_only ?? 0),
            'feesOnly'        => (int) ($counts->fees_only ?? 0),
            'academicRecords' => (int) ($counts->academic_records ?? 0),
            'feeRecords'      => (int) ($counts->fee_records ?? 0),
            'totalPaid'       => ($counts->total_paid ?? null) === null ? null : round((float) $counts->total_paid, 2),
            'projectedAt'     => $counts->projected_at ?? null,
            'datasets'        => $this->datasets->roles($tenantId),
            'filters'         => [
                'standards'         => $this->students->distinctValues($tenantId, 'standard'),
                'academicStandards' => $this->students->distinctValues($tenantId, 'academic_standard'),
                'divisions'         => $this->students->distinctValues($tenantId, 'division'),
                'quotas'            => $this->students->distinctValues($tenantId, 'student_quota'),
                'subjects'          => $this->subjects($tenantId),
            ],
        ]);
    }

    /**
     * The subjects this tenant's academic dataset contains.
     *
     * A DISTINCT over the tenant's dataset slice, which sounds alarming beside a
     * 388,401-row table and is not: (tenant_id, dataset, category) covers it
     * exactly, so MySQL answers from the index without reading a row, and there
     * are single digits of distinct values. Capped anyway — a filter dropdown
     * that needs more than 200 entries is not a dropdown.
     *
     * @return array<int, string>
     */
    private function subjects(string $tenantId): array
    {
        $dataset = $this->datasets->academic($tenantId);

        if ($dataset === null) {
            return [];
        }

        /*
          CACHED, BECAUSE THIS IS ON THE SUMMARY ENDPOINT.

          Both the People screen and the Departments screen call /summary on
          every visit, to decide which experience to render. Everything else in
          that response reads hpbrain_students, which has one row per student;
          this is the single query in it that touches the 388,401-row records
          table. It should be answered from the (tenant_id, dataset, category)
          index without reading a row — but "should" is doing real work in that
          sentence on a shared database under load, and the subject list changes
          only when an import runs.

          Same fingerprint as the structure and intelligence caches, so an import
          invalidates all three together.
        */
        $version = $this->intelligence->dataVersion($tenantId);

        return $this->cache->remember(
            $tenantId,
            "hpbrain:school:subjects:v1:{$tenantId}:{$version}",
            self::STRUCTURE_TTL_SECONDS,
            fn (): array => \Illuminate\Support\Facades\DB::table('hpbrain_operational_records')
                ->where('tenant_id', $tenantId)
                ->where('dataset', $dataset)
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->limit(200)
                ->pluck('category')
                ->map(fn ($v) => (string) $v)
                ->values()
                ->all(),
        );
    }

    /** GET students/{tenantId}/search?q= — a capped page of matches, for type-ahead. */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json(['data' => [], 'total' => 0, 'page' => 1, 'pageSize' => 0]);
        }

        return response()->json($this->students->paginate(
            $this->authTenantId($request),
            ['q' => $query],
            1,
            (int) $request->query('page_size', 25),
        ));
    }

    /**
     * GET students/{tenantId}/structure — the dataset's own dimensions.
     *
     * The Departments screen calls this when the organization has no HR
     * departments. See AcademicRecordRepository::structure() for why this is not
     * a department list wearing a different label.
     */
    public function structure(Request $request): JsonResponse
    {
        $tenantId = $this->authTenantId($request);

        /*
          CACHED ON THE SAME DATA FINGERPRINT AS THE INTELLIGENCE.

          This is the Departments screen for a dataset-backed organization, so it
          is on the critical path of the demo — and its four academic dimensions
          are GROUP BY / COUNT(DISTINCT) over the tenant's entire slice of
          hpbrain_operational_records, which for Lions is 388,401 rows. That is
          seconds of work to produce a few dozen labels that change only when an
          import runs.

          The key carries a hash of the tenant's import-job and projection
          high-water marks, so an import invalidates it immediately and every
          read in between is free. The TTL bounds the cache; it does not decide
          freshness.
        */
        $version = $this->intelligence->dataVersion($tenantId);

        $structure = $this->cache->remember(
            $tenantId,
            "hpbrain:school:structure:v1:{$tenantId}:{$version}",
            self::STRUCTURE_TTL_SECONDS,
            fn (): array => $this->records->structure($tenantId),
        );

        return response()->json($structure);
    }

    /**
     * GET students/{tenantId}/intelligence — the organization-wide analysis.
     *
     * Cached against a data fingerprint. No language model is called here or
     * anywhere below it.
     */
    public function intelligence(Request $request): JsonResponse
    {
        $tenantId = $this->authTenantId($request);
        $fresh = $request->boolean('fresh');

        return response()->json($this->intelligence->forTenant($tenantId, $fresh));
    }

    /**
     * GET students/{tenantId}/{id} — one student, with the first page of both
     * record types.
     *
     * The two record lists are paged from the start rather than fetched whole: a
     * student with four years of results across nine subjects and three exam
     * types has several hundred rows, and there is no reason to send them all to
     * open a profile.
     */
    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->authTenantId($request);
        $student = $this->students->find($tenant, $id);

        if ($student === null) {
            return response()->json(['error' => 'student_not_found'], 404);
        }

        return response()->json([
            'student'         => $student,
            'academicRecords' => $this->records->academicForStudent($tenant, $student['studentRef'], 1, 50),
            'feeRecords'      => $this->records->feesForStudent($tenant, $student['studentRef'], 1, 50),
            'relationship'    => $this->studentRelationship($student),
        ]);
    }

    /** GET students/{tenantId}/by-ref/{studentRef} — the same, keyed by enrollment number. */
    public function byRef(Request $request, string $tenantId, string $studentRef): JsonResponse
    {
        $tenant = $this->authTenantId($request);
        $student = $this->students->findByRef($tenant, $studentRef);

        if ($student === null) {
            return response()->json(['error' => 'student_not_found'], 404);
        }

        return $this->show($request, $tenantId, $student['id']);
    }

    /** GET students/{tenantId}/{id}/academic-records — paged. */
    public function academicRecords(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->authTenantId($request);
        $student = $this->students->find($tenant, $id);

        if ($student === null) {
            return response()->json(['error' => 'student_not_found'], 404);
        }

        return response()->json($this->records->academicForStudent(
            $tenant,
            $student['studentRef'],
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 50),
        ));
    }

    /** GET students/{tenantId}/{id}/fee-records — paged. */
    public function feeRecords(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->authTenantId($request);
        $student = $this->students->find($tenant, $id);

        if ($student === null) {
            return response()->json(['error' => 'student_not_found'], 404);
        }

        return response()->json($this->records->feesForStudent(
            $tenant,
            $student['studentRef'],
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 50),
        ));
    }

    /**
     * What can honestly be said about one student's two record sets.
     *
     * The dates are the point. A student present in both files has results from
     * one period and receipts from another, and saying so is the difference
     * between a fact and an insinuation.
     *
     * @param  array<string, mixed>  $student
     * @return array<string, mixed>
     */
    private function studentRelationship(array $student): array
    {
        $hasBoth = ($student['inAcademic'] ?? false) && ($student['inFees'] ?? false);

        if (! $hasBoth) {
            return [
                'matched' => false,
                'note'    => ($student['inAcademic'] ?? false)
                    ? 'This student appears in the academic export only. No fee receipt carries their enrollment number.'
                    : 'This student appears in the fee register only. No exam result carries their GR number.',
            ];
        }

        $academicTo = $student['lastAcademicYear'] ?? null;
        $feeFrom = $student['firstReceiptDate'] ?? null;

        $overlaps = $academicTo !== null && $feeFrom !== null
            && (int) substr((string) $feeFrom, 0, 4) <= (int) $academicTo;

        return [
            'matched'         => true,
            'joinKey'         => 'enrollment_no = GR NO.',
            'academicYears'   => [$student['firstAcademicYear'] ?? null, $academicTo],
            'receiptDates'    => [$feeFrom, $student['lastReceiptDate'] ?? null],
            'contemporaneous' => $overlaps,
            'note'            => $overlaps
                ? 'The results and receipts cover overlapping periods.'
                : 'The results and receipts cover DIFFERENT periods. Read them as two separate histories — '
                  .'this student\'s marks do not describe the years they were paying for, and the receipts '
                  .'do not describe the years they were examined.',
        ];
    }
}
