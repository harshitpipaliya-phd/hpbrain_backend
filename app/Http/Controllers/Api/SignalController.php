<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\SignalRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SignalController extends Controller
{
    public function __construct(private readonly SignalRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->repository->list($this->tenantId($request), $request->query('status')));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->findById($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'signal_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source'         => ['required', 'string', 'max:190'],
            'classification' => ['required', 'string', 'max:190'],
            'priority'       => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'severity'       => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'confidence'     => ['nullable', 'numeric', 'between:0,1'],
            'metadata'       => ['nullable', 'array'],
        ]);

        return response()->json($this->repository->insert([
            'tenant_id'      => $this->tenantId($request),
            'source'         => $data['source'],
            'classification' => $data['classification'],
            'priority'       => $data['priority'] ?? 'medium',
            'severity'       => $data['severity'] ?? 'medium',
            'confidence'     => $data['confidence'] ?? null,
            'metadata'       => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'status'         => 'new',
            'created_by'     => $this->actorId($request),
        ]), 201);
    }

    public function changeStatus(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['new', 'triaged', 'investigating', 'resolved', 'dismissed'])],
        ]);

        $row = $this->repository->updateFields($this->tenantId($request), $id, ['status' => $data['status']]);

        return $row ? response()->json($row) : response()->json(['error' => 'signal_not_found'], 404);
    }
}
