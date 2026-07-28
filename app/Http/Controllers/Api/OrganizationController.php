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
 */
final class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationRepository $repository)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->repository->list());
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = collect($this->repository->list())->firstWhere('id', (int) $id);

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

        // institute_detail.created_by and org_details.created_by are BIGINT;
        // see Controller::actorErpId().
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

        $map = ['name' => 'organization_name', 'orgCode' => 'organization_code', 'industry' => 'industry_type'];
        $fields = [];
        foreach ($data as $k => $v) { $fields[$map[$k]] = $v; }

        if ($fields === []) {
            return response()->json(['error' => 'no_fields_to_update'], 422);
        }

        $fields['updated_at'] = now()->format('Y-m-d H:i:s');
        $n = DB::table('institute_detail')->where('sub_institute_id', $id)->whereNull('deleted_at')->update($fields);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'organization_not_found'], 404);
    }

    /**
     * Soft delete only. These rows are ERP-owned system-of-record data; the
     * Brain archives, it never destroys what it does not own.
     */
    public function archive(Request $request, string $tenantId, string $id): JsonResponse
    {
        $n = DB::table('institute_detail')->where('sub_institute_id', $id)->whereNull('deleted_at')
            ->update(['deleted_at' => now()->format('Y-m-d H:i:s')]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'organization_not_found'], 404);
    }
}
