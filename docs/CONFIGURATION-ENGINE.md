# CONFIGURATION ENGINE

## Overview

The `ConfigurationEngine` is the central service for resolving configuration across the platform. It implements a multi-level inheritance model.

## Inheritance Chain

```
Platform Default
    → Industry Template
        → Organization Override
            → Role Override
                → User Preference
```

## Usage

### Getting Configuration

```php
$engine = app(ConfigurationEngine::class);
$value = $engine->get($tenantId, $orgId, 'setting_key', 'default_value');
```

### Setting Configuration

```php
$engine->set($tenantId, $orgId, 'setting_key', 'new_value', 'scalar');
// or for JSON values
$engine->set($tenantId, $orgId, 'widget_layout', $layoutArray, 'json');
```

### Resolving Terminology

```php
$displayName = $engine->resolveTerminology($tenantId, 'healthcare', 'Person');
// Returns "Patient"
```

### Getting Navigation

```php
$navigation = $engine->getNavigation($tenantId, 'healthcare', 'admin');
```

### Getting Branding

```php
$branding = $engine->getBranding($tenantId, $orgId);
// Falls back to industry branding if org has none
```

### Getting Modules

```php
$modules = $engine->getModules($tenantId, $orgId);
```

### Checking Feature Flags

```php
$enabled = $engine->isFeatureEnabled($tenantId, 'ai_workspace', ['user_id' => $userId]);
```

## Caching

All resolved configurations are cached via `TenantConfigCache` with tenant-prefixed keys. Cache keys include the tenant ID to prevent cross-tenant leakage.

## Cache Invalidation

- `TenantConfigCache::forget($tenantId, $key)` — clears a specific key
- `TenantConfigCache::flushTenant($tenantId)` — clears all cache for a tenant
