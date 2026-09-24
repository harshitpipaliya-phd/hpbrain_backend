<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\FeatureFlagRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FeatureFlagController extends Controller
{
    public function __construct(private readonly FeatureFlagRepository $repository)
    {
    }

    public function index(Request $request, string $tenantId): JsonResponse
    {
        return response()->json($this->repository->list($this->tenantId($request)));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'feature_flag_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'flag_key'           => ['required', 'string', 'max:255'],
            'flag_name'          => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string'],
            'enabled'            => ['nullable', 'boolean'],
            'level'              => ['nullable', 'string', 'max:50'],
            'level_id'           => ['nullable', 'string', 'max:255'],
            'rollout_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'rules'              => ['nullable', 'array'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'flag_key'           => ['sometimes', 'string', 'max:255'],
            'flag_name'          => ['sometimes', 'string', 'max:255'],
            'description'        => ['sometimes', 'nullable', 'string'],
            'enabled'            => ['sometimes', 'nullable', 'boolean'],
            'level'              => ['sometimes', 'nullable', 'string', 'max:50'],
            'level_id'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'rollout_percentage' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'rules'              => ['sometimes', 'nullable', 'array'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'feature_flag_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'feature_flag_not_found'], 404);
    }
}
