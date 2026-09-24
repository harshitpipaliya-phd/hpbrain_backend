<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The DeepSeek chat-completions API over plain HTTP.
 *
 * NO SDK PACKAGE, for the same reason as AnthropicProvider: ADR-004 wants the
 * vendor confined to one file, and a composer dependency spreads vendor types
 * through the autoloader where they get picked up by type hints elsewhere.
 *
 * THIS IS THE ONLY FILE IN THE APPLICATION THAT KNOWS DEEPSEEK EXISTS. The
 * endpoint, the bearer header, the OpenAI-compatible request envelope and the
 * usage keys are all local to it.
 *
 * The wire format is OpenAI-compatible, which is a convenience and not a
 * guarantee: DeepSeek's own model identifiers are NOT OpenAI's, and the model
 * name still has to come from config.
 *
 * The key comes from config, never env() directly: `php artisan config:cache`
 * makes env() return null, so a provider reading env() at request time works
 * locally and fails silently once the config is cached.
 */
final class DeepSeekProvider implements AiProvider
{
    private const ENDPOINT = 'https://api.deepseek.com/chat/completions';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $defaultModel = 'deepseek-v4-flash',
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function complete(AiRequest $request): AiResponse
    {
        if ($this->apiKey === '') {
            // Never send an unauthenticated request just to see what happens.
            throw new RuntimeException('deepseek_api_key_missing');
        }

        $model = $request->model ?? $this->defaultModel;

        $startedAt = microtime(true);

        $httpResponse = Http::withHeaders([
            // Bearer HEADER, not a query parameter. Confirmed against
            // api-docs.deepseek.com — a key on the query string would be
            // logged by every proxy between here and there.
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ])
            ->timeout($this->timeoutSeconds)
            // Synchronous only (no streaming): the guardrail step must inspect
            // a COMPLETE response before anything downstream may see it, and a
            // streamed answer is by definition visible before it is validated.
            ->post(self::ENDPOINT, [
                'model'    => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPromptFor($request)],
                    ['role' => 'user',   'content' => $request->userPrompt],
                ],
                'temperature'     => $request->temperature,
                'max_tokens'      => $request->maxTokens,
                'response_format' => ['type' => 'json_object'],
            ]);

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($httpResponse->failed()) {
            // Thrown, not returned: AiGateway distinguishes "the model answered"
            // from "the call failed", and records the second as a failed
            // execution rather than as a conclusion.
            throw new RuntimeException(sprintf(
                'deepseek_http_%d: %s', $httpResponse->status(), mb_substr($httpResponse->body(), 0, 500)
            ));
        }

        $body = $httpResponse->json();

        return new AiResponse(
            content: (string) ($body['choices'][0]['message']['content'] ?? ''),
            model: (string) ($body['model'] ?? $model),
            inputTokens: isset($body['usage']['prompt_tokens']) ? (int) $body['usage']['prompt_tokens'] : null,
            outputTokens: isset($body['usage']['completion_tokens']) ? (int) $body['usage']['completion_tokens'] : null,
            latencyMs: $latencyMs,
            finishReason: $body['choices'][0]['finish_reason'] ?? null,
        );
    }

    /**
     * DeepSeek's JSON mode has a documented precondition the other drivers do
     * not: with response_format=json_object the prompt must contain the word
     * "json" AND an example of the wanted shape, or the API "may occasionally
     * return empty content". An empty string parses to null in
     * AiResponse::json(), so the caller would read a silent format failure as
     * UNDETERMINED and never learn why.
     *
     * AiRequest already carries responseSchema for exactly this. Appending it
     * satisfies the precondition from data the caller already supplied rather
     * than asking every call site to restate its schema in prose.
     */
    private function systemPromptFor(AiRequest $request): string
    {
        if ($request->responseSchema === []) {
            return $request->systemPrompt;
        }

        return $request->systemPrompt
            . "\n\nRespond with a single json object of exactly this shape:\n"
            . (json_encode($request->responseSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
