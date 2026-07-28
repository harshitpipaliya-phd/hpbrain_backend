<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Undetermined\VerbResult;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    public function providers(): JsonResponse
    {
        $configured = array_values(array_filter(
            ['anthropic', 'openai', 'gemini', 'ollama'],
            fn (string $p) => (string) env(strtoupper($p).'_API_KEY', '') !== ''
        ));

        return response()->json([
            'supported'  => ['anthropic', 'openai', 'gemini', 'ollama'],
            'configured' => $configured,     // names only — never keys
            'active'     => (string) env('AI_PROVIDER', '') ?: null,
        ]);
    }

    public function executions(Request $request): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_ai_executions')->where('tenant_id', $this->tenantId($request))
                ->orderByDesc('created_date')->limit(200)->get()
        );
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

        if ((string) env('AI_PROVIDER', '') === '') {
            return response()->json(
                VerbResult::undetermined(['no_ai_provider_configured'], $evidence->pluck('id')->all()),
                200
            );
        }

        // A provider is configured but the client is not implemented. Say so
        // rather than silently degrading to a template.
        return response()->json(
            VerbResult::undetermined(['ai_provider_client_not_implemented'], $evidence->pluck('id')->all()),
            200
        );
    }
}
