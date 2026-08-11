<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

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
        $key     = 'brain:intel:v3:'.$tenantId.':'.$version;

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::TTL_SECONDS, fn (): array => $this->compute($tenantId, $version));
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
        $recommendations = $this->recommendations->recommend($gaps, $risks, $state, $knowledge);

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
