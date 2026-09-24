<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class QuotaResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $used,
        public readonly int $remaining,
        public readonly string $resetPeriod,
    ) {
    }
}
