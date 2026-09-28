<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Support;

/**
 * What one model call produced, and what it cost. Ported from G2G unchanged.
 */
final class AiCompletion
{
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $latencyMs = 0,
        /** Why the model stopped. `length` / `max_tokens` means the answer was cut off. */
        public readonly ?string $finishReason = null,
    ) {
    }

    public function wasTruncated(): bool
    {
        return in_array(strtolower((string) $this->finishReason), ['length', 'max_tokens'], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'latency_ms' => $this->latencyMs,
            'finish_reason' => $this->finishReason,
            'truncated' => $this->wasTruncated(),
        ];
    }
}
