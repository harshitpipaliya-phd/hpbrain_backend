<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class GroundedResponse
{
    /**
     * @param  array<int, array<string, mixed>>  $claims
     * @param  array<int, string>  $gaps
     */
    public function __construct(
        public readonly string $content,
        public readonly array $claims = [],
        public readonly array $gaps = [],
        public readonly array $citations = [],
    ) {
    }
}
