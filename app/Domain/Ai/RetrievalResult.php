<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class RetrievalResult
{
    /**
     * @param  array<int, array<string, mixed>>  $documents
     */
    public function __construct(
        public readonly array $documents = [],
        public readonly int $total = 0,
        public readonly array $sources = [],
    ) {
    }
}
