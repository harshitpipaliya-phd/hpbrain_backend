<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\PositionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PositionController extends Controller
{
    public function __construct(private readonly PositionRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $orgId = $request->query('orgId');
        $unitId = $request->query('unitId');

        return response()->json($this->repository->list($tenantId, $orgId, $unitId));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'position_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_id'               => ['nullable', 'string'],
            'unit_id'              => ['nullable', 'string'],
            'title'                => ['required', 'string', 'max:255'],
            'description'          => ['nullable', 'string'],
            'employment_type'      => ['nullable', 'string', 'max:50'],
            'is_vacant'            => ['nullable', 'boolean'],
            'reports_to_position_id' => ['nullable', 'string'],
            'metadata'             => ['nullable', 'array'],
            'status'               => ['nullable', 'string', 'max:50'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'org_id'               => ['sometimes', 'nullable', 'string'],
            'unit_id'              => ['sometimes', 'nullable', 'string'],
            'title'                => ['sometimes', 'string', 'max:255'],
            'description'          => ['sometimes', 'nullable', 'string'],
            'employment_type'      => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_vacant'            => ['sometimes', 'nullable', 'boolean'],
            'reports_to_position_id' => ['sometimes', 'nullable', 'string'],
            'metadata'             => ['sometimes', 'nullable', 'array'],
            'status'               => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row ? response()->json($row) : response()->json(['error' => 'position_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'position_not_found'], 404);
    }
}
