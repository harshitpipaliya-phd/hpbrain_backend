<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\AiResponse;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AssembledContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class AiCacheService
{
    public function remember(string $tenantId, string $cacheKey, \Closure $callback, int $ttl = 3600): mixed
    {
        $key = "hpbrain:ai:{$tenantId}:{$cacheKey}";

        return Cache::remember($key, $ttl, $callback);
    }

    public function forget(string $tenantId, string $cacheKey): void
    {
        Cache::forget("hpbrain:ai:{$tenantId}:{$cacheKey}");
    }

    public function flushTenant(string $tenantId): void
    {
        try {
            if (function_exists('redis') && class_exists(\Illuminate\Support\Facades\Redis::class)) {
                redis()->deletePattern("hpbrain:ai:{$tenantId}:*");
            } else {
                Cache::flush();
            }
        } catch (\Throwable $e) {
            Cache::flush();
        }
    }
}
