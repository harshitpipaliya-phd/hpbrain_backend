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
    public function index(): JsonResponse
    {
        $rows = DB::table('hrms_departments')->whereNull('deleted_at')->orderBy('id')->get();

        return response()->json($rows->map(fn ($r) => $this->map((array) $r))->all());
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = DB::table('hrms_departments')->where('id', $id)->whereNull('deleted_at')->first();

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
            'orgId'       => ['required', 'integer'],
        ]);

        $now = now()->format('Y-m-d H:i:s');

        $id = DB::table('hrms_departments')->insertGetId([
            'department'           => $data['name'],
            'roles_responsibility' => $data['description'] ?? null,
            'parent_id'            => $data['parentId'] ?? 0,
            'status'               => 1,
            'is_calculated'        => 0,
            'sub_institute_id'     => $data['orgId'],
            'created_by'           => $this->actorId($request),
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
            'headId'             => null,  // hrms_departments has no head column
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
                ->orderByDesc('created_date')->get()
        );
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'parentId'    => ['sometimes', 'nullable', 'integer'],
        ]);

        $map = ['name' => 'department', 'description' => 'roles_responsibility', 'parentId' => 'parent_id'];
        $fields = [];
        foreach ($data as $k => $v) { $fields[$map[$k]] = $v; }

        if ($fields === []) {
            return response()->json(['error' => 'no_fields_to_update'], 422);
        }

        $fields['updated_at'] = now()->format('Y-m-d H:i:s');
        $n = DB::table('hrms_departments')->where('id', $id)->whereNull('deleted_at')->update($fields);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'department_not_found'], 404);
    }

    public function archive(Request $request, string $tenantId, string $id): JsonResponse
    {
        $n = DB::table('hrms_departments')->where('id', $id)->whereNull('deleted_at')
            ->update(['deleted_at' => now()->format('Y-m-d H:i:s')]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'department_not_found'], 404);
    }

    /**
     * The department "twin" — the Brain's view of an ERP entity: what it knows,
     * how firmly, and with what open questions. This is the Organizational
     * Twin idea in miniature.
     */
    public function twin(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = DB::table('hrms_departments')->where('id', $id)->whereNull('deleted_at')->first();

        if (! $row) {
            return response()->json(['error' => 'department_not_found'], 404);
        }

        $t = $this->tenantId($request);
        $headcount = DB::table('tbluser')->where('department_id', $id)->whereNull('deleted_at')->where('status', 1)->count();

        return response()->json([
            'department' => $this->map((array) $row),
            'headcount'  => $headcount,
            'signals'    => DB::table('hpbrain_signals')->where('tenant_id', $t)->count(),
            'openCases'  => DB::table('hpbrain_cases')->where('tenant_id', $t)->whereNotIn('status', ['closed'])->count(),
        ]);
    }
}
