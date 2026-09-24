<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\LocationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LocationController extends Controller
{
    public function __construct(private readonly LocationRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $orgId = $request->query('orgId');

        return response()->json($this->repository->list($tenantId, $orgId));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'location_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_id'           => ['nullable', 'string'],
            'location_type_id' => ['nullable', 'string'],
            'name'             => ['required', 'string', 'max:255'],
            'address'          => ['nullable', 'string'],
            'city'             => ['nullable', 'string', 'max:100'],
            'state'            => ['nullable', 'string', 'max:100'],
            'country'          => ['nullable', 'string', 'max:100'],
            'postal_code'      => ['nullable', 'string', 'max:20'],
            'timezone'         => ['nullable', 'string', 'max:50'],
            'phone'            => ['nullable', 'string', 'max:50'],
            'email'            => ['nullable', 'string', 'max:255'],
            'metadata'         => ['nullable', 'array'],
            'is_headquarters'  => ['nullable', 'boolean'],
            'status'           => ['nullable', 'string', 'max:50'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'org_id'           => ['sometimes', 'nullable', 'string'],
            'location_type_id' => ['sometimes', 'nullable', 'string'],
            'name'             => ['sometimes', 'string', 'max:255'],
            'address'          => ['sometimes', 'nullable', 'string'],
            'city'             => ['sometimes', 'nullable', 'string', 'max:100'],
            'state'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'country'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code'      => ['sometimes', 'nullable', 'string', 'max:20'],
            'timezone'         => ['sometimes', 'nullable', 'string', 'max:50'],
            'phone'            => ['sometimes', 'nullable', 'string', 'max:50'],
            'email'            => ['sometimes', 'nullable', 'string', 'max:255'],
            'metadata'         => ['sometimes', 'nullable', 'array'],
            'is_headquarters'  => ['sometimes', 'nullable', 'boolean'],
            'status'           => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row ? response()->json($row) : response()->json(['error' => 'location_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'location_not_found'], 404);
    }

    public function headquarters(Request $request, string $tenantId): JsonResponse
    {
        $rows = $this->repository->list($this->tenantId($request), $tenantId, true);

        return response()->json($rows);
    }
}
