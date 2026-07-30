<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ExecutorRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

/**
 * Response shapes must match the Express originals exactly — web/src/api/*.ts
 * consumes them literally (ADR-007). The Multi-Agent Monitor reads these rows.
 *
 * The registry of who — or what — can execute. A human executor that names no
 * person is a capacity claim with nobody behind it, which is why personId is
 * conditional on executor_type rather than merely encouraged.
 */
final class ExecutorController extends Controller
{
    public function __construct(private readonly ExecutorRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->repository->list($this->tenantId($request), $request->query('status'))
        );
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->findById($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'executor_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenantId'         => ['required', 'string', 'min:1'],
            'executorType'     => ['required', Rule::in(['human', 'system', 'ai', 'external'])],
            'name'             => ['required', 'string', 'min:1', 'max:300'],
            // A human executor must name the human. The other three types have
            // no person behind them by definition.
            'personId'         => ['required_if:executorType,human', 'nullable', 'string', 'size:36'],
            'capabilityTags'   => ['nullable', 'array'],
            'capabilityTags.*' => ['string', 'max:100'],
            'trustLevel'       => ['nullable', 'numeric', 'between:0,1'],
            'maxConcurrent'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenant   = $this->tenantId($request);
        $personId = $data['personId'] ?? null;

        // The foreign key proves the person exists; it says nothing about who
        // owns them. Without this, one tenant could register an executor
        // pointing at another tenant's employee.
        if ($personId !== null) {
            $ownsPerson = DB::table('hpbrain_people')
                ->where('tenant_id', $tenant)
                ->where('id', $personId)
                ->exists();

            if (! $ownsPerson) {
                return response()->json(['error' => 'person_not_found'], 422);
            }
        }

        $row = [
            'id'               => Uuid::uuid4()->toString(),
            // tenantId always comes from the token, never the body — a client
            // must not be able to write into another tenant by changing a
            // payload field.
            'tenant_id'        => $tenant,
            'executor_type'    => $data['executorType'],
            'name'             => $data['name'],
            'person_id'        => $personId,
            'capability_tags'  => json_encode($data['capabilityTags'] ?? []),
            'trust_level'      => $data['trustLevel'] ?? 0.5,
            'max_concurrent'   => $data['maxConcurrent'] ?? 1,
            // DERIVED, never accepted from the client. current_workload is the
            // count of in-flight hpbrain_eso_executions for this executor; a
            // caller that can set it can understate its own load and be handed
            // work it has no capacity for — an executor lying about itself is
            // exactly what the routing logic must not be able to be told.
            // Always 0 at registration: a brand-new executor has nothing
            // in flight by construction.
            'current_workload' => 0,
            'available'        => true,
            'status'           => 'active',
            // NOTE: hpbrain_executors has no created_by column. Do not add one
            // to this array — the insert would fail with unknown column.
        ];

        $this->repository->insert($row);

        // Re-read so `capability_tags` comes back as an array; the Multi-Agent
        // Monitor calls .map on it and a string has no .map.
        return response()->json($this->repository->findById($tenant, $row['id']), 201);
    }
}
