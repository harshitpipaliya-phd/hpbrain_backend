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

        // hpbrain_capability_versions stores a real column-per-field snapshot
        // (version, name, description, category, capability_type, difficulty,
        // criticality and the five KASBA columns) — not a `version_number` plus
        // a JSON `snapshot` blob. Both of those names were invented; neither
        // exists, so versioning 500'd on every call.
        $next = (int) DB::table('hpbrain_capability_versions')
            ->where('tenant_id', $tenant)->where('capability_id', $id)->max('version') + 1;

        $row = [
            'id'              => Uuid::uuid4()->toString(),
            'tenant_id'       => $tenant,
            'capability_id'   => $id,
            'version'         => $next,
            'name'            => $current['name'] ?? null,
            'description'     => $current['description'] ?? null,
            'category'        => $current['category'] ?? null,
            'capability_type' => $current['capability_type'] ?? null,
            'difficulty'      => $current['difficulty'] ?? null,
            'criticality'     => $current['criticality'] ?? null,
            'knowledge'       => $current['knowledge'] ?? null,
            'ability'         => $current['ability'] ?? null,
            'skill'           => $current['skill'] ?? null,
            'behaviour'       => $current['behaviour'] ?? null,
            'attitude'        => $current['attitude'] ?? null,
            'created_by'      => $this->actorId($request),
            'created_date'    => now()->format('Y-m-d H:i:s'),
        ];

        DB::table('hpbrain_capability_versions')->insert($row);

        // Keep the capability's own version counter in step with its history.
        $this->repository->updateFields($tenant, $id, ['version' => $next]);

        return response()->json($row, 201);
    }

    public function versions(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_capability_versions')
                ->where('tenant_id', $this->tenantId($request))->where('capability_id', $id)
                ->orderByDesc('version')->get()
        );
    }

    public function archive(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->updateFields($this->tenantId($request), $id, ['status' => 'archived']);

        return $row ? response()->json($row) : response()->json(['error' => 'capability_not_found'], 404);
    }

    /**
     * hpbrain_capability_assignments is polymorphic — (target_type, target_id,
     * assigned_by, assigned_date) — so a capability can be assigned to a
     * Person, Department, JobRole or Organization. This method previously
     * validated a single `personId` and wrote a `person_id` column that does
     * not exist, which both 500'd and threw away three quarters of what the
     * schema supports.
     */
    public function assign(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'targetType' => ['required', 'string', 'in:Person,Department,JobRole,Organization'],
            'targetId'   => ['required', 'string'],
        ]);

        $tenant = $this->tenantId($request);

        if (! $this->repository->findById($tenant, $id)) {
            return response()->json(['error' => 'capability_not_found'], 404);
        }

        $existing = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $tenant)->where('capability_id', $id)
            ->where('target_type', $data['targetType'])->where('target_id', $data['targetId'])
            ->first();

        if ($existing) {
            return response()->json($existing);
        }

        $row = [
            'id'            => Uuid::uuid4()->toString(),
            'tenant_id'     => $tenant,
            'capability_id' => $id,
            'target_type'   => $data['targetType'],
            'target_id'     => $data['targetId'],
            'status'        => 'active',
            'assigned_by'   => $this->actorId($request),
            'assigned_date' => now()->format('Y-m-d H:i:s'),
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
                ->orderByDesc('created_at')->get()
        );
    }
}
