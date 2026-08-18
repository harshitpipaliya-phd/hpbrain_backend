<?php

declare(strict_types=1);

/*
 * THE FALLBACK IS `file`, NOT `database`, AND THAT IS THE POINT.
 *
 * Laravel's own default here is `database`, which routes every cache read and
 * write into a query against the very database the cache exists to spare. On a
 * deployment whose CACHE_STORE was absent from .env that default applied
 * silently — nothing errors, the cache simply becomes table traffic — and
 * concurrent reads and writes against the `cache` table are how this
 * application reached `SQLSTATE[HY000]: General error: 1205 Lock wait timeout
 * exceeded`.
 *
 * The application already assumes the file store: TenantScopedCache and
 * IntelligenceEngine both address `store('file')` explicitly. Making that the
 * fallback too means a missing environment variable degrades to the store the
 * code already expects, instead of to the one that caused the outage.
 *
 * `database` remains configured, because a deployment may still select it
 * deliberately via CACHE_STORE. It is no longer what you get by accident.
 */
return [
    'default' => env('CACHE_STORE', 'file'),

    'stores' => [
        'database' => ['driver' => 'database', 'table' => 'cache'],
        'file'     => ['driver' => 'file', 'path' => storage_path('framework/cache/data')],
    ],
];
