<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\QuotaCheckResult;
use App\Domain\Ai\QuotaResult;
use Illuminate\Support\Facades\DB;

final class AiQuotaEnforcer
{
    public function checkBeforeCall(string $tenantId, string $userId, string $feature, int $estimatedTokens): QuotaCheckResult
    {
        $quota = DB::table('hpbrain_ai_quotas')
            ->where('tenant_id', $tenantId)
            ->where('quota_type', 'feature')
            ->where('quota_key', $feature)
            ->where('is_active', true)
            ->first();

        if (!$quota) {
            return new QuotaCheckResult(allowed: true, reason: 'no_quota_configured');
        }

        $limit = (int) $quota->limit_value;
        $used = (int) $quota->current_usage;

        if ($used + $estimatedTokens > $limit) {
            return new QuotaCheckResult(
                allowed: false,
                reason: 'quota_exceeded',
                quota: new QuotaResult(
                    allowed: false,
                    limit: $limit,
                    used: $used,
                    remaining: max(0, $limit - $used),
                    resetPeriod: (string) $quota->reset_period,
                ),
            );
        }

        return new QuotaCheckResult(
            allowed: true,
            reason: 'within_quota',
            quota: new QuotaResult(
                allowed: true,
                limit: $limit,
                used: $used,
                remaining: $limit - $used,
                resetPeriod: (string) $quota->reset_period,
            ),
        );
    }

    public function recordUsage(string $tenantId, string $userId, string $feature, int $inputTokens, int $outputTokens): void
    {
        $total = $inputTokens + $outputTokens;

        DB::table('hpbrain_ai_quotas')
            ->where('tenant_id', $tenantId)
            ->where('quota_type', 'feature')
            ->where('quota_key', $feature)
            ->where('is_active', true)
            ->increment('current_usage', $total);
    }
}
