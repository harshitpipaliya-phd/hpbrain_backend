<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Reports\ModuleDataSourceCatalog;
use App\Domain\AiIntelligence\Stack\AiStackModules;
use App\Domain\AiIntelligence\Support\AiAuditLogger;
use App\Domain\AiIntelligence\Support\AiIntelligenceScope;
use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Tool agents for an HP Brain area's AI Stack Automations tab — ported from G2G's
 * AiToolAgentController with the same `Agent` / `AgentRun` response shapes.
 *
 * G2G stored these in its Agentic AI tables (agentic_agents / agentic_agent_runs).
 * HP Brain has no such store, so they live in hpbrain_ai_tool_agents /
 * hpbrain_ai_tool_agent_runs; `status` is stored as the API speaks it (draft |
 * active | paused | archived), and `archived` hides an agent from every read, as
 * G2G's soft delete did.
 *
 * WHAT AN AGENT MAY DO: run ONE of its allowed tools, and nothing else. Every tool is a
 * read-only ModuleDataSourceCatalog source of the agent's OWN module, re-checked on
 * every run (the allow-list AND the module), so an agent can look at its area's records
 * for the caller's tenant and cannot change one. No model is called. Each run writes a
 * run row and an hpbrain_ai_audit_logs row under `module.{module}.{module}_agent_run` (the operation key every module descriptor declares), so it
 * appears on the module's Activity tab.
 */
final class AiStackToolAgentController extends AiIntelligenceController
{
    private const AGENTS = 'hpbrain_ai_tool_agents';

    private const RUNS = 'hpbrain_ai_tool_agent_runs';

    private const LIVE_STATUSES = ['draft', 'active', 'paused'];

