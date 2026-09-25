<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Policies\AiPolicyCatalog;
use App\Domain\AiIntelligence\Support\AiAuditLogger;
use App\Domain\AiIntelligence\Support\AiIntelligenceScope;
use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * AI Policies — what the AI is permitted to do, and where.
 *
 * Ported from G2G's AiPolicyController onto hpbrain_ai_policies,
 * hpbrain_ai_policy_rules and hpbrain_ai_policy_assignments, written and read
 * together. A platform policy (`tenant_id = '*'`) is never edited by a tenant: an
 * edit writes that tenant its own copy (`action: forked`, `forked_from`), and
 * retiring one is refused with 403. Scope targets are HP Brain's own — modules,
 * departments, positions — and every assignment is checked against them.
 */
final class AiIntelligencePolicyController extends AiIntelligenceController
{
    public function __construct(
        private readonly AiPolicyCatalog $catalog,
        private readonly AiAuditLogger $audit,
    ) {
    }

    public function options(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            return $this->success('AI policy options resolved.', [
                'policy_types' => $this->catalog->policyTypeOptions(),
                'rule_catalogue' => $this->catalog->ruleCatalogue(),
                'scope_types' => $this->catalog->scopeTypeOptions(),
                'scope_targets' => $this->catalog->scopeTargets($tenantId),
                'modules' => $this->catalog->moduleOptions($tenantId),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            $moduleKey = trim((string) $request->input('module_key', ''));
            $moduleIds = $moduleKey === '' ? [] : $this->catalog->moduleIds($moduleKey, $tenantId);

            if ($moduleKey !== '' && $moduleIds === []) {
                return $this->success('AI policies resolved.', [
                    'tenant_id' => $tenantId,
                    'module_key' => $moduleKey,
                    'module_ids' => [],
                    'policies' => [],
                ]);
            }

            $query = Platform::visible(DB::table('hpbrain_ai_policies as p'), $tenantId, 'p.tenant_id');

            if ($moduleKey !== '') {
                $query->whereExists(function ($exists) use ($moduleIds) {
                    $exists->from('hpbrain_ai_policy_assignments as a')
                        ->whereColumn('a.policy_id', 'p.id')
                        ->where('a.scope_type', 'module')
                        ->whereIn('a.scope_id', $moduleIds);
                });
            }

            $rows = $query->orderByDesc('p.updated_date')->orderByDesc('p.created_date')->get(['p.id'])->all();

            $policies = array_values(array_filter(array_map(
                fn ($row) => $this->policyDetail((string) $row->id, $tenantId),
                $rows
            )));

            return $this->success('AI policies resolved.', [
                'tenant_id' => $tenantId,
                'module_key' => $moduleKey === '' ? null : $moduleKey,
                'module_ids' => array_values($moduleIds),
                'policies' => $policies,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;
            $data = $this->validatedPolicy($request, $tenantId);

            $id = DB::transaction(fn () => $this->insertPolicy($data, $scope));

            $this->audit->record('ai.policy.created', $scope, [
                'related_type' => 'hpbrain_ai_policies',
                'related_id' => $id,
                'message' => sprintf('AI policy "%s" created.', trim($data['name'])),
                'payload' => $this->auditPayload($data),
            ]);

            return $this->success('AI policy saved.', ['policy' => $this->policyDetail($id, $tenantId)], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            $row = $this->visiblePolicy($id, $tenantId);

            if ($row === null) {
                return $this->failure('That AI policy was not found.', 404);
            }

            $data = $this->validatedPolicy($request, $tenantId);

            // A platform policy is shared: an edit writes THIS tenant its own copy and
            // leaves the platform row intact for everyone else.
            if (Platform::isPlatform($row)) {
                $forkId = DB::transaction(fn () => $this->insertPolicy($data, $scope));

                $this->audit->record('ai.policy.forked', $scope, [
                    'related_type' => 'hpbrain_ai_policies',
                    'related_id' => $forkId,
                    'message' => sprintf('Platform AI policy %s copied to this organisation as "%s".', $id, trim($data['name'])),
                    'payload' => $this->auditPayload($data) + ['forked_from' => $id],
                ]);

                return $this->success('Saved as this organisation\'s own copy of the shared policy.', [
                    'policy' => $this->policyDetail($forkId, $tenantId),
                    'action' => 'forked',
                    'forked_from' => $id,
                ]);
            }

            DB::transaction(function () use ($id, $data, $scope, $tenantId) {
                DB::table('hpbrain_ai_policies')->where('id', $id)->where('tenant_id', $tenantId)->update(
                    $this->policyColumns($data) + [
                        'updated_by' => $scope->userId,
                        'updated_date' => Platform::now(),
                    ]
                );

                $this->saveRules($id, $data['rules'], $tenantId);
                $this->saveAssignments($id, $data['assignments'], $tenantId, $scope->userId);
            });

            $this->audit->record('ai.policy.updated', $scope, [
                'related_type' => 'hpbrain_ai_policies',
                'related_id' => $id,
                'message' => sprintf('AI policy "%s" updated.', trim($data['name'])),
                'payload' => $this->auditPayload($data),
            ]);

            return $this->success('AI policy updated.', [
                'policy' => $this->policyDetail($id, $tenantId),
                'action' => 'updated',
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Retire (status = 0) rather than delete: audit rows name the policy. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            $row = $this->visiblePolicy($id, $tenantId);

            if ($row === null) {
                return $this->failure('That AI policy was not found.', 404);
            }

            if (Platform::isPlatform($row)) {
                return $this->failure(
                    'This is a platform policy shared by every organisation and cannot be retired here.',
                    403
                );
            }

            DB::table('hpbrain_ai_policies')->where('id', $id)->where('tenant_id', $tenantId)->update([
                'status' => 0,
                'updated_by' => $scope->userId,
                'updated_date' => Platform::now(),
            ]);

            $this->audit->record('ai.policy.retired', $scope, [
                'related_type' => 'hpbrain_ai_policies',
                'related_id' => $id,
                'message' => 'AI policy retired.',
            ]);

            return $this->success('AI policy retired.', ['id' => $id, 'tenant_id' => $tenantId]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function validatedPolicy(Request $request, string $tenantId): array
    {
        $policyTypes = array_column($this->catalog->policyTypeOptions(), 'value');
        $scopeTypes = array_column($this->catalog->scopeTypeOptions(), 'value');
        $ruleKeys = array_column($this->catalog->ruleCatalogue(), 'key');

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'policy_type' => 'required|string|in:' . implode(',', $policyTypes),
            'status' => 'nullable|integer|in:0,1',
            'require_disclosure' => 'nullable|integer|in:0,1',
            'require_acknowledgement' => 'nullable|integer|in:0,1',
            'ai_detection_required' => 'nullable|integer|in:0,1',
            'plagiarism_check_required' => 'nullable|integer|in:0,1',
            'detection_provider' => 'nullable|string|max:120',
            'detection_threshold' => 'nullable|numeric|min:0|max:100',
            'rules' => 'nullable|array',
            'rules.*' => 'nullable|boolean',
            'assignments' => 'nullable|array',
            'assignments.*.scope_type' => 'required|string|in:' . implode(',', $scopeTypes),
            // A string: module and position ids are UUIDs, department ids come from
            // the ERP and may be numeric — both arrive as strings.
            'assignments.*.scope_id' => 'nullable|string|max:64',
            'assignments.*.status' => 'nullable|integer|in:0,1',
        ]);

        $rules = [];

        foreach ($validated['rules'] ?? [] as $key => $value) {
            if (in_array((string) $key, $ruleKeys, true)) {
                $rules[(string) $key] = (bool) $value;
            }
        }

        $validated['rules'] = $rules;

        $assignments = [];
        $errors = [];

        foreach (array_values($validated['assignments'] ?? []) as $index => $assignment) {
            $scopeType = (string) ($assignment['scope_type'] ?? 'global');
            $scopeId = isset($assignment['scope_id']) && trim((string) $assignment['scope_id']) !== ''
                ? trim((string) $assignment['scope_id'])
                : null;

            if ($scopeType === 'global') {
                $scopeId = null;
            } elseif (! $this->catalog->targetExists($scopeType, $scopeId, $tenantId)) {
                $errors["assignments.{$index}.scope_id"] = [
                    sprintf('That %s is not one this organisation has.', str_replace('_', ' ', $scopeType)),
                ];

                continue;
            }

            $assignments[] = [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'status' => isset($assignment['status']) ? (int) $assignment['status'] : 1,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $validated['assignments'] = $assignments;

        return $validated;
    }

    /** @param array<string, mixed> $data */
    private function insertPolicy(array $data, AiIntelligenceScope $scope): string
    {
        $now = Platform::now();
        $id = Platform::id();

        DB::table('hpbrain_ai_policies')->insert($this->policyColumns($data) + [
            'id' => $id,
            'tenant_id' => $scope->tenantId,
            'created_by' => $scope->userId,
            'updated_by' => $scope->userId,
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        $this->saveRules($id, $data['rules'], $scope->tenantId);
        $this->saveAssignments($id, $data['assignments'], $scope->tenantId, $scope->userId);

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function policyColumns(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'policy_type' => $data['policy_type'],
            'status' => $data['status'] ?? 1,
            'require_disclosure' => $data['require_disclosure'] ?? 0,
            'require_acknowledgement' => $data['require_acknowledgement'] ?? 0,
            'ai_detection_required' => $data['ai_detection_required'] ?? 0,
            'plagiarism_check_required' => $data['plagiarism_check_required'] ?? 0,
            'detection_provider' => $data['detection_provider'] ?? null,
            'detection_threshold' => $data['detection_threshold'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function auditPayload(array $data): array
    {
        return [
            'policy_type' => $data['policy_type'],
            'status' => $data['status'] ?? 1,
            'rules' => $data['rules'],
            'assignments' => $data['assignments'],
        ];
    }

    private function visiblePolicy(string $id, string $tenantId): ?object
    {
        return Platform::visible(DB::table('hpbrain_ai_policies')->where('id', $id), $tenantId)->first();
    }

    /** @return array<string, mixed>|null */
    private function policyDetail(string $id, string $tenantId): ?array
    {
        $row = $this->visiblePolicy($id, $tenantId);

        if ($row === null) {
            return null;
        }

        $owner = (string) $row->tenant_id;

        $rules = [];

        foreach (DB::table('hpbrain_ai_policy_rules')->where('policy_id', $id)->where('tenant_id', $owner)->get() as $rule) {
            $rules[(string) $rule->rule_key] = (bool) $rule->rule_value;
        }

        $assignments = DB::table('hpbrain_ai_policy_assignments')
            ->where('policy_id', $id)
            ->where('tenant_id', $owner)
            ->orderBy('created_date')
            ->get()
            ->map(fn ($assignment) => [
                'id' => (string) $assignment->id,
                'policy_id' => (string) $assignment->policy_id,
                'scope_type' => (string) $assignment->scope_type,
                'scope_id' => $assignment->scope_id !== null ? (string) $assignment->scope_id : null,
                'tenant_id' => Platform::present((string) $assignment->tenant_id),
                'status' => (int) $assignment->status,
            ])
            ->all();

        $moduleIds = [];

        foreach ($assignments as $assignment) {
            if ($assignment['scope_type'] === 'module' && $assignment['scope_id'] !== null) {
                $moduleIds[] = $assignment['scope_id'];
            }
        }

        $isPlatform = Platform::isPlatform($row);

        return [
            'id' => (string) $row->id,
            'tenant_id' => Platform::present($owner),
            'is_platform' => $isPlatform,
            'is_example' => $isPlatform && str_ends_with((string) $row->name, '(example)') ? 1 : 0,
            'editable' => ! $isPlatform,
            'name' => (string) $row->name,
            'description' => $row->description,
            'policy_type' => (string) $row->policy_type,
            'status' => (int) $row->status,
            'require_disclosure' => (int) $row->require_disclosure,
            'require_acknowledgement' => (int) $row->require_acknowledgement,
            'ai_detection_required' => (int) $row->ai_detection_required,
            'plagiarism_check_required' => (int) $row->plagiarism_check_required,
            'detection_provider' => $row->detection_provider,
            'detection_threshold' => $row->detection_threshold !== null ? (float) $row->detection_threshold : null,
            'created_by' => $row->created_by,
            'updated_by' => $row->updated_by,
            'created_at' => $row->created_date,
            'updated_at' => $row->updated_date,
            'rules' => $rules,
            'assignments' => $assignments,
            'module_keys' => $this->catalog->moduleKeysFor($moduleIds, $tenantId),
            'institute_scope' => $tenantId,
        ];
    }

    /** @param array<string, bool> $rules */
    private function saveRules(string $policyId, array $rules, string $tenantId): void
    {
        DB::table('hpbrain_ai_policy_rules')->where('policy_id', $policyId)->where('tenant_id', $tenantId)->delete();

        $now = Platform::now();

        foreach ($rules as $ruleKey => $enabled) {
            if (! is_string($ruleKey) || $ruleKey === '') {
                continue;
            }

            DB::table('hpbrain_ai_policy_rules')->insert([
                'id' => Platform::id(),
                'tenant_id' => $tenantId,
                'policy_id' => $policyId,
                'rule_key' => $ruleKey,
                'rule_value' => $enabled ? '1' : '0',
                'created_date' => $now,
                'updated_date' => $now,
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $assignments */
    private function saveAssignments(string $policyId, array $assignments, string $tenantId, string $userId): void
    {
        DB::table('hpbrain_ai_policy_assignments')->where('policy_id', $policyId)->where('tenant_id', $tenantId)->delete();

        // No assignment would govern nowhere; default to the whole organisation.
        if ($assignments === []) {
            $assignments = [['scope_type' => 'global', 'scope_id' => null, 'status' => 1]];
        }

        $now = Platform::now();
        $seen = [];

        foreach ($assignments as $assignment) {
            $signature = $assignment['scope_type'] . '|' . ($assignment['scope_id'] ?? '');

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;

            DB::table('hpbrain_ai_policy_assignments')->insert([
                'id' => Platform::id(),
                'tenant_id' => $tenantId,
                'policy_id' => $policyId,
                'scope_type' => $assignment['scope_type'],
                'scope_id' => $assignment['scope_id'],
                'status' => (int) ($assignment['status'] ?? 1),
                'created_by' => $userId,
                'created_date' => $now,
                'updated_date' => $now,
            ]);
        }
    }
}
