<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\PersonRoleRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PersonRoleController extends Controller
{
    public function __construct(private readonly PersonRoleRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $personId = $request->query('personId');
        $roleId = $request->query('roleId');
        $orgId = $request->query('orgId');

        return response()->json($this->repository->list($tenantId, $personId, $roleId, $orgId));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'person_role_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'person_id'  => ['required', 'string'],
            'role_id'    => ['required', 'string'],
            'org_id'     => ['nullable', 'string'],
            'unit_id'    => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date'],
            'is_primary' => ['nullable', 'boolean'],
            'metadata'   => ['nullable', 'array'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'person_id'  => ['sometimes', 'string'],
            'role_id'    => ['sometimes', 'string'],
            'org_id'     => ['sometimes', 'nullable', 'string'],
            'unit_id'    => ['sometimes', 'nullable', 'string'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date'   => ['sometimes', 'nullable', 'date'],
            'is_primary' => ['sometimes', 'nullable', 'boolean'],
            'metadata'   => ['sometimes', 'nullable', 'array'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row ? response()->json($row) : response()->json(['error' => 'person_role_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'person_role_not_found'], 404);
    }

    public function byPerson(Request $request, string $tenantId, string $personId): JsonResponse
    {
        return response()->json($this->repository->findByPerson($this->tenantId($request), $personId));
    }

    public function byRole(Request $request, string $tenantId, string $roleId): JsonResponse
    {
        return response()->json($this->repository->findByRole($this->tenantId($request), $roleId));
    }
}
