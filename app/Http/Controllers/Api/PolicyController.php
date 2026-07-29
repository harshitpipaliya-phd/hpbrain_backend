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

/**
 * Policy governance.
 *
 * Every write here used to name columns hpbrain_policies does not have —
 * `effect`, `version_number`, `supersedes_id`. The table's real columns are
 * policy_type, version and previous_version_id, so creating a policy or cutting
 * a new version raised "Unknown column 'effect' in 'field list'" and the New
 * Policy form could never save anything. The table is the source of truth; the
 * queries were what was wrong.
 *
 * `scope` and `policy_type` are the two fields the Policy Management screen
 * shows on every row, and both are accepted from the create form.
 */
final class PolicyController extends Controller
{
    public function __construct(
        private readonly PolicyRepository $repository,
        private readonly PolicyService $policies,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(array_map(
            fn (array $p) => $this->present($p),
            $this->repository->list($this->tenantId($request), $request->query('status'))
        ));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->findById($this->tenantId($request), $id);

        return $row ? response()->json($this->present($row)) : response()->json(['error' => 'policy_not_found'], 404);
    }

    /**
     * `rules` must reach the client as a list. The repository decodes the JSON
     * column, but a policy stored with NULL rules still arrives as null, and
     * the screen calls .map on it — one such row blanked the whole list.
     *
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    private function present(array $policy): array
    {
        $policy['rules'] = $this->rulesOf($policy);

        return $policy;
    }

    /** Rules as a list, whether the row was hydrated or is still raw JSON text. */
    private function rulesOf(array $policy): array
    {
        $rules = $policy['rules'] ?? null;

        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }

        return is_array($rules) ? $rules : [];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'min:1', 'max:255'],
            'rules'      => ['required', 'array', 'min:1'],
            'scope'      => ['nullable', 'string', 'max:255'],
            'policyType' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->present($this->repository->insert([
            'tenant_id'   => $this->tenantId($request),
            'name'        => $data['name'],
            'scope'       => $data['scope'] ?? 'recommendations',
            'policy_type' => $data['policyType'] ?? 'business_rule',
            'rules'       => json_encode($data['rules']),
            'version'     => 1,
            'status'      => 'active',
            'created_by'  => $this->actorId($request),
        ])), 201);
    }

    /**
     * Policies are versioned, never edited in place. A policy that authorized an
     * execution last month must still be readable in its original form, or the
     * audit trail for that execution is meaningless.
     */
    public function createVersion(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['required', 'array', 'min:1'],
            'scope' => ['sometimes', 'string', 'max:255'],
        ]);

        $tenant = $this->tenantId($request);
        $current = $this->repository->findById($tenant, $id);

        if (! $current) {
            return response()->json(['error' => 'policy_not_found'], 404);
        }

        return DB::transaction(function () use ($request, $tenant, $id, $current, $data) {
            DB::table('hpbrain_policies')->where('tenant_id', $tenant)->where('id', $id)
                ->update(['status' => 'superseded', 'updated_date' => now()->format('Y-m-d H:i:s')]);

            $row = [
                'id'                  => Uuid::uuid4()->toString(),
                'tenant_id'           => $tenant,
                'name'                => $current['name'],
                'scope'               => $data['scope'] ?? $current['scope'] ?? null,
                'policy_type'         => $current['policy_type'] ?? 'business_rule',
                'rules'               => json_encode($data['rules']),
                'version'             => (int) ($current['version'] ?? 1) + 1,
                'previous_version_id' => $id,
                'status'              => 'active',
                'created_by'          => $this->actorId($request),
                'created_date'        => now()->format('Y-m-d H:i:s'),
            ];

            DB::table('hpbrain_policies')->insert($row);

            return response()->json($this->present($row), 201);
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

            $chain[] = $this->present($row);
            $cursor = $row['previous_version_id'] ?? null;
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

        $rules = $this->rulesOf($policy);
        $results = array_map(
            fn (array $rule) => $this->policies->evaluateRule($rule, $data['context']),
            $rules
        );

        // A policy with no rules matches nothing. Treating an empty rule set as
        // "all rules passed" would make an unconfigured policy authorize
        // everything — the one outcome a governance surface must never produce.
        $matched = $rules !== [] && ! in_array(false, $results, true);

        // hpbrain_policies has no `effect` column. The action a matched rule
        // names IS the effect — that is where the rule builder writes it — so
        // the decision is read from the rules rather than from a column that
        // does not exist.
        $actions = array_values(array_unique(array_filter(array_map(
            fn (array $rule) => isset($rule['action']) ? (string) $rule['action'] : null,
            $rules
        ))));

        return response()->json([
            'policyId'    => $id,
            'matched'     => $matched,
            'actions'     => $actions,
            'decision'    => $matched ? ($actions[0] ?? 'no_action') : 'no_match',
            'ruleResults' => $results,
        ]);
    }
}
