<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Industry\Vocabulary;
use App\Domain\Universal\EntityResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the vocabulary layer.
 *
 * scoped() rather than singleton(): the resolver caches tenant mappings, and
 * those mappings are configuration that changes while the application runs.
 * A singleton would hold the cache for the lifetime of the worker process and
 * keep serving a stale table name after an administrator corrected it. scoped()
 * gives one instance — and one cache — per request, which is the lifetime the
 * cache is actually correct for.
 *
 * bind() would be the other safe option but wastes the cache entirely: a single
 * request resolves Person, OrganizationUnit and Organization repeatedly across
 * a controller and the repositories it calls, and each would pay its own query.
 */
final class UniversalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(EntityResolver::class);

        // Vocabulary caches per tenant for exactly the same reason and with
        // exactly the same lifetime: labels are configuration, and a request
        // composes several sentences from them.
        $this->app->scoped(Vocabulary::class);
    }
}
