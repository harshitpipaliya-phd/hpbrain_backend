<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\AssembledContext;
use App\Domain\Ai\RetrievalResult;
use App\Domain\Graph\GraphQueryPort;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6.1 (partial — no vector store exists, by deliberate decision this
 * session; this fixes what does not need one). Keyword search over real
 * tables, not semantic retrieval — `score` is a match-quality heuristic
 * (exact/starts-with/contains, and which field matched), not a learned
 * similarity. Real semantic search needs an embedding pipeline and a vector
 * store, neither of which exists in any of the three products; building
 * this without one would be the same shell the audit already found, just
 * with a less obviously-fake score.
 *
 * STILL UNCALLED. RagService (the only caller of this class) has zero
 * callers of its own anywhere in the app — confirmed by a whole-repo grep.
 * Fixing this class makes it correct whenever something does call it; it
 * does not, by itself, make anything call it.
 */
final class RetrievalService
{
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

    /** Keyword search over hpbrain_knowledge_assets (title, content, tags). */
    public function searchDocuments(string $tenantId, string $query): array
    {
        $rows = DB::table('hpbrain_knowledge_assets')
            ->where('tenant_id', $tenantId)
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
            'score' => $this->matchScore($query, (string) $row->title, true),
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
     * Keyword search across Organizational Memory & ESO (Phase 6.5): learnings
     * (pattern, description, domain), mental models (name, description) and
     * ESO definitions (name, objective, trigger_description) — the three
     * tables the roadmap names for this task. Decision narratives are already
     * covered by searchEntities('decision'), so they are not repeated here.
     */
    public function searchMemory(string $tenantId, string $query): array
    {
        $learnings = DB::table('hpbrain_learnings')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($query) {
                $q->where('pattern', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%")
                  ->orWhere('domain', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'pattern', 'description'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'type' => 'memory',
                'content' => (string) ($row->pattern ?: mb_substr((string) $row->description, 0, 200)),
                'score' => $this->matchScore($query, (string) $row->pattern, true),
            ]);

        $mentalModels = DB::table('hpbrain_mental_models')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%")
                  ->orWhere('domain', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'description'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'type' => 'mental_model',
                'content' => (string) ($row->name ?: mb_substr((string) $row->description, 0, 200)),
                'score' => $this->matchScore($query, (string) $row->name, true),
            ]);

        $esoDefinitions = DB::table('hpbrain_eso_definitions')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('objective', 'like', "%{$query}%")
                  ->orWhere('trigger_description', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'objective'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'type' => 'eso_definition',
                'content' => (string) ($row->name ?: mb_substr((string) $row->objective, 0, 200)),
                'score' => $this->matchScore($query, (string) $row->name, true),
            ]);

        return $learnings->concat($mentalModels)->concat($esoDefinitions)->all();
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
