<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\EsoExecutionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ESO runtime. Invariant 4: no ESO runs without a measurement plan defined
 * BEFORE it starts — an action we cannot measure is an action we do not take.
 *
 * Invariant 3 / ADR-004: EXECUTE ships DARK in v1. The executor binding is
 * restricted to 'human' here; autonomous AI execution is built and governed but
 * flag-off, because EXECUTE is the only verb that changes the world outside the
 * Brain.
 */
final class EsoExecutionController extends Controller
{
    public function __construct(private readonly EsoExecutionRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->repository->list($this->tenantId($request), $request->query('status')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'decisionId'      => ['required', 'string'],
            'esoDefinitionId' => ['nullable', 'string'],
            'executorType'    => ['required', Rule::in(['human'])],  // EXECUTE dark in v1
            'executorId'      => ['nullable', 'string'],
            'measurementPlan' => ['required', 'string', 'min:1'],    // Invariant 4
        ]);

        return response()->json($this->repository->insert([
            'tenant_id'         => $this->tenantId($request),
            'decision_id'       => $data['decisionId'],
            'eso_definition_id' => $data['esoDefinitionId'] ?? null,
            'executor_type'     => $data['executorType'],
            'executor_id'       => $data['executorId'] ?? null,
            'measurement_plan'  => $data['measurementPlan'],
            'status'            => 'running',
            'created_by'        => $this->actorId($request),
        ]), 201);
    }

    public function complete(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['completed', 'failed'])],
        ]);

        return response()->json($this->repository->updateFields($this->tenantId($request), $id, [
            'status' => $data['status'],
        ]));
    }

    public function history(Request $request, string $tenantId, string $esoId): JsonResponse
    {
        $all = $this->repository->list($this->tenantId($request));

        return response()->json(array_values(array_filter(
            $all, fn ($e) => ($e['eso_definition_id'] ?? null) === $esoId
        )));
    }

    /**
     * Rollback records a NEW terminal state; it never rewrites the original
     * execution row. An execution log that can be edited cannot answer "what
     * did we actually do?", which is the only reason to keep one.
     */
    public function rollback(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:1']]);

        $tenant = $this->tenantId($request);
        $existing = $this->repository->findById($tenant, $id);

        if (! $existing) {
            return response()->json(['error' => 'eso_execution_not_found'], 404);
        }

        return response()->json($this->repository->updateFields($tenant, $id, [
            'status'          => 'rolled_back',
            'rollback_reason' => $data['reason'],
        ]));
    }
}
