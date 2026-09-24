<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\QuotaResult;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Per-feature AI consumption limits.
 *
 * A LIMIT OF ZERO MEANS "METER, DO NOT CAP" — it is not a limit of nothing.
 * The distinction matters because of how rows come to exist: recordUsage()
 * creates one the first time a feature is used, and it cannot invent a cap the
 * organization never set. Reading 0 as a hard ceiling would make the first call
 * to any un-configured feature also the last.
 *
 * USAGE IS RECORDED EVEN WHEN NO QUOTA IS CONFIGURED. The previous version
 * incremented a row that had to already exist, so consumption on every
 * unconfigured feature — which is all of them, until an admin visits the quota
 * screen — silently hit zero rows and vanished. Spend that is not written down
 * cannot be governed later, and the gap is invisible precisely where it is
 * widest: a brand-new tenant, before anyone has thought about limits.
 */
final class QuotaService
{
    public function check(string $tenantId, string $userId, string $feature): QuotaResult
    {
        $quota = DB::table('hpbrain_ai_quotas')
            ->where('tenant_id', $tenantId)
            ->where('quota_type', 'feature')
            ->where('quota_key', $feature)
            ->where('is_active', true)
            ->first();

        if (! $quota) {
            return new QuotaResult(
                allowed: true, limit: 0, used: 0,
                remaining: PHP_INT_MAX, resetPeriod: 'monthly',
            );
        }

        $limit = (int) $quota->limit_value;
        $used  = (int) $quota->current_usage;

        // limit <= 0 is the metering-only row recordUsage() creates. Report the
        // real usage — that is the point of the row — but do not cap on it.
        if ($limit <= 0) {
            return new QuotaResult(
                allowed: true, limit: 0, used: $used,
                remaining: PHP_INT_MAX, resetPeriod: (string) $quota->reset_period,
            );
        }

        return new QuotaResult(
            allowed: $used < $limit,
            limit: $limit,
            used: $used,
            remaining: max(0, $limit - $used),
            resetPeriod: (string) $quota->reset_period,
        );
    }

    /**
     * Add $tokens to the feature's running total, creating the row if needed.
     *
     * $cost is accepted but not persisted here: hpbrain_ai_quotas has no cost
     * column, and the authoritative per-call spend is already written to
     * hpbrain_ai_executions.estimated_cost_usd by AiGateway. Adding a second,
     * separately-maintained cost total would give the governance dashboard two
     * numbers that drift.
     */
    public function recordUsage(string $tenantId, string $userId, string $feature, int $tokens, float $cost): void
    {
        $updated = DB::table('hpbrain_ai_quotas')
            ->where('tenant_id', $tenantId)
            ->where('quota_type', 'feature')
            ->where('quota_key', $feature)
            ->where('is_active', true)
            ->increment('current_usage', $tokens);

        if ($updated > 0) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s');

        DB::table('hpbrain_ai_quotas')->insert([
            'id'            => Uuid::uuid4()->toString(),
            'tenant_id'     => $tenantId,
            'quota_type'    => 'feature',
            'quota_key'     => $feature,
            // Metering only. An admin setting a real cap later just raises this.
            'limit_value'   => 0,
            'current_usage' => $tokens,
            'reset_period'  => 'monthly',
            'is_active'     => true,
            'created_by'    => $userId,
            'created_date'  => $now,
            'updated_date'  => $now,
        ]);
    }
}
