<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OrganizationConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationConfigController extends Controller
{
    public function __construct(private readonly OrganizationConfigRepository $repository)
    {
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
            : response()->json(['error' => 'config_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_id'       => ['required', 'string'],
            'config_key'   => ['required', 'string', 'max:255'],
            'config_value' => ['nullable', 'string'],
            'config_type'  => ['nullable', 'string', 'max:50'],
            'description'  => ['nullable', 'string'],
            'is_active'    => ['nullable', 'boolean'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'config_value' => ['sometimes', 'nullable', 'string'],
            'config_type'  => ['sometimes', 'nullable', 'string', 'max:50'],
            'description'  => ['sometimes', 'nullable', 'string'],
            'is_active'    => ['sometimes', 'nullable', 'boolean'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'config_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'config_not_found'], 404);
    }
}
