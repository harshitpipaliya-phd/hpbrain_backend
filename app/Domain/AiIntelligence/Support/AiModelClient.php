<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Support;

use App\Domain\AiIntelligence\Configuration\AiConfigurationResolver;
use App\Domain\AiIntelligence\Configuration\AiModuleRegistry;
use App\Domain\AiIntelligence\Configuration\ProviderCatalog;
use App\Domain\AiIntelligence\Configuration\ResolvedAiConfiguration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The one place the AI & Intelligence layer calls a model.
 *
 * Ported from G2G's AiModelClient. Every call resolves its provider, model and
 * credential through AiConfigurationResolver (so saving a configuration changes
 * what the next call does), is quota-checked before it reaches the network, and
 * is metered on every outcome — success, provider failure and quota refusal.
 *
 * Three wires, selected by ProviderCatalog::shape():
 *
 *   anthropic          POST {base}/messages — the same wire, headers and pinned API
 *                      version as App\Domain\Ai\Providers\AnthropicProvider.
 *   gemini             POST {base}/models/{model}:generateContent, key in a header.
 *   openai_compatible  POST {base}/chat/completions, bearer token.
 *
 * This class does not replace the existing AiGateway (ADR-004): the intelligence
 * loop's verbs still call their provider through it. This client serves the
 * console's own capabilities — the assistant and AI Evaluation.
 */
final class AiModelClient
{
    /** Pinned, as in AnthropicProvider: an unpinned version changes the response shape. */
    private const ANTHROPIC_VERSION = '2023-06-01';

