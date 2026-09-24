<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\IndustryTemplateRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IndustryTemplateController extends Controller
{
    public function __construct(private readonly IndustryTemplateRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->repository->list($this->tenantId($request)));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'industry_template_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'industry_code' => ['required', 'string', 'max:100'],
            'template_name' => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'terminology'   => ['nullable', 'array'],
            'modules'       => ['nullable', 'array'],
            'navigation'    => ['nullable', 'array'],
            'dashboards'    => ['nullable', 'array'],
            'branding'      => ['nullable', 'array'],
            'workflows'     => ['nullable', 'array'],
            'integrations'  => ['nullable', 'array'],
            'is_system'     => ['nullable', 'boolean'],
            'is_active'     => ['nullable', 'boolean'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'industry_code' => ['sometimes', 'string', 'max:100'],
            'template_name' => ['sometimes', 'string', 'max:255'],
            'description'   => ['sometimes', 'nullable', 'string'],
            'terminology'   => ['sometimes', 'nullable', 'array'],
            'modules'       => ['sometimes', 'nullable', 'array'],
            'navigation'    => ['sometimes', 'nullable', 'array'],
            'dashboards'    => ['sometimes', 'nullable', 'array'],
            'branding'      => ['sometimes', 'nullable', 'array'],
            'workflows'     => ['sometimes', 'nullable', 'array'],
            'integrations'  => ['sometimes', 'nullable', 'array'],
            'is_system'     => ['sometimes', 'nullable', 'boolean'],
            'is_active'     => ['sometimes', 'nullable', 'boolean'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'industry_template_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'industry_template_not_found'], 404);
    }
}
