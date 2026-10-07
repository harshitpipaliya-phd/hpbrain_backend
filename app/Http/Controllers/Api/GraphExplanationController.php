<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Domain\Graph\GraphQueryPort;
use App\Domain\Graph\GraphVocabulary;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Graph RAG for Enterprise Brain: one entity's own facts and connections,
 * narrated in plain language. Phase 5 of the Neo4j/Graph RAG roadmap —
 * covers the document's 5.4 (Signal reasoning explanation), 5.5
 * (Recommendation reasoning trail) and 5.7 (Entity intelligence) in one
 * generic endpoint, because GraphProjection::detail() already answers all
 * three the same way: one query pattern, every label it knows.
 *
 * RETRIEVAL AND GENERATION ARE SEPARATE STEPS, same reference shape as every
 * other Graph RAG feature in this project. GraphQueryPort::detail() (pure,
 * no model call) is the retrieval; this controller's only job on top of it
 * is turning already-labelled facts/connections into a sentence. A graph
 * miss is a 404. An unconfigured or failing model is a clean, separate
 * failure — never a narrative the facts don't support.
 *
 * Gated `permission:create`, not the surrounding group's `read`, because
 * this calls AiGateway and is billed — same rule api/ai/evidence/summarize
 * already states for the same reason.
 */
final class GraphExplanationController extends Controller
{
    private const SERVICE = 'graph_explanation';

    public function explain(Request $request, string $label, string $id, GraphQueryPort $projection, AiGateway $ai): JsonResponse
    {
        if (! GraphVocabulary::isKnownLabel($label)) {
            return response()->json(['error' => 'unknown_label', 'supported' => array_keys(GraphVocabulary::LABEL_FAMILY)], 422);
        }

        $tenantId = $this->tenantId($request);

        try {
            $detail = $projection->detail($tenantId, $label, $id);
        } catch (Throwable $e) {
            return response()->json(['error' => 'graph_unavailable', 'message' => $e->getMessage()], 503);
        }

        if ($detail === null) {
            return response()->json(['error' => 'entity_not_found', 'label' => $label, 'id' => $id], 404);
        }

        if (! $ai->isConfigured()) {
            return response()->json($detail + ['explanation' => null, 'explanationStatus' => 'ai_not_configured']);
        }

        try {
            $response = $ai->complete(
                new AiRequest(
                    systemPrompt: $this->systemPrompt(),
                    userPrompt: $this->userPrompt($label, $detail),
                    responseSchema: self::SCHEMA,
                    maxTokens: 700,
                    temperature: 0.2,
                ),
                tenantId: $tenantId,
                actorId: $this->actorId($request),
                service: self::SERVICE,
                entityType: $label,
                entityId: $id,
            );
        } catch (Throwable $e) {
            // The gateway has already recorded the failed execution (Invariant
            // 7). The facts themselves are still a complete, honest answer.
            return response()->json($detail + ['explanation' => null, 'explanationStatus' => 'ai_call_failed']);
        }

        $json = $response->json();

        // DeepSeekProvider's json_object mode "may occasionally return empty
        // content" per its own docblock - json() returning null here means
        // exactly that, not that explanation is genuinely absent.
        if ($json === null || ! isset($json['explanation'])) {
            return response()->json($detail + ['explanation' => null, 'explanationStatus' => 'ai_response_not_json']);
        }

        return response()->json($detail + ['explanation' => trim((string) $json['explanation']), 'explanationStatus' => 'ok']);
    }

    /**
     * Every call through DeepSeekProvider is unconditionally response_format
     * json_object (confirmed in that class) - a non-empty responseSchema is
     * what makes AiRequest's own systemPromptFor() append the "respond with
     * json" instruction DeepSeek's API requires, not an optional nicety.
     */
    private const SCHEMA = ['explanation' => 'string, 3-5 sentences of plain language'];

    private function systemPrompt(): string
    {
        return 'You are explaining one record from an organization\'s intelligence graph, to someone deciding '
            . 'whether to act on it. Use only the facts and connections given in the message - never invent a '
            . 'name, figure, or relationship not present in the input. Say plainly when a section has nothing '
            . 'in it rather than guessing a reason.';
    }

    /** @param array{node:array,facts:array,connections:array} $detail */
    private function userPrompt(string $label, array $detail): string
    {
        $lines = [sprintf('Entity: %s "%s"', $label, $detail['node']['title'] ?? $detail['node']['id'] ?? '')];

        if ($detail['facts'] !== []) {
            $lines[] = '';
            $lines[] = 'Facts:';
            foreach ($detail['facts'] as $fact) {
                $lines[] = sprintf('- %s: %s', $fact['label'], $fact['value']);
            }
        }

        if ($detail['connections'] !== []) {
            $lines[] = '';
            $lines[] = 'Connections:';
            foreach ($detail['connections'] as $c) {
                $lines[] = sprintf('- %d %s (%s)', $c['count'], $c['label'], $c['relationship']);
            }
        }

        if ($detail['facts'] === [] && $detail['connections'] === []) {
            $lines[] = '';
            $lines[] = 'No facts or connections are recorded for this entity.';
        }

        return implode("\n", $lines);
    }
}
