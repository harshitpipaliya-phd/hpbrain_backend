<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ReportingStructureRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportingStructureController extends Controller
{
    public function __construct(private readonly ReportingStructureRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $orgId = $request->query('orgId');
        $reportingType = $request->query('reportingType');

        return response()->json($this->repository->list($tenantId, $orgId, $reportingType));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'reporting_structure_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_id'             => ['nullable', 'string'],
            'reporter_person_id' => ['required', 'string'],
            'reportee_person_id' => ['required', 'string'],
            'reporting_type'     => ['nullable', 'string', 'max:50'],
            'unit_id'            => ['nullable', 'string'],
            'start_date'         => ['nullable', 'date'],
            'end_date'           => ['nullable', 'date'],
            'metadata'           => ['nullable', 'array'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'org_id'             => ['sometimes', 'nullable', 'string'],
            'reporter_person_id' => ['sometimes', 'string'],
            'reportee_person_id' => ['sometimes', 'string'],
            'reporting_type'     => ['sometimes', 'nullable', 'string', 'max:50'],
            'unit_id'            => ['sometimes', 'nullable', 'string'],
            'start_date'         => ['sometimes', 'nullable', 'date'],
            'end_date'           => ['sometimes', 'nullable', 'date'],
            'metadata'           => ['sometimes', 'nullable', 'array'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row ? response()->json($row) : response()->json(['error' => 'reporting_structure_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'reporting_structure_not_found'], 404);
    }

    public function forPerson(Request $request, string $tenantId, string $personId): JsonResponse
    {
        $orgId = $request->query('orgId');
        $reporters = $this->repository->findByReporter($this->tenantId($request), $personId);
        $reportees = $this->repository->findByReportee($this->tenantId($request), $personId);

        return response()->json([
            'reporters' => $reporters,
            'reportees' => $reportees,
        ]);
    }
}
