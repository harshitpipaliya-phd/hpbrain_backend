<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiGateway;
use App\Domain\Graph\GraphQueryPort;
use App\Domain\Knowledge\OrganizationalMemoryService;
use App\Http\Controllers\Concerns\BuildsGraphRagNarrative;
use App\Http\Controllers\Controller;
use App\Services\RetrievalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Organizational Memory — the LEARN half of the loop.
 *
 * The Memory screen previously assembled itself in the browser from three
 * calls, one of which was a raw `fetch('/api/v1/events?type=LearningGrounded')`
 * carrying no tenant at all: every tenant's grounding events were fetched and
 * then filtered client-side by id. This endpoint replaces that. Scope is
 * decided here, server-side, from the request's resolved tenant.
 */
final class OrganizationalMemoryController extends Controller
{
    use BuildsGraphRagNarrative;

    /** AiGateway service name — the hpbrain_ai_executions row for this verb is traced under this. */
    private const LINEAGE_SERVICE = 'decision_lineage_narrative';

    public function index(Request $request, OrganizationalMemoryService $service): JsonResponse
    {
        return response()->json($service->list($this->tenantId($request), [
            'q' => $request->query('q'),
            'domain' => $request->query('domain'),
            'pattern' => $request->query('pattern'),
            'reusable' => $request->query('reusable'),
            'provenance' => $request->query('provenance'),
            'page' => $request->query('page'),
            'pageSize' => $request->query('pageSize'),
        ]));
    }

    public function summary(Request $request, OrganizationalMemoryService $service): JsonResponse
    {
        return response()->json($service->summary($this->tenantId($request)));
    }

    public function show(Request $request, string $tenantId, string $id, OrganizationalMemoryService $service): JsonResponse
    {
        $payload = $service->detail($this->tenantId($request), $id);

        return $payload
            ? response()->json($payload)
            : response()->json(['error' => 'memory_not_found'], 404);
    }

    /**
     * GET organizational-memory/{tenantId}/decision/{decisionId}/lineage —
     * ADR-005's "Decisions→outcomes→learnings→mental models" chain, narrated.
     *
     * THE ONE ROW IN THIS EFFORT THAT GENUINELY NEEDS BOTH HALVES. Decision is
     * an intelligence-family label, so its own upstream reasoning (what
     * signal, case, hypothesis and recommendation led to it) is read the same
     * way SignalController::explainReasoning() and
     * EntityIntelligenceController::graphNarrative() already read theirs:
     * GraphQueryPort::reasoningTrail() -> reasoningTrailDocuments(). That is
     * the KG half, and it alone would miss ADR-005's whole point — a decision
     * is also supposed to leave the organization smarter, through the
     * outcome and learning it produced and the mental model that learning
     * reinforces, and none of that is in the intelligence-chain graph at
     * all; `hpbrain_outcomes`/`hpbrain_learnings`/`hpbrain_mental_models` are
     * text, not nodes GraphVocabulary knows how to draw.
     *
     * THE RAG HALF IS GROUNDED ON THE GRAPH'S OWN WORDS, NOT A GUESSED QUERY —
     * BUT ONE SHORT TERM AT A TIME, NOT ONE LONG PARAGRAPH.
     * RetrievalService::searchMemory() needs a free-text question, and rather
     * than inventing one this reuses the trail's own short, keyword-dense
     * node titles (the signal's classification, the case's title, the
     * recommendation's title, the decision's own rationale) as the search
     * terms. Each is searched SEPARATELY and the results merged — not
     * concatenated into one long paragraph — because searchMemory()'s own
     * keyword fallback (`field LIKE '%query%'`) can only ever match when the
     * query is short enough to plausibly be contained in a learning's
     * pattern/description/domain; a single formatted multi-sentence blob of
     * the whole trail would never match anything through that fallback and
     * would silently make this endpoint's RAG half dead weight whenever the
     * semantic embedding path is unavailable (as it is in this test suite,
     * and in this installation's current environment per the embedding
     * work done earlier this session). Splitting into short terms keeps the
     * fallback honestly functional, and costs the semantic path nothing it
     * was not already going to do per query. `memory` (learnings) and
     * `mental_model` are kept; `eso_definition` is dropped — an executable
     * object is not a learning or a belief, and MentalModelController::ask()
     * is where that type belongs.
     */
    public function decisionLineage(
        Request $request,
        string $tenantId,
        string $decisionId,
        GraphQueryPort $graph,
        RetrievalService $retrieval,
        AiGateway $ai,
    ): JsonResponse {
        $tenant = $this->tenantId($request);

        try {
            $trail = $graph->reasoningTrail($tenant, 'Decision', $decisionId);
        } catch (Throwable $e) {
            return response()->json(['error' => 'graph_unavailable', 'message' => $e->getMessage()], 503);
        }

        if (($trail['origin']['key'] ?? null) === null) {
            return response()->json(['error' => 'decision_not_found'], 404);
        }

        $graphDocuments = count($trail['trail'] ?? []) > 1 ? $this->reasoningTrailDocuments($trail) : [];

        $memoryDocuments = $this->memoryDocumentsForTrail($tenant, $trail, $retrieval);
        $documents = array_merge($graphDocuments, $memoryDocuments);

        if ($documents === []) {
            return response()->json($trail + [
                'memoryDocumentsFound' => 0,
                'narrative' => null,
                'narrativeStatus' => 'no_evidence',
            ]);
        }

        $result = $this->narrateFromDocuments(
            $request,
            $ai,
            $tenant,
            self::LINEAGE_SERVICE,
            'You are explaining one decision\'s full lineage in an organization\'s intelligence graph and '
                .'memory — what led to it, and what the organization learned or already believed as a result. '
                .'Use only the steps, facts and memories given in the retrieved context — never invent a cause, '
                .'an outcome, or a learning not present in it.',
            sprintf('Explain the lineage of Decision "%s": what led to it, and what it taught this organization.', $decisionId),
            $documents,
        );

        return response()->json($trail + ['memoryDocumentsFound' => count($memoryDocuments)] + $result);
    }

