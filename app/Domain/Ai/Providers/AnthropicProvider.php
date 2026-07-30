<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Anthropic Messages API over plain HTTP.
 *
 * NO SDK PACKAGE, deliberately. ADR-004 wants the vendor confined to one file;
 * a composer dependency spreads vendor types through the autoloader and invites
 * them into type hints elsewhere, which is precisely what the interface is
 * there to stop. The wire format is three fields and a header — a dependency
 * buys nothing here and costs the isolation.
 *
 * THIS IS THE ONLY FILE IN THE APPLICATION THAT KNOWS ANTHROPIC EXISTS. The
 * request shape, the header names, the API version, the response envelope and
 * the token-usage keys are all local to it. Swapping providers means writing a
 * sibling in this directory and changing one config value.
 *
 * The key comes from config, never env() directly: `php artisan config:cache`
 * makes env() return null in production, so a provider reading env() at request
 * time works locally and fails silently once the config is cached.
 */
final class AnthropicProvider implements AiProvider
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** Pinned. An unpinned API version changes the response shape underneath us. */
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $defaultModel,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function complete(AiRequest $request): AiResponse
    {
        if ($this->apiKey === '') {
            // Never send an unauthenticated request just to see what happens.
            throw new RuntimeException('anthropic_api_key_missing');
        }

        $startedAt = microtime(true);

        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type'      => 'application/json',
        ])
            ->timeout($this->timeoutSeconds)
            // Synchronous only in v1 (no streaming, no async): the verb
            // pipeline's guardrail step must inspect a COMPLETE response before
            // anything downstream may see it, and a streamed answer is by
            // definition visible before it has been validated.
            ->post(self::ENDPOINT, array_filter([
                'model'       => $request->model ?? $this->defaultModel,
                'max_tokens'  => $request->maxTokens,
                'temperature' => $request->temperature,
                'system'      => $request->systemPrompt,
                'messages'    => [['role' => 'user', 'content' => $request->userPrompt]],
            ], fn ($v) => $v !== null && $v !== ''));

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            // Thrown, not returned: AiGateway distinguishes "the model answered"
            // from "the call failed", and records the second as a failed
            // execution rather than as a conclusion.
            throw new RuntimeException(sprintf(
                'anthropic_http_%d: %s', $response->status(), mb_substr($response->body(), 0, 500)
            ));
        }

        $body = $response->json();

        // The Messages API returns content as a list of typed blocks. Only text
        // blocks are joined; anything else is ignored rather than stringified,
        // because a tool-use block rendered as text would be parsed downstream
        // as though the model had answered.
        $text = implode('', array_map(
            fn (array $block) => (string) ($block['text'] ?? ''),
            array_filter($body['content'] ?? [], fn ($b) => ($b['type'] ?? null) === 'text')
        ));

        return new AiResponse(
            content: $text,
            model: (string) ($body['model'] ?? $this->defaultModel),
            inputTokens: isset($body['usage']['input_tokens']) ? (int) $body['usage']['input_tokens'] : null,
            outputTokens: isset($body['usage']['output_tokens']) ? (int) $body['usage']['output_tokens'] : null,
            latencyMs: $latencyMs,
            finishReason: $body['stop_reason'] ?? null,
        );
    }
}
