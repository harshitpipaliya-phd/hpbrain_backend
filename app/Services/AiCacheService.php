<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Tenant-scoped caching of AI responses.
 *
 * The key shape — `hpbrain:ai:{tenant}:{key}` — and the file store are both
 * unchanged. flushTenant() now forgets the tenant's tracked keys rather than
 * scanning for filenames the file store never writes, and its failure path no
 * longer falls back to a global flush: one tenant's cache operation must not be
 * able to destroy another's. See TenantScopedCache.
 */
final class AiCacheService
{
    public function __construct(private readonly TenantScopedCache $cache)
    {
    }

    public function remember(string $tenantId, string $cacheKey, \Closure $callback, int $ttl = 3600): mixed
    {
        return $this->cache->remember($tenantId, $this->key($tenantId, $cacheKey), $ttl, $callback);
    }

    public function forget(string $tenantId, string $cacheKey): void
    {
        $this->cache->forget($this->key($tenantId, $cacheKey));
    }

    public function flushTenant(string $tenantId): void
    {
        $this->cache->forgetTenant($tenantId);
    }

    private function key(string $tenantId, string $cacheKey): string
    {
        return "hpbrain:ai:{$tenantId}:{$cacheKey}";
    }
}
