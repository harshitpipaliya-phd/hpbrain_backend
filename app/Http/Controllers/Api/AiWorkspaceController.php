<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCurrentContext;
use App\Http\Controllers\Controller;
use App\Services\AiWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
