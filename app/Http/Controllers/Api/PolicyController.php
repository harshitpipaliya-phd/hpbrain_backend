<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Policy\PolicyService;
use App\Http\Controllers\Controller;
use App\Repositories\PolicyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class PolicyController extends Controller
{
    public function __construct(
        private readonly PolicyRepository $repository,
        private readonly PolicyService $policies,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->repository->list($this->tenantId($request), $request->query('status')));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->findById($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'policy_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'min:1', 'max:255'],
            'rules'  => ['required', 'array', 'min:1'],
            'effect' => ['required', 'in:allow,deny'],
        ]);

        return response()->json($this->repository->insert([
            'tenant_id'      => $this->tenantId($request),
            'name'           => $data['name'],
            'rules'          => json_encode($data['rules']),
            'effect'         => $data['effect'],
            'version_number' => 1,
            'status'         => 'active',
            'created_by'     => $this->actorId($request),
        ]), 201);
    }

    /**
     * Policies are versioned, never edited in place. A policy that authorized an
     * execution last month must still be readable in its original form, or the
     * audit trail for that execution is meaningless.
     */
    public function createVersion(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'rules'  => ['required', 'array', 'min:1'],
            'effect' => ['sometimes', 'in:allow,deny'],
        ]);

        $tenant = $this->tenantId($request);
        $current = $this->repository->findById($tenant, $id);

        if (! $current) {
            return response()->json(['error' => 'policy_not_found'], 404);
        }

        return DB::transaction(function () use ($request, $tenant, $id, $current, $data) {
            DB::table('hpbrain_policies')->where('tenant_id', $tenant)->where('id', $id)
                ->update(['status' => 'superseded']);

            $row = [
                'id'             => Uuid::uuid4()->toString(),
                'tenant_id'      => $tenant,
                'name'           => $current['name'],
                'rules'          => json_encode($data['rules']),
                'effect'         => $data['effect'] ?? $current['effect'],
                'version_number' => (int) ($current['version_number'] ?? 1) + 1,
                'supersedes_id'  => $id,
                'status'         => 'active',
                'created_by'     => $this->actorId($request),
                'created_date'   => now()->format('Y-m-d H:i:s'),
            ];

            DB::table('hpbrain_policies')->insert($row);

            return response()->json($row, 201);
        });
    }

    public function history(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $chain = [];
        $cursor = $id;

        // Walk the supersedes chain backwards. Bounded so a corrupted cycle
        // cannot spin forever.
        for ($i = 0; $i < 100 && $cursor !== null; $i++) {
            $row = $this->repository->findById($tenant, $cursor);

            if (! $row) {
                break;
            }

            $chain[] = $row;
            $cursor = $row['supersedes_id'] ?? null;
        }

        return response()->json($chain);
    }

    public function evaluate(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate(['context' => ['required', 'array']]);

        $policy = $this->repository->findById($this->tenantId($request), $id);

        if (! $policy) {
            return response()->json(['error' => 'policy_not_found'], 404);
        }

        $rules = json_decode((string) $policy['rules'], true) ?: [];
        $results = array_map(
            fn (array $rule) => $this->policies->evaluateRule($rule, $data['context']),
            $rules
        );

        $matched = ! in_array(false, $results, true);

        return response()->json([
            'policyId' => $id,
            'matched'  => $matched,
            'effect'   => $policy['effect'],
            'decision' => $matched ? $policy['effect'] : ($policy['effect'] === 'allow' ? 'deny' : 'allow'),
            'ruleResults' => $results,
        ]);
    }
}
