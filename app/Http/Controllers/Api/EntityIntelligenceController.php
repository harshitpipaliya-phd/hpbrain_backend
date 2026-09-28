<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Intelligence\EntityIntelligenceComposer;
use App\Domain\Intelligence\IntelligenceSummaryComposer;
use App\Domain\Universal\EntityResolver;
use App\Http\Controllers\Concerns\ResolvesCurrentContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
