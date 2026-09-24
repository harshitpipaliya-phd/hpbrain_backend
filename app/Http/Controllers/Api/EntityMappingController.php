<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\EntityMappingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EntityMappingController extends Controller
{
    public function __construct(private readonly EntityMappingRepository $repository)
    {
    }

    public function index(Request $request, string $tenantId): JsonResponse
    {
        $sourceSystem = $request->query('source_system');

        if ($sourceSystem) {
            $items = $this->repository->findBySource($this->tenantId($request), $sourceSystem);
        } else {
            $items = $this->repository->list($this->tenantId($request));
        }

        return response()->json($items);
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'mapping_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_system'    => ['required', 'string', 'max:100'],
            'source_entity'    => ['required', 'string', 'max:255'],
            'source_field'     => ['required', 'string', 'max:255'],
            'universal_entity' => ['required', 'string', 'max:255'],
            'universal_field'  => ['required', 'string', 'max:255'],
            'mapping_type'     => ['nullable', 'string', 'max:50'],
            'transform_expression' => ['nullable', 'string'],
            'lookup_table'     => ['nullable', 'string', 'max:255'],
            'is_active'        => ['nullable', 'boolean'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'source_system'    => ['sometimes', 'string', 'max:100'],
            'source_entity'    => ['sometimes', 'string', 'max:255'],
            'source_field'     => ['sometimes', 'string', 'max:255'],
            'universal_entity' => ['sometimes', 'string', 'max:255'],
            'universal_field'  => ['sometimes', 'string', 'max:255'],
            'mapping_type'     => ['sometimes', 'nullable', 'string', 'max:50'],
            'transform_expression' => ['sometimes', 'nullable', 'string'],
            'lookup_table'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active'        => ['sometimes', 'nullable', 'boolean'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'mapping_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'mapping_not_found'], 404);
    }
}
