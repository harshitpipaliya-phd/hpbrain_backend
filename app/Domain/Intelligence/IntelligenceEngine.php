<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Eso\EsoCatalogue;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The one entry point. Composes the analyzers in dependency order, once.
 *
 * WHY A FACADE RATHER THAN LETTING CONTROLLERS COMPOSE. The analyzers have a real
 * dependency order — risks need patterns, decisions need risks, gaps need all of
 * them, recommendations need gaps and state — and five endpoints each wiring that
 * order by hand is five chances for two screens to disagree about the same
 * organization. Composed once here, the Knowledge screen and the Decision screen
 * cannot be looking at different numbers.
 *
 * CACHING IS KEYED ON A FINGERPRINT OF THE DATA, NOT ON A CLOCK. A TTL would leave
 * every screen reporting a pre-import picture of the organization for however long
 * the TTL was, and the entire premise of this product is that acting on the
 * organization changes what the screens say next. OrganizationDataProfiler::
 * dataVersion() hashes the row counts and high-water timestamps of everything the
 * intelligence is computed from, so an import, a decision, a new piece of evidence
 * or a recorded outcome changes the key and the next read recomputes. Every read in
 * between is free. A long TTL sits behind the fingerprint purely to bound memory,
 * never to decide freshness.
 *
 * THE CACHE KEY CONTAINS THE TENANT, AND THE FINGERPRINT IS COMPUTED PER TENANT.
 * Two organizations cannot collide on a key, and a fingerprint that changed for one
 * cannot invalidate or serve the other's entry.
 */
final class IntelligenceEngine
{
    /**
     * How long a fingerprinted entry may live.
     *
     * Long, because the fingerprint — not this number — decides whether an entry is
     * still correct. Its only job is to stop keys for data versions nobody will ask
     * for again from accumulating forever.
     */
    private const TTL_SECONDS = 21600;

    /**
     * Ceiling on how long one holder may keep the compute lock.
     *
     * Above the slowest observed cold computation, so a legitimate scan is never
     * evicted mid-flight, and finite so a killed worker cannot wedge a tenant.
     */
    private const LOCK_SECONDS = 900;

    /**
     * How long a reader with nothing cached waits for the in-flight holder
     * before computing for itself.
     */
    private const WAIT_SECONDS = 120;

    /**
     * The last completed answer per tenant, held well beyond TTL_SECONDS so
     * there is something to serve while a new version is being computed.
     */
    private const LAST_GOOD_TTL_SECONDS = 604800;

    public function __construct(
        private readonly OrganizationDataProfiler $profiler,
        private readonly KnowledgeAnalyzer $knowledge,
        private readonly PatternDetector $patterns,
        private readonly RiskAnalyzer $risks,
        private readonly DecisionAnalyzer $decisions,
        private readonly CapabilityAnalyzer $capability,
        private readonly GapDetector $gaps,
        private readonly OrganizationalStateAnalyzer $state,
        private readonly RecommendationEngine $recommendations,
    ) {
    }

    /**
     * Everything, for one organization.
     *
     * @return array<string, mixed>
     */
    public function forOrganization(string $tenantId, bool $fresh = false): array
    {
        $version = $this->profiler->dataVersion($tenantId);
        $key     = $this->versionedKey($tenantId, $version);
        $store   = Cache::store('file');

        if ($fresh) {
            $store->forget($key);
        }

        // Fast path. Unchanged, and still the path almost every request takes.
        $hit = $store->get($key);

        if (is_array($hit)) {
            return $hit;
        }

        /*
          SINGLE FLIGHT. `remember()` gave no exclusion, so every request that
          arrived on a cold key started its own full computation: on the live
          database that was observed as a dozen concurrent copies of the same
          388k-row scan, each past 300 seconds, all competing for the same I/O
          and so all finishing later than one alone would have. Ordinary
          navigation was enough to trigger it, because nothing served a reader
          while the first computation was still running.

          One holder computes. Everyone else takes the branch below.
        */
        $lock = $store->lock($this->lockKey($tenantId), self::LOCK_SECONDS);

        if ($lock->get()) {
            try {
                return $this->computeAndStore($tenantId, $version, $key, $store);
            } finally {
                $lock->release();
            }
        }

        /*
          STALE BEATS SLOW, AND BOTH BEAT A SPINNER. A computation is already in
          flight; the previous good answer for this tenant is a few minutes out
          of date at worst and is returned immediately, flagged, rather than
          making the reader wait for a scan someone else is already paying for.
        */
        $lastGood = $store->get($this->lastGoodKey($tenantId));

        if (is_array($lastGood)) {
            return $this->markStale($lastGood, $version);
        }

        /*
          Nothing cached at all — the tenant's first ever read. Wait for the
          holder rather than starting a second scan, then take whatever it
          published. Only if that wait expires with still nothing do we compute
          inline, which is the one case where a reader pays full price.
        */
        try {
            $lock->block(self::WAIT_SECONDS);
        } catch (LockTimeoutException) {
            $hit = $store->get($key);

            return is_array($hit) ? $hit : $this->computeAndStore($tenantId, $version, $key, $store);
        }

        try {
            $hit = $store->get($key);

            return is_array($hit) ? $hit : $this->computeAndStore($tenantId, $version, $key, $store);
        } finally {
            $lock->release();
        }
    }

