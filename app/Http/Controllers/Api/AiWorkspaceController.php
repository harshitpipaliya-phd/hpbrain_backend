<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiGateway;
use App\Domain\Graph\GraphBuilder;
use App\Domain\Graph\GraphQueryPort;
use App\Http\Controllers\Concerns\BuildsGraphRagNarrative;
use App\Http\Controllers\Concerns\ResolvesCurrentContext;
use App\Http\Controllers\Controller;
use App\Services\AiWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The AI Workspace surface.
 *
 * THE TENANT COMES FROM THE TOKEN, NOT THE URL. These routes are declared as
 * `workspace/sessions/{sessionId}/...` with no {tenantId} segment, and the
 * earlier signatures here still accepted one — so Laravel bound $tenantId to
 * the session id and left $sessionId unfilled, and every method failed on
 * dispatch. Reading the tenant from the authenticated request is also the safer
 * of the two available fixes: a tenant in the path is a value the caller picks.
 *
 * A null from the service means "no such session for this tenant". That is
 * answered 404 — never 403 — so a response cannot confirm that an id exists in
 * someone else's tenant.
 */
final class AiWorkspaceController extends Controller
{
    use ResolvesCurrentContext;
    use BuildsGraphRagNarrative;

    /** AiGateway service name — the hpbrain_ai_executions row for this verb is traced under this. */
    private const WORKSPACE_GRAPH_NARRATIVE_SERVICE = 'workspace_graph_narrative';

    /**
     * A session's context_type is validated against EntityResolver::ENTITIES
     * (see ResolvesCurrentContext::contextRules()) — the ERP's own universal
     * entity names — not against GraphVocabulary::LABEL_FAMILY's graph
     * labels. The two vocabularies overlap on three names outright and
     * disagree on a fourth (EntityIntelligenceComposer's own PROVIDERS map
     * carries the same 'Department' => 'OrganizationUnit' alias). Position,
     * OrganizationProfile and PersonProfile have no graph-label counterpart
     * at all — a session opened over one of those falls through to the
     * organization-wide briefing below, same as a session with no context.
     */
    private const CONTEXT_TYPE_TO_GRAPH_LABEL = [
        'Organization'     => 'Organization',
        'OrganizationUnit' => 'Department',
        'Person'           => 'Person',
        'Student'          => 'Student',
    ];

    public function __construct(private readonly AiWorkspaceService $workspace)
    {
    }

    public function sessions(Request $request): JsonResponse
    {
        return response()->json(
            $this->workspace->listSessions($this->tenantId($request), $this->actorId($request))
        );
    }

