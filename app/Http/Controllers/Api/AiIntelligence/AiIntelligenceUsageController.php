<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Configuration\AiModuleRegistry;
use App\Domain\AiIntelligence\Support\AiAuditLogger;
use App\Domain\AiIntelligence\Support\AiUsageMeter;
use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Usage & Cost — what the AI spent, broken down, and the quota that bounds it.
 *
 * Ported from G2G's UsageController onto hpbrain_ai_usage_events /
 * hpbrain_ai_usage_quotas (hpbrain_ai_quotas already exists for the older /ai
 * screens). `other_ledgers` names hpbrain_ai_executions — the AiGateway's ledger
 * for the intelligence loop's verbs — so an administrator looking for a figure
 * that is not metered here knows where else it lives.
 */
final class AiIntelligenceUsageController extends AiIntelligenceController
{
    private const WINDOWS = ['day' => 1, 'week' => 7, 'month' => 30, 'quarter' => 90];

    public function __construct(
        private readonly AiUsageMeter $meter,
        private readonly AiModuleRegistry $modules,
        private readonly AiAuditLogger $audit,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            if (! Schema::hasTable('hpbrain_ai_usage_events')) {
                return $this->failure('Usage metering is not installed on this deployment.', 422);
            }

            $validated = $request->validate([
                'window' => ['nullable', 'string', Rule::in(array_keys(self::WINDOWS))],
            ]);

            $window = $validated['window'] ?? 'month';
            $since = Platform::daysAgo(self::WINDOWS[$window]);

            return $this->success('Usage resolved.', [
                'tenant_id' => $tenantId,
                'window' => $window,
                'since' => $since,
                'totals' => $this->totals($tenantId, $since),
                'by_module' => $this->byModule($tenantId, $since),
                'by_model' => $this->byModel($tenantId, $since),
                'by_day' => $this->byDay($tenantId, $since),
                'quotas' => $this->quotas($tenantId),
                'other_ledgers' => [
                    'hpbrain_ai_executions' => $this->foreignCount('hpbrain_ai_executions', $tenantId),
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function events(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            $rows = Schema::hasTable('hpbrain_ai_usage_events')
                ? DB::table('hpbrain_ai_usage_events')
                    ->where('tenant_id', $tenantId)
                    ->orderByDesc('created_date')
                    ->limit($this->limit($request, 50, 200))
                    ->get()
                : collect();

            return $this->success('Usage events resolved.', [
                'tenant_id' => $tenantId,
                'events' => $rows->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'ai_module' => (string) $row->ai_module,
                    'module_label' => $this->modules->label((string) $row->ai_module),
                    'provider' => (string) $row->provider,
                    'model' => $row->model === null ? null : (string) $row->model,
                    'source' => $row->source === null ? null : (string) $row->source,
                    'input_tokens' => (int) $row->input_tokens,
                    'output_tokens' => (int) $row->output_tokens,
                    'latency_ms' => $row->latency_ms === null ? null : (int) $row->latency_ms,
                    'estimated_cost_usd' => $row->estimated_cost_usd === null ? null : (float) $row->estimated_cost_usd,
                    'outcome' => (string) $row->outcome,
                    'finish_reason' => $row->finish_reason === null ? null : (string) $row->finish_reason,
                    'error' => $row->error === null ? null : (string) $row->error,
                    'related_type' => $row->related_type === null ? null : (string) $row->related_type,
                    'related_id' => $row->related_id === null ? null : (string) $row->related_id,
                    'created_at' => $row->created_date === null ? null : (string) $row->created_date,
                ])->values()->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** A token_limit of 0 removes the quota rather than setting a limit of nothing. */
    public function saveQuota(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            $validated = $request->validate([
                'ai_module' => ['nullable', 'string', Rule::in($this->modules->keys())],
                'period' => ['required', 'string', Rule::in(['day', 'month'])],
                'token_limit' => ['required', 'integer', 'min:0', 'max:1000000000'],
                'warn_at_percent' => ['nullable', 'integer', 'min:1', 'max:99'],
            ]);

            $moduleKey = $validated['ai_module'] ?? null;
            $label = $moduleKey === null ? 'the whole organisation' : $this->modules->label($moduleKey);

            $existing = DB::table('hpbrain_ai_usage_quotas')
                ->where('tenant_id', $tenantId)
                ->where('period', $validated['period'])
                ->when($moduleKey === null, fn ($q) => $q->whereNull('ai_module'))
                ->when($moduleKey !== null, fn ($q) => $q->where('ai_module', $moduleKey))
                ->first();

            if ((int) $validated['token_limit'] === 0) {
                if ($existing !== null) {
                    DB::table('hpbrain_ai_usage_quotas')->where('id', $existing->id)->where('tenant_id', $tenantId)->delete();
                }

                $this->audit->record('ai.quota.removed', $scope, [
                    'related_type' => 'hpbrain_ai_usage_quotas',
                    'related_id' => $existing->id ?? null,
                    'message' => sprintf('AI token quota removed for %s (%s).', $label, $validated['period']),
                ]);

                return $this->success('Quota removed.', ['quotas' => $this->quotas($tenantId)]);
            }

            $now = Platform::now();

            $payload = [
                'token_limit' => (int) $validated['token_limit'],
                'warn_at_percent' => $validated['warn_at_percent'] ?? 80,
                'status' => 1,
                'updated_date' => $now,
            ];

            if ($existing !== null) {
                $quotaId = (string) $existing->id;
                DB::table('hpbrain_ai_usage_quotas')->where('id', $quotaId)->where('tenant_id', $tenantId)->update($payload);
            } else {
                $quotaId = Platform::id();
                DB::table('hpbrain_ai_usage_quotas')->insert($payload + [
                    'id' => $quotaId,
                    'tenant_id' => $tenantId,
                    'ai_module' => $moduleKey,
                    'period' => $validated['period'],
                    'created_by' => $scope->userId,
                    'created_date' => $now,
                ]);
            }

            $this->audit->record('ai.quota.set', $scope, [
                'related_type' => 'hpbrain_ai_usage_quotas',
                'related_id' => $quotaId,
                'message' => sprintf(
                    'AI token quota set to %s per %s for %s.',
                    number_format((int) $validated['token_limit']),
                    $validated['period'],
                    $label
                ),
                'payload' => $payload + ['ai_module' => $moduleKey, 'period' => $validated['period']],
            ]);

            return $this->success('Quota saved.', ['quotas' => $this->quotas($tenantId)]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function options(Request $request): JsonResponse
    {
        try {
            $this->scope($request);

            return $this->success('Usage options resolved.', [
                'modules' => array_map(fn ($module) => [
                    'key' => $module['key'],
                    'label' => $module['label'],
                    'wired' => $module['wired'],
                ], $this->modules->all()),
                'periods' => ['day', 'month'],
                'windows' => array_keys(self::WINDOWS),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** @return array<string, mixed> */
    private function totals(string $tenantId, string $since): array
    {
        $row = $this->scoped($tenantId, $since)
            ->selectRaw('
                COUNT(*) AS calls,
                SUM(input_tokens) AS input_tokens,
                SUM(output_tokens) AS output_tokens,
                SUM(estimated_cost_usd) AS cost,
                SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) AS refused,
                SUM(CASE WHEN estimated_cost_usd IS NOT NULL THEN 1 ELSE 0 END) AS priced,
                AVG(latency_ms) AS avg_latency
            ', [AiUsageMeter::OUTCOME_FAILED, AiUsageMeter::OUTCOME_REFUSED])
            ->first();

        $cost = $row->cost === null ? null : (float) $row->cost;
        $calls = (int) $row->calls;
        $priced = (int) $row->priced;

        return [
            'calls' => $calls,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'total_tokens' => (int) $row->input_tokens + (int) $row->output_tokens,
            'failed' => (int) $row->failed,
            'refused' => (int) $row->refused,
            'avg_latency_ms' => $row->avg_latency === null ? null : (int) round((float) $row->avg_latency),
            'estimated_cost_usd' => $cost,
            // How many calls that cost figure actually covers — SUM() skips nulls.
            'calls_priced' => $priced,
            'cost_known' => $cost !== null,
            'cost_complete' => $calls > 0 && $priced === $calls,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function byModule(string $tenantId, string $since): array
    {
        return $this->scoped($tenantId, $since)
            ->selectRaw('ai_module, COUNT(*) AS calls, SUM(input_tokens + output_tokens) AS tokens, SUM(estimated_cost_usd) AS cost')
            ->groupBy('ai_module')
            ->orderByDesc('tokens')
            ->get()
            ->map(fn ($row) => [
                'ai_module' => (string) $row->ai_module,
                'module_label' => $this->modules->label((string) $row->ai_module),
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
                'estimated_cost_usd' => $row->cost === null ? null : (float) $row->cost,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function byModel(string $tenantId, string $since): array
    {
        return $this->scoped($tenantId, $since)
            ->selectRaw('provider, model, COUNT(*) AS calls, SUM(input_tokens + output_tokens) AS tokens, SUM(estimated_cost_usd) AS cost')
            ->groupBy('provider', 'model')
            ->orderByDesc('tokens')
            ->get()
            ->map(fn ($row) => [
                'provider' => (string) $row->provider,
                'model' => $row->model === null ? null : (string) $row->model,
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
                'estimated_cost_usd' => $row->cost === null ? null : (float) $row->cost,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function byDay(string $tenantId, string $since): array
    {
        return $this->scoped($tenantId, $since)
            ->selectRaw('DATE(created_date) AS day, COUNT(*) AS calls, SUM(input_tokens + output_tokens) AS tokens, SUM(estimated_cost_usd) AS cost')
            ->groupBy(DB::raw('DATE(created_date)'))
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
                'estimated_cost_usd' => $row->cost === null ? null : (float) $row->cost,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> Each quota with how much of it is spent. */
    private function quotas(string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_ai_usage_quotas')) {
            return [];
        }

        return DB::table('hpbrain_ai_usage_quotas')
            ->where('tenant_id', $tenantId)
            ->orderByRaw('CASE WHEN ai_module IS NULL THEN 0 ELSE 1 END')
            ->orderBy('ai_module')
            ->get()
            ->map(function ($row) use ($tenantId) {
                $used = $this->meter->tokensUsed($tenantId, $row->ai_module, (string) $row->period);
                $limit = (int) $row->token_limit;
                $percent = $limit > 0 ? (int) round($used / $limit * 100) : 0;

                return [
                    'id' => (string) $row->id,
                    'ai_module' => $row->ai_module === null ? null : (string) $row->ai_module,
                    'module_label' => $row->ai_module === null ? 'Whole organisation' : $this->modules->label((string) $row->ai_module),
                    'period' => (string) $row->period,
                    'token_limit' => $limit,
                    'tokens_used' => $used,
                    'percent_used' => $percent,
                    'warn_at_percent' => (int) $row->warn_at_percent,
                    'warning' => $percent >= (int) $row->warn_at_percent && $percent < 100,
                    'exceeded' => $used >= $limit,
                    'status' => (int) $row->status,
                ];
            })
            ->all();
    }

    /** A count from a ledger this layer does not write, reported rather than merged. */
    private function foreignCount(string $table, string $tenantId): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        return (int) DB::table($table)->where('tenant_id', $tenantId)->count();
    }

    private function scoped(string $tenantId, string $since): Builder
    {
        return DB::table('hpbrain_ai_usage_events')
            ->where('tenant_id', $tenantId)
            ->where('created_date', '>=', $since);
    }
}
