<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\PersonCompetencyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PersonCompetencyController extends Controller
{
    public function __construct(private readonly PersonCompetencyRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $personId = $request->query('personId');
        $competencyId = $request->query('competencyId');

        return response()->json($this->repository->list($tenantId, $personId, $competencyId));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'person_competency_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'person_id'     => ['required', 'string'],
            'competency_id' => ['required', 'string'],
            'current_level' => ['nullable', 'string', 'max:50'],
            'target_level'  => ['nullable', 'string', 'max:50'],
            'assessed_date' => ['nullable', 'date'],
            'metadata'      => ['nullable', 'array'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'current_level' => ['sometimes', 'nullable', 'string', 'max:50'],
            'target_level'  => ['sometimes', 'nullable', 'string', 'max:50'],
            'assessed_date' => ['sometimes', 'nullable', 'date'],
            'metadata'      => ['sometimes', 'nullable', 'array'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row ? response()->json($row) : response()->json(['error' => 'person_competency_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'person_competency_not_found'], 404);
    }

    public function byPerson(Request $request, string $tenantId, string $personId): JsonResponse
    {
        return response()->json($this->repository->findByPerson($this->tenantId($request), $personId));
    }
}
