<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class CitationVerificationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly array $verified = [],
        public readonly array $unverified = [],
    ) {
    }
}
