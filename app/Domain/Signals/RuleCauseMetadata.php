<?php

declare(strict_types=1);

namespace App\Domain\Signals;

use Illuminate\Support\Facades\DB;

/**
 * Resolves human-approved root-cause metadata for both rule storage models.
 *
 * Row-held rules use hpbrain_signal_rules. Code-held operational rules use
 * hpbrain_operational_rule_metadata. Callers should not need to care which
 * physical table a rule came from; a null family still means "not approved".
 */
final class RuleCauseMetadata
{
    /**
     * @return array<int, string>
     */
    public function approvedRuleKeys(string $tenantId): array
    {
        $rowRules = DB::table('hpbrain_signal_rules')
            ->where('is_active', 1)
            ->whereIn('tenant_id', [RuleEvaluator::PLATFORM_TENANT, $tenantId])
            ->whereNotNull('root_cause_family')
            ->whereNotNull('hypothesis_confidence')
            ->pluck('rule_key')
            ->map(fn ($key) => (string) $key)
            ->all();

        $operationalRules = $this->hasOperationalTable()
            ? DB::table('hpbrain_operational_rule_metadata')
                ->whereIn('tenant_id', [RuleEvaluator::PLATFORM_TENANT, $tenantId])
                ->whereNotNull('root_cause_family')
                ->whereNotNull('hypothesis_confidence')
                ->pluck('rule_key')
                ->map(fn ($key) => (string) $key)
                ->all()
            : [];

        return array_values(array_unique(array_merge($rowRules, $operationalRules)));
    }

    /**
     * @return array{family: string, confidence: float, statement: string|null, source: string}|null
     */
    public function approvedFor(string $tenantId, string $ruleKey): ?array
    {
        $rowRule = DB::table('hpbrain_signal_rules')
            ->where('is_active', 1)
            ->whereIn('tenant_id', [RuleEvaluator::PLATFORM_TENANT, $tenantId])
            ->where('rule_key', $ruleKey)
            ->whereNotNull('root_cause_family')
            ->whereNotNull('hypothesis_confidence')
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
            ->first();

        if ($rowRule !== null) {
            return [
                'family'     => (string) $rowRule->root_cause_family,
                'confidence' => (float) $rowRule->hypothesis_confidence,
                'statement'  => (string) $rowRule->recommended_action,
                'source'     => 'signal_rule',
            ];
        }

        if (! $this->hasOperationalTable()) {
            return null;
        }

        $operationalRule = DB::table('hpbrain_operational_rule_metadata')
            ->whereIn('tenant_id', [RuleEvaluator::PLATFORM_TENANT, $tenantId])
            ->where('rule_key', $ruleKey)
            ->whereNotNull('root_cause_family')
            ->whereNotNull('hypothesis_confidence')
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
            ->first();

        if ($operationalRule === null) {
            return null;
        }

        return [
            'family'     => (string) $operationalRule->root_cause_family,
            'confidence' => (float) $operationalRule->hypothesis_confidence,
            'statement'  => $operationalRule->recommended_action === null
                ? null
                : (string) $operationalRule->recommended_action,
            'source'     => 'operational_rule_metadata',
        ];
    }

    public function hasOperationalTable(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('hpbrain_operational_rule_metadata');
        } catch (\Throwable) {
            return false;
        }
    }
}
