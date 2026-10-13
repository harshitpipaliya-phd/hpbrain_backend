<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiGateway;
use App\Domain\Graph\GraphBuilder;
use App\Domain\Graph\GraphQueryPort;
use App\Domain\Graph\GraphVocabulary;
use App\Domain\Intelligence\EntityIntelligenceComposer;
use App\Domain\Intelligence\IntelligenceSummaryComposer;
use App\Domain\Universal\EntityResolver;
use App\Http\Controllers\Concerns\BuildsGraphRagNarrative;
use App\Http\Controllers\Concerns\ResolvesCurrentContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * GET entity-intelligence/{tenantId}/{entityType}/{entityId} — one entry
 * point for "open this entity's intelligence", whatever the entity turns out
 * to be.
 *
 * WHY THIS EXISTS. Three places in the product already carry a resolved
 * (entity type, entity id) pair with nowhere to send it: a department row in
 * the Organization overview, a node in Graph Explorer, a result in search.
 * Each of them would otherwise have to know, itself, whether "Department"
 * means DepartmentVerdict or "Person" means PersonIntelligenceService — and
 * a fourth caller would have to learn that mapping a fourth time. This
 * endpoint is that mapping, kept in one place.
 *
 * IT ANSWERS HONESTLY WHEN THERE IS NO COMPOSER. A Student, a Position, an
 * OrganizationProfile or a PersonProfile has no DepartmentVerdict-style
 * engine behind it. Rather than 404 outright, this falls back to the same
 * ContextEngine facts-and-signals read the AI Assistant already uses for
 * "what is on screen" — real recorded facts, real signals, explicitly marked
 * `intelligenceAvailable: false` and why. See EntityIntelligenceComposer.
 */
final class EntityIntelligenceController extends Controller
{
    use ResolvesCurrentContext;
    use BuildsGraphRagNarrative;

    /** AiGateway service name — the hpbrain_ai_executions row for this verb is traced under this. */
    private const NARRATIVE_SERVICE = 'entity_intelligence_narrative';

    public function __construct(
        private readonly EntityIntelligenceComposer $composer,
        private readonly IntelligenceSummaryComposer $summaries,
    ) {
    }

    public function show(Request $request, string $tenantId, string $entityType, string $entityId): JsonResponse
    {
        $canonical = $this->composer->canonicalType($entityType);

        if (! in_array($canonical, EntityResolver::ENTITIES, true)) {
            return response()->json([
                'error' => 'unknown_entity_type',
                'supported' => EntityResolver::ENTITIES,
            ], 422);
        }

        if ($this->composer->hasProvider($canonical)) {
            return $this->fromProvider($request, $canonical, $entityId);
        }

        return $this->fromContextOnly($request, $canonical, $entityId);
    }

