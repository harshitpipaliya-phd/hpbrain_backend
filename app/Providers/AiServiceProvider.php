<?php
declare(strict_types=1);
namespace App\Providers;
use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiProvider;
use App\Domain\Ai\Providers\AnthropicProvider;
use App\Domain\Ai\Providers\DeepSeekProvider;
use App\Domain\Ai\Providers\GeminiProvider;
use App\Domain\Ai\Providers\NullAiProvider;
use App\Domain\Ingestion\DatasetAnalysisService;
use App\Repositories\ImportJobRepository;
use Illuminate\Support\ServiceProvider;

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
                'gemini' => new GeminiProvider(
                    apiKey: (string) config('brain.ai.gemini.api_key', ''),
                    defaultModel: (string) config('brain.ai.model', 'gemini-2.5-flash'),
                    timeoutSeconds: (int) config('brain.ai.gemini.timeout', 30),
                ),
                'deepseek' => new DeepSeekProvider(
                    apiKey: (string) config('brain.ai.deepseek.api_key', ''),
                    defaultModel: (string) config('brain.ai.model', 'deepseek-v4-flash'),
                    timeoutSeconds: (int) config('brain.ai.deepseek.timeout', 30),
                ),
                default => new NullAiProvider(),
            };
        });

        $this->app->singleton(DatasetAnalysisService::class, function ($app) {
            return new DatasetAnalysisService(
                $app->make(AiGateway::class),
                $app->make(ImportJobRepository::class),
            );
        });
    }
}
