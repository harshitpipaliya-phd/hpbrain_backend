<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Support;

use App\Domain\AiIntelligence\Configuration\ModelCatalog;
use App\Domain\AiIntelligence\Configuration\ResolvedAiConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Counts what the AI costs (hpbrain_ai_usage_events), and refuses the call that
 * would exceed a quota (hpbrain_ai_usage_quotas).
 *
 * Ported from G2G's AiUsageMeter. Every call goes through AiModelClient::complete(),
 * which calls guard() before the request and record() after it — for successes,
 * provider failures and quota refusals alike — so nothing can spend without being
 * counted. record() never breaks the call; guard() treats a failure to READ the
 * quota as "no quota". Cost is null, never a guess, when the catalogue has no rate.
 */
final class AiUsageMeter
{
    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_REFUSED = 'refused';

    public function __construct(private readonly ModelCatalog $models)
    {
    }

    /** @param array<string, mixed> $options `related_type`, `related_id`, `user_id`, `outcome`, `error`, `finish_reason` */
    public function record(
        string $moduleKey,
        ResolvedAiConfiguration $config,
        string $tenantId,
        int $inputTokens,
        int $outputTokens,
        ?int $latencyMs,
        array $options = []
    ): void {
        try {
            if (! Schema::hasTable('hpbrain_ai_usage_events')) {
                return;
            }

            $now = Platform::now();

            DB::table('hpbrain_ai_usage_events')->insert([
                'id' => Platform::id(),
                'tenant_id' => $tenantId,
                'ai_module' => $moduleKey,
                'provider' => $config->provider,
                'model' => $config->model,
                'source' => $config->source,
                'input_tokens' => max(0, $inputTokens),
                'output_tokens' => max(0, $outputTokens),
                'latency_ms' => $latencyMs,
                'estimated_cost_usd' => $this->cost($config, $inputTokens, $outputTokens, $tenantId),
                'outcome' => $options['outcome'] ?? self::OUTCOME_SUCCESS,
                'finish_reason' => isset($options['finish_reason']) ? mb_substr((string) $options['finish_reason'], 0, 40) : null,
                'error' => isset($options['error']) ? mb_substr((string) $options['error'], 0, 2000) : null,
                'related_type' => $options['related_type'] ?? null,
                'related_id' => isset($options['related_id']) ? mb_substr((string) $options['related_id'], 0, 64) : null,
                'user_id' => isset($options['user_id']) ? mb_substr((string) $options['user_id'], 0, 64) : null,
                'created_date' => $now,
                'updated_date' => $now,
            ]);
        } catch (Throwable $exception) {
            Log::warning('[ai.intelligence.usage] could not record a usage event', [
                'module' => $moduleKey,
                'provider' => $config->provider,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** Null when the call may proceed, or the reason it may not. */
    public function guard(string $moduleKey, string $tenantId): ?string
    {
        if (trim($tenantId) === '') {
            return null;
        }

        try {
            $quota = $this->applicableQuota($moduleKey, $tenantId);

            if ($quota === null) {
                return null;
            }

            $used = $this->tokensUsed($tenantId, $quota->ai_module, (string) $quota->period);

            if ($used < (int) $quota->token_limit) {
                return null;
            }

            return sprintf(
                'The %s AI token quota for this organisation is spent: %s of %s tokens used this %s. '
                . 'Raise the limit under AI & Intelligence → Usage & Cost, or wait for the period to reset.',
                $quota->ai_module === null ? 'overall' : $quota->ai_module,
                number_format($used),
                number_format((int) $quota->token_limit),
                $quota->period
            );
        } catch (Throwable $exception) {
            Log::warning('[ai.intelligence.usage] could not evaluate a quota; allowing the call', [
                'module' => $moduleKey,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /** The narrowest active quota: this capability's, else the whole tenant's. */
    private function applicableQuota(string $moduleKey, string $tenantId): ?object
    {
        if (! Schema::hasTable('hpbrain_ai_usage_quotas')) {
            return null;
        }

        foreach ([$moduleKey, null] as $scope) {
            $query = DB::table('hpbrain_ai_usage_quotas')
                ->where('tenant_id', $tenantId)
                ->where('status', 1);

            $scope === null ? $query->whereNull('ai_module') : $query->where('ai_module', $scope);

            $row = $query->first();

            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    /** Tokens consumed in the current period. Refused calls are excluded — they cost nothing. */
    public function tokensUsed(string $tenantId, ?string $moduleKey, string $period): int
    {
        if (! Schema::hasTable('hpbrain_ai_usage_events')) {
            return 0;
        }

        $query = DB::table('hpbrain_ai_usage_events')
            ->where('tenant_id', $tenantId)
            ->where('outcome', '!=', self::OUTCOME_REFUSED)
            ->where('created_date', '>=', Platform::periodStart($period));

        if ($moduleKey !== null) {
            $query->where('ai_module', $moduleKey);
        }

        return (int) $query->sum(DB::raw('input_tokens + output_tokens'));
    }

    private function cost(ResolvedAiConfiguration $config, int $inputTokens, int $outputTokens, string $tenantId): ?float
    {
        $rates = $this->models->rates($config->provider, $config->model, $tenantId);

        if ($rates === null) {
            return null;
        }

        return round(($inputTokens / 1000) * $rates['input'] + ($outputTokens / 1000) * $rates['output'], 6);
    }
}
