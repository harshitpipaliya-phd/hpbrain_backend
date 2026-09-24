<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ConfigVersionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConfigVersionController extends Controller
{
    public function __construct(private readonly ConfigVersionRepository $repository)
    {
    }

    public function index(Request $request, string $tenantId): JsonResponse
    {
        $configType = $request->query('config_type');
        $configKey = $request->query('config_key');

        return response()->json($this->repository->list($this->tenantId($request), $configType, $configKey));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'config_version_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_id'         => ['required', 'string'],
            'config_type'    => ['required', 'string', 'max:100'],
            'config_key'     => ['required', 'string', 'max:255'],
            'version'        => ['nullable', 'integer'],
            'data'           => ['nullable', 'array'],
            'status'         => ['nullable', 'string', 'max:50'],
            'change_summary' => ['nullable', 'string'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function activate(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = app(\App\Services\ConfigVersionService::class)->activateVersion(
            $this->tenantId($request),
            $id,
            $this->actorId($request)
        );

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'config_version_not_found'], 404);
    }

    public function rollback(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = app(\App\Services\ConfigVersionService::class)->rollbackVersion(
            $this->tenantId($request),
            $id,
            $this->actorId($request)
        );

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'config_version_not_found'], 404);
    }
}