    private function fromProvider(Request $request, string $canonical, string $entityId): JsonResponse
    {
        // Each provider's own defaults and bounds, unchanged — this endpoint
        // does not get to invent a third pagination contract. Department's
        // dedicated route defaults pageSize to 5 and caps it at 50; Person's
        // defaults to 25 and caps at 100.
        [$defaultPageSize, $maxPageSize] = $canonical === 'Person' ? [25, 100] : [5, 50];

        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'pageSize' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPageSize],
            'fresh' => 'sometimes|boolean',
        ]);

        $payload = $this->composer->forEntity(
            $this->authTenantId($request),
            $canonical,
            $entityId,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['pageSize'] ?? $defaultPageSize),
            (bool) ($validated['fresh'] ?? false),
        );

        if ($payload === null) {
            return response()->json(['error' => 'entity_not_found'], 404);
        }

        $provider = $this->composer->providerName($canonical);

        return response()->json([
            'entityType' => $canonical,
            'entityId' => $entityId,
            'provider' => $provider,
            'intelligenceAvailable' => true,
            'intelligence' => $payload,
            // A normalized, validated projection of the payload above — see
            // IntelligenceSummaryComposer. Additive: 'intelligence' is
            // unchanged and every existing consumer of this endpoint keeps
            // reading exactly what it read before.
            'summary' => $provider === 'department'
                ? $this->summaries->forDepartment($payload)
                : $this->summaries->forPerson($payload),
        ]);
    }

    /**
     * No domain composer for this entity type — the honest fallback, not a
     * dead end. Reuses ResolvesCurrentContext, the same trait the Context
     * endpoint and the AI Assistant's "what is on screen" lookup are built
     * on, so an unmapped entity type here reports the identical
     * `entity_not_mapped_for_tenant` reason it would anywhere else in the
     * product — not a second vocabulary for the same fact.
     */
    private function fromContextOnly(Request $request, string $canonical, string $entityId): JsonResponse
    {
        $resolved = $this->resolveContext($request, [
            'objectType' => $canonical,
            'objectId' => $entityId,
        ]);

        if (! $resolved->object->present) {
            return response()->json([
                'error' => 'entity_not_found',
                'reason' => $resolved->object->reason,
            ], 404);
        }

        return response()->json([
            'entityType' => $canonical,
            'entityId' => $entityId,
            'provider' => 'context-only',
            'intelligenceAvailable' => false,
            'reason' => "No department- or person-style intelligence composer exists yet for {$canonical}. Showing the recorded facts and any signals raised about it instead.",
            'facts' => $resolved->object->toArray(),
            'signals' => $resolved->signals->toArray(),
        ]);
    }

    /**
     * GET entity-intelligence/{tenantId}/{label}/{id}/narrative — one
     * entity narrated, from the GRAPH's vocabulary (Phase 5 GraphRAG,
     * roadmap doc 5.7).
     *
     * DELIBERATELY A SEPARATE {label} NAMESPACE FROM show()'s {entityType}
     * ABOVE. show()'s type is EntityResolver::ENTITIES — the ERP's own
     * types (Department, Person, Student, Position, ...) — dispatched to
     * DepartmentVerdict/PersonIntelligenceService. This one's {label} is
     * GraphVocabulary::LABEL_FAMILY's 14 graph labels, and it branches on
     * the FAMILY, not the label, because the right retrieval shape differs
     * by family: the five non-intelligence families (Organization,
     * Department, Person, Student, Standard, Subject, Dataset) read
     * through GraphQueryPort::detail() + expand() — one hop is the right
     * answer to "what is this and what does it touch". The intelligence
     * family (Signal, Evidence, Case, Hypothesis, Recommendation,
     * Decision, Capability) reads through reasoningTrail() instead, the
     * same multi-hop walk SignalController::explainReasoning() and
     * RecommendationController::reasoningTrail() use — a single hop from
     * a Recommendation would show its Decision and nothing upstream of it,
     * which is a worse answer to "what intelligence produced this" than
     * the trail that actually produced it.
     */
    public function graphNarrative(Request $request, string $tenantId, string $label, string $id, GraphQueryPort $graph, AiGateway $ai): JsonResponse
    {
        if (! GraphVocabulary::isKnownLabel($label)) {
            return response()->json(['error' => 'unknown_label', 'supported' => array_keys(GraphVocabulary::LABEL_FAMILY)], 422);
        }

        $tenant = $this->tenantId($request);
        $family = GraphVocabulary::family($label);
        $found = false;
        $hasContext = false;
        $retrieved = [];
        $documents = [];

        try {
            if ($family === GraphVocabulary::FAMILY_INTELLIGENCE) {
                $retrieved = $graph->reasoningTrail($tenant, $label, $id);
                $found = ($retrieved['origin']['key'] ?? null) !== null;
                $hasContext = $found && count($retrieved['trail'] ?? []) > 1;

                if ($hasContext) {
                    $documents = $this->reasoningTrailDocuments($retrieved);
                }
            } else {
                $detail = $graph->detail($tenant, $label, $id);
                $found = $detail !== null;

                if ($found) {
                    $retrieved = $detail;
                    $expansion = $graph->expand($tenant, $label, $id);
                    $selfKey = GraphBuilder::key($label, $id);
                    $neighbours = array_filter(
                        $expansion['nodes'] ?? [],
                        static fn (array $n): bool => $n['key'] !== $selfKey,
                    );
                    $hasContext = ($detail['facts'] ?? []) !== [] || ($detail['connections'] ?? []) !== [] || $neighbours !== [];

                    if ($hasContext) {
                        $documents = $this->entityContextDocuments($label, $id, $detail, $expansion);
                    }
                }
            }
        } catch (Throwable $e) {
            return response()->json(['error' => 'graph_unavailable', 'message' => $e->getMessage()], 503);
        }

        if (! $found) {
            return response()->json(['error' => 'entity_not_found', 'label' => $label, 'id' => $id], 404);
        }

        if (! $hasContext) {
            return response()->json($retrieved + ['label' => $label, 'id' => $id, 'narrative' => null, 'narrativeStatus' => 'no_evidence']);
        }

        $result = $this->narrateFromDocuments(
            $request,
            $ai,
            $tenant,
            self::NARRATIVE_SERVICE,
            'You are explaining one entity from an organization\'s intelligence graph to someone deciding '
                .'whether to act on it. Use only the facts, connections, and reasoning steps given in the '
                .'retrieved context — never invent a name, figure, or relationship not present in it.',
            sprintf('Explain what the graph records about %s "%s".', $label, $id),
            $documents,
        );

        return response()->json($retrieved + ['label' => $label, 'id' => $id] + $result);
    }
}
