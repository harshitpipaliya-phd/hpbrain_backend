<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrganizationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Organizations come from the institute ERP (institute_detail + org_details),
 * not from a Brain-owned table. See README "Where the data comes from".
 *
 * Every list and detail response is scoped to the authenticated tenant. Only a
 * platform admin may address an organization that is not the caller's own, and
 * that is bounded by EnsureTenantScope (the tenant must exist and not be
 * archived).
 */
final class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);

        return response()->json($this->repository->list($t));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);

        $row = collect($this->repository->list($t))->firstWhere('id', (int) $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'organization_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'min:1'],
            'orgCode'   => ['nullable', 'string'],
            'industry'  => ['nullable', 'string'],
            'legalName' => ['nullable', 'string'],
            'logo'      => ['nullable', 'string'],
        ]);

        $data['createdBy'] = $this->actorErpId($request);

        return response()->json($this->repository->create($data), 201);
    }

    public function audit(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_audit_logs')
                ->where('tenant_id', $this->tenantId($request))
                ->where('entity_type', 'Organization')->where('entity_id', $id)
                ->orderByDesc('created_at')->get()
        );
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'min:1', 'max:255'],
            'orgCode'   => ['sometimes', 'nullable', 'string'],
            'industry'  => ['sometimes', 'nullable', 'string'],
        ]);

        $t = $this->tenantId($request);

        $map = ['name' => 'organization_name', 'orgCode' => 'organization_code', 'industry' => 'industry_type'];
        $fields = [];
        foreach ($data as $k => $v) { $fields[$map[$k]] = $v; }

        if ($fields === []) {
            return response()->json(['error' => 'no_fields_to_update'], 422);
        }

        $fields['updated_at'] = now()->format('Y-m-d H:i:s');
        $n = DB::table('institute_detail')
            ->where('sub_institute_id', $id)
            ->where('sub_institute_id', $t)
            ->whereNull('deleted_at')
            ->update($fields);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'organization_not_found'], 404);
    }

    /**
     * Soft delete only. These rows are ERP-owned system-of-record data; the
     * Brain archives, it never destroys what it does not own.
     */
    public function archive(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);

        $n = DB::table('institute_detail')
            ->where('sub_institute_id', $id)
            ->where('sub_institute_id', $t)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()->format('Y-m-d H:i:s')]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'organization_not_found'], 404);
    }

    public function structure(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);

        $exists = DB::table('institute_detail')
            ->where('sub_institute_id', $id)
            ->where('sub_institute_id', $t)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            return response()->json(['error' => 'organization_not_found'], 404);
        }

        $departments = DB::table('hrms_departments')
            ->where('sub_institute_id', $t)
            ->whereNull('deleted_at')
            ->get(['id', 'department', 'parent_id', 'status'])
            ->map(fn ($d) => [
                'id' => (string) $d->id,
                'name' => (string) $d->department,
                'parentId' => (string) $d->parent_id,
                'status' => (string) $d->status,
            ])->values();

        $peopleByDepartment = DB::table('tbluser')
            ->where('sub_institute_id', $t)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->select('department_id', DB::raw('COUNT(*) as count'))
            ->groupBy('department_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->department_id => (int) $r->count]);

        $heads = DB::table('hrms_departments')
            ->where('sub_institute_id', $t)
            ->whereNull('deleted_at')
            ->get(['id', 'department'])
            ->mapWithKeys(fn ($d) => [(string) $d->id => (string) $d->department]);

        return response()->json([
            'departments' => $departments,
            'peopleByDepartment' => $peopleByDepartment,
            'heads' => $heads,
        ]);
    }

    public function dataQuality(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);

        $exists = DB::table('institute_detail')
            ->where('sub_institute_id', $id)
            ->where('sub_institute_id', $t)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            return response()->json(['error' => 'organization_not_found'], 404);
        }

        $peopleWithoutDept = DB::table('tbluser')
            ->where('sub_institute_id', $t)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('department_id')->orWhere('department_id', 0);
            })
            ->count();

        $peopleWithoutProfile = DB::table('tbluser')
            ->where('sub_institute_id', $t)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('user_profile_id')->orWhere('user_profile_id', 0);
            })
            ->count();

        $peopleWithoutEmail = DB::table('tbluser')
            ->where('sub_institute_id', $t)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('email')->orWhere('email', '');
            })
            ->count();

        $deptsWithoutHead = DB::table('hrms_departments')
            ->where('sub_institute_id', $t)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('parent_id')->orWhere('parent_id', 0);
            })
            ->count();

        $totalPeople = DB::table('tbluser')
            ->where('sub_institute_id', $t)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->count();

        $totalDepts = DB::table('hrms_departments')
            ->where('sub_institute_id', $t)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->count();

        $issues = [];

        if ($peopleWithoutDept > 0) {
            $issues[] = ['field' => 'department_id', 'count' => $peopleWithoutDept, 'severity' => 'medium'];
        }
        if ($peopleWithoutProfile > 0) {
            $issues[] = ['field' => 'user_profile_id', 'count' => $peopleWithoutProfile, 'severity' => 'medium'];
        }
        if ($peopleWithoutEmail > 0) {
            $issues[] = ['field' => 'email', 'count' => $peopleWithoutEmail, 'severity' => 'high'];
        }
        if ($deptsWithoutHead > 0) {
            $issues[] = ['field' => 'parent_id', 'count' => $deptsWithoutHead, 'severity' => 'low'];
        }

        $score = $totalPeople + $totalDepts > 0
            ? round((1 - array_sum(array_column($issues, 'count')) / ($totalPeople + $totalDepts)) * 100, 1)
            : 100.0;

        return response()->json([
            'score' => max(0.0, min(100.0, $score)),
            'totalPeople' => $totalPeople,
            'totalDepartments' => $totalDepts,
            'issues' => $issues,
            'completeness' => [
                'peopleWithDepartment' => $totalPeople - $peopleWithoutDept,
                'peopleWithProfile' => $totalPeople - $peopleWithoutProfile,
                'peopleWithEmail' => $totalPeople - $peopleWithoutEmail,
                'departmentsWithHead' => $totalDepts - $deptsWithoutHead,
            ],
        ]);
    }
}