    /**
     * Compute, publish under the versioned key, and keep a copy as this tenant's
     * last known good answer.
     *
     * @return array<string, mixed>
     */
    private function computeAndStore(string $tenantId, string $version, string $key, \Illuminate\Contracts\Cache\Repository $store): array
    {
        // Another holder may have published while we were queuing for the lock.
        $hit = $store->get($key);

        if (is_array($hit)) {
            return $hit;
        }

        $computed = $this->compute($tenantId, $version);

        $store->put($key, $computed, self::TTL_SECONDS);
        $store->put($this->lastGoodKey($tenantId), $computed, self::LAST_GOOD_TTL_SECONDS);

        return $computed;
    }

    /**
     * Say plainly that this is the previous answer, and which version the caller
     * actually asked for. A stale figure presented as current is the one
     * failure this cache must not have.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function markStale(array $payload, string $requestedVersion): array
    {
        $payload['stale'] = [
            'isStale'           => true,
            'servedVersion'     => $payload['dataVersion'] ?? null,
            'requestedVersion'  => $requestedVersion,
            'reason'            => 'The current version is being computed by another request; this is the previous completed answer for this organization.',
        ];

        return $payload;
    }

    private function versionedKey(string $tenantId, string $version): string
    {
        return 'brain:intel:v3:'.$tenantId.':'.$version;
    }

    /** Per tenant, so one organization's computation never blocks another's. */
    private function lockKey(string $tenantId): string
    {
        return 'brain:intel:lock:'.$tenantId;
    }

    /** Per tenant, so a stale read can never serve another organization. */
    private function lastGoodKey(string $tenantId): string
    {
        return 'brain:intel:v3:last:'.$tenantId;
    }

    /**
     * The fingerprint of everything the intelligence is derived from.
     *
     * Exposed so a caller can decide whether to refetch without paying for the
     * computation, and so a response can carry the version it was computed at.
     */
    public function version(string $tenantId): string
    {
        return $this->profiler->dataVersion($tenantId);
    }

    /**
     * The composition, in dependency order.
     *
     * @return array<string, mixed>
     */
    private function compute(string $tenantId, string $version): array
    {
        $startedAt = microtime(true);

        $profile    = $this->profiler->profile($tenantId);
        $patterns   = $this->patterns->detect($tenantId, $profile);
        $knowledge  = $this->knowledge->analyse($tenantId, $profile);
        $risks      = $this->risks->analyse($tenantId, $profile, $patterns);
        $decisions  = $this->decisions->analyse($tenantId, $profile, $risks);
        $capability = $this->capability->analyse($tenantId, $profile);
        $gaps       = $this->gaps->detect($tenantId, $profile, $knowledge, $capability, $decisions, $risks);
        $state      = $this->state->analyse($tenantId, $profile, $knowledge, $capability, $decisions, $risks, $gaps);
        // The tenant's own executable objects, so a recommendation can name a
        // real ESO where one declares its finding. hpbrain_eso_definitions is
        // already in OrganizationDataProfiler::LOOP_TABLES, so authoring an ESO
        // changes the data version and this composition is recomputed — a new
        // definition binds on the next read without a manual cache flush.
        $recommendations = $this->recommendations->recommend($gaps, $risks, $state, $knowledge, EsoCatalogue::forTenant($tenantId));

        return [
            'tenantId'    => $tenantId,
            'dataVersion' => $version,
            'computedAt'  => gmdate('c'),
            'computeMs'   => (int) round((microtime(true) - $startedAt) * 1000),
            'profile'     => $profile,
            'patterns'    => $patterns,
            'knowledge'   => $knowledge,
            'risks'       => $risks,
            'decisions'   => $decisions,
            'capability'  => $capability,
            'gaps'        => $gaps,
            'state'       => $state,
            'recommendations' => $recommendations,
            // Stated on every response, not documented elsewhere and hoped for. The
            // guarantee a reader needs is that nothing on the screen was written by
            // a language model, and the place to make it is next to the numbers.
            'derivation'  => [
                'method'   => 'Every figure is computed deterministically by SQL aggregation over this organization\'s own rows, on read.',
                'llm'      => 'No language model contributed to any figure, ranking, category or conclusion in this response.',
                'scope'    => 'Every query is filtered to tenant_id = '.$tenantId.'. No aggregate crosses organizations.',
                'liveness' => 'Cached against a fingerprint of the source data rather than a clock, so a change to the organization\'s records invalidates it immediately.',
            ],
        ];
    }
}
