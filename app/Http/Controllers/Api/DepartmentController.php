<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Departments are read from the ERP table hrms_departments. */
final class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);

        $rows = DB::table('hrms_departments')
            ->where('sub_institute_id', $t)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        return response()->json($rows->map(fn ($r) => $this->map((array) $r))->all());
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);

        $row = DB::table('hrms_departments')
            ->where('id', $id)
            ->where('sub_institute_id', $t)
            ->whereNull('deleted_at')
            ->first();

        return $row
            ? response()->json($this->map((array) $row))
            : response()->json(['error' => 'department_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'min:1'],
            'description' => ['nullable', 'string'],
            'parentId'    => ['nullable', 'integer'],
        ]);

        $t = $this->tenantId($request);

        $now = now()->format('Y-m-d H:i:s');

        $id = DB::table('hrms_departments')->insertGetId([
            'department'           => $data['name'],
            'roles_responsibility' => $data['description'] ?? null,
            'parent_id'            => $data['parentId'] ?? 0,
            'status'               => 1,
            'is_calculated'        => 0,
            'sub_institute_id'     => $t,
            'created_by'           => $this->actorErpId($request),
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        return response()->json($this->map((array) DB::table('hrms_departments')->find($id)), 201);
    }

    /** ERP row -> the shape web/src/api/department.ts expects. */
    private function map(array $row): array
    {
        $parent = ($row['parent_id'] ?? 0) ? (string) $row['parent_id'] : null;

        return [
            'id'                 => (string) $row['id'],
            'name'               => (string) ($row['department'] ?? ''),
            'description'        => $row['roles_responsibility'] ?? null,
            'departmentType'     => 'department',
            'parentDepartmentId' => $parent,
            'headId'             => null,
            'orgId'              => (string) ($row['sub_institute_id'] ?? ''),
            'status'             => $row['deleted_at'] ? 'archived' : (((int) ($row['status'] ?? 0)) === 1 ? 'active' : 'inactive'),
            'createdBy'          => (string) ($row['created_by'] ?? 'unknown'),
            'createdDate'        => $row['created_at'] ?? null,
            'updatedDate'        => $row['updated_at'] ?? null,
        ];
    }

    public function audit(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_audit_logs')
                ->where('tenant_id', $this->tenantId($request))
                ->where('entity_type', 'Department')->where('entity_id', $id)
                ->orderByDesc('created_at')->get()
        );
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'parentId'    => ['sometimes', 'nullable', 'integer'],
        ]);

        $t = $this->tenantId($request);

        $map = ['name' => 'department', 'description' => 'roles_responsibility', 'parentId' => 'parent_id'];
        $fields = [];
        foreach ($data as $k => $v) { $fields[$map[$k]] = $v; }

        if ($fields === []) {
            return response()->json(['error' => 'no_fields_to_update'], 422);
        }

        $fields['updated_at'] = now()->format('Y-m-d H:i:s');
        $n = DB::table('hrms_departments')
            ->where('id', $id)
            ->where('sub_institute_id', $t)
            ->whereNull('deleted_at')
            ->update($fields);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'department_not_found'], 404);
    }

    public function archive(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);

        $n = DB::table('hrms_departments')
            ->where('id', $id)
            ->where('sub_institute_id', $t)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()->format('Y-m-d H:i:s')]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'department_not_found'], 404);
    }

    public function twin(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);

        $row = DB::table('hrms_departments')
            ->where('id', $id)
            ->where('sub_institute_id', $t)
            ->whereNull('deleted_at')
            ->first();

        if (! $row) {
            return response()->json(['error' => 'department_not_found'], 404);
        }

        $did = (string) $id;

        $personIds = DB::table('tbluser')
            ->where('sub_institute_id', $t)
            ->where('department_id', $id)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->pluck('id')->map(fn ($v) => (string) $v)->all();

        $assignments = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $t)
            ->where(function ($w) use ($did, $personIds) {
                $w->where(fn ($q) => $q->where('target_type', 'Department')->where('target_id', $did));
                if ($personIds !== []) {
                    $w->orWhere(fn ($q) => $q->where('target_type', 'Person')->whereIn('target_id', $personIds));
                }
            })
            ->get();

        $proficiency = $assignments->isEmpty() ? collect() : DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $t)
            ->whereIn('assignment_id', $assignments->pluck('id')->all())
            ->orderByDesc('assessed_date')
            ->get()
            ->groupBy('assignment_id')
            ->map(fn ($rows) => $rows->first());

        $capabilityHeatmap = $assignments
            ->groupBy('capability_id')
            ->map(function ($group, $capabilityId) use ($proficiency, $did) {
                $levels = [];

                foreach ($group as $a) {
                    $p = $proficiency->get($a->id);
                    if (! $p) { continue; }

                    $dims = array_values(array_filter(
                        [$p->knowledge_level, $p->ability_level, $p->skill_level, $p->behaviour_level, $p->attitude_level],
                        fn ($v) => $v !== null
                    ));

                    if ($dims !== []) {
                        $levels[] = array_sum(array_map('floatval', $dims)) / count($dims);
                    }
                }

                return [
                    'capabilityId'  => (string) $capabilityId,
                    'departmentId'  => $did,
                    'averageLevel'  => $levels === [] ? 0.0 : round(array_sum($levels) / count($levels), 2),
                    'assessedCount' => count($levels),
                ];
            })->values();

        $openRiskSignals = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->where('department_id', $did)
            ->whereRaw('LOWER(status) NOT IN (?, ?, ?)', ['resolved', 'closed', 'dismissed'])
            ->whereRaw('LOWER(severity) IN (?, ?)', ['high', 'critical'])
            ->count();

        $decisions = $personIds === [] ? collect() : DB::table('hpbrain_decisions')
            ->where('tenant_id', $t)->whereIn('decided_by', $personIds)->get();

        $approved = $decisions->filter(
            fn ($d) => in_array(strtolower((string) $d->status), ['approved', 'accepted'], true)
        )->count();

        $timeline = DB::table('hpbrain_audit_logs')
            ->where('tenant_id', $t)
            ->where(function ($w) use ($did, $personIds) {
                $w->where(fn ($q) => $q->where('entity_type', 'Department')->where('entity_id', $did));
                if ($personIds !== []) {
                    $w->orWhereIn('actor_id', $personIds);
                }
            })
            ->orderByDesc('created_at')->limit(25)->get()
            ->map(fn ($a) => [
                'type'      => (string) $a->action,
                'actorId'   => (string) ($a->actor_id ?? ''),
                'createdAt' => $a->created_at,
            ])->values();

        return response()->json([
            'department'           => $this->map((array) $row),
            'personCount'          => count($personIds),
            'capabilityHeatmap'    => $capabilityHeatmap,
            'openRiskSignalCount'  => $openRiskSignals,
            'decisionCount'        => $decisions->count(),
            'decisionApprovalRate' => $decisions->isEmpty() ? null : round($approved / $decisions->count(), 4),
            'timeline'             => $timeline,
            'openCasesTenantWide'  => DB::table('hpbrain_cases')
                ->where('tenant_id', $t)->whereNotIn('status', ['closed'])->count(),
        ]);
    }
}
