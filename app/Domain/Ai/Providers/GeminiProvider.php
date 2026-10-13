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
        private readonly string $defaultEmbeddingModel = 'text-embedding-004',
    ) {
    }

    /**
     * Built straight from config rather than resolved through the
     * AiProvider container binding, which is deliberate: AI_PROVIDER picks
     * which provider handles chat COMPLETIONS (today 'deepseek' in this
     * installation's .env), but embeddings always go to Gemini regardless
     * of that choice — RAG retrieval (Embeddings::CURRENT_MODEL /
     * KnowledgeIngestionService / RetrievalService) has no dependency on
     * which model answers a user's question. Using a static factory here
     * means that stays true without touching AiServiceProvider's container
     * bindings at all.
     */
    public static function fromConfig(): self
    {
        return new self(
            apiKey: (string) config('brain.ai.gemini.api_key', ''),
            defaultModel: (string) config('brain.ai.model', 'gemini-2.5-flash'),
            timeoutSeconds: (int) config('brain.ai.gemini.timeout', 30),
            defaultEmbeddingModel: (string) config('brain.ai.gemini.embedding_model', 'text-embedding-004'),
        );
    }

    /**
     * One chunk of text -> one vector, via Gemini's embedContent endpoint.
     *
     * Called once per chunk by KnowledgeIngestionService and once per query
     * by RetrievalService — no batching in this v1 (Gemini's
     * batchEmbedContents exists and would cut request count for a large
     * ingest, but per-chunk calls are what the task asked for, and batching
     * is a straightforward later optimization, not a correctness concern).
     *
     * @return array<int, float>
     */
    public function embed(string $text, ?string $model = null): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('gemini_api_key_missing');
        }

        $model = $model ?? $this->defaultEmbeddingModel;
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent";

        $httpResponse = Http::timeout($this->timeoutSeconds)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("{$url}?key={$this->apiKey}", [
                'content' => ['parts' => [['text' => $text]]],
            ]);

        if ($httpResponse->failed()) {
            throw new \RuntimeException(
                'gemini_embed_failed: ' . $httpResponse->status() . ' ' . $httpResponse->body()
            );
        }

        $values = $httpResponse->json('embedding.values');

        if (! is_array($values)) {
            throw new \RuntimeException('gemini_embed_no_values: ' . $httpResponse->body());
        }

        return array_map('floatval', $values);
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
