<?php

declare(strict_types=1);

namespace App\Services;

final class TenantConfigCache
{
    public function remember(string $tenantId, string $key, \Closure $callback, int $ttl = 3600)
    {
        $cacheKey = "hpbrain:{$tenantId}:{$key}";

        return cache()->remember($cacheKey, $ttl, $callback);
    }

    public function forget(string $tenantId, string $key): void
    {
        cache()->forget("hpbrain:{$tenantId}:{$key}");
    }

    public function flushTenant(string $tenantId): void
    {
        try {
            if (function_exists('redis') && class_exists(\Illuminate\Support\Facades\Redis::class)) {
                redis()->deletePattern("hpbrain:{$tenantId}:*");
            } else {
                cache()->flush();
            }
        } catch (\Throwable $e) {
            cache()->flush();
        }
    }
}
