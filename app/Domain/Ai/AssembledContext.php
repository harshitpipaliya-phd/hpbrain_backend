<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class AssembledContext
{
    /**
     * @param  array<int, array<string, mixed>>  $citations
     * @param  array<int, array<string, mixed>>  $groundingEvidence
     */
    public function __construct(
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly array $citations = [],
        public readonly array $groundingEvidence = [],
    ) {
    }
}
