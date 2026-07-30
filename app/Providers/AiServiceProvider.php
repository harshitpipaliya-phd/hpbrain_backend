<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\Providers\AnthropicProvider;
use App\Domain\Ai\Providers\NullAiProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Resolves the configured model driver (ADR-004).
 *
 * THIS IS THE ONLY PLACE THE APPLICATION CHOOSES A VENDOR. Everything else
 * type-hints App\Domain\Ai\AiProvider, so adding a provider means writing one
 * class in Domain/Ai/Providers and adding one arm to the match below.
 *
 * An unknown name — including a typo in AI_PROVIDER — resolves to the null
 * driver rather than throwing. A boot-time exception would take the whole API
 * down over a misconfigured optional feature; the null driver keeps the API up
 * and, because AiGateway::isConfigured() refuses to treat it as real outside
 * local/testing, every reasoning call then answers UNDETERMINED instead of
 * inventing something. Failing to an honest "I don't know" beats failing loud
 * here and beats failing silently to fiction.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiProvider::class, function () {
            return match ((string) config('brain.ai.provider', '')) {
                'anthropic' => new AnthropicProvider(
                    apiKey: (string) config('brain.ai.anthropic.api_key', ''),
                    defaultModel: (string) config('brain.ai.model', 'claude-sonnet-5'),
                    timeoutSeconds: (int) config('brain.ai.anthropic.timeout', 30),
                ),
                default => new NullAiProvider(),
            };
        });
    }
}
