# FEATURE FLAGS

## Overview

Feature flags allow controlled rollout of features at multiple levels. They are stored in `hpbrain_feature_flags` and evaluated by the `ConfigurationEngine`.

## Flag Levels

| Level | Description | Example |
|-------|-------------|---------|
| platform | Applies to all tenants | `ai_workspace` |
| plan | Applies to a subscription plan | `enterprise_features` |
| organization | Applies to a specific org | `custom_branding` |
| role | Applies to a specific role | `admin_tools` |
| user | Applies to a specific user | `beta_tester` |

## Flag Structure

```json
{
  "flag_key": "ai_workspace",
  "flag_name": "AI Workspace",
  "enabled": true,
  "level": "platform",
  "level_id": null,
  "rollout_percentage": 100,
  "rules": {}
}
```

## Rollout Percentage

When `rollout_percentage` is less than 100, users are deterministically included based on a hash of their user ID. This allows gradual rollout without tracking individual users.

## Evaluation Logic

```php
$engine = app(ConfigurationEngine::class);
$enabled = $engine->isFeatureEnabled($tenantId, 'ai_workspace', ['user_id' => $userId]);
```

Evaluation order:
1. Check for user-level flag
2. Check for role-level flag
3. Check for organization-level flag
4. Check for plan-level flag
5. Check for platform-level flag
6. Return false if no flag found

## Creating a Feature Flag

```http
POST /api/v1/feature-flags
{
  "flag_key": "new_feature",
  "flag_name": "New Feature",
  "enabled": true,
  "level": "platform",
  "rollout_percentage": 50
}
```

## Frontend Integration

```tsx
const { isEnabled } = useFeatureFlag();
if (isEnabled('ai_workspace')) {
  // Show AI workspace
}
```
