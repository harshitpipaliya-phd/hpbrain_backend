# AI PROVIDER ABSTRACTION

## Overview

The AI Provider Abstraction layer ensures the Brain can work with any LLM provider without changing business logic. This is enforced by ADR-004.

## Architecture

### AiProvider Interface

```php
interface AiProvider
{
    public function complete(AiRequest $request): AiResponse;
}
```

Only ONE method. Everything the Brain needs from a model is "given these instructions and this grounding, return this JSON".

### Provider Isolation Rule

NO vendor SDK may appear outside `app/Domain/Ai/Providers/`. Business logic imports `AiRequest` and `AiResponse` and nothing else.

### AiGateway

The ONLY sanctioned way to call a model. It:
1. Records execution BEFORE returning to caller
2. Records execution on failure too
3. Estimates cost
4. Handles retries

### AiProviderRegistry

Static registry of known models and their capabilities:
- `register(string $model, array $capabilities)`
- `get(string $model): ModelCapability`
- `hasCapability(string $model, string $capability): bool`
- `getModelsByCapability(string $capability): array`

### ModelCapabilities

Each model has:
- `capabilities`: grounded_chat, extraction, summarization, classification, recommendation, explanation, embedding, reranking
- `maxTokens`, `contextWindow`
- `supportsSystemPrompt`, `supportsJsonMode`, `supportsVision`

## Provider Configuration

Providers are configured in `config/brain.php`:
```php
'ai' => [
    'provider' => env('AI_PROVIDER', ''),
    'model'    => env('AI_MODEL', 'claude-sonnet-5'),
    'fallback_chain' => ['anthropic', 'openai'],
]
```

## Fallback Chain

When a provider fails, the system falls back to the next provider in the chain. Configured via:
- `AiProviderRegistry::getFallbackChain()`
- Database `hpbrain_ai_fallback_chains`