    /**
     * Open a conversation — optionally over the object the user was looking at.
     *
     * THE ONE PLACE CONTEXT ENTERS THE AI SURFACE, and deliberately the only
     * one. `screen`, `objectId` and `objectType` are optional: without them
     * this behaves exactly as it did, which is what keeps the Assistant working
     * from the home screen and keeps every existing client unchanged.
     *
     * CONTEXT IS RESOLVED HERE, NOT ACCEPTED. The caller says which screen and
     * which id; ContextEngine decides what that actually is, scoped to the
     * tenant the token resolved. Only the object it FOUND is bound to the
     * session. An id from another organization resolves to nothing and the
     * session is created with no subject rather than with a foreign one.
     *
     * NOTHING IS REASONED OVER HERE. The resolved context travels back in the
     * response and is recorded on the session row. Interpreting it — evidence,
     * recommendations, decisions — remains entirely with Enterprise Brain, and
     * no model is called on this path.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['title' => ['required', 'string', 'max:255']] + $this->contextRules()
        );

        $context = $this->resolveContext($request, $data);
        $object = $context->object;

        $session = $this->workspace->createSession(
            $this->tenantId($request),
            $this->actorId($request),
            $data['title'],
            // Only a resolved object becomes the session's subject. See
            // AiWorkspaceService::createSession for why the requested id must
            // not be stored in its place.
            $object->present ? (string) $object->data['type'] : null,
            $object->present ? (string) $object->data['id'] : null,
        );

        // The context is returned WITH the session so the Assistant does not
        // have to make a second call to learn what it was opened over — and so
        // what it shows is the same resolution that was persisted, rather than
        // a separate lookup that might disagree with it.
        return response()->json($session + ['context' => $context->toArray()], 201);
    }

    public function messages(Request $request, string $sessionId): JsonResponse
    {
        return response()->json(
            $this->workspace->getConversationHistory($this->tenantId($request), $sessionId)
        );
    }

    public function send(Request $request, string $sessionId): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string']]);

        $message = $this->workspace->sendMessage(
            $this->tenantId($request),
            $sessionId,
            $data['message'],
        );

        if ($message === null) {
            return response()->json(['error' => 'session_not_found'], 404);
        }

        return response()->json($message, 201);
    }

    public function regenerate(Request $request, string $sessionId, string $messageId): JsonResponse
    {
        $result = $this->workspace->regenerate($this->tenantId($request), $sessionId, $messageId);

        if ($result === null) {
            return response()->json(['error' => 'message_not_found'], 404);
        }

        return response()->json($result);
    }

    public function explain(Request $request, string $sessionId, string $messageId): JsonResponse
    {
        $result = $this->workspace->explain($this->tenantId($request), $sessionId, $messageId);

        if ($result === null) {
            return response()->json(['error' => 'message_not_found'], 404);
        }

        return response()->json($result);
    }

    public function followUp(Request $request, string $sessionId, string $messageId): JsonResponse
    {
        return response()->json(
            $this->workspace->getFollowUpQuestions($this->tenantId($request), $sessionId, $messageId)
        );
    }

    public function history(Request $request, string $sessionId): JsonResponse
    {
        return response()->json(
            $this->workspace->getConversationHistory($this->tenantId($request), $sessionId)
        );
    }

    /**
     * GET workspace/sessions/{sessionId}/graph-narrative — "workspace
     * intelligence from graph context" (Phase 5 GraphRAG, same shared-trait
     * pattern as the seven narrative endpoints already built on
     * BuildsGraphRagNarrative).
     *
     * SCOPES TO WHATEVER THE SESSION ITSELF SCOPES TO, READ FROM ITS OWN
     * ROW, NOT GUESSED. store()'s doc explains that context_type and
     * context_entity_id record a RESOLVED object — found by ContextEngine
     * against this tenant's own data — or null when the conversation was
     * opened with no subject, which is what every session created before
     * today's fix to store() actually has, and what most sessions will
     * keep having: a session is not required to be opened over anything.
     * Both are therefore real, reachable shapes, not a hypothetical to
     * design around:
     *
     *   - A session whose context_type maps to a graph label (see
     *     self::CONTEXT_TYPE_TO_GRAPH_LABEL) AND whose context_entity_id
     *     still resolves on the graph is narrated as THAT ENTITY —
     *     detail()+expand()->entityContextDocuments(), the same retrieval
     *     EntityIntelligenceController::graphNarrative() uses for every
     *     label outside the intelligence family (Organization, Department,
     *     Person and Student all are).
     *   - Every other session — no context, an unmapped context_type
     *     (Position/OrganizationProfile/PersonProfile have no graph-label
     *     counterpart), or a context_entity_id that no longer resolves —
     *     falls back to the organization-wide graph briefing
     *     OrganizationIntelligenceController::graphBriefing() uses:
     *     summary()+overview() -> organizationBriefingDocuments(). An
     *     open-ended workspace conversation with nothing more specific to
     *     ground on is still honestly grounded in what the tenant's graph
     *     currently holds, rather than refusing to answer at all.
     *
     * Either way `narrativeStatus: 'no_evidence'` is reported rather than
     * fabricated when even that fallback finds nothing.
     */
    public function graphNarrative(Request $request, string $sessionId, GraphQueryPort $graph, AiGateway $ai): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $session = $this->workspace->getSession($tenant, $sessionId);

        if ($session === null) {
            return response()->json(['error' => 'session_not_found'], 404);
        }

        $contextType = $session['context_type'] ?? null;
        $contextEntityId = $session['context_entity_id'] ?? null;
        $label = is_string($contextType) ? (self::CONTEXT_TYPE_TO_GRAPH_LABEL[$contextType] ?? null) : null;

