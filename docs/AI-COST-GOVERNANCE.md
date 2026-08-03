# AI COST GOVERNANCE

## Overview

AI Cost Governance provides visibility and control over AI spending across the platform.

## TokenCostAccountingService

Records every AI execution with:
- `input_tokens`
- `output_tokens`
- `estimated_cost_usd`
- `model`
- `entity_type` / `entity_id`

### getMonthlyCost(string $tenantId, string $orgId): array

Returns:
```php
[
    'totalCost' => 123.45,
    'byModel' => ['gpt-4' => 100.00, 'claude-3' => 23.45],
    'byFeature' => ['ai.chat' => 80.00, 'ai.summarize' => 43.45],
]
```

## QuotaService

Quotas are stored in `hpbrain_ai_quotas`:
- `quota_type`: organization/user/feature/monthly
- `quota_key`: Feature key (e.g., `ai.chat`)
- `limit_value`: Maximum allowed
- `current_usage`: Current usage
- `reset_period`: monthly/weekly/daily

### Levels

1. **Organization-level**: Limits for the entire org
2. **User-level**: Limits per user
3. **Feature-level**: Limits per AI feature
4. **Monthly budget**: Overall spending limit

## AiQuotaEnforcer

Called BEFORE every AI call:
```php
$result = $quotaEnforcer->checkBeforeCall($tenantId, $userId, 'ai.chat', $estimatedTokens);
if (!$result->allowed) {
    return error('quota_exceeded');
}
```

## Pricing

Configured in `config/brain.php`:
```php
'ai' => [
    'pricing' => [
        'claude-opus-5'   => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-5' => ['input' => 3.00,  'output' => 15.00],
    ],
]
```

## Cost Control

- Quotas checked BEFORE making the call
- All costs recorded in `hpbrain_ai_executions`
- Monthly cost reports available via API
- Per-model and per-feature breakdowns
