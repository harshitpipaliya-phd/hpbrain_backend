<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Intelligence\OrganizationDataProfiler;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the data profiler with a one-request lifetime.
 *
 * scoped(), NOT singleton(), and the distinction is the whole reason this provider
 * exists. The profiler memoises its loop-table counts and its column lists per
 * tenant, because both are needed more than once inside a single computation —
 * dataVersion() reads the counts to fingerprint the cache, and profile() reads them
 * again to report the shape of the loop. Against the remote MySQL this was built for,
 * where a bare `SELECT 1` measured close to three seconds, resolving that twice is a
 * cost worth removing.
 *
 * A singleton would hold those memos for the lifetime of a queue worker and keep
 * reporting a pre-import picture of the organization after an import landed — which
 * would defeat the fingerprint the cache is keyed on and make the "intelligence is
 * live" guarantee false in exactly the deployment where it matters most. scoped()
 * discards the instance, and the memos with it, at the end of each request.
 *
 * The analyzers themselves are stateless and are left to the container's default
 * transient resolution; nothing is gained by caching an object with no state.
 */
final class IntelligenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OrganizationDataProfiler::class);
    }
}
