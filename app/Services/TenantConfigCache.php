<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Tenant-scoped configuration caching.
 *
 * The key shape is unchanged — `hpbrain:{tenant}:{key}` — and so is the file
 * store. What changed is that flushTenant() now actually forgets this tenant's
 * entries instead of scanning for filenames that the file store never writes.
 * See TenantScopedCache for the full account.
 */
final class TenantConfigCache
{
    public function __construct(private readonly TenantScopedCache $cache)
    {
    }

    public function remember(string $tenantId, string $key, \Closure $callback, int $ttl = 3600)
    {
        return $this->cache->remember($tenantId, "hpbrain:{$tenantId}:{$key}", $ttl, $callback);
    }

    public function forget(string $tenantId, string $key): void
    {
        $this->cache->forget("hpbrain:{$tenantId}:{$key}");
    }

    public function flushTenant(string $tenantId): void
    {
        $this->cache->forgetTenant($tenantId);
    }
}