    /**
     * Short, keyword-dense search terms off the trail's own nodes — see
     * decisionLineage()'s docblock for why these stay short rather than
     * being joined into one paragraph. Each term is searched separately and
     * the highest score per (id, type) across all of them wins, which is
     * the right merge rule for "found via any of this decision's own
     * vocabulary" rather than "found via all of it at once".
     *
     * CAPPED AT 3 TERMS, NOT EVERY NODE IN THE TRAIL. searchMemory() embeds
     * its query via a live network call (Embeddings::CURRENT_MODEL, through
     * GeminiProvider), and that call's own timeout is 30s
     * (config('brain.ai.gemini.timeout')) — a 50-step trail's worth of
     * distinct node titles, searched one at a time, would turn one page
     * load into minutes of latency whenever that network call is slow
     * rather than fast-failing (confirmed against this installation's own
     * current environment while building this feature). The classification
     * property is prioritised first — the single most semantically specific
     * term available — then node titles in trail order, which for the
     * realistic case (a handful of steps) is everything the trail has
     * anyway.
     *
     * @param  array<string, mixed>  $trail
     * @return array<int, array{id:string,type:string,content:string,score:float,retrieval:string}>
     */
    private function memoryDocumentsForTrail(string $tenant, array $trail, RetrievalService $retrieval): array
    {
        $classifications = [];
        $titles = [];

        foreach ($trail['nodes'] ?? [] as $node) {
            $classification = $node['properties']['classification'] ?? null;

            if (is_string($classification) && trim($classification) !== '') {
                $classifications[] = trim($classification);
            }

            $title = $node['title'] ?? null;

            if (is_string($title) && trim($title) !== '') {
                $titles[] = trim($title);
            }
        }

        $terms = array_slice(array_values(array_unique(array_merge($classifications, $titles))), 0, 3);

        $best = [];

        foreach ($terms as $term) {
            foreach ($retrieval->searchMemory($tenant, $term) as $doc) {
                if (! in_array($doc['type'] ?? null, ['memory', 'mental_model'], true)) {
                    continue;
                }

                $key = $doc['type'].':'.$doc['id'];

                if (! isset($best[$key]) || $doc['score'] > $best[$key]['score']) {
                    $best[$key] = $doc;
                }
            }
        }

        $results = array_values($best);
        usort($results, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $results;
    }
}
