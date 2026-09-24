<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Domain\Undetermined\VerbResult;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AI surface.
 *
 * ADR-004 requires the model to be swappable: providers are addressed through
 * configuration, never a vendor SDK bound into business logic. Provider
 * credentials live in .env and are never returned by this endpoint.
 *
 * IMPORTANT — no provider is wired yet. Rather than returning invented text,
 * summarize() grounds on real evidence and returns UNDETERMINED naming the
 * gap when no provider is configured. Fabricating a summary would violate the
 * honesty principle this product is built on, and a fake success response is
 * worse than an honest refusal because nobody can tell it apart from a real one.
 */
final class AiController extends Controller
{
    private const SUPPORTED = ['anthropic', 'openai', 'gemini', 'ollama'];

    public function __construct(private readonly AiGateway $ai)
    {
    }

    /**
     * `providers` is a list of {name, available} because that is what the AI
     * Workspace and the Command Center both iterate. Returning only the two
     * flat name lists left providerData.providers undefined, and .filter on it
     * took both screens down before they painted.
     *
     * supported/configured are kept alongside it — they are the same facts in
     * the form the API contract already published.
     */
    public function providers(): JsonResponse
    {
        $configured = array_values(array_filter(
            self::SUPPORTED,
            fn (string $p) => (string) env(strtoupper($p).'_API_KEY', '') !== ''
        ));

        $active = (string) env('AI_PROVIDER', '') ?: null;

        return response()->json([
            'providers'  => array_map(
                fn (string $p) => ['name' => $p, 'available' => in_array($p, $configured, true)],
                self::SUPPORTED
            ),
            'supported'  => self::SUPPORTED,
            'configured' => $configured,     // names only — never keys
            // The UI prints this straight into a sentence, so it must be a
            // string; `null` rendered as an empty gap after "Active provider:".
            'active'     => $active ?? 'none configured',
        ]);
    }

    public function executions(Request $request): JsonResponse
    {
        $rows = DB::table('hpbrain_ai_executions')->where('tenant_id', $this->tenantId($request))
            ->orderByDesc('created_date')->limit(200)->get();

        // camelCase, matching every other read surface in this API. The raw
        // snake_case row left serviceName/latencyMs/createdDate undefined in
        // the execution list.
        return response()->json($rows->map(fn ($r) => [
            'id'           => (string) $r->id,
            'serviceName'  => (string) ($r->service_name ?? ''),
            'provider'     => (string) ($r->provider ?? ''),
            'model'        => $r->model ?? null,
            'status'       => (string) ($r->status ?? 'unknown'),
            'inputTokens'  => $r->input_tokens === null ? null : (int) $r->input_tokens,
            'outputTokens' => $r->output_tokens === null ? null : (int) $r->output_tokens,
            'latencyMs'    => $r->latency_ms === null ? null : (int) $r->latency_ms,
            'error'        => $r->error ?? null,
            'entityType'   => $r->entity_type ?? null,
            'entityId'     => $r->entity_id ?? null,
            'createdDate'  => $r->created_date,
        ])->values());
    }

    public function summarizeEvidence(Request $request): JsonResponse
    {
        $data = $request->validate(['signalId' => ['required', 'string']]);

        $evidence = DB::table('hpbrain_evidence')
            ->where('tenant_id', $this->tenantId($request))
            ->where('signal_id', $data['signalId'])->get();

        if ($evidence->isEmpty()) {
            return response()->json(VerbResult::undetermined(['no_evidence_for_signal']), 200);
        }

        $refs = $evidence->pluck('id')->all();

        // Unchanged behaviour when no provider is configured, and the golden
        // intelligence-flow test asserts this exact gap string. AiGateway
        // treats the null driver as unconfigured outside local/testing, so a
        // stray AI_PROVIDER on a production box cannot turn canned text into
        // an apparent summary.
        if (! $this->ai->isConfigured()) {
            return response()->json(VerbResult::undetermined(['no_ai_provider_configured'], $refs), 200);
        }

        try {
            $response = $this->ai->complete(
                new AiRequest(
                    systemPrompt: 'Summarise the evidence supplied. Reply with STRICT JSON only: '
                        .'{"summary":"...","evidenceRefs":["..."]}. Cite only ids you were given.',
                    userPrompt: $evidence
                        ->map(fn ($e) => sprintf('- id=%s content=%s', $e->id, (string) $e->content))
                        ->implode("\n"),
                    responseSchema: ['summary' => 'string', 'evidenceRefs' => ['string']],
                    temperature: 0.1,
                ),
                tenantId: $this->tenantId($request),
                actorId: $this->actorId($request),
                service: 'ai.summarize_evidence',
                entityType: 'Signal',
                entityId: $data['signalId'],
            );
        } catch (Throwable) {
            // The gateway has already written the failed execution row.
            return response()->json(VerbResult::undetermined(['ai_call_failed'], $refs), 200);
        }

        $parsed = $response->json();

        if ($parsed === null || ! isset($parsed['summary']) || ! is_string($parsed['summary'])) {
            // Never the raw text as if it were a summary: a caller cannot tell
            // a model's apology from its answer once it is rendered on screen.
            return response()->json(VerbResult::undetermined(['ai_response_unparseable'], $refs), 200);
        }

        // Citations are intersected with what was actually supplied, so a
        // fabricated reference cannot reach the caller.
        $cited = array_values(array_intersect(
            array_filter($parsed['evidenceRefs'] ?? [], 'is_string'), $refs
        ));

        return response()->json(VerbResult::decided(
            ['summary' => $parsed['summary']],
            $cited === [] ? $refs : $cited,
        ), 200);
    }
}
