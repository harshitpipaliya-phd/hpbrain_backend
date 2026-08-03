<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\RoleRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RoleController extends Controller
{
    public function __construct(private readonly RoleRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $category = $request->query('category');

        return response()->json($this->repository->list($tenantId, $category));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'role_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role_key'   => ['required', 'string', 'max:100'],
            'name'       => ['required', 'string', 'max:255'],
            'description'=> ['nullable', 'string'],
            'category'   => ['nullable', 'string', 'max:100'],
            'permissions'=> ['nullable', 'array'],
            'is_system'  => ['nullable', 'boolean'],
            'status'     => ['nullable', 'string', 'max:50'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'role_key'   => ['sometimes', 'string', 'max:100'],
            'name'       => ['sometimes', 'string', 'max:255'],
            'description'=> ['sometimes', 'nullable', 'string'],
            'category'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'permissions'=> ['sometimes', 'nullable', 'array'],
            'is_system'  => ['sometimes', 'nullable', 'boolean'],
            'status'     => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row ? response()->json($row) : response()->json(['error' => 'role_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'role_not_found'], 404);
    }
}
