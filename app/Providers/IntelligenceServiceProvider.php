<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Intelligence\OrganizationDataProfiler;
use App\Domain\School\DatasetRegistry;
use App\Services\TenantScopedCache;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the memoising services with a one-request lifetime.
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
 *
 * DatasetRegistry IS STATEFUL AND WAS NOT BOUND, which made its memo useless.
 * It answers "which dataset holds this tenant's exam results" by reading
 * hpbrain_data_sources, and memoises the answer — but the container's default is
 * a fresh instance per injection, so StudentController, AcademicRecordRepository
 * and AcademicIntelligenceService each got their own empty memo and each ran the
 * query. Its docblock said the container bound it as a singleton; it did not,
 * until this line. scoped() for the same reason as the profiler: a reconfigured
 * source must be visible on the next request, not after a worker restart.
 *
 * TenantScopedCache holds no memo, but binding it scoped keeps one instance per
 * request rather than one per injection point, which is free and keeps its
 * key-index writes going through a single object.
 */
final class IntelligenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OrganizationDataProfiler::class);
        $this->app->scoped(DatasetRegistry::class);
        $this->app->scoped(TenantScopedCache::class);
    }
}
