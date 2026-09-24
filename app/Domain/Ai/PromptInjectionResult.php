<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class PromptInjectionResult
{
    public function __construct(
        public readonly bool $detected,
        public readonly array $patterns = [],
        public readonly string $severity = 'low',
    ) {
    }
}
