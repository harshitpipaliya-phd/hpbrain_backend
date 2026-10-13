<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Domain\Graph\GraphBuilder;
use Illuminate\Http\Request;
use Throwable;

/**
 * Shared GraphRAG plumbing for the Phase 5 narrative endpoints (Signal
 * reasoning explanation, Recommendation reasoning trail, Entity
 * intelligence, Executive summary). Every one of them follows the same
 * sequence — resolve tenant, read the graph through GraphQueryPort, turn
 * the facts it returns into completeWithRag()'s `documents` shape
 * (`{id, type, content, score}` — the shape RetrievalResult::$documents and
 * RetrievalService's search*() methods already return, and the shape
 * KnowledgeAskController already feeds completeWithRag()), then call
 * AiGateway::completeWithRag() with a grounded-only prompt. The conversion
 * and the AI step are written once here instead of four times.
 *
 * NEVER A FABRICATED ANSWER. narrateFromDocuments() refuses to call the
 * model at all when there is nothing to ground it in (`no_evidence`)
 * rather than letting completeWithRag() silently fall back to an
 * ungrounded complete() call for an empty `documents` array — that
 * fallback exists for callers with no retrieval step at all (none of
 * these four are that), and taking it here would let the model invent a
 * reason where the graph recorded none. Each caller decides what counts
 * as "nothing to ground on" for its own shape of retrieval (a bare origin
 * node with no onward trail, an entity with no facts and no neighbours, an
 * organization graph with no branches) and passes `[]` for documents in
 * that case — this trait does not guess at that threshold either.
 */
trait BuildsGraphRagNarrative
{
    /**
     * GraphQueryPort::reasoningTrail()'s ordered `trail` turned into one
     * document per step, each carrying the relationship (and its edge
     * note, where one exists — e.g. the reasoning sentence behind a
     * `led_to` edge) that reached it from the previous step. Bounded by
     * reasoningTrail()'s own walk; nothing here re-queries anything.
     *
     * @param  array<string,mixed>  $trail  GraphQueryPort::reasoningTrail()'s return
     * @return array<int, array{id:string,type:string,content:string,score:float}>
     */
    protected function reasoningTrailDocuments(array $trail): array
    {
        $nodesByKey = [];

        foreach ($trail['nodes'] ?? [] as $node) {
            $nodesByKey[$node['key']] = $node;
        }

        $edgesByPair = [];

        foreach ($trail['edges'] ?? [] as $edge) {
            $edgesByPair[$edge['from'].'|'.$edge['to']] = $edge;
            $edgesByPair[$edge['to'].'|'.$edge['from']] = $edge;
        }

        $documents = [];
        $previousKey = null;

        foreach ($trail['trail'] ?? [] as $step) {
            $key = GraphBuilder::key((string) $step['label'], (string) $step['id']);
            $node = $nodesByKey[$key] ?? null;

            if ($node === null) {
                $previousKey = $key;

                continue;
            }

            $lines = [sprintf('Step %d: %s "%s"', $step['step'], $node['label'], $node['title'] ?? $node['id'])];

            if (! empty($node['subtitle'])) {
                $lines[] = (string) $node['subtitle'];
            }

            foreach ((array) ($node['properties'] ?? []) as $propKey => $propValue) {
                if ($propValue === null || $propValue === '') {
                    continue;
                }

                $lines[] = sprintf('%s=%s', $propKey, is_scalar($propValue) ? (string) $propValue : (string) json_encode($propValue));
            }

            if ($previousKey !== null) {
                $edge = $edgesByPair[$previousKey.'|'.$key] ?? null;

                if ($edge !== null) {
                    $lines[] = sprintf('Reached via "%s" (%s)', $edge['label'], $edge['provenance']);

                    if (! empty($edge['note'])) {
                        $lines[] = 'Reasoning: '.$edge['note'];
                    }
                }
            }

            $documents[] = [
                'id'      => (string) $node['id'],
                'type'    => (string) $node['label'],
                'content' => implode('; ', $lines),
                'score'   => 1.0,
            ];

            $previousKey = $key;
        }

        return $documents;
    }

    /**
     * GraphQueryPort::detail()'s own facts and connections, plus one
     * document per neighbour GraphQueryPort::expand() returned — the
     * non-intelligence-family counterpart of reasoningTrailDocuments()
     * above, for the labels GraphVocabulary places outside
     * FAMILY_INTELLIGENCE (Organization, Department, Person, Student,
     * Standard, Subject, Dataset).
     *
     * @param  array{node:array,facts:array,connections:array}  $detail  GraphQueryPort::detail()'s return
     * @param  array<string,mixed>  $expansion  GraphQueryPort::expand()'s return
     * @return array<int, array{id:string,type:string,content:string,score:float}>
     */
    protected function entityContextDocuments(string $label, string $id, array $detail, array $expansion): array
    {
        $lines = [sprintf('%s "%s"', $label, $detail['node']['title'] ?? $id)];

        foreach ($detail['facts'] ?? [] as $fact) {
            $lines[] = sprintf('%s: %s', $fact['label'], $fact['value']);
        }

        foreach ($detail['connections'] ?? [] as $connection) {
            $lines[] = sprintf('%d %s (%s — %s)', $connection['count'], $connection['label'], $connection['relationship'], $connection['provenance']);
        }

        $documents = [[
            'id'      => $id,
            'type'    => $label,
            'content' => implode('; ', $lines),
            'score'   => 1.0,
        ]];

        $selfKey = GraphBuilder::key($label, $id);

        foreach ($expansion['nodes'] ?? [] as $node) {
            if ($node['key'] === $selfKey) {
                continue;
            }

            $edge = null;

            foreach ($expansion['edges'] ?? [] as $candidate) {
                if ($candidate['from'] === $node['key'] || $candidate['to'] === $node['key']) {
                    $edge = $candidate;

                    break;
                }
            }

            $neighbourLines = [sprintf('%s "%s"', $node['label'], $node['title'] ?? $node['id'])];

            if (! empty($node['subtitle'])) {
                $neighbourLines[] = (string) $node['subtitle'];
            }

            if ($edge !== null) {
                $neighbourLines[] = sprintf('Connected via "%s" (%s)', $edge['label'], $edge['provenance']);

                if (! empty($edge['note'])) {
                    $neighbourLines[] = 'Note: '.$edge['note'];
                }
            }

            $documents[] = [
                'id'      => (string) $node['id'],
                'type'    => (string) $node['label'],
                'content' => implode('; ', $neighbourLines),
                'score'   => 0.8,
            ];
        }

        return $documents;
    }

