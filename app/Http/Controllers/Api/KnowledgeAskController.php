<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Http\Controllers\Controller;
use App\Services\RetrievalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Phase 6's first real caller. RetrievalService and AiGateway::completeWithRag()
 * were both fixed earlier this session and had zero callers anywhere in the
 * app — fixing them made them correct, not used. This is "ask a question
 * against your org's knowledge": Knowledge Library documents and
 * Organizational Memory (learnings/mental models/ESO definitions) are
 * retrieved first, then AiGateway::completeWithRag() is told to answer only
 * from what was retrieved.
 *
 * Deliberately NOT through RagService — that class's own retrieve() only
 * ever calls searchEntities() (signal/evidence/decision/capability), never
 * searchDocuments()/searchMemory(), so it would silently answer from the
 * wrong sources for a "knowledge" question. RetrievalService is called
 * directly instead.
 */
final class KnowledgeAskController extends Controller
{
    private const SERVICE = 'knowledge_ask';

    public function ask(Request $request, string $tenantId, RetrievalService $retrieval, AiGateway $ai): JsonResponse
    {
        $query = trim((string) $request->input('query', ''));

        if ($query === '') {
            return response()->json(['error' => 'query_required'], 422);
        }

        $documents = array_merge(
            $retrieval->searchDocuments($tenantId, $query),
            $retrieval->searchMemory($tenantId, $query),
        );

        if (! $ai->isConfigured()) {
            return response()->json([
                'query' => $query,
                'documentsFound' => count($documents),
                'answer' => null,
                'answerStatus' => 'ai_not_configured',
            ]);
        }

        try {
            $response = $ai->completeWithRag(
                $tenantId,
                $this->actorId($request),
                self::SERVICE,
                new AiRequest(
                    systemPrompt: 'You answer questions about this organization using only the knowledge '
                        . 'documents and organizational memory given to you. Never invent a fact not present '
                        . 'in the retrieved context.',
                    userPrompt: $query,
                    responseSchema: self::SCHEMA,
                    maxTokens: 700,
                    temperature: 0.2,
                ),
                ['documents' => $documents],
            );
        } catch (Throwable $e) {
            return response()->json([
                'query' => $query,
                'documentsFound' => count($documents),
                'answer' => null,
                'answerStatus' => 'ai_call_failed',
            ]);
        }

        $json = $response->json();

        if ($json === null || ! isset($json['answer'])) {
            return response()->json([
                'query' => $query,
                'documentsFound' => count($documents),
                'answer' => null,
                'answerStatus' => 'ai_response_not_json',
            ]);
        }

        return response()->json([
            'query' => $query,
            'documentsFound' => count($documents),
            'answer' => trim((string) $json['answer']),
            'answerStatus' => count($documents) === 0 ? 'ok_no_evidence' : 'ok',
        ]);
    }

    private const SCHEMA = ['answer' => 'string, 2-5 sentences, json'];
}
