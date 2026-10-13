<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\AssembledContext;
use App\Domain\Ai\Embeddings;
use App\Domain\Ai\Providers\GeminiProvider;
use App\Domain\Ai\RetrievalResult;
use App\Domain\Graph\GraphQueryPort;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 6.1 RAG v1. searchDocuments() now does real semantic retrieval —
 * the query is embedded (Gemini, via GeminiProvider::fromConfig()) and
 * scored by cosine similarity against the per-chunk vectors
 * KnowledgeIngestionService stored on each row — for every row that has
 * been embedded under the CURRENT model/version (Embeddings::CURRENT_*).
 * A row that has not been embedded yet (pre-migration content, or a
 * failed/pending ingest) is never silently dropped: it is still searched
 * by the original LIKE heuristic, down-weighted, and labelled
 * `retrieval => 'keyword_only'` in its result so a caller can see which
 * kind of match it is looking at. If the embedding call for the QUERY
 * itself fails (no key, network, rate limit), every row falls back to
 * that same LIKE heuristic — the pre-existing behavior is the safety net,
 * not deleted code.
 *
 * STILL A v1/MVP SHAPE, NOT A SCALING DESIGN. This is PHP-side cosine
 * similarity over a JSON column (see the 2026_10_10_120000 migration's own
 * docblock) — fine for the corpus size this installation actually has,
 * not how this would be built for a large one. No vector store was added;
 * that was an explicit architecture decision, not an oversight.
 *
 * searchMemory() (Organizational Memory & ESO: hpbrain_learnings,
 * hpbrain_mental_models, hpbrain_eso_definitions) got the identical
 * treatment in a follow-up task — query embedded once, cosine-ranked per
 * table against MemoryIngestionService's embedded rows, LIKE-based
 * `keyword_only` fallback for anything unembedded or stale. searchEntities()
 * and searchGraph() below remain unchanged — out of scope for both tasks.
 *
 * STILL UNCALLED BY PRODUCTION CODE. RagService (the only caller of this
 * class) has zero callers of its own anywhere in the app — confirmed by a
 * whole-repo grep, same as before this change. EvaluationService is a NEW
 * caller, added specifically to build a real evaluation harness against
 * this method; that is not the same as this class being wired into a
 * user-facing RAG endpoint.
 */
final class RetrievalService
{
    /** Keyword-only matches never outrank a genuine semantic match at the same nominal score. */
    private const KEYWORD_ONLY_PENALTY = 0.5;

    public function __construct(private readonly GraphQueryPort $graph)
    {
    }

    public function searchEntities(string $tenantId, string $query, array $entityTypes): array
    {
        $results = [];

        foreach ($entityTypes as $entityType) {
            $table = match ($entityType) {
                'signal' => 'hpbrain_signals',
                'evidence' => 'hpbrain_evidence',
                'decision' => 'hpbrain_decisions',
                'capability' => 'hpbrain_capabilities',
                default => null,
            };

            if ($table === null) {
                continue;
            }

            $rows = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where(function ($q) use ($query) {
                    $q->where('title', 'like', "%{$query}%")
                      ->orWhere('name', 'like', "%{$query}%")
                      ->orWhere('description', 'like', "%{$query}%");
                })
                ->limit(10)
                ->get();

            foreach ($rows as $row) {
                $content = (string) ($row->title ?? $row->name ?? $row->description ?? '');
                $results[] = [
                    'id' => (string) $row->id,
                    'type' => $entityType,
                    'content' => $content,
                    'score' => $this->matchScore($query, $content, isset($row->title) || isset($row->name)),
                ];
            }
        }

        return $results;
    }

