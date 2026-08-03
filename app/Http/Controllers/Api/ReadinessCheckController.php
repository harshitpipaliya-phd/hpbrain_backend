<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ReadinessCheckRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReadinessCheckController extends Controller
{
    public function __construct(private readonly ReadinessCheckRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $orgId = $request->query('orgId');
        $checkType = $request->query('checkType');
        $status = $request->query('status');

        return response()->json($this->repository->list($tenantId, $orgId, $checkType, $status));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'readiness_check_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_id'     => ['nullable', 'string'],
            'check_type' => ['required', 'string', 'max:100'],
            'check_name' => ['required', 'string', 'max:255'],
            'status'     => ['nullable', 'string', 'max:50'],
            'message'    => ['nullable', 'string'],
            'metadata'   => ['nullable', 'array'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'check_type' => ['sometimes', 'string', 'max:100'],
            'check_name' => ['sometimes', 'string', 'max:255'],
            'status'     => ['sometimes', 'nullable', 'string', 'max:50'],
            'message'    => ['sometimes', 'nullable', 'string'],
            'metadata'   => ['sometimes', 'nullable', 'array'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row ? response()->json($row) : response()->json(['error' => 'readiness_check_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'readiness_check_not_found'], 404);
    }
}
