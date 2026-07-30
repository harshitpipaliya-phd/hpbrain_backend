<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * What the Brain asks a model, expressed in the Brain's own terms.
 *
 * ADR-004: the model is addressed through an interface, never a vendor SDK, so
 * it stays swappable. That only holds if the REQUEST is vendor-neutral too — a
 * value object shaped like one provider's payload is a vendor SDK with extra
 * steps.
 *
 * `responseSchema` is not decoration. Every reasoning call in this system must
 * return strict JSON that can be validated before a single field is believed;
 * a request that does not state its expected shape has no way to tell a
 * well-formed answer from a plausible-sounding paragraph.
 */
final class AiRequest
{
    /**
     * @param  array<string, mixed>  $responseSchema  the JSON shape the caller
     *                                                will validate the reply against
     */
    public function __construct(
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly array $responseSchema = [],
        public readonly int $maxTokens = 2048,
        public readonly float $temperature = 0.2,
        public readonly ?string $model = null,
    ) {
    }
}
