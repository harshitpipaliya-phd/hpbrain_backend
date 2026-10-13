<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Http\Controllers\Controller;
use App\Services\RetrievalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

final class MentalModelController extends Controller
{
    private const TABLE = 'hpbrain_mental_models';

    /** AiGateway service name — the hpbrain_ai_executions row for this verb is traced under this. */
    private const ASK_SERVICE = 'mental_model_and_eso_ask';

    /**
     * `rules` is a JSON column, and PDO returns it as a string. The Mental
     * Model Browser reads m.rules.patterns — against a string that is
     * undefined, so every model rendered with its patterns section silently
     * missing. Decoded here so the API expresses the structure it stores.
     *
     * Text that does not parse is passed through untouched rather than
     * nulled: whatever is in there, it is the only copy.
     */
    private function hydrate(object $row): object
    {
        if (is_string($row->rules ?? null)) {
            $decoded = json_decode($row->rules, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $row->rules = $decoded;
            }
        }

        return $row;
    }

    public function index(Request $request): JsonResponse
    {
        $q = DB::table(self::TABLE)->where('tenant_id', $this->tenantId($request));

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return response()->json(
            $q->orderByDesc('created_date')->limit(500)->get()->map(fn ($r) => $this->hydrate($r))
        );
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = DB::table(self::TABLE)
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)->first();

        return $row ? response()->json($this->hydrate($row)) : response()->json(['error' => 'mental_model_not_found'], 404);
    }

    public function byDomain(Request $request, string $tenantId, string $domain): JsonResponse
    {
        $row = DB::table(self::TABLE)
            ->where('tenant_id', $this->tenantId($request))->where('domain', $domain)
            ->orderByDesc('created_date')->first();

        return $row ? response()->json($this->hydrate($row)) : response()->json(['error' => 'mental_model_not_found'], 404);
    }

    /**
     * POST mental-models/{tenantId}/ask — "ask about our mental models and
     * ESO library", as its own feature.
     *
     * DELIBERATELY NARROWER THAN KnowledgeAskController. That endpoint already
     * grounds on EVERY Organizational Memory & ESO table through
     * RetrievalService::searchMemory() — learnings, mental models AND ESO
     * definitions, plus Knowledge Library documents. This one answers a
     * different, narrower question: not "what does the org know in general"
     * but "what do our mental models say, and what executable objects do we
     * have" — so it keeps only the two result types that question is
     * actually about and drops `memory` (learnings; that is
     * LearningController::ask()'s own question, not this one's) and
     * documents (Knowledge Library; not this screen's job either).
     *
     * NOT BuildsGraphRagNarrative. That trait's narrateFromDocuments() is
     * reusable AI-step plumbing, but this is pure RAG with no graph retrieval
     * step — same shape as KnowledgeAskController, which hand-rolls the
     * identical completeWithRag() call rather than reaching for a trait named
     * for a graph step it does not have either.
     */
    public function ask(Request $request, string $tenantId, RetrievalService $retrieval, AiGateway $ai): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $query = trim((string) $request->input('query', ''));

        if ($query === '') {
            return response()->json(['error' => 'query_required'], 422);
        }

        $documents = array_values(array_filter(
            $retrieval->searchMemory($tenant, $query),
            static fn (array $doc): bool => in_array($doc['type'] ?? null, ['mental_model', 'eso_definition'], true),
        ));

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
                    systemPrompt: 'You answer questions about this organization\'s mental models (its standing '
                        .'beliefs about how things work) and its ESO library (the executable objects it can run) '
                        .'using only the retrieved context given to you. Never invent a model, a rule, or an ESO '
                        .'not present in it.',
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

    protected function insertRow(Request $request, array $fields): array
    {
        $row = array_merge($fields, [
            'id'           => Uuid::uuid4()->toString(),
            'tenant_id'    => $this->tenantId($request),
            'created_by'   => $this->actorId($request),
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        DB::table(self::TABLE)->insert($row);

        return $row;
    }
}
