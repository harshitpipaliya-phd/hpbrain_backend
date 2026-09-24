<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Registry of known models and their capabilities.
 *
 * The registry is seeded from config and can be extended at runtime. A model
 * absent from the registry is treated as incapable of everything except
 * completion.
 */
final class ModelCapabilityRegistry
{
    /** @var array<string, ModelCapability> */
    private static array $models = [];

    public static function register(string $model, array $capabilities, int $maxTokens = 4096, int $contextWindow = 8192, bool $supportsSystemPrompt = true, bool $supportsJsonMode = true, bool $supportsVision = false): void
    {
        self::$models[$model] = new ModelCapability(
            model: $model,
            capabilities: $capabilities,
            maxTokens: $maxTokens,
            contextWindow: $contextWindow,
            supportsSystemPrompt: $supportsSystemPrompt,
            supportsJsonMode: $supportsJsonMode,
            supportsVision: $supportsVision,
        );
    }

    public static function get(string $model): ModelCapability
    {
        return self::$models[$model] ?? new ModelCapability(model: $model);
    }

    public static function hasCapability(string $model, string $capability): bool
    {
        return self::get($model)->has($capability);
    }

    /** @return array<string, ModelCapability> */
    public static function getAllModels(): array
    {
        return self::$models;
    }

    /** @return array<string> */
    public static function getModelsByCapability(string $capability): array
    {
        $result = [];
        foreach (self::$models as $model => $cap) {
            if ($cap->has($capability)) {
                $result[] = $model;
            }
        }
        return $result;
    }
}
