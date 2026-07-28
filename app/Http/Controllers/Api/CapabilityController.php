<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\CapabilityRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class CapabilityController extends Controller
{
    public function __construct(private readonly CapabilityRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $q = DB::table('hpbrain_capabilities')->where('tenant_id', $this->tenantId($request));

        if ($orgId = $request->query('orgId')) {
            $q->where('org_id', $orgId);
        }

        return response()->json($q->orderBy('name')->get());
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return response()->json([]);
        }

        return response()->json(
            DB::table('hpbrain_capabilities')->where('tenant_id', $this->tenantId($request))
                ->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                                     ->orWhere('capability_code', 'like', "%{$term}%"))
                ->limit(100)->get()
        );
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->findById($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'capability_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'min:1', 'max:255'],
            'capabilityCode' => ['required', 'string', 'max:100'],
            'orgId'          => ['nullable', 'string'],
            'description'    => ['nullable', 'string'],
            'category'       => ['nullable', 'string'],
        ]);

        return response()->json($this->repository->insert([
            'tenant_id'       => $this->tenantId($request),
            'org_id'          => $data['orgId'] ?? null,
            'name'            => $data['name'],
            'capability_code' => $data['capabilityCode'],
            'description'     => $data['description'] ?? null,
            'category'        => $data['category'] ?? null,
            'status'          => 'active',
            'created_by'      => $this->actorId($request),
        ]), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'category'    => ['sometimes', 'nullable', 'string'],
        ]);

        $row = $this->repository->updateFields($this->tenantId($request), $id, $data);

        return $row ? response()->json($row) : response()->json(['error' => 'capability_not_found'], 404);
    }

    /**
     * Versions are append-only. A capability definition that can be edited in
     * place makes every historical assessment against it unreadable — you can
     * no longer tell what "level 4" meant when it was recorded.
     */
    public function createVersion(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $current = $this->repository->findById($tenant, $id);

        if (! $current) {
            return response()->json(['error' => 'capability_not_found'], 404);
        }

        $next = (int) DB::table('hpbrain_capability_versions')
            ->where('tenant_id', $tenant)->where('capability_id', $id)->max('version_number') + 1;

        $row = [
            'id'             => Uuid::uuid4()->toString(),
            'tenant_id'      => $tenant,
            'capability_id'  => $id,
            'version_number' => $next,
            'snapshot'       => json_encode($current),
            'created_by'     => $this->actorId($request),
            'created_date'   => now()->format('Y-m-d H:i:s'),
        ];

        DB::table('hpbrain_capability_versions')->insert($row);

        return response()->json($row, 201);
    }

    public function versions(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_capability_versions')
                ->where('tenant_id', $this->tenantId($request))->where('capability_id', $id)
                ->orderByDesc('version_number')->get()
        );
    }

    public function archive(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->updateFields($this->tenantId($request), $id, ['status' => 'archived']);

        return $row ? response()->json($row) : response()->json(['error' => 'capability_not_found'], 404);
    }

    public function assign(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'personId' => ['required', 'string'],
        ]);

        $tenant = $this->tenantId($request);

        if (! $this->repository->findById($tenant, $id)) {
            return response()->json(['error' => 'capability_not_found'], 404);
        }

        $existing = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $tenant)->where('capability_id', $id)
            ->where('person_id', $data['personId'])->first();

        if ($existing) {
            return response()->json($existing);
        }

        $row = [
            'id'            => Uuid::uuid4()->toString(),
            'tenant_id'     => $tenant,
            'capability_id' => $id,
            'person_id'     => $data['personId'],
            'status'        => 'active',
            'created_by'    => $this->actorId($request),
            'created_date'  => now()->format('Y-m-d H:i:s'),
        ];

        DB::table('hpbrain_capability_assignments')->insert($row);

        return response()->json($row, 201);
    }

    public function assignments(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_capability_assignments')
                ->where('tenant_id', $this->tenantId($request))->where('capability_id', $id)
                ->get()
        );
    }

    public function audit(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_audit_logs')
                ->where('tenant_id', $this->tenantId($request))
                ->where('entity_type', 'Capability')->where('entity_id', $id)
                ->orderByDesc('created_date')->get()
        );
    }
}
