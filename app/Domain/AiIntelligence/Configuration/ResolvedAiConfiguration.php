<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Configuration;

/**
 * What one AI capability should call, and where that answer came from.
 *
 * `source` is which precedence rule won — `module` | `module_platform` | `pool`
 * | `pool_platform` | `env` | `config` — and is shown on the admin screen beside
 * each capability. There is deliberately no method that returns the key inside an
 * array: `toArray()` is what gets logged, and a credential must not ride along.
 */
final class ResolvedAiConfiguration
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $model,
        public readonly ?string $apiKey,
        public readonly string $source,
        public readonly ?string $keyId = null,
        /** `institute` | `platform` | `env` | `config` */
        public readonly string $scope = 'config',
        public readonly ?int $maxOutputTokens = null,
    ) {
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== null && trim($this->apiKey) !== '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'source' => $this->source,
            'scope' => $this->scope,
            'key_id' => $this->keyId,
            'has_key' => $this->hasKey(),
        ];
    }
}
