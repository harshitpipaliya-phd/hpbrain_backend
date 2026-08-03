<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrganizationUnitRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationUnitController extends Controller
{
    public function __construct(private readonly OrganizationUnitRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $orgId = $request->query('orgId');
        $unitType = $request->query('unitType');
        $status = $request->query('status');

        return response()->json($this->repository->list($tenantId, $orgId, $unitType, $status));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'organization_unit_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_id'        => ['nullable', 'string'],
            'unit_type'     => ['nullable', 'string', 'max:50'],
            'name'          => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'code'          => ['nullable', 'string', 'max:100'],
            'parent_unit_id'=> ['nullable', 'string'],
            'head_id'       => ['nullable', 'string'],
            'location'      => ['nullable', 'string', 'max:255'],
            'cost_center'   => ['nullable', 'string', 'max:100'],
            'status'        => ['nullable', 'string', 'max:50'],
            'metadata'      => ['nullable', 'array'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'org_id'        => ['sometimes', 'nullable', 'string'],
            'unit_type'     => ['sometimes', 'nullable', 'string', 'max:50'],
            'name'          => ['sometimes', 'string', 'max:255'],
            'description'   => ['sometimes', 'nullable', 'string'],
            'code'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'parent_unit_id'=> ['sometimes', 'nullable', 'string'],
            'head_id'       => ['sometimes', 'nullable', 'string'],
            'location'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'cost_center'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'status'        => ['sometimes', 'nullable', 'string', 'max:50'],
            'metadata'      => ['sometimes', 'nullable', 'array'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row ? response()->json($row) : response()->json(['error' => 'organization_unit_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'organization_unit_not_found'], 404);
    }

    public function hierarchy(Request $request, string $tenantId): JsonResponse
    {
        $orgId = $request->query('orgId');

        if (!$orgId) {
            return response()->json(['error' => 'orgId_required'], 422);
        }

        return response()->json($this->repository->getHierarchy($this->tenantId($request), $orgId));
    }

    public function tree(Request $request, string $tenantId): JsonResponse
    {
        $orgId = $request->query('orgId');

        if (!$orgId) {
            return response()->json(['error' => 'orgId_required'], 422);
        }

        return response()->json($this->repository->getHierarchy($this->tenantId($request), $orgId));
    }
}