    public function __construct(
        private readonly ModuleDataSourceCatalog $sources,
        private readonly AiStackModules $modules,
        private readonly AiAuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $tenant = $this->scope($request)->tenantId;

            if (! Schema::hasTable(self::AGENTS)) {
                return $this->success('Agents resolved.', ['agents' => []]);
            }

            $query = DB::table(self::AGENTS)
                ->where('tenant_id', $tenant)
                ->whereIn('status', self::LIVE_STATUSES)
                ->whereIn('module', $this->modules->stackKeys($tenant));

            if ($request->filled('module')) {
                $query->where('module', (string) $request->query('module'));
            }

            if ($request->filled('status') && in_array((string) $request->query('status'), self::LIVE_STATUSES, true)) {
                $query->where('status', (string) $request->query('status'));
            }

            $rows = $query->orderByDesc('created_date')->get();
            $names = $this->userNames($tenant, $rows->pluck('created_by')->all());

            return $this->success('Agents resolved.', [
                'agents' => $rows->map(fn ($row) => $this->presentAgent($row, $names))->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenant = $scope->tenantId;

            if (! Schema::hasTable(self::AGENTS)) {
                return $this->failure('Tool agents are not available on this deployment yet.', 503);
            }

            $data = $request->validate([
                'name' => 'required|string|max:191',
                'description' => 'nullable|string|max:2000',
                'module' => ['required', 'string', Rule::in($this->modules->stackKeys($tenant))],
                'tools_allowed' => 'required|array|min:1',
                'tools_allowed.*' => 'string|max:120',
                'instructions' => 'nullable|string|max:10000',
                'status' => ['nullable', Rule::in(['draft', 'active'])],
            ]);

            $allowed = array_column($this->sources->forModule($data['module']), 'name');
            $unknown = array_values(array_diff($data['tools_allowed'], $allowed));

            if ($unknown !== []) {
                return $this->failure('These tools are not read-only sources of this module: ' . implode(', ', $unknown) . '.', 422);
            }

            $id = Platform::id();
            $now = Platform::now();

            DB::table(self::AGENTS)->insert([
                'id' => $id,
                'tenant_id' => $tenant,
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'module' => $data['module'],
                'tools_allowed' => json_encode(array_values(array_unique($data['tools_allowed']))),
                'instructions' => $data['instructions'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'created_by' => $scope->userId,
                'updated_by' => $scope->userId,
                'created_date' => $now,
                'updated_date' => $now,
            ]);

            $this->audit->record('ai.tool_agent.created', $scope, [
                'related_type' => self::AGENTS,
                'related_id' => $id,
                'message' => sprintf('Agent "%s" created for %s.', trim($data['name']), $data['module']),
            ]);

            $row = $this->agent($id, $tenant);

            return $this->success('Agent created.', ['agent' => $this->presentAgent($row, $this->userNames($tenant, [$row->created_by]))], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function setStatus(Request $request, string $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenant = $scope->tenantId;
            $row = $this->agent($id, $tenant);

            if ($row === null) {
                return $this->failure('That agent was not found.', 404);
            }

            $status = (string) $request->validate(['status' => ['required', Rule::in(['draft', 'active', 'paused', 'archived'])]])['status'];

            DB::table(self::AGENTS)->where('id', $id)->where('tenant_id', $tenant)->update([
                'status' => $status,
                'updated_by' => $scope->userId,
                'updated_date' => Platform::now(),
            ]);

            $this->audit->record('ai.tool_agent.status', $scope, [
                'related_type' => self::AGENTS,
                'related_id' => $id,
                'message' => sprintf('Agent "%s" set to %s.', $row->name, $status),
            ]);

            $fresh = DB::table(self::AGENTS)->where('id', $id)->where('tenant_id', $tenant)->first();

            return $this->success('Agent updated.', ['agent' => $this->presentAgent($fresh, $this->userNames($tenant, [$fresh->created_by]))]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Run one allowed tool, as the caller, and record the run. */
    public function run(Request $request, string $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenant = $scope->tenantId;
            $row = $this->agent($id, $tenant);

            if ($row === null) {
                return $this->failure('That agent was not found.', 404);
            }

            $data = $request->validate([
                'tool' => 'nullable|string|max:120',
                'arguments' => 'nullable|array',
                'arguments.limit' => 'nullable|integer|min:1|max:' . ModuleDataSourceCatalog::MAX_ROWS,
            ]);

            $tools = $this->decode($row->tools_allowed);
            $tool = $data['tool'] ?? ($tools[0] ?? null);
            $arguments = $this->arguments($request);
            $started = Platform::now();
            $clock = microtime(true);

            // The server re-checks everything the screen might have: the agent is live,
            // the tool is on ITS allow-list, and the tool is a source of ITS module.
            $denial = null;
            if ((string) $row->status !== 'active') {
                $denial = 'Denied: the agent is not active.';
            } elseif ($tool === null || ! in_array($tool, $tools, true)) {
                $denial = 'Denied: that tool is not on this agent\'s allow-list.';
            } elseif (! in_array($tool, array_column($this->sources->forModule((string) $row->module), 'name'), true)) {
                $denial = 'Denied: that tool does not belong to this agent\'s module.';
            }

            $output = null;
            $error = $denial;
            $rowCount = 0;

            if ($denial === null) {
                try {
                    $declared = $this->sources->argumentKeys($tool);
                    $result = $this->sources->run($tool, $scope, array_intersect_key($arguments, array_flip($declared)));

                    if (! $result['available']) {
                        $error = (string) $result['reason'];
                    } else {
                        $rowCount = $result['total'];
                        $output = [
                            'tool' => $tool,
                            'total' => $result['total'],
                            'truncated' => $result['truncated'],
                            // A run log is an audit record, not an export: the first rows only.
                            'rows' => array_slice($result['rows'], 0, 50),
                        ];
                    }
                } catch (Throwable $e) {
                    report($e);
                    $error = 'The tool could not be run.';
                }
            }

            $status = $error === null ? 'success' : ($denial !== null ? 'denied' : 'failure');
            $finished = Platform::now();
            $runId = Platform::id();

            DB::table(self::RUNS)->insert([
                'id' => $runId,
                'tenant_id' => $tenant,
                'agent_id' => $id,
                'module' => (string) $row->module,
                'tool' => $tool,
                'status' => $status,
                'trigger' => 'manual',
                'input' => json_encode(['tool' => $tool, 'arguments' => $arguments], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'output' => $output === null ? null : json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'row_count' => $rowCount,
                'error_message' => $error,
                'duration_ms' => (int) round((microtime(true) - $clock) * 1000),
                'started_at' => $started,
                'completed_at' => $finished,
                'created_by' => $scope->userId,
                'created_date' => $started,
            ]);

            $this->audit->record("module.{$row->module}.{$row->module}_agent_run", $scope, [
                'related_type' => self::RUNS,
                'related_id' => $runId,
                'outcome' => match ($status) {
                    'success' => 'success',
                    'denied' => 'rejected',
                    default => 'failure',
                },
                'message' => $error ?? sprintf('Agent "%s" ran %s (%d rows).', $row->name, $tool, $rowCount),
                'payload' => [
                    'module' => (string) $row->module,
                    'operation' => "{$row->module}_agent_run",
                    'operation_label' => 'Agent run',
                    'capability' => 'agent',
                    'status' => match ($status) {
                        'success' => 'completed',
                        'denied' => 'denied',
                        default => 'failed',
                    },
                    'subject_label' => (string) $row->name,
                    'reference' => $runId,
                    'used' => [
                        'agent' => ['id' => $id, 'name' => (string) $row->name, 'run_id' => $runId, 'source' => self::AGENTS],
                        'tool' => $tool,
                    ],
                    'result' => ['status' => $status, 'row_count' => $rowCount],
                ],
            ]);

            $run = DB::table(self::RUNS)->where('id', $runId)->where('tenant_id', $tenant)->first();

            return $this->success(
                $error === null ? 'Agent run completed.' : 'Agent run did not complete.',
                ['run' => $this->presentRun($run, (string) $row->name, $this->actors($tenant, [$run->created_by]))]
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function runs(Request $request): JsonResponse
    {
        try {
            $tenant = $this->scope($request)->tenantId;

            if (! Schema::hasTable(self::RUNS) || ! Schema::hasTable(self::AGENTS)) {
                return $this->success('Runs resolved.', ['runs' => [], 'summary' => $this->emptySummary()]);
            }

            $query = DB::table(self::RUNS . ' as r')
                ->join(self::AGENTS . ' as a', function ($join) {
                    $join->on('a.id', '=', 'r.agent_id')->on('a.tenant_id', '=', 'r.tenant_id');
                })
                ->where('r.tenant_id', $tenant)
                ->whereIn('a.module', $this->modules->stackKeys($tenant));

            if ($request->filled('module')) {
                $query->where('a.module', (string) $request->query('module'));
            }

            if ($request->filled('agent_id')) {
                $query->where('r.agent_id', (string) $request->query('agent_id'));
            }

            // Aggregates over EVERY run the filters match for this tenant, counted in
            // SQL — not over the page of rows fetched below.
            $aggregate = (clone $query)
                ->selectRaw(
                    'count(*) as total,'
                    . ' sum(case when r.created_date >= ? then 1 else 0 end) as today,'
                    . " sum(case when r.status = 'success' then 1 else 0 end) as succeeded,"
                    . " sum(case when r.status = 'denied' then 1 else 0 end) as denied,"
                    . " sum(case when r.status not in ('success', 'denied') then 1 else 0 end) as failed,"
                    . ' count(distinct r.created_by) as distinct_users,'
                    . ' avg(r.duration_ms) as avg_duration_ms',
                    [Platform::periodStart('day')]
                )
                ->first();

            $rows = $query->orderByDesc('r.created_date')
                ->limit($this->limit($request, 50, 200))
                ->get(['r.*', 'a.name as agent_name_col']);

            $actors = $this->actors($tenant, $rows->pluck('created_by')->all());

            return $this->success('Runs resolved.', [
                'runs' => $rows->map(fn ($run) => $this->presentRun($run, (string) $run->agent_name_col, $actors))->all(),
                'summary' => [
                    'total' => (int) ($aggregate->total ?? 0),
                    'today' => (int) ($aggregate->today ?? 0),
                    'succeeded' => (int) ($aggregate->succeeded ?? 0),
                    'failed' => (int) ($aggregate->failed ?? 0),
                    'denied' => (int) ($aggregate->denied ?? 0),
                    'distinct_users' => (int) ($aggregate->distinct_users ?? 0),
                    'avg_duration_ms' => ($aggregate->avg_duration_ms ?? null) === null ? null : (int) round((float) $aggregate->avg_duration_ms),
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string, int|null> */
    private function emptySummary(): array
    {
        return ['total' => 0, 'today' => 0, 'succeeded' => 0, 'failed' => 0, 'denied' => 0, 'distinct_users' => 0, 'avg_duration_ms' => null];
    }

    private function agent(string $id, string $tenant): ?object
    {
        if (! Schema::hasTable(self::AGENTS)) {
            return null;
        }

        return DB::table(self::AGENTS)
            ->where('id', $id)
            ->where('tenant_id', $tenant)
            ->whereIn('status', self::LIVE_STATUSES)
            ->whereIn('module', $this->modules->stackKeys($tenant))
            ->first();
    }

    /** @param array<string, string> $names */
    private function presentAgent(object $row, array $names): array
    {
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'description' => (string) ($row->description ?? ''),
            'module' => (string) $row->module,
            'tenant_id' => (string) $row->tenant_id,
            'tools_allowed' => $this->decode($row->tools_allowed),
            'instructions' => (string) ($row->instructions ?? ''),
            'trigger' => 'manual',
            'status' => in_array((string) $row->status, ['active', 'paused', 'archived'], true) ? (string) $row->status : 'draft',
            'created_by' => (string) ($row->created_by ?? ''),
            'created_by_name' => $names[(string) ($row->created_by ?? '')]['name'] ?? '',
            'created_at' => $this->iso($row->created_date),
            'updated_at' => $this->iso($row->updated_date),
        ];
    }

    /** @param array<string, array{name:string, role:string}> $actors */
    private function presentRun(object $run, string $agentName, array $actors): array
    {
        $input = Platform::decode($run->input ?? null);
        $output = Platform::decode($run->output ?? null);
        $error = $run->error_message === null ? null : (string) $run->error_message;
        $actor = $actors[(string) ($run->created_by ?? '')] ?? null;

        return [
            'id' => (string) $run->id,
            'agent_id' => (string) $run->agent_id,
            'agent_name' => $agentName,
            'module' => (string) $run->module,
            'tenant_id' => (string) $run->tenant_id,
            'started_at' => $this->iso($run->started_at ?? $run->created_date),
            'finished_at' => $this->iso($run->completed_at ?? $run->created_date),
            'completed_at' => $this->iso($run->completed_at ?? $run->created_date),
            'duration_ms' => (int) ($run->duration_ms ?? 0),
            'acting_user_id' => (string) ($run->created_by ?? ''),
            'acting_user_name' => $actor['name'] ?? '',
            // HP Brain has no profile table behind its users; the role is the profile.
            'acting_profile_id' => $actor['role'] ?? '',
            'acting_profile_name' => $actor['role'] ?? '',
            'trigger' => (string) ($run->trigger ?? 'manual'),
            'input' => $input,
            'output' => $output === [] ? null : $output,
            'tools_used' => $run->status === 'success' && isset($input['tool']) ? [(string) $input['tool']] : [],
            'status' => in_array((string) $run->status, ['success', 'denied'], true) ? (string) $run->status : 'failure',
            'error' => $error,
        ];
    }

    /** @param array<int, mixed> $ids @return array<string, array{name:string, role:string}> */
    private function actors(string $tenant, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($v) => (string) $v, $ids), fn ($v) => $v !== '')));

        if ($ids === [] || ! Schema::hasTable('hpbrain_auth_users')) {
            return [];
        }

        return DB::table('hpbrain_auth_users')
            ->where('tenant_id', $tenant)
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'role'])
            ->mapWithKeys(fn ($u) => [(string) $u->id => ['name' => trim((string) $u->name), 'role' => (string) $u->role]])
            ->all();
    }

    /** @param array<int, mixed> $ids @return array<string, array{name:string, role:string}> */
    private function userNames(string $tenant, array $ids): array
    {
        return $this->actors($tenant, $ids);
    }

    /**
     * The request's `arguments` object, read AFTER validation passed (validate() would
     * return only the validated `arguments.limit` and drop the other declared keys).
     *
     * @return array<string, mixed>
     */
    private function arguments(Request $request): array
    {
        $given = $request->input('arguments', []);

        return is_array($given) ? $given : [];
    }

    /** A stored UTC `Y-m-d H:i:s` as ISO-8601 (`2026-09-28T10:15:00Z`), which the screens compare by date prefix. */
    private function iso(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return $value;
        }
    }

    /** @return array<int, string> */
    private function decode(mixed $value): array
    {
        return array_values(array_filter(Platform::decode($value), 'is_string'));
    }
}
