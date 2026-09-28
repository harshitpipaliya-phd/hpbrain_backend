<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Organization\DepartmentVerdict;
use App\Domain\People\PersonIntelligenceService;

/**
 * ONE DOOR INTO WHATEVER INTELLIGENCE AN ENTITY ACTUALLY HAS.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * A DISPATCHER, NOT A THIRD ENGINE
 *
 * DepartmentVerdict and PersonIntelligenceService are the two intelligence
 * composers this application has — each independently built, each with its own
 * scoring, its own confidence model, its own blind spots. This class computes
 * NONE of that. It answers one question — "given an entity type this Brain's
 * vocabulary knows, which composer (if any) speaks for it?" — and hands the
 * caller straight to that composer's own, unmodified output. Nothing here
 * reshapes DepartmentVerdict's payload to look like PersonIntelligenceService's,
 * because the two screens they feed are allowed to look different: a department
 * has a roster, a person does not, and forcing one contract on both would mean
 * inventing fields neither composer actually has an answer for.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * NOT EVERY ENTITY TYPE HAS A PROVIDER, AND THAT IS AN HONEST ANSWER
 *
 * `App\Domain\Universal\EntityResolver::ENTITIES` names seven universal
 * entities; only two of them — OrganizationUnit and Person — have an
 * intelligence composer today. `hasProvider()` is how a caller finds out before
 * asking, so a Student or a Position gets told plainly that no domain
 * composer exists for it yet, rather than a screen assembled from figures
 * nothing actually measured.
 */
final class EntityIntelligenceComposer
{
    /**
     * What the UI calls an entity type versus what EntityResolver calls it.
     * 'Department' is the name used everywhere in navigation, Graph Explorer
     * and the Signals table; 'OrganizationUnit' is the universal name the
     * resolver and the intelligence composer actually use.
     */
    private const TYPE_ALIASES = [
        'Department' => 'OrganizationUnit',
    ];

    /** Universal entity type => which composer answers for it. */
    private const PROVIDERS = [
        'OrganizationUnit' => 'department',
        'Person' => 'person',
    ];

    public function __construct(
        private readonly DepartmentVerdict $departments,
        private readonly PersonIntelligenceService $people,
    ) {
    }

    /** The universal name EntityResolver and the composers expect. */
    public function canonicalType(string $entityType): string
    {
        return self::TYPE_ALIASES[$entityType] ?? $entityType;
    }

    public function hasProvider(string $canonicalType): bool
    {
        return isset(self::PROVIDERS[$canonicalType]);
    }

    public function providerName(string $canonicalType): ?string
    {
        return self::PROVIDERS[$canonicalType] ?? null;
    }

    /**
     * @return array<string, mixed>|null  the composer's own payload, verbatim;
     *         null when the entity itself was not found (not when the TYPE
     *         has no provider — check hasProvider() before calling this)
     */
    public function forEntity(
        string $tenantId,
        string $canonicalType,
        string $entityId,
        int $page,
        int $pageSize,
        bool $fresh,
    ): ?array {
        return match ($canonicalType) {
            'OrganizationUnit' => $this->departments->forDepartment($tenantId, $entityId, $page, $pageSize, $fresh),
            'Person' => $this->people->buildWithPage($tenantId, $entityId, $page, $pageSize),
            default => null,
        };
    }
}
