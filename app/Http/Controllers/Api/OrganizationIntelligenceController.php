<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Intelligence\IntelligenceEngine;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization intelligence, one endpoint per question a screen asks.
 *
 * MODULAR RATHER THAN ONE ENORMOUS PAYLOAD. The full computation is composed once
 * per request by IntelligenceEngine — the analyzers have a real dependency order and
 * splitting the computation would let two screens disagree about the same
 * organization — but each endpoint returns only the slice its screen renders. The
 * Knowledge screen has no use for the risk matrix, and shipping it costs bytes on
 * every poll for nothing.
 *
 * EVERY RESPONSE CARRIES ITS DATA VERSION. The fingerprint the cache is keyed on is
 * published, so a client can tell whether two panels were computed from the same
 * state of the organization, and a reload can be shown to have changed something.
 *
 * TENANT SCOPE IS NOT THIS CONTROLLER'S DECISION. `tenantId()` reads the value
 * EnsureTenantScope resolved from the authenticated token; a route parameter can
 * narrow to that same tenant but can never switch to another one, including for an
 * admin. Nothing below accepts a tenant from the query string, and the engine
 * filters every query on the resolved value.
 */
final class OrganizationIntelligenceController extends Controller
{
    public function __construct(private readonly IntelligenceEngine $engine)
    {
    }

    /**
     * Everything, for a client that wants one round trip.
     *
     * Genuinely large. Offered because the report and export paths need the whole
     * picture consistently, not because any screen should call it.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->intelligence($request));
    }

    /**
     * GET /intelligence/{tenantId}/state — "Where are we?"
     */
    public function state(Request $request): JsonResponse
    {
        $all = $this->intelligence($request);

        return $this->respond($all, [
            'state'    => $all['state'],
            'totals'   => $all['profile']['totals'],
            'movement' => [
                'moving' => $all['patterns']['moving'],
                'method' => $all['patterns']['method'],
            ],
            'consequence' => [
                'criticalGaps' => $all['gaps']['critical'],
                'openRisks'    => $all['risks']['open'],
                'unownedRisks' => $all['risks']['unowned'],
                'firstAction'  => $all['recommendations']['firstAction'],
            ],
        ]);
    }

    /**
     * GET /intelligence/{tenantId}/knowledge — "What does this organization know?"
     */
    public function knowledge(Request $request): JsonResponse
    {
        $all = $this->intelligence($request);

        return $this->respond($all, [
            'state'       => $all['knowledge']['state'],
            'domains'     => $all['knowledge']['domains'],
            'evidence'    => $all['knowledge']['evidence'],
            'blindSpots'  => $all['knowledge']['blindSpots'],
            'learnNext'   => $all['knowledge']['learnNext'],
            'definitions' => $all['knowledge']['definitions'],
            // Movement belongs on this screen too: a domain's confidence is a
            // standing figure, and whether the work behind it is growing or
            // shrinking is what tells a reader whether to care.
            'trends'         => $all['patterns']['trends'],
            'concentrations' => $all['patterns']['concentrations'],
            'method'         => $all['patterns']['method'],
            'derivation'     => $all['derivation'],
        ]);
    }

    /**
     * GET /intelligence/{tenantId}/decisions — "Are our decisions any good?"
     */
    public function decisions(Request $request): JsonResponse
    {
        $all = $this->intelligence($request);

        return $this->respond($all, [
            'state'                => $all['decisions']['state'],
            'latency'              => $all['decisions']['latency'],
            'accuracy'             => $all['decisions']['accuracy'],
            'quality'              => $all['decisions']['quality'],
            'byExecutor'           => $all['decisions']['byExecutor'],
            'categoryByExecutor'   => $all['decisions']['categoryByExecutor'],
            'byCategory'           => $all['decisions']['byCategory'],
            'acceptanceVsEvidence' => $all['decisions']['acceptanceVsEvidence'],
            'acceptanceVsAccuracy' => $all['decisions']['acceptanceVsAccuracy'],
            'openBeyond'           => $all['decisions']['openBeyond'],
            'rootCause'            => $all['decisions']['rootCause'],
            'confidenceBands'      => $all['decisions']['confidenceBands'],
            'risks'                => [
                'open'        => $all['risks']['open'],
                'mitigated'   => $all['risks']['mitigated'],
                'registered'  => $all['risks']['registered'],
                'derived'     => $all['risks']['derived'],
                'unowned'     => $all['risks']['unowned'],
                'maxSeverity' => $all['risks']['maxSeverity'],
                'matrix'      => $all['risks']['matrix'],
                'register'    => $all['risks']['risks'],
                'method'      => $all['risks']['method'],
            ],
            'derivation' => $all['derivation'],
        ]);
    }

    /**
     * GET /intelligence/{tenantId}/risks
     */
    public function risks(Request $request): JsonResponse
    {
        $all = $this->intelligence($request);

        return $this->respond($all, $all['risks']);
    }

    /**
     * GET /intelligence/{tenantId}/capability
     */
    public function capability(Request $request): JsonResponse
    {
        $all = $this->intelligence($request);

        return $this->respond($all, $all['capability']);
    }

    /**
     * GET /intelligence/{tenantId}/gaps — "What is missing?"
     */
    public function gaps(Request $request): JsonResponse
    {
        $all = $this->intelligence($request);

        return $this->respond($all, $all['gaps'] + ['derivation' => $all['derivation']]);
    }

    /**
     * GET /intelligence/{tenantId}/recommendations — "What should we do next?"
     */
    public function recommendations(Request $request): JsonResponse
    {
        $all = $this->intelligence($request);

        return $this->respond($all, $all['recommendations'] + ['derivation' => $all['derivation']]);
    }

    /**
     * GET /intelligence/{tenantId}/profile — what the intelligence is computed from.
     *
     * The audit surface. Every figure on every other endpoint is an aggregate over
     * what this returns, so a reader who distrusts a number can come here and see
     * the population it was taken over.
     */
    public function profile(Request $request): JsonResponse
    {
        $all = $this->intelligence($request);

        return $this->respond($all, $all['profile'] + ['derivation' => $all['derivation']]);
    }

    /* ─────────────────────────── plumbing ─────────────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function intelligence(Request $request): array
    {
        // `?fresh=1` bypasses the cached entry for the current data version. For an
        // operator verifying that a change landed, not for normal reads: the
        // fingerprint already invalidates on any change to the underlying rows, so
        // routine use of this would recompute for no reason.
        $fresh = $request->boolean('fresh');

        return $this->engine->forOrganization($this->tenantId($request), $fresh);
    }

    /**
     * Wrap a slice with the metadata every response carries.
     *
     * @param array<string, mixed> $all
     * @param array<string, mixed> $payload
     */
    private function respond(array $all, array $payload): JsonResponse
    {
        return response()->json($payload + [
            'tenantId'    => $all['tenantId'],
            'dataVersion' => $all['dataVersion'],
            'computedAt'  => $all['computedAt'],
            'computeMs'   => $all['computeMs'],
        ]);
    }
}
