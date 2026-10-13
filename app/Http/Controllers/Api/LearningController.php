<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Domain\Learning\LearningService;
use App\Domain\Learning\MemoryGrounding;
use App\Http\Controllers\Controller;
use App\Repositories\LearningRepository;
use App\Repositories\OutcomeRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class LearningController extends Controller
{
    /** AiGateway service name — the hpbrain_ai_executions row for this verb is traced under this. */
    private const ASK_SERVICE = 'learning_ask';

    public function __construct(
        private readonly LearningRepository $repository,
        private readonly OutcomeRepository $outcomes,
        private readonly LearningService $learning,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->repository->list($this->tenantId($request)));
    }

    public function reusable(Request $request): JsonResponse
    {
        $all = $this->repository->list($this->tenantId($request));

        return response()->json(array_values(array_filter($all, fn ($l) => (bool) ($l['reusable'] ?? false))));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outcomeId'   => ['required', 'string'],
            'pattern'     => ['required', 'string', 'min:1'],
            'description' => ['nullable', 'string'],
        ]);

        $tenant  = $this->tenantId($request);
        $outcome = $this->outcomes->findById($tenant, $data['outcomeId']);

        if (! $outcome) {
            return response()->json(['error' => 'outcome_not_found'], 404);
        }

        // A failed or low-confidence outcome is still recorded — the loop must
        // learn from failure — but is not marked reusable, so it is never
        // surfaced as a pattern to repeat.
        $reusable = $this->learning->isReusable(
            (string) ($outcome['result'] ?? ''),
            (float) ($outcome['confidence'] ?? 0)
        );

        return response()->json($this->repository->insert([
            'tenant_id'   => $tenant,
            'outcome_id'  => $data['outcomeId'],
            'pattern'     => $data['pattern'],
            'description' => $data['description'] ?? null,
            'confidence'  => (float) ($outcome['confidence'] ?? 0),
            'reusable'    => $reusable,
            'created_by'  => $this->actorId($request),
        ]), 201);
    }

    /**
     * POST learnings/{tenantId}/ask — "what have we learned about X", in the
     * asker's own words.
     *
     * WHY THIS IS NOT KnowledgeAskController AGAIN. That endpoint grounds on
     * Knowledge Library documents AND every Organizational Memory & ESO
     * table together, because it is answering "what does this org know,
     * period". This is answering a narrower, different question — ADR-005's
     * flywheel, specifically: can reusable learnings actually be retrieved
     * BY MEANING and fed back into reasoning, not just listed. It is the
     * read half of MemoryGrounding, the class ADR-005 calls the bridge
     * between learning and RAG, now actually wired to
     * RetrievalService::searchMemory() (via MemoryGrounding::retrieveSemantic())
     * and AiGateway::completeWithRag() — the same grounded-only discipline
     * every other narrative endpoint in this app follows, hand-rolled the
     * same way KnowledgeAskController hand-rolls it rather than reaching for
     * BuildsGraphRagNarrative, since there is no graph retrieval step here.
     *
     * retrieveSemantic() already enforces the reusable-only floor
     * (ADR-005: a failed outcome is recorded but never offered back as a
     * pattern to repeat), so nothing here re-checks it.
     */
    public function ask(Request $request, string $tenantId, MemoryGrounding $memory, AiGateway $ai): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $query = trim((string) $request->input('query', ''));

        if ($query === '') {
            return response()->json(['error' => 'query_required'], 422);
        }

        $documents = $memory->retrieveSemantic($tenant, $query);

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
                $tenant,
                $this->actorId($request),
                self::ASK_SERVICE,
                new AiRequest(
                    systemPrompt: 'You answer questions about what this organization has learned from past '
                        .'decisions, using only the reusable learnings given to you as retrieved context. Never '
                        .'invent a lesson, a pattern, or an outcome not present in it.',
                    userPrompt: $query,
                    responseSchema: ['answer' => 'string, 2-5 sentences, json'],
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
}
