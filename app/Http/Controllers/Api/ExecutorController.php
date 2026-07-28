<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ExecutorRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Response shapes must match the Express originals exactly — web/src/api/*.ts
 * consumes them literally (ADR-007).
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
            'tenantId' => ['required', 'string', 'min:1'],
        ] + $this->rules());

        // tenantId always comes from the token, never the body — a client must
        // not be able to write into another tenant by changing a payload field.
        $row = $this->repository->insert(
            $this->toRow($data, $this->tenantId($request), $this->actorId($request))
        );

        return response()->json($row, 201);
    }

    /** Per-resource validation. Extend as fields are ported. */
    protected function rules(): array
    {
        return [];
    }

    protected function toRow(array $data, string $tenantId, string $actorId): array
    {
        unset($data['tenantId']);

        return array_merge($data, [
            'tenant_id'  => $tenantId,
            'created_by' => $actorId,
        ]);
    }
}
