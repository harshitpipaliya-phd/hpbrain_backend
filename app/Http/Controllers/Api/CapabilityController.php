<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiGateway;
use App\Domain\Graph\GraphBuilder;
use App\Domain\Graph\GraphQueryPort;
use App\Http\Controllers\Concerns\BuildsGraphRagNarrative;
use App\Http\Controllers\Controller;
use App\Repositories\CapabilityRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

final class CapabilityController extends Controller
{
    use BuildsGraphRagNarrative;

    /** AiGateway service name — the hpbrain_ai_executions row for this verb is traced under this. */
    private const CAPABILITY_GRAPH_NARRATIVE_SERVICE = 'capability_graph_narrative';

    /**
     * The two subjects "what capabilities does this org/person have" is
     * actually about. Not GraphVocabulary's full fourteen labels —
     * EntityIntelligenceController::graphNarrative() already answers
     * "narrate any graph label" generically; this one stays scoped to the
     * question the Capability Management feature actually asks.
     */
    private const NARRATABLE_LABELS = ['Organization', 'Person'];

    public function __construct(private readonly CapabilityRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $q = DB::table('hpbrain_capabilities')->where('tenant_id', $this->tenantId($request));

        if ($orgId = $request->query('orgId')) {
            $q->where('org_id', $orgId);
        }

        return response()->json($q->orderBy('name')->get());
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return response()->json([]);
        }

