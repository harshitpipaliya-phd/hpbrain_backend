<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\DashboardWidgetRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardWidgetController extends Controller
{
    public function __construct(private readonly DashboardWidgetRepository $repository)
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
            : response()->json(['error' => 'widget_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'widget_key'     => ['required', 'string', 'max:100'],
            'name'           => ['required', 'string', 'max:255'],
            'description'    => ['nullable', 'string'],
            'category'       => ['nullable', 'string', 'max:100'],
            'component_type' => ['required', 'string', 'max:255'],
            'config_schema'  => ['nullable', 'array'],
            'default_config' => ['nullable', 'array'],
            'icon'           => ['nullable', 'string', 'max:255'],
            'is_system'      => ['nullable', 'boolean'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'widget_key'     => ['sometimes', 'string', 'max:100'],
            'name'           => ['sometimes', 'string', 'max:255'],
            'description'    => ['sometimes', 'nullable', 'string'],
            'category'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'component_type' => ['sometimes', 'string', 'max:255'],
            'config_schema'  => ['sometimes', 'nullable', 'array'],
            'default_config' => ['sometimes', 'nullable', 'array'],
            'icon'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_system'      => ['sometimes', 'nullable', 'boolean'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'widget_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'widget_not_found'], 404);
    }
}