    /**
     * Real semantic search over hpbrain_knowledge_assets, with a keyword
     * fallback for rows that have not been embedded (or were embedded
     * under a stale model/version).
     *
     * @return array<int, array{id:string, type:string, content:string, score:float, retrieval:string}>
     */
    public function searchDocuments(string $tenantId, string $query, int $topK = 10): array
    {
        $queryVector = $this->embedQuery($query);

        $semantic = $queryVector !== null
            ? $this->searchDocumentsSemantic($tenantId, $queryVector)
            : [];

        $excludeIds = array_column($semantic, 'id');

        $keyword = $this->searchDocumentsKeyword($tenantId, $query, $excludeIds);

        $results = array_merge($semantic, $keyword);

        usort($results, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $topK);
    }

    /**
     * The query's own embedding, or null if Gemini could not be reached —
     * null means "fall back to keyword search for everything", never a
     * zero vector pretending to be a real one.
     *
     * @return array<int, float>|null
     */
    private function embedQuery(string $query): ?array
    {
        try {
            return GeminiProvider::fromConfig()->embed($query);
        } catch (\Throwable $e) {
            Log::warning('retrieval_query_embed_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Cosine similarity against every CURRENT-model/version embedded row's
     * chunks, scored by the best-matching chunk (max over chunks, the
     * standard way to score a chunked document against a single query
     * vector).
     *
     * @param  array<int, float>  $queryVector
     * @return array<int, array{id:string, type:string, content:string, score:float, retrieval:string}>
     */
    private function searchDocumentsSemantic(string $tenantId, array $queryVector): array
    {
        $rows = DB::table('hpbrain_knowledge_assets')
            ->where('tenant_id', $tenantId)
            ->where('embedding_model', Embeddings::CURRENT_MODEL)
            ->where('embedding_version', Embeddings::CURRENT_VERSION)
            ->whereNotNull('embedding')
            ->get(['id', 'title', 'embedding']);

        $results = [];

        foreach ($rows as $row) {
            $chunks = Embeddings::decodeChunks($row->embedding);

            if ($chunks === []) {
                continue;
            }

            $bestScore = 0.0;
            $bestText = '';

            foreach ($chunks as $chunk) {
                $score = Embeddings::cosineSimilarity($queryVector, $chunk['vector']);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestText = $chunk['text'];
                }
            }

            $results[] = [
                'id' => (string) $row->id,
                'type' => 'document',
                'content' => $bestText !== '' ? $bestText : (string) $row->title,
                'score' => $bestScore,
                'retrieval' => 'semantic',
            ];
        }

        return $results;
    }

    /**
     * The original LIKE search, excluding any row already answered
     * semantically, and down-weighted so a keyword hit cannot outrank a
     * genuine semantic one at equal nominal score.
     *
     * @param  array<int, string>  $excludeIds
     * @return array<int, array{id:string, type:string, content:string, score:float, retrieval:string}>
     */
    private function searchDocumentsKeyword(string $tenantId, string $query, array $excludeIds): array
    {
        $rows = DB::table('hpbrain_knowledge_assets')
            ->where('tenant_id', $tenantId)
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('content', 'like', "%{$query}%")
                  ->orWhere('tags', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'title', 'content']);

        return $rows->map(fn ($row) => [
            'id' => (string) $row->id,
            'type' => 'document',
            'content' => (string) ($row->title ?: mb_substr((string) $row->content, 0, 200)),
            'score' => $this->matchScore($query, (string) $row->title, true) * self::KEYWORD_ONLY_PENALTY,
            'retrieval' => 'keyword_only',
        ])->all();
    }

    /**
     * Graph search, via the same GraphQueryPort::search() Graph Explorer
     * already uses — not a new search, a new caller of an existing one.
     */
    public function searchGraph(string $tenantId, string $query): array
    {
        $found = $this->graph->search($tenantId, $query);

        return array_map(fn (array $node) => [
            'id' => (string) ($node['id'] ?? ''),
            'type' => (string) ($node['label'] ?? 'entity'),
            'content' => trim(((string) ($node['title'] ?? '')) . ' ' . ((string) ($node['subtitle'] ?? ''))),
            // search() already filtered to matches; it does not rank them,
            // so every result is scored the same rather than inventing an
            // order the underlying query does not have.
            'score' => 0.7,
        ], $found['results'] ?? []);
    }

    /**
     * Real semantic search across Organizational Memory & ESO (Phase 6.5):
     * learnings (pattern, description, domain), mental models (name,
     * description, domain) and ESO definitions (name, objective,
     * trigger_description) — the three tables the roadmap names for this
     * task. Decision narratives are already covered by
     * searchEntities('decision'), so they are not repeated here.
     *
     * Exactly the same retrieval shape as searchDocuments(): the query is
     * embedded once and scored by cosine similarity against each table's
     * CURRENT-model/version embedded rows (MemoryIngestionService is the
     * writer); a row with no embedding yet, or one stamped with a stale
     * model/version, is never dropped — it is still searched by the
     * original LIKE heuristic, down-weighted, and labelled
     * `retrieval => 'keyword_only'`. If the query's own embedding fails,
     * every row across all three tables falls back to that same LIKE
     * heuristic.
     *
     * @return array<int, array{id:string, type:string, content:string, score:float, retrieval:string}>
     */
    public function searchMemory(string $tenantId, string $query): array
    {
        $queryVector = $this->embedQuery($query);

        $semanticLearnings = $queryVector !== null
            ? $this->searchMemoryTableSemantic($tenantId, $queryVector, 'hpbrain_learnings', 'memory', 'pattern')
            : [];
        $semanticMentalModels = $queryVector !== null
            ? $this->searchMemoryTableSemantic($tenantId, $queryVector, 'hpbrain_mental_models', 'mental_model', 'name')
            : [];
        $semanticEsoDefinitions = $queryVector !== null
            ? $this->searchMemoryTableSemantic($tenantId, $queryVector, 'hpbrain_eso_definitions', 'eso_definition', 'name')
            : [];

        $learnings = $this->searchLearningsKeyword($tenantId, $query, array_column($semanticLearnings, 'id'));
        $mentalModels = $this->searchMentalModelsKeyword($tenantId, $query, array_column($semanticMentalModels, 'id'));
        $esoDefinitions = $this->searchEsoDefinitionsKeyword($tenantId, $query, array_column($semanticEsoDefinitions, 'id'));

        $results = array_merge(
            $semanticLearnings, $semanticMentalModels, $semanticEsoDefinitions,
            $learnings, $mentalModels, $esoDefinitions,
        );

        usort($results, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * Cosine similarity against one memory table's CURRENT-model/version
     * embedded rows, scored by the best-matching chunk — the same scoring
     * rule as searchDocumentsSemantic(), generalised over the table name,
     * result `type` label, and the column to fall back to as `content`
     * when a row has chunks but somehow none score above zero.
     *
     * @param  array<int, float>  $queryVector
     * @return array<int, array{id:string, type:string, content:string, score:float, retrieval:string}>
     */
    private function searchMemoryTableSemantic(string $tenantId, array $queryVector, string $table, string $type, string $titleColumn): array
    {
        $rows = DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('embedding_model', Embeddings::CURRENT_MODEL)
            ->where('embedding_version', Embeddings::CURRENT_VERSION)
            ->whereNotNull('embedding')
            ->get(['id', $titleColumn, 'embedding']);

        $results = [];

        foreach ($rows as $row) {
            $chunks = Embeddings::decodeChunks($row->embedding);

            if ($chunks === []) {
                continue;
            }

            $bestScore = 0.0;
            $bestText = '';

            foreach ($chunks as $chunk) {
                $score = Embeddings::cosineSimilarity($queryVector, $chunk['vector']);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestText = $chunk['text'];
                }
            }

            $results[] = [
                'id' => (string) $row->id,
                'type' => $type,
                'content' => $bestText !== '' ? $bestText : (string) $row->{$titleColumn},
                'score' => $bestScore,
                'retrieval' => 'semantic',
            ];
        }

        return $results;
    }

    /**
     * The original hpbrain_learnings LIKE search, excluding any row already
     * answered semantically, down-weighted the same way
     * searchDocumentsKeyword() down-weights a keyword hit.
     *
     * @param  array<int, string>  $excludeIds
     * @return array<int, array{id:string, type:string, content:string, score:float, retrieval:string}>
     */
    private function searchLearningsKeyword(string $tenantId, string $query, array $excludeIds): array
    {
        $rows = DB::table('hpbrain_learnings')
            ->where('tenant_id', $tenantId)
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->where(function ($q) use ($query) {
                $q->where('pattern', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%")
                  ->orWhere('domain', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'pattern', 'description']);

        return $rows->map(fn ($row) => [
            'id' => (string) $row->id,
            'type' => 'memory',
            'content' => (string) ($row->pattern ?: mb_substr((string) $row->description, 0, 200)),
            'score' => $this->matchScore($query, (string) $row->pattern, true) * self::KEYWORD_ONLY_PENALTY,
            'retrieval' => 'keyword_only',
        ])->all();
    }

    /**
     * The original hpbrain_mental_models LIKE search, excluding any row
     * already answered semantically, down-weighted the same way
     * searchDocumentsKeyword() down-weights a keyword hit.
     *
     * @param  array<int, string>  $excludeIds
     * @return array<int, array{id:string, type:string, content:string, score:float, retrieval:string}>
     */
    private function searchMentalModelsKeyword(string $tenantId, string $query, array $excludeIds): array
    {
        $rows = DB::table('hpbrain_mental_models')
            ->where('tenant_id', $tenantId)
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%")
                  ->orWhere('domain', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'description']);

        return $rows->map(fn ($row) => [
            'id' => (string) $row->id,
            'type' => 'mental_model',
            'content' => (string) ($row->name ?: mb_substr((string) $row->description, 0, 200)),
            'score' => $this->matchScore($query, (string) $row->name, true) * self::KEYWORD_ONLY_PENALTY,
            'retrieval' => 'keyword_only',
        ])->all();
    }

    /**
     * The original hpbrain_eso_definitions LIKE search, excluding any row
     * already answered semantically, down-weighted the same way
     * searchDocumentsKeyword() down-weights a keyword hit.
     *
     * @param  array<int, string>  $excludeIds
     * @return array<int, array{id:string, type:string, content:string, score:float, retrieval:string}>
     */
    private function searchEsoDefinitionsKeyword(string $tenantId, string $query, array $excludeIds): array
    {
        $rows = DB::table('hpbrain_eso_definitions')
            ->where('tenant_id', $tenantId)
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('objective', 'like', "%{$query}%")
                  ->orWhere('trigger_description', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'objective']);

        return $rows->map(fn ($row) => [
            'id' => (string) $row->id,
            'type' => 'eso_definition',
            'content' => (string) ($row->name ?: mb_substr((string) $row->objective, 0, 200)),
            'score' => $this->matchScore($query, (string) $row->name, true) * self::KEYWORD_ONLY_PENALTY,
            'retrieval' => 'keyword_only',
        ])->all();
    }

    /**
     * A real heuristic, not a constant: an exact or prefix match on the
     * PRIMARY field (title/name/pattern) ranks above a match found only
     * inside a longer description, the one distinction a keyword search can
     * honestly make without an embedding model to judge meaning.
     */
    private function matchScore(string $query, string $matchedOn, bool $wasPrimaryField): float
    {
        $needle = mb_strtolower(trim($query));
        $haystack = mb_strtolower($matchedOn);

        if ($needle === '' || $haystack === '') {
            return $wasPrimaryField ? 0.5 : 0.3;
        }

        if ($haystack === $needle) {
            return 1.0;
        }

        if (str_starts_with($haystack, $needle)) {
            return $wasPrimaryField ? 0.9 : 0.6;
        }

        if (str_contains($haystack, $needle)) {
            return $wasPrimaryField ? 0.7 : 0.4;
        }

        // The query matched a DIFFERENT field than $matchedOn (e.g. only the
        // description), so this row's own primary field says nothing about
        // relevance - the lowest honest score, not a guess at a higher one.
        return 0.3;
    }
}