    public function __construct(
        private readonly AiConfigurationResolver $configuration,
        private readonly ProviderCatalog $providers,
        private readonly AiModuleRegistry $modules,
        private readonly AiUsageMeter $meter,
    ) {
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{max_tokens?: int, temperature?: float, json?: bool, related_type?: string, related_id?: string, user_id?: string}  $options
     */
    public function complete(string $moduleKey, array $messages, array $options, string $tenantId): AiCompletion
    {
        $config = $this->configuration->resolve($moduleKey, $tenantId);

        if (! $this->providers->isDriveable($config->provider)) {
            throw AiNotConfiguredException::providerNotDriveable($this->providers->label($config->provider));
        }

        if (! $config->hasKey()) {
            throw AiNotConfiguredException::forModule(
                $this->modules->label($moduleKey),
                $this->providers->label($config->provider)
            );
        }

        // BEFORE the network. A quota checked afterwards is an accounting entry.
        $refusal = $this->meter->guard($moduleKey, $tenantId);

        if ($refusal !== null) {
            $this->meter->record($moduleKey, $config, $tenantId, 0, 0, null, [
                'outcome' => AiUsageMeter::OUTCOME_REFUSED,
                'error' => $refusal,
            ] + $this->meta($options));

            throw new AiQuotaExceededException($refusal);
        }

        // The saved per-key ceiling wins over the caller's preference.
        $maxTokens = $config->maxOutputTokens ?: (int) ($options['max_tokens'] ?? 2048);

        $started = microtime(true);

        try {
            $completion = match ($this->providers->shape($config->provider)) {
                ProviderCatalog::SHAPE_ANTHROPIC => $this->callAnthropic($config, $messages, $options, $maxTokens),
                ProviderCatalog::SHAPE_GEMINI => $this->callGemini($config, $messages, $options, $maxTokens),
                default => $this->callOpenAiCompatible($config, $messages, $options, $maxTokens),
            };
        } catch (Throwable $exception) {
            // Metered on the way out: a provider that rejects a request has usually
            // still charged for the prompt.
            $this->meter->record($moduleKey, $config, $tenantId, 0, 0, $this->elapsed($started), [
                'outcome' => AiUsageMeter::OUTCOME_FAILED,
                'error' => $exception->getMessage(),
            ] + $this->meta($options));

            throw $exception;
        }

        $latencyMs = $this->elapsed($started);

        $this->meter->record(
            $moduleKey,
            $config,
            $tenantId,
            $completion['input_tokens'],
            $completion['output_tokens'],
            $latencyMs,
            ['finish_reason' => $completion['finish_reason']] + $this->meta($options)
        );

        return new AiCompletion(
            text: $completion['text'],
            provider: $config->provider,
            model: $config->model,
            inputTokens: $completion['input_tokens'],
            outputTokens: $completion['output_tokens'],
            latencyMs: $latencyMs,
            finishReason: $completion['finish_reason'],
        );
    }

    /** @param array<string, mixed> $options */
    private function meta(array $options): array
    {
        return array_filter([
            'related_type' => $options['related_type'] ?? null,
            'related_id' => $options['related_id'] ?? null,
            'user_id' => $options['user_id'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * The Anthropic Messages API.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{text: string, input_tokens: int, output_tokens: int, finish_reason: ?string}
     */
    private function callAnthropic(ResolvedAiConfiguration $config, array $messages, array $options, int $maxTokens): array
    {
        [$system, $turns] = $this->split($messages);

        if ($config->model === null || $config->model === '') {
            throw new RuntimeException('No model is configured for Anthropic Claude. Add one under AI & Intelligence → Model Management.');
        }

        $payload = array_filter([
            'model' => $config->model,
            'max_tokens' => $maxTokens,
            'temperature' => $options['temperature'] ?? null,
            'system' => $system,
            'messages' => array_map(
                fn (array $turn) => ['role' => $turn['role'], 'content' => $turn['content']],
                $turns
            ),
        ], fn ($value) => $value !== null && $value !== '');

        $base = rtrim((string) $this->providers->baseUrl($config->provider), '/');

        $response = Http::timeout($this->providers->timeout($config->provider))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-api-key' => (string) $config->apiKey,
                'anthropic-version' => self::ANTHROPIC_VERSION,
            ])
            ->post("{$base}/messages", $payload);

        $this->guard($response, $this->providers->label($config->provider));

        $blocks = $response->json('content') ?? [];
        $text = implode('', array_map(
            fn ($block) => is_array($block) && ($block['type'] ?? null) === 'text' ? (string) ($block['text'] ?? '') : '',
            is_array($blocks) ? $blocks : []
        ));

        return [
            'text' => trim($text),
            'input_tokens' => (int) ($response->json('usage.input_tokens') ?? 0),
            'output_tokens' => (int) ($response->json('usage.output_tokens') ?? 0),
            'finish_reason' => $response->json('stop_reason'),
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{text: string, input_tokens: int, output_tokens: int, finish_reason: ?string}
     */
    private function callGemini(ResolvedAiConfiguration $config, array $messages, array $options, int $maxTokens): array
    {
        [$system, $turns] = $this->split($messages);

        $payload = [
            'contents' => array_map(fn (array $turn) => [
                'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn['content']]],
            ], $turns),
            'generationConfig' => array_filter([
                'maxOutputTokens' => $maxTokens,
                'temperature' => $options['temperature'] ?? null,
                'responseMimeType' => ! empty($options['json']) ? 'application/json' : null,
            ], fn ($value) => $value !== null),
        ];

        if ($system !== null) {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $base = rtrim((string) $this->providers->baseUrl($config->provider), '/');
        $model = $config->model ?: 'gemini-2.5-flash';

        $response = Http::timeout($this->providers->timeout($config->provider))
            ->acceptJson()
            ->asJson()
            // A header, not the query string: URLs reach access and proxy logs.
            ->withHeaders(['x-goog-api-key' => (string) $config->apiKey])
            ->post("{$base}/models/{$model}:generateContent", $payload);

        $this->guard($response, $this->providers->label($config->provider));

        $parts = $response->json('candidates.0.content.parts') ?? [];
        $text = implode('', array_map(fn ($part) => (string) ($part['text'] ?? ''), is_array($parts) ? $parts : []));

        return [
            'text' => trim($text),
            'input_tokens' => (int) ($response->json('usageMetadata.promptTokenCount') ?? 0),
            'output_tokens' => (int) ($response->json('usageMetadata.candidatesTokenCount') ?? 0),
            'finish_reason' => $response->json('candidates.0.finishReason'),
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{text: string, input_tokens: int, output_tokens: int, finish_reason: ?string}
     */
    private function callOpenAiCompatible(ResolvedAiConfiguration $config, array $messages, array $options, int $maxTokens): array
    {
        $payload = array_filter([
            'model' => $config->model,
            'messages' => array_values(array_filter(
                $messages,
                fn ($message) => trim((string) ($message['content'] ?? '')) !== ''
            )),
            'max_tokens' => $maxTokens,
            'temperature' => $options['temperature'] ?? null,
            'stream' => false,
        ], fn ($value) => $value !== null);

        if (! empty($options['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $base = rtrim((string) $this->providers->baseUrl($config->provider), '/');

        $response = Http::timeout($this->providers->timeout($config->provider))
            ->withToken((string) $config->apiKey)
            ->acceptJson()
            ->asJson()
            ->post("{$base}/chat/completions", $payload);

        $this->guard($response, $this->providers->label($config->provider));

        return [
            'text' => trim((string) ($response->json('choices.0.message.content') ?? '')),
            'input_tokens' => (int) ($response->json('usage.prompt_tokens') ?? 0),
            'output_tokens' => (int) ($response->json('usage.completion_tokens') ?? 0),
            'finish_reason' => $response->json('choices.0.finish_reason'),
        ];
    }

    /**
     * System text and the user/assistant turns, with consecutive same-role turns
     * merged. Anthropic and Gemini both expect the conversation to alternate, and a
     * transcript whose failed assistant turns were skipped can hold two user turns
     * in a row. Multiple system messages are concatenated, never overwritten.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{0: ?string, 1: array<int, array{role: string, content: string}>}
     */
    private function split(array $messages): array
    {
        $system = null;
        $turns = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $text = trim((string) ($message['content'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($role === 'system') {
                $system = $system === null ? $text : $system . "\n\n" . $text;

                continue;
            }

            $role = $role === 'assistant' ? 'assistant' : 'user';
            $last = count($turns) - 1;

            if ($last >= 0 && $turns[$last]['role'] === $role) {
                $turns[$last]['content'] .= "\n\n" . $text;

                continue;
            }

            $turns[] = ['role' => $role, 'content' => $text];
        }

        // A conversation must open with the user.
        if ($turns !== [] && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        return [$system, $turns];
    }

    /**
     * The provider's own message is surfaced (it is usually the diagnosis); the body
     * is not returned wholesale because it can echo the tenant's prompt.
     */
    private function guard(Response $response, string $providerLabel): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message')
            ?? $response->json('message')
            ?? $response->json('error');

        throw new RuntimeException(sprintf(
            '%s refused the request (HTTP %d)%s',
            $providerLabel,
            $response->status(),
            is_string($message) && $message !== '' ? ': ' . mb_substr($message, 0, 500) : '.'
        ));
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
