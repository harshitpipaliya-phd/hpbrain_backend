<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class QuotaCheckResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly ?QuotaResult $quota = null,
    ) {
    }
}
