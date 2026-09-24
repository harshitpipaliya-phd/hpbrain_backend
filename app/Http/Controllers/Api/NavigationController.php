<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\NavigationItemRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NavigationController extends Controller
{
    public function __construct(private readonly NavigationItemRepository $repository)
    {
    }

    public function index(Request $request, string $tenantId): JsonResponse
    {
        $industryCode = $request->query('industry_code');
        $roleKey = $request->query('role_key');

        $items = $this->repository->list($this->tenantId($request), $industryCode, $roleKey);

        return response()->json($items);
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'navigation_item_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'industry_code'       => ['required', 'string', 'max:100'],
            'role_key'            => ['required', 'string', 'max:100'],
            'item_key'            => ['required', 'string', 'max:255'],
            'label'               => ['required', 'string', 'max:255'],
            'icon'                => ['nullable', 'string', 'max:255'],
            'route'               => ['nullable', 'string', 'max:500'],
            'parent_id'           => ['nullable', 'string'],
            'sort_order'          => ['nullable', 'integer'],
            'is_visible'          => ['nullable', 'boolean'],
            'required_permission' => ['nullable', 'string', 'max:255'],
            'required_flag'       => ['nullable', 'string', 'max:255'],
            'required_module'     => ['nullable', 'string', 'max:255'],
            'children'            => ['nullable', 'array'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'industry_code'       => ['sometimes', 'string', 'max:100'],
            'role_key'            => ['sometimes', 'string', 'max:100'],
            'item_key'            => ['sometimes', 'string', 'max:255'],
            'label'               => ['sometimes', 'string', 'max:255'],
            'icon'                => ['sometimes', 'nullable', 'string', 'max:255'],
            'route'               => ['sometimes', 'nullable', 'string', 'max:500'],
            'parent_id'           => ['sometimes', 'nullable', 'string'],
            'sort_order'          => ['sometimes', 'nullable', 'integer'],
            'is_visible'          => ['sometimes', 'nullable', 'boolean'],
            'required_permission' => ['sometimes', 'nullable', 'string', 'max:255'],
            'required_flag'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'required_module'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'children'            => ['sometimes', 'nullable', 'array'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'navigation_item_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'navigation_item_not_found'], 404);
    }
}
