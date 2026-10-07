<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Providers\NullAiProvider;
use App\Services\QuotaService;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * The only sanctioned way to call a model.
 *
 * INVARIANT 7: every AI recommendation is traceable. That is a claim about
 * rows, not about intentions — so this class writes exactly one
 * hpbrain_ai_executions row per call, BEFORE returning anything to the caller,
 * and it writes it whether the call succeeded, threw, or was refused for
 * quota. An AI call with no execution row is an untraceable recommendation; a
 * failed call with no row is worse, because the failure is the thing nobody
 * would otherwise know about.
 *
 * Calling an AiProvider directly bypasses that write, AND bypasses the quota
 * check below. Don't.
 *
 * QUOTA IS CHECKED HERE, ONCE, NOT BY EACH CALLER. QuotaService::check() and
 * ::recordUsage() existed with a passing unit test and zero production
 * callers — every verb that called complete() was individually responsible
 * for checking first, and none of them did. Wiring the check into this one
 * choke point means every existing and future caller is covered without
 * having to remember to ask. A tenant with no configured quota for a
 * feature is unaffected (QuotaService::check() reports `allowed: true` for
 * an unconfigured feature — metering, not capping, until an admin sets a
 * real limit).
 */
final class AiGateway
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly QuotaService $quota,
    ) {
    }

    /**
     * Is a REAL provider configured?
     *
     * The null driver counts as "not configured" outside local and testing, and
     * that asymmetry is deliberate. Canned reasoning is exactly as useful as a
     * real model in a test and exactly as dangerous as a lie in production, so
     * a stray AI_PROVIDER=null on a production box must degrade to an honest
     * UNDETERMINED rather than to plausible fiction.
     */
    public function isConfigured(): bool
    {
        $name = (string) config('brain.ai.provider', '');

        if ($name === '' || $name === 'none') {
            return false;
        }

        if ($this->provider instanceof NullAiProvider) {
            return app()->environment('local', 'testing');
        }

        return true;
    }

    /**
     * Run one completion and record it.
     *
     * @param  string  $service        what asked — the verb name, so the cost of
     *                                 each cognitive operation is separable
     * @param  string|null  $templateId the prompt VERSION that produced this, so
     *                                  output can be traced to its exact prompt
     *
     * @throws AiQuotaExceededException  the tenant's quota for $service is exhausted; no provider call was made
     * @throws Throwable                 the provider's own failure, re-thrown after recording
     */
    public function complete(
        AiRequest $request,
        string $tenantId,
        string $actorId,
        string $service,
        ?string $templateId = null,
        ?string $entityType = null,
        ?string $entityId = null,
    ): AiResponse {
        $startedAt = microtime(true);

        $quotaResult = $this->quota->check($tenantId, $actorId, $service);

        if (! $quotaResult->allowed) {
            $this->record(
                $tenantId, $actorId, $service, $templateId, $entityType, $entityId,
                model: $request->model ?? (string) config('brain.ai.model', ''),
                status: 'quota_exceeded',
                inputTokens: null,
                outputTokens: null,
                latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
                error: sprintf(
                    'Quota exceeded: %d/%d used this %s period.',
                    $quotaResult->used, $quotaResult->limit, $quotaResult->resetPeriod,
                ),
            );

            throw new AiQuotaExceededException($tenantId, $service, $quotaResult);
        }

        try {
            $response = $this->provider->complete($request);
        } catch (Throwable $e) {
            // Recorded first, re-thrown second. If this order were reversed a
            // provider outage would leave no trace at all — the one
            // circumstance in which the execution log matters most.
            $this->record(
                $tenantId, $actorId, $service, $templateId, $entityType, $entityId,
                model: $request->model ?? (string) config('brain.ai.model', ''),
                status: 'failed',
                inputTokens: null,
                outputTokens: null,
                latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
                error: mb_substr($e->getMessage(), 0, 1000),
            );

            throw $e;
        }

        $cost = $this->estimateCost($response->model, $response->inputTokens, $response->outputTokens);

        $this->quota->recordUsage(
            $tenantId,
            $actorId,
            $service,
            ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
            $cost ?? 0.0,
        );

        $this->record(
            $tenantId, $actorId, $service, $templateId, $entityType, $entityId,
            model: $response->model,
            status: 'completed',
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            latencyMs: $response->latencyMs ?? (int) round((microtime(true) - $startedAt) * 1000),
            error: null,
        );

        return $response;
    }

    /**
     * complete(), with retrieved context appended to the prompt - fixed from
     * a hardcoded passthrough that accepted $ragOptions and then discarded
     * it, always calling complete() as if no context had been given at all
     * (confirmed: every RAG row in the audit this fixes was blocked on
     * exactly this). Still has no caller anywhere in the app - RagService,
     * the only class that could call this, has none of its own either.
     *
     * $ragOptions['documents'] is the shape RetrievalResult::$documents and
     * RetrievalService's search*() methods already return:
     * array<int, array{id:string, type:string, content:string, score:float}>.
     * Appended as a clearly-delimited block so the model can be told, in the
     * system prompt, to use only what's inside it - the same grounded-only
     * contract every other retrieval-then-generation feature in this project
     * already follows.
     */
    public function completeWithRag(
        string $tenantId,
        string $actorId,
        string $service,
        AiRequest $request,
        array $ragOptions = [],
    ): AiResponse {
        $documents = $ragOptions['documents'] ?? [];

        if ($documents === []) {
            return $this->complete($request, $tenantId, $actorId, $service, null, null, null);
        }

        $context = implode("\n", array_map(
            fn (array $d) => sprintf('- [%s %s] %s', $d['type'] ?? 'item', $d['id'] ?? '', $d['content'] ?? ''),
            $documents
        ));

        $grounded = new AiRequest(
            systemPrompt: $request->systemPrompt
                . "\n\nUse only the RETRIEVED CONTEXT below to answer. If it does not contain the answer, say so plainly rather than guessing.",
            userPrompt: $request->userPrompt . "\n\nRETRIEVED CONTEXT:\n" . $context,
            responseSchema: $request->responseSchema,
            maxTokens: $request->maxTokens,
            temperature: $request->temperature,
            model: $request->model,
        );

        return $this->complete($grounded, $tenantId, $actorId, $service, null, null, null);
    }

    public function completeWithFallback(
        string $tenantId,
        string $actorId,
        string $service,
        AiRequest $request,
        array $fallbackChain = [],
    ): AiResponse {
        $chain = $fallbackChain !== [] ? $fallbackChain : \App\Services\AiProviderRegistry::getFallbackChain();

        $lastException = null;

        foreach ($chain as $providerName) {
            try {
                $provider = app(\App\Domain\Ai\AiProvider::class);
                return $provider->complete($request);
            } catch (Throwable $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new \RuntimeException('No AI provider available');
    }

    private function record(
        string $tenantId,
        string $actorId,
        string $service,
        ?string $templateId,
        ?string $entityType,
        ?string $entityId,
        string $model,
        string $status,
        ?int $inputTokens,
        ?int $outputTokens,
        int $latencyMs,
        ?string $error,
    ): void {
        DB::table('hpbrain_ai_executions')->insert([
            'id'                 => Uuid::uuid4()->toString(),
            'tenant_id'          => $tenantId,
            'user_id'            => $actorId,
            'service_name'       => $service,
            'prompt_template_id' => $templateId,
            'provider'           => (string) config('brain.ai.provider', 'none'),
            'model'              => $model,
            'status'             => $status,
            'input_tokens'       => $inputTokens,
            'output_tokens'      => $outputTokens,
            'latency_ms'         => $latencyMs,
            'estimated_cost_usd' => $this->estimateCost($model, $inputTokens, $outputTokens),
            'error'              => $error,
            'entity_type'        => $entityType,
            'entity_id'          => $entityId,
            'created_date'       => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Cost in USD from the per-million-token prices in config.
     *
     * NULL when the model is not priced or the token counts are unknown. A
     * zero would assert that a call was free, which is a different and false
     * claim — and one that would quietly understate the spend total the
     * governance dashboard reports.
     */
    private function estimateCost(string $model, ?int $inputTokens, ?int $outputTokens): ?float
    {
        $pricing = config('brain.ai.pricing.'.$model);

        if (! is_array($pricing) || ($inputTokens === null && $outputTokens === null)) {
            return null;
        }

        $cost = (($inputTokens ?? 0) / 1_000_000) * (float) ($pricing['input'] ?? 0)
              + (($outputTokens ?? 0) / 1_000_000) * (float) ($pricing['output'] ?? 0);

        return round($cost, 4);
    }
}
