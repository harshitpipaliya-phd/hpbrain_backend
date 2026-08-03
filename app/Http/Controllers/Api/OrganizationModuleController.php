<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrganizationModuleRepository;
use App\Repositories\ModuleRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationModuleController extends Controller
{
    public function __construct(
        private readonly OrganizationModuleRepository $repository,
        private readonly ModuleRepository $moduleRepo
    ) {
    }

    public function index(Request $request, string $tenantId): JsonResponse
    {
        $orgId = $request->query('org_id');

        return response()->json($this->repository->list($this->tenantId($request), $orgId));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'org_module_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_id'     => ['required', 'string'],
            'module_id'  => ['required', 'string'],
            'is_enabled' => ['nullable', 'boolean'],
            'config'     => ['nullable', 'array'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'is_enabled' => ['sometimes', 'nullable', 'boolean'],
            'config'     => ['sometimes', 'nullable', 'array'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'org_module_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'org_module_not_found'], 404);
    }
}
