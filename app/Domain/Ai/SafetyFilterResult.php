<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class SafetyFilterResult
{
    public function __construct(
        public readonly bool $safe,
        public readonly string $content,
        public readonly array $flags = [],
        public readonly array $actions = [],
    ) {
    }
}
