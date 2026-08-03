<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\FormRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FormController extends Controller
{
    public function __construct(private readonly FormRepository $repository)
    {
    }

    public function index(Request $request, string $tenantId): JsonResponse
    {
        $orgId = $request->query('org_id');
        $entityType = $request->query('entity_type');

        $items = $this->repository->list($this->tenantId($request), $orgId);

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
            : response()->json(['error' => 'form_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_id'          => ['required', 'string'],
            'form_key'        => ['required', 'string', 'max:255'],
            'name'            => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'entity_type'     => ['nullable', 'string', 'max:100'],
            'fields'          => ['nullable', 'array'],
            'validation_rules'=> ['nullable', 'array'],
            'submit_action'   => ['nullable', 'string'],
            'is_active'       => ['nullable', 'boolean'],
            'version'         => ['nullable', 'integer'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'form_key'        => ['sometimes', 'string', 'max:255'],
            'name'            => ['sometimes', 'string', 'max:255'],
            'description'     => ['sometimes', 'nullable', 'string'],
            'entity_type'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'fields'          => ['sometimes', 'nullable', 'array'],
            'validation_rules'=> ['sometimes', 'nullable', 'array'],
            'submit_action'   => ['sometimes', 'nullable', 'string'],
            'is_active'       => ['sometimes', 'nullable', 'boolean'],
            'version'         => ['sometimes', 'nullable', 'integer'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'form_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'form_not_found'], 404);
    }
}