        try {
            if ($label !== null && $contextEntityId !== null) {
                $entityResult = $this->entityScopedNarrative($request, $graph, $ai, $tenant, $label, (string) $contextEntityId);

                if ($entityResult !== null) {
                    return response()->json($entityResult + ['sessionId' => $sessionId, 'scope' => 'entity']);
                }
            }

            return response()->json(
                $this->organizationScopedNarrative($request, $graph, $ai, $tenant) + ['sessionId' => $sessionId, 'scope' => 'organization']
            );
        } catch (Throwable $e) {
            return response()->json(['error' => 'graph_unavailable', 'message' => $e->getMessage()], 503);
        }
    }

    /**
     * The entity-scoped half of graphNarrative() above. Returns null — not
     * a "no evidence" payload — when the session's bound entity no longer
     * resolves on the graph, so the caller falls through to the
     * organization-wide briefing instead of reporting "no evidence" about
     * an entity that may simply have been renamed or removed since the
     * session was opened.
     *
     * @return array<string, mixed>|null
     */
    private function entityScopedNarrative(
        Request $request,
        GraphQueryPort $graph,
        AiGateway $ai,
        string $tenant,
        string $label,
        string $entityId,
    ): ?array {
        $detail = $graph->detail($tenant, $label, $entityId);

        if ($detail === null) {
            return null;
        }

        $expansion = $graph->expand($tenant, $label, $entityId);
        $selfKey = GraphBuilder::key($label, $entityId);
        $neighbours = array_filter(
            $expansion['nodes'] ?? [],
            static fn (array $n): bool => $n['key'] !== $selfKey,
        );
        $hasContext = ($detail['facts'] ?? []) !== [] || ($detail['connections'] ?? []) !== [] || $neighbours !== [];

        if (! $hasContext) {
            return $detail + ['label' => $label, 'id' => $entityId, 'narrative' => null, 'narrativeStatus' => 'no_evidence'];
        }

        $result = $this->narrateFromDocuments(
            $request,
            $ai,
            $tenant,
            self::WORKSPACE_GRAPH_NARRATIVE_SERVICE,
            'You are grounding an AI workspace conversation in what the organization\'s intelligence graph '
                .'records about the thing this conversation was opened over. Use only the facts and connections '
                .'given in the retrieved context — never invent a name, figure, or relationship not present in it.',
            sprintf('Summarize what the graph records about %s "%s", for someone about to discuss it.', $label, $entityId),
            $this->entityContextDocuments($label, $entityId, $detail, $expansion),
        );

        return $detail + ['label' => $label, 'id' => $entityId] + $result;
    }

    /**
     * The organization-wide fallback half of graphNarrative() above —
     * identical retrieval to OrganizationIntelligenceController::
     * graphBriefing(), reused here under the workspace's own AiGateway
     * service name rather than duplicated as a second copy of that
     * controller's logic.
     *
     * @return array<string, mixed>
     */
    private function organizationScopedNarrative(Request $request, GraphQueryPort $graph, AiGateway $ai, string $tenant): array
    {
        $summary = $graph->summary($tenant);
        $overview = $graph->overview($tenant, 1, ['signals', 'cases', 'recommendations', 'decisions', 'capabilities']);

        if (count($overview['nodes'] ?? []) <= 1) {
            return ['summary' => $summary, 'narrative' => null, 'narrativeStatus' => 'no_evidence'];
        }

        $result = $this->narrateFromDocuments(
            $request,
            $ai,
            $tenant,
            self::WORKSPACE_GRAPH_NARRATIVE_SERVICE,
            'You are grounding an open-ended AI workspace conversation in this organization\'s intelligence '
                .'graph — its departments, signals, cases, recommendations and decisions — because this '
                .'conversation was not opened over any one specific thing. Use only the metrics and branches '
                .'given in the retrieved context — never invent a number, risk, or branch not present in it.',
            'Summarize what this organization\'s intelligence graph currently holds, for someone about to start '
                .'an open-ended conversation about it.',
            $this->organizationBriefingDocuments($summary, $overview),
        );

        return [
            'summary'   => $summary,
            'nodeCount' => $overview['nodeCount'] ?? count($overview['nodes'] ?? []),
            'truncated' => $overview['truncated'] ?? [],
        ] + $result;
    }
}
