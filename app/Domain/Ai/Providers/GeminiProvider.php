<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResponse;
use Illuminate\Support\Facades\Http;

final class GeminiProvider implements AiProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $defaultModel = 'gemini-2.5-flash',
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function complete(AiRequest $request): AiResponse
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('gemini_api_key_missing');
        }

        $model = $request->model ?? $this->defaultModel;
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $start = microtime(true);

        $httpResponse = Http::timeout($this->timeoutSeconds)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("{$url}?key={$this->apiKey}", [
                'systemInstruction' => [
                    'parts' => [['text' => $request->systemPrompt]],
                ],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $request->userPrompt]]],
                ],
                'generationConfig' => [
                    'temperature' => $request->temperature,
                    'maxOutputTokens' => $request->maxTokens,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if ($httpResponse->failed()) {
            throw new \RuntimeException(
                'gemini_request_failed: ' . $httpResponse->status() . ' ' . $httpResponse->body()
            );
        }

        $body = $httpResponse->json();
        $content = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return new AiResponse(
            content: $content,
            model: $model,
            inputTokens: $body['usageMetadata']['promptTokenCount'] ?? null,
            outputTokens: $body['usageMetadata']['candidatesTokenCount'] ?? null,
            latencyMs: $latencyMs,
            finishReason: $body['candidates'][0]['finishReason'] ?? null,
        );
    }
}
