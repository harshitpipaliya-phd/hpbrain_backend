<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\DashboardRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController extends Controller
{
    public function __construct(private readonly DashboardRepository $repository)
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
            : response()->json(['error' => 'dashboard_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_id'       => ['nullable', 'string'],
            'dashboard_key'=> ['required', 'string', 'max:255'],
            'name'         => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'industry_code'=> ['nullable', 'string', 'max:100'],
            'role_key'     => ['nullable', 'string', 'max:100'],
            'is_default'   => ['nullable', 'boolean'],
            'is_system'    => ['nullable', 'boolean'],
            'layout'       => ['nullable', 'array'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'dashboard_key'=> ['sometimes', 'string', 'max:255'],
            'name'         => ['sometimes', 'string', 'max:255'],
            'description'  => ['sometimes', 'nullable', 'string'],
            'industry_code'=> ['sometimes', 'nullable', 'string', 'max:100'],
            'role_key'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_default'   => ['sometimes', 'nullable', 'boolean'],
            'is_system'    => ['sometimes', 'nullable', 'boolean'],
            'layout'       => ['sometimes', 'nullable', 'array'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'dashboard_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'dashboard_not_found'], 404);
    }
}
