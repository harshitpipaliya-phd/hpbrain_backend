<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Configuration;

/**
 * The providers HP Brain can be configured to call, and the wire that reaches each.
 *
 * Three wire shapes, where G2G has two:
 *
 *   - `anthropic`         — the Messages API (`POST /v1/messages`, system prompt as
 *                           a top-level field, content blocks back). HP Brain already
 *                           drives it — see App\Domain\Ai\Providers\AnthropicProvider —
 *                           so here it is callable rather than listed-but-refused.
 *   - `gemini`            — Google's `POST /models/{model}:generateContent`.
 *   - `openai_compatible` — chat completions: DeepSeek (HP Brain's RECOMMEND
 *                           provider), OpenRouter, OpenAI, Groq and Mistral.
 *
 * Env credentials and timeouts come from config('brain.ai.*') — the same keys the
 * existing AiGateway reads — so a deployment with ANTHROPIC_API_KEY set works here
 * with nothing saved on the AI Providers screen.
 */
final class ProviderCatalog
{
    public const SHAPE_ANTHROPIC = 'anthropic';

    public const SHAPE_GEMINI = 'gemini';

    public const SHAPE_OPENAI = 'openai_compatible';

    /**
     * @var array<string, array{label:string, shape:string|null, base_url:string, docs:string}>
     */
    private const PROVIDERS = [
        'anthropic' => [
            'label' => 'Anthropic Claude',
            'shape' => self::SHAPE_ANTHROPIC,
            'base_url' => 'https://api.anthropic.com/v1',
            'docs' => 'https://console.anthropic.com/settings/keys',
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'shape' => self::SHAPE_GEMINI,
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'docs' => 'https://aistudio.google.com/apikey',
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'shape' => self::SHAPE_OPENAI,
            // The endpoint App\Domain\Ai\Providers\DeepSeekProvider already calls.
            'base_url' => 'https://api.deepseek.com',
            'docs' => 'https://platform.deepseek.com/api_keys',
        ],
        'openrouter' => [
            'label' => 'OpenRouter',
            'shape' => self::SHAPE_OPENAI,
            'base_url' => 'https://openrouter.ai/api/v1',
            'docs' => 'https://openrouter.ai/keys',
        ],
        'openai' => [
            'label' => 'OpenAI',
            'shape' => self::SHAPE_OPENAI,
            'base_url' => 'https://api.openai.com/v1',
            'docs' => 'https://platform.openai.com/api-keys',
        ],
        'groq' => [
            'label' => 'Groq',
            'shape' => self::SHAPE_OPENAI,
            'base_url' => 'https://api.groq.com/openai/v1',
            'docs' => 'https://console.groq.com/keys',
        ],
        'mistral' => [
            'label' => 'Mistral',
            'shape' => self::SHAPE_OPENAI,
            'base_url' => 'https://api.mistral.ai/v1',
            'docs' => 'https://console.mistral.ai/api-keys',
        ],
    ];

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public function exists(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS);
    }

    public function isDriveable(string $provider): bool
    {
        return $this->shape($provider) !== null;
    }

    public function shape(string $provider): ?string
    {
        return self::PROVIDERS[$provider]['shape'] ?? null;
    }

    public function label(string $provider): string
    {
        return self::PROVIDERS[$provider]['label'] ?? $provider;
    }

    public function baseUrl(string $provider): ?string
    {
        $configured = trim((string) config("brain.ai.{$provider}.base_url", ''));

        return $configured !== '' ? $configured : (self::PROVIDERS[$provider]['base_url'] ?? null);
    }

    /** The hpbrain_ai_api_keys.api_type credentials for this provider are tagged with. */
    public function apiType(string $provider): string
    {
        return $provider;
    }

    /** The environment credential config/brain.php carries for this provider, if any. */
    public function envKey(string $provider): ?string
    {
        $value = trim((string) config("brain.ai.{$provider}.api_key", ''), " \t\n\r\0\x0B'\"");

        return $value !== '' ? $value : null;
    }

    /**
     * The model the environment runs for this provider.
     *
     * config('brain.ai.model') belongs to the configured driver only (AI_PROVIDER);
     * applying it to any other provider would send, say, a Claude model id to
     * DeepSeek.
     */
    public function defaultModel(string $provider): ?string
    {
        if ($provider !== $this->configuredDriver()) {
            return null;
        }

        $value = trim((string) config('brain.ai.model', ''));

        return $value !== '' ? $value : null;
    }

    /** config('brain.ai.provider') when it names a provider here, else null. */
    public function configuredDriver(): ?string
    {
        $driver = trim((string) config('brain.ai.provider', ''));

        return $this->exists($driver) ? $driver : null;
    }

    public function timeout(string $provider): int
    {
        return ((int) config("brain.ai.{$provider}.timeout")) ?: 45;
    }

    /**
     * @return array<int, array{key:string, label:string, driveable:bool, api_type:string, docs:string}>
     */
    public function all(): array
    {
        $out = [];

        foreach (self::PROVIDERS as $key => $provider) {
            $out[] = [
                'key' => $key,
                'label' => $provider['label'],
                'driveable' => $provider['shape'] !== null,
                'api_type' => $this->apiType($key),
                'docs' => $provider['docs'],
            ];
        }

        return $out;
    }
}
