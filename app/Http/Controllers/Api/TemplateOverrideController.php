<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\TemplateOverrideRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TemplateOverrideController extends Controller
{
    public function __construct(private readonly TemplateOverrideRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $orgId = $request->query('orgId');
        $templateType = $request->query('templateType');
        $isActive = $request->boolean('isActive', null);

        return response()->json($this->repository->list($tenantId, $orgId, $templateType, $isActive));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'template_override_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_id'         => ['nullable', 'string'],
            'template_type'  => ['required', 'string', 'max:100'],
            'template_key'   => ['required', 'string', 'max:100'],
            'override_level' => ['nullable', 'string', 'max:50'],
            'override_data'  => ['nullable', 'array'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'template_type'  => ['sometimes', 'string', 'max:100'],
            'template_key'   => ['sometimes', 'string', 'max:100'],
            'override_level' => ['sometimes', 'nullable', 'string', 'max:50'],
            'override_data'  => ['sometimes', 'nullable', 'array'],
            'is_active'      => ['sometimes', 'nullable', 'boolean'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row ? response()->json($row) : response()->json(['error' => 'template_override_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'template_override_not_found'], 404);
    }
}
