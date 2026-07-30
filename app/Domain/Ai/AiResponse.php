<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * What a model returned, in the Brain's terms.
 *
 * The token counts and latency are not telemetry niceties — they are the
 * substance of the hpbrain_ai_executions row that Invariant 7 requires, and a
 * provider that cannot report them produces an execution record that cannot
 * answer "what did this cost and how long did it take?". Nullable rather than
 * zero-defaulted, because an unknown token count is not a token count of zero.
 */
final class AiResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $latencyMs = null,
        public readonly ?string $finishReason = null,
    ) {
    }

    /**
     * The content parsed as strict JSON, or null.
     *
     * Null means "this is not the JSON that was asked for" and callers must
     * treat it as UNDETERMINED. Returning the raw text as a conclusion is the
     * failure this whole layer exists to prevent: a paragraph the model wrote
     * instead of the object it was told to produce is not a lower-quality
     * answer, it is a different kind of thing.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $trimmed = trim($this->content);

        // Models routinely wrap JSON in a ```json fence even when told not to.
        // Stripping a fence is not a fallback — the payload inside must still
        // parse strictly, and anything else still returns null.
        if (str_starts_with($trimmed, '```')) {
            $trimmed = trim(preg_replace('/^```(?:json)?|```$/m', '', $trimmed) ?? '');
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }
}
