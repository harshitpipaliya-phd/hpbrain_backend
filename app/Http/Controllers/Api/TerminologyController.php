<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\TerminologyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TerminologyController extends Controller
{
    public function __construct(private readonly TerminologyRepository $repository)
    {
    }

    public function index(Request $request, string $tenantId): JsonResponse
    {
        $industryCode = $request->query('industry_code');
        $entityType = $request->query('entity_type');

        $items = $this->repository->list($this->tenantId($request));

        if ($industryCode) {
            $items = array_values(array_filter($items, fn ($i) => $i['industry_code'] === $industryCode));
        }

        if ($entityType) {
            $items = array_values(array_filter($items, fn ($i) => $i['entity_type'] === $entityType));
        }

        return response()->json($items);
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'terminology_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'industry_code' => ['required', 'string', 'max:100'],
            'entity_type'   => ['required', 'string', 'max:100'],
            'display_name'  => ['required', 'string', 'max:255'],
            'plural_name'   => ['nullable', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'icon'          => ['nullable', 'string', 'max:255'],
            'sort_order'    => ['nullable', 'integer'],
            'status'        => ['nullable', 'string', 'max:50'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'industry_code' => ['sometimes', 'string', 'max:100'],
            'entity_type'   => ['sometimes', 'string', 'max:100'],
            'display_name'  => ['sometimes', 'string', 'max:255'],
            'plural_name'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'description'   => ['sometimes', 'nullable', 'string'],
            'icon'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order'    => ['sometimes', 'nullable', 'integer'],
            'status'        => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'terminology_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'terminology_not_found'], 404);
    }
}