    /**
     * GraphQueryPort::summary()'s metric strip plus overview()'s own
     * branch nodes — the bounded, org-level context an executive briefing
     * grounds on. Bounded by overview()'s own node budget (nothing here
     * re-queries anything), and every line is a stored count or a stated
     * truncation, never a derived score.
     *
     * @param  array<string,int|string|null>  $summary  GraphQueryPort::summary()'s return
     * @param  array<string,mixed>  $overview  GraphQueryPort::overview()'s return
     * @return array<int, array{id:string,type:string,content:string,score:float}>
     */
    protected function organizationBriefingDocuments(array $summary, array $overview): array
    {
        $summaryLine = implode('; ', array_map(
            static fn ($key, $value): string => sprintf('%s=%s', $key, is_scalar($value) ? (string) $value : (string) json_encode($value)),
            array_keys($summary),
            $summary,
        ));

        $documents = [[
            'id'      => 'organization-summary',
            'type'    => 'OrganizationSummary',
            'content' => 'Organization metrics: '.$summaryLine,
            'score'   => 1.0,
        ]];

        foreach ($overview['nodes'] ?? [] as $node) {
            if (($node['label'] ?? null) === 'Organization') {
                continue;
            }

            $lines = [sprintf('%s "%s"', $node['label'], $node['title'] ?? $node['id'])];

            if (! empty($node['subtitle'])) {
                $lines[] = (string) $node['subtitle'];
            }

            if (($node['count'] ?? null) !== null) {
                $lines[] = 'count='.$node['count'];
            }

            $documents[] = [
                'id'      => (string) ($node['id'] ?? $node['key']),
                'type'    => (string) ($node['label'] ?? 'Node'),
                'content' => implode('; ', $lines),
                'score'   => 0.7,
            ];
        }

        foreach ($overview['truncated'] ?? [] as $truncation) {
            $documents[] = [
                'id'      => 'truncated-'.$truncation['kind'],
                'type'    => 'Truncated',
                'content' => $truncation['reason'],
                'score'   => 0.5,
            ];
        }

        return $documents;
    }

    /**
     * The one AI step every narrative endpoint in this family shares:
     * refuse to call the model with nothing to ground it in, report
     * honestly when the model is not configured, the call fails, or it
     * returns something other than the JSON shape asked for, and
     * otherwise return the narrative. AiGateway's own execution-row write
     * (Invariant 7) happens regardless of which branch below is taken —
     * every path through completeWithRag()/complete() writes one.
     *
     * @param  array<int, array{id:string,type:string,content:string,score:float}>  $documents
     * @return array{narrative: ?string, narrativeStatus: string}
     */
    protected function narrateFromDocuments(
        Request $request,
        AiGateway $ai,
        string $tenant,
        string $service,
        string $systemPrompt,
        string $userPrompt,
        array $documents,
    ): array {
        if ($documents === []) {
            return ['narrative' => null, 'narrativeStatus' => 'no_evidence'];
        }

        if (! $ai->isConfigured()) {
            return ['narrative' => null, 'narrativeStatus' => 'ai_not_configured'];
        }

        try {
            $response = $ai->completeWithRag(
                $tenant,
                $this->actorId($request),
                $service,
                new AiRequest(
                    systemPrompt: $systemPrompt,
                    userPrompt: $userPrompt,
                    responseSchema: ['narrative' => 'string, 3-5 sentences of plain language, json'],
                    // 1600, not the 700 GraphExplanationController/PersonProfileController
                    // use for a single entity's facts — confirmed live against
                    // DeepSeek that a multi-step trail's retrieved context (several
                    // documents, not one) needs considerably more of the output
                    // budget to close its JSON: at 700, a real 6-node trail was cut
                    // off mid-string (`ai_response_not_json`) even though the model
                    // was grounding correctly; DeepSeek's own output length for the
                    // identical prompt varies run to run (904 tokens one call, 1200+
                    // another), so this is sized with headroom above the observed
                    // range rather than to one sample.
                    maxTokens: 1600,
                    temperature: 0.2,
                ),
                ['documents' => $documents],
            );
        } catch (Throwable $e) {
            return ['narrative' => null, 'narrativeStatus' => 'ai_call_failed'];
        }

        $json = $response->json();

        if ($json === null || ! isset($json['narrative'])) {
            return ['narrative' => null, 'narrativeStatus' => 'ai_response_not_json'];
        }

        return ['narrative' => trim((string) $json['narrative']), 'narrativeStatus' => 'ok'];
    }
}
