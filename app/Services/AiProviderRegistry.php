<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\ModelCapabilityRegistry;

final class AiProviderRegistry
{
    /** @var array<string, array{name: string, class: string, config: array}> */
    private static array $providers = [];

    public static function register(string $name, string $class, array $config = []): void
    {
        self::$providers[$name] = ['name' => $name, 'class' => $class, 'config' => $config];
    }

    public static function get(string $name): ?array
    {
        return self::$providers[$name] ?? null;
    }

    public static function getActive(): ?array
    {
        $active = (string) config('brain.ai.provider', '');

        return $active !== '' ? self::get($active) : null;
    }

    /** @return array<int, string> */
    public static function getFallbackChain(): array
    {
        $chain = (array) config('brain.ai.fallback_chain', []);

        return array_values(array_filter($chain, fn ($p) => self::get($p) !== null));
    }

    /** @return array<string, array{name: string, class: string}> */
    public static function getAllProviders(): array
    {
        $result = [];
        foreach (self::$providers as $name => $provider) {
            $result[$name] = ['name' => $provider['name'], 'class' => $provider['class']];
        }
        return $result;
    }
}
