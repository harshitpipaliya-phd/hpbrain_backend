<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

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
    public function __construct(private readonly AiWorkspaceService $workspace)
    {
    }

    public function sessions(Request $request): JsonResponse
    {
        return response()->json(
            $this->workspace->listSessions($this->tenantId($request), $this->actorId($request))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);

        $session = $this->workspace->createSession(
            $this->tenantId($request),
            $this->actorId($request),
            $data['title'],
        );

        return response()->json($session, 201);
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