        return response()->json(
            DB::table('hpbrain_capabilities')->where('tenant_id', $this->tenantId($request))
                ->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                                     ->orWhere('capability_code', 'like', "%{$term}%"))
                ->limit(100)->get()
        );
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->findById($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'capability_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'min:1', 'max:255'],
            'capabilityCode' => ['required', 'string', 'max:100'],
            'orgId'          => ['nullable', 'string'],
            'description'    => ['nullable', 'string'],
            'category'       => ['nullable', 'string'],
            'capabilityType' => ['nullable', 'string'],
            'difficulty'     => ['nullable', 'string'],
            'criticality'    => ['nullable', 'string'],
            'knowledge'      => ['nullable', 'array'],
            'ability'        => ['nullable', 'array'],
            'skill'          => ['nullable', 'array'],
            'behaviour'      => ['nullable', 'array'],
            'attitude'       => ['nullable', 'array'],
        ]);

        return response()->json($this->repository->insert([
            'tenant_id'       => $this->tenantId($request),
            'org_id'          => $data['orgId'] ?? null,
            'name'            => $data['name'],
            'capability_code' => $data['capabilityCode'],
            'description'     => $data['description'] ?? null,
            'category'        => $data['category'] ?? null,
            'capability_type' => $data['capabilityType'] ?? 'competency',
            'difficulty'      => $data['difficulty'] ?? 'intermediate',
            'criticality'     => $data['criticality'] ?? 'medium',
            'knowledge'       => isset($data['knowledge']) ? json_encode($data['knowledge']) : null,
            'ability'         => isset($data['ability']) ? json_encode($data['ability']) : null,
            'skill'           => isset($data['skill']) ? json_encode($data['skill']) : null,
            'behaviour'       => isset($data['behaviour']) ? json_encode($data['behaviour']) : null,
            'attitude'        => isset($data['attitude']) ? json_encode($data['attitude']) : null,
            'status'          => 'active',
            'created_by'      => $this->actorId($request),
        ]), 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'category'    => ['sometimes', 'nullable', 'string'],
        ]);

        $row = $this->repository->updateFields($this->tenantId($request), $id, $data);

        return $row ? response()->json($row) : response()->json(['error' => 'capability_not_found'], 404);
    }

    /**
     * Versions are append-only. A capability definition that can be edited in
     * place makes every historical assessment against it unreadable — you can
     * no longer tell what "level 4" meant when it was recorded.
     */
    public function createVersion(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $current = $this->repository->findById($tenant, $id);

        if (! $current) {
            return response()->json(['error' => 'capability_not_found'], 404);
        }

        // hpbrain_capability_versions stores a real column-per-field snapshot
        // (version, name, description, category, capability_type, difficulty,
        // criticality and the five KASBA columns) — not a `version_number` plus
        // a JSON `snapshot` blob. Both of those names were invented; neither
        // exists, so versioning 500'd on every call.
        $next = (int) DB::table('hpbrain_capability_versions')
            ->where('tenant_id', $tenant)->where('capability_id', $id)->max('version') + 1;

        $row = [
            'id'              => Uuid::uuid4()->toString(),
            'tenant_id'       => $tenant,
            'capability_id'   => $id,
            'version'         => $next,
            'name'            => $current['name'] ?? null,
            'description'     => $current['description'] ?? null,
            'category'        => $current['category'] ?? null,
            'capability_type' => $current['capability_type'] ?? null,
            'difficulty'      => $current['difficulty'] ?? null,
            'criticality'     => $current['criticality'] ?? null,
            'knowledge'       => $current['knowledge'] ?? null,
            'ability'         => $current['ability'] ?? null,
            'skill'           => $current['skill'] ?? null,
            'behaviour'       => $current['behaviour'] ?? null,
            'attitude'        => $current['attitude'] ?? null,
            'created_by'      => $this->actorId($request),
            'created_date'    => now()->format('Y-m-d H:i:s'),
        ];

        DB::table('hpbrain_capability_versions')->insert($row);

        // Keep the capability's own version counter in step with its history.
        $this->repository->updateFields($tenant, $id, ['version' => $next]);

        return response()->json($row, 201);
    }

    public function versions(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_capability_versions')
                ->where('tenant_id', $this->tenantId($request))->where('capability_id', $id)
                ->orderByDesc('version')->get()
        );
    }

    public function archive(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->updateFields($this->tenantId($request), $id, ['status' => 'archived']);

        return $row ? response()->json($row) : response()->json(['error' => 'capability_not_found'], 404);
    }

    /**
     * hpbrain_capability_assignments is polymorphic — (target_type, target_id,
     * assigned_by, assigned_date) — so a capability can be assigned to a
     * Person, Department, JobRole or Organization. This method previously
     * validated a single `personId` and wrote a `person_id` column that does
     * not exist, which both 500'd and threw away three quarters of what the
     * schema supports.
     */
    public function assign(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'targetType' => ['required', 'string', 'in:Person,Department,JobRole,Organization'],
            'targetId'   => ['required', 'string'],
        ]);

        $tenant = $this->tenantId($request);

        if (! $this->repository->findById($tenant, $id)) {
            return response()->json(['error' => 'capability_not_found'], 404);
        }

        $existing = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $tenant)->where('capability_id', $id)
            ->where('target_type', $data['targetType'])->where('target_id', $data['targetId'])
            ->first();

        if ($existing) {
            return response()->json($existing);
        }

        $row = [
            'id'            => Uuid::uuid4()->toString(),
            'tenant_id'     => $tenant,
            'capability_id' => $id,
            'target_type'   => $data['targetType'],
            'target_id'     => $data['targetId'],
            'status'        => 'active',
            'assigned_by'   => $this->actorId($request),
            'assigned_date' => now()->format('Y-m-d H:i:s'),
        ];

        DB::table('hpbrain_capability_assignments')->insert($row);

        return response()->json($row, 201);
    }

    public function assignments(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_capability_assignments')
                ->where('tenant_id', $this->tenantId($request))->where('capability_id', $id)
                ->get()
        );
    }

    public function audit(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_audit_logs')
                ->where('tenant_id', $this->tenantId($request))
                ->where('entity_type', 'Capability')->where('entity_id', $id)
                ->orderByDesc('created_at')->get()
        );
    }

    /**
     * GET capabilities/{tenantId}/graph-narrative/{label}/{id} — "what
     * capabilities does this org/person have, and how are they
     * distributed" (Phase 5 GraphRAG, same shared-trait pattern as the
     * seven narrative endpoints already built on BuildsGraphRagNarrative).
     *
     * RETRIEVAL IS detail()+expand(), NOT reasoningTrail() — EVEN THOUGH
     * Capability itself sits in GraphVocabulary's intelligence family.
     * {label} here is never "Capability"; it is the SUBJECT the question
     * is about, Organization or Person, and both of those are in the
     * organization/people families. expand() for either already walks the
     * real hpbrain_capability_assignments rows: GraphProjection::
     * expandPerson() calls capabilityNodesFor() and emits one
     * 'has_capability' edge per assignment row for this person, and
     * GraphProjection::attachOrganizationBranches() lists every capability
     * the tenant has registered under 'holds_capability'. Those are the
     * subject's own capability facts and neighbours — exactly what
     * entityContextDocuments() is for. reasoningTrail('Capability', id)
     * would not serve this: its walk deliberately drops any neighbour
     * outside the intelligence family, which means the very
     * Person/Department holders expandCapability() reaches are filtered
     * back out before they ever reach the caller.
     *
     * {label} is validated against self::NARRATABLE_LABELS before it is
     * validated against GraphVocabulary at all, so an unsupported label
     * (including "Capability" itself) gets the same 422 shape every other
     * graph endpoint in this codebase gives, not a 404 from a failed
     * lookup.
     */
    public function graphNarrative(
        Request $request,
        string $tenantId,
        string $label,
        string $id,
        GraphQueryPort $graph,
        AiGateway $ai,
    ): JsonResponse {
        if (! in_array($label, self::NARRATABLE_LABELS, true)) {
            return response()->json([
                'error'     => 'unsupported_label',
                'supported' => self::NARRATABLE_LABELS,
            ], 422);
        }

        $tenant = $this->tenantId($request);

        // The organization's own graph id is the tenant id itself (see
        // GraphProjection::buildOverview()) — read from the resolved
        // tenant, never from the caller's {id}, so an Organization request
        // can never be pointed at another tenant's root node.
        $entityId = $label === 'Organization' ? $tenant : $id;

        try {
            $detail = $graph->detail($tenant, $label, $entityId);
        } catch (Throwable $e) {
            return response()->json(['error' => 'graph_unavailable', 'message' => $e->getMessage()], 503);
        }

        if ($detail === null) {
            return response()->json(['error' => 'entity_not_found', 'label' => $label, 'id' => $entityId], 404);
        }

        $expansion = $graph->expand($tenant, $label, $entityId);
        $selfKey = GraphBuilder::key($label, $entityId);
        $neighbours = array_filter(
            $expansion['nodes'] ?? [],
            static fn (array $n): bool => $n['key'] !== $selfKey,
        );
        $hasContext = ($detail['facts'] ?? []) !== [] || ($detail['connections'] ?? []) !== [] || $neighbours !== [];

        if (! $hasContext) {
            return response()->json($detail + [
                'label' => $label,
                'id' => $entityId,
                'narrative' => null,
                'narrativeStatus' => 'no_evidence',
            ]);
        }

        $result = $this->narrateFromDocuments(
            $request,
            $ai,
            $tenant,
            self::CAPABILITY_GRAPH_NARRATIVE_SERVICE,
            'You are explaining what capabilities '.($label === 'Organization' ? 'an organization' : 'a person')
                .' has, and how those capabilities are distributed, from an organization\'s intelligence graph. '
                .'Use only the facts and connections given in the retrieved context — never invent a capability, '
                .'a count, or a holder not present in it.',
            sprintf(
                'Explain what capabilities %s "%s" has, and how they are distributed.',
                strtolower($label),
                $entityId,
            ),
            $this->entityContextDocuments($label, $entityId, $detail, $expansion),
        );

        return response()->json($detail + ['label' => $label, 'id' => $entityId] + $result);
    }
}
