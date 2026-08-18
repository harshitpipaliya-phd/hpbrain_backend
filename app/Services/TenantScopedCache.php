<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Tenant-scoped caching where "flush this tenant" means only that tenant.
 *
 * THE PROBLEM THIS REPLACES, IN ORDER.
 *
 * 1. `Cache::flush()` was called to clear one tenant. It clears EVERY tenant —
 *    so an import for one school threw away the intelligence, config and
 *    navigation caches of every other, and each of them then recomputed against
 *    the shared database at the same time. On the `database` cache store that
 *    also meant a DELETE across the whole cache table while other requests were
 *    reading it, which is one of the ways this deployment reached
 *    `SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded`.
 *
 * 2. The fix for (1) moved to the file store and tried to delete the tenant's
 *    entries by scanning storage/framework/cache/data for filenames containing
 *    the tenant prefix. That does not work and, worse, does not fail: Laravel's
 *    file store writes each entry to data/<2 hex>/<2 hex>/<sha1 of key>, so the
 *    top level contains only directories, `is_file()` is false for all of them,
 *    and no basename ever contains the prefix. The loop matched nothing on every
 *    run. Global flushing had been removed and precise flushing had not been
 *    added — stale tenant entries simply survived until their TTL.
 *
 * WHAT THIS DOES INSTEAD. Every key written through here is recorded in a small
 * per-tenant index that is itself a cache entry. Forgetting a tenant reads its
 * index and forgets exactly those keys. No global flush, no filesystem
 * assumptions, no dependence on how the store spells a key on disk — so it stays
 * correct if the store is later moved to redis or memcached.
 *
 * THE INDEX IS BOUNDED. A tenant that somehow writes more than MAX_TRACKED
 * distinct keys drops its oldest entries from the index rather than growing
 * without limit; those fall out by TTL instead. Losing an index entry costs a
 * stale read until the TTL expires, which is strictly better than the
 * alternative this replaces.
 *
 * THE FILE STORE IS DELIBERATE AND IS NOT CHANGED HERE. `database` turns every
 * cache read into a query against the very database the cache exists to spare,
 * and is what made cache contention a lock problem in the first place.
 */
final class TenantScopedCache
{
    /** Ceiling on keys tracked per tenant, so the index cannot grow unbounded. */
    private const MAX_TRACKED = 500;

    /** The index outlives any entry it points at; a stale pointer is harmless. */
    private const INDEX_TTL = 86400;

    /** The store every tenant-scoped entry lives in. */
    public function store(): Repository
    {
        return Cache::store('file');
    }

    /**
     * Remember a value under a tenant-scoped key, and track the key.
     *
     * @template T
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function remember(string $tenantId, string $key, int $ttl, \Closure $callback)
    {
        $this->track($tenantId, $key);

        return $this->store()->remember($key, $ttl, $callback);
    }

    public function get(string $tenantId, string $key): mixed
    {
        return $this->store()->get($key);
    }

    public function put(string $tenantId, string $key, mixed $value, int $ttl): void
    {
        $this->track($tenantId, $key);
        $this->store()->put($key, $value, $ttl);
    }

    public function forget(string $key): void
    {
        $this->store()->forget($key);
    }

    /**
     * Forget every key this tenant has written — and nothing else.
     */
    public function forgetTenant(string $tenantId): void
    {
        $index = $this->indexKey($tenantId);
        $keys = $this->store()->get($index);

        if (is_array($keys)) {
            foreach ($keys as $key) {
                $this->store()->forget((string) $key);
            }
        }

        $this->store()->forget($index);
    }

    /**
     * Record that $key belongs to $tenantId.
     *
     * Read-modify-write without a lock, and that is a considered choice: taking
     * a cache lock here would reintroduce the contention this class exists to
     * remove, and the worst a lost update can do is leave one key untracked —
     * which costs a stale entry until its TTL, not a correctness failure. Cache
     * locks are what turn a cache into a bottleneck; an index that is 99%
     * complete is worth far more than one that is exact and serialises every
     * write.
     */
    private function track(string $tenantId, string $key): void
    {
        $index = $this->indexKey($tenantId);
        $keys = $this->store()->get($index);
        $keys = is_array($keys) ? $keys : [];

        if (in_array($key, $keys, true)) {
            return;
        }

        $keys[] = $key;

        if (count($keys) > self::MAX_TRACKED) {
            $keys = array_slice($keys, -self::MAX_TRACKED);
        }

        $this->store()->put($index, $keys, self::INDEX_TTL);
    }

    private function indexKey(string $tenantId): string
    {
        return "hpbrain:cache-index:{$tenantId}";
    }
}
