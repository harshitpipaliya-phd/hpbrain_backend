<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Capabilities a model may expose.
 *
 * The capability set is intentionally flat: a model either supports something
 * or it does not, and the gateway chooses providers based on those facts before
 * it ever calls complete().
 */
final class ModelCapability
{
    public function __construct(
        public readonly string $model,
        public readonly array $capabilities = [],
        public readonly int $maxTokens = 4096,
        public readonly int $contextWindow = 8192,
        public readonly bool $supportsSystemPrompt = true,
        public readonly bool $supportsJsonMode = true,
        public readonly bool $supportsVision = false,
    ) {
    }

    public function has(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
