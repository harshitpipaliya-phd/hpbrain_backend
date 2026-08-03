<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ThemeRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ThemeController extends Controller
{
    public function __construct(private readonly ThemeRepository $repository)
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
            : response()->json(['error' => 'theme_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme_key'    => ['required', 'string', 'max:100'],
            'name'         => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'colors'       => ['nullable', 'array'],
            'typography'   => ['nullable', 'array'],
            'spacing'      => ['nullable', 'array'],
            'borderRadius' => ['nullable', 'array'],
            'shadows'      => ['nullable', 'array'],
            'is_dark'      => ['nullable', 'boolean'],
            'is_default'   => ['nullable', 'boolean'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'theme_key'    => ['sometimes', 'string', 'max:100'],
            'name'         => ['sometimes', 'string', 'max:255'],
            'description'  => ['sometimes', 'nullable', 'string'],
            'colors'       => ['sometimes', 'nullable', 'array'],
            'typography'   => ['sometimes', 'nullable', 'array'],
            'spacing'      => ['sometimes', 'nullable', 'array'],
            'borderRadius' => ['sometimes', 'nullable', 'array'],
            'shadows'      => ['sometimes', 'nullable', 'array'],
            'is_dark'      => ['sometimes', 'nullable', 'boolean'],
            'is_default'   => ['sometimes', 'nullable', 'boolean'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'theme_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'theme_not_found'], 404);
    }
}
