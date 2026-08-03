<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ModuleRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ModuleController extends Controller
{
    public function __construct(private readonly ModuleRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        return response()->json($this->repository->list($tenantId));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'module_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module_key'  => ['required', 'string', 'max:100'],
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'version'     => ['nullable', 'string', 'max:50'],
            'category'    => ['nullable', 'string', 'max:100'],
            'is_core'     => ['nullable', 'boolean'],
            'is_enabled'  => ['nullable', 'boolean'],
            'dependencies'=> ['nullable', 'array'],
            'config_schema'=> ['nullable', 'array'],
            'sort_order'  => ['nullable', 'integer'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'module_key'   => ['sometimes', 'string', 'max:100'],
            'name'         => ['sometimes', 'string', 'max:255'],
            'description'  => ['sometimes', 'nullable', 'string'],
            'version'      => ['sometimes', 'nullable', 'string', 'max:50'],
            'category'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_core'      => ['sometimes', 'nullable', 'boolean'],
            'is_enabled'   => ['sometimes', 'nullable', 'boolean'],
            'dependencies' => ['sometimes', 'nullable', 'array'],
            'config_schema'=> ['sometimes', 'nullable', 'array'],
            'sort_order'   => ['sometimes', 'nullable', 'integer'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'module_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'module_not_found'], 404);
    }
}
