<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BrandingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BrandingController extends Controller
{
    public function __construct(private readonly BrandingRepository $repository)
    {
    }

    public function index(Request $request, string $tenantId): JsonResponse
    {
        $orgId = $request->query('org_id');

        return response()->json($this->repository->list($this->tenantId($request), $orgId));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->find($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'branding_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_id'              => ['required', 'string'],
            'name'                => ['nullable', 'string', 'max:255'],
            'logo_url'            => ['nullable', 'string'],
            'favicon_url'         => ['nullable', 'string'],
            'primary_color'       => ['nullable', 'string', 'max:20'],
            'secondary_color'     => ['nullable', 'string', 'max:20'],
            'accent_color'        => ['nullable', 'string', 'max:20'],
            'font_family'         => ['nullable', 'string', 'max:255'],
            'login_background_url'=> ['nullable', 'string'],
            'email_header_url'    => ['nullable', 'string'],
            'custom_css'          => ['nullable', 'string'],
            'is_active'           => ['nullable', 'boolean'],
        ]);

        $data['created_by'] = $this->actorId($request);

        return response()->json($this->repository->create($this->tenantId($request), $data), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'name'                => ['sometimes', 'nullable', 'string', 'max:255'],
            'logo_url'            => ['sometimes', 'nullable', 'string'],
            'favicon_url'         => ['sometimes', 'nullable', 'string'],
            'primary_color'       => ['sometimes', 'nullable', 'string', 'max:20'],
            'secondary_color'     => ['sometimes', 'nullable', 'string', 'max:20'],
            'accent_color'        => ['sometimes', 'nullable', 'string', 'max:20'],
            'font_family'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'login_background_url'=> ['sometimes', 'nullable', 'string'],
            'email_header_url'    => ['sometimes', 'nullable', 'string'],
            'custom_css'          => ['sometimes', 'nullable', 'string'],
            'is_active'           => ['sometimes', 'nullable', 'boolean'],
        ]);

        $row = $this->repository->update($this->tenantId($request), $id, $data);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'branding_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $ok = $this->repository->delete($this->tenantId($request), $id);

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'branding_not_found'], 404);
    }
}
