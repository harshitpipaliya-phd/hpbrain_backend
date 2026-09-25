<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Configuration;

use App\Domain\AiIntelligence\Support\ApiKeyVault;
use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One answer to "what should this capability call", for every AI capability.
 *
 * The precedence is G2G's, narrowest first:
 *
 *   1. `module`          — this tenant saved a row for this capability.
 *   2. `module_platform` — the platform saved a row for this capability.
 *   3. `pool`            — this tenant's key for the configured driver.
 *   4. `pool_platform`   — the platform's key for the configured driver.
 *   5. `env`             — the driver's key from config('brain.ai.*').
 *   6. `config`          — provider and model resolved, no credential. Returned
 *                          rather than thrown so the caller can say "not
 *                          configured" instead of "something failed".
 *
 * Only a capability row may choose the provider. With none, the provider is
 * config('brain.ai.provider') — the driver the existing AiGateway uses — falling
 * back to Anthropic, HP Brain's primary vendor, when that is unset.
 *
 * The tenant comes from the caller's token via AiIntelligenceScope; nothing here
 * reads the request.
 */
final class AiConfigurationResolver
{
    public function __construct(
        private readonly ProviderCatalog $providers,
        private readonly ModelCatalog $models,
        private readonly AiModuleRegistry $modules,
        private readonly ProviderKeyResolver $keys,
        private readonly ApiKeyVault $vault,
    ) {
    }

    public function resolve(?string $moduleKey, string $tenantId): ResolvedAiConfiguration
    {
        $moduleKey = $moduleKey !== null && $this->modules->exists($moduleKey) ? $moduleKey : null;

        $row = $this->findModuleRow($moduleKey, $tenantId);

        if ($row !== null) {
            $provider = $this->providerFromRow($row);

            return new ResolvedAiConfiguration(
                provider: $provider,
                model: $this->modelFor($provider, $row->model ?? null, $tenantId),
                apiKey: $row->plain_key,
                source: $row->source,
                keyId: (string) $row->id,
                scope: Platform::isPlatform($row) ? 'platform' : 'institute',
                maxOutputTokens: $this->maxTokens($row->api_limit ?? null),
            );
        }

        $provider = $this->defaultProvider();

        $key = $this->keys->resolve(
            $this->providers->apiType($provider),
            $tenantId,
            $this->providers->envKey($provider),
        );

        return new ResolvedAiConfiguration(
            provider: $provider,
            model: $this->modelFor($provider, null, $tenantId),
            apiKey: $key['api_key'] ?? null,
            source: $this->sourceFor($key),
            keyId: $key['id'] ?? null,
            scope: $key['scope'] ?? 'config',
            maxOutputTokens: $this->maxTokens($key['api_limit'] ?? null),
        );
    }

    /**
     * What every capability resolves to right now, for the admin screen's list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overview(string $tenantId): array
    {
        $out = [];

        foreach ($this->modules->all() as $module) {
            $config = $this->resolve($module['key'], $tenantId);

            $out[] = [
                'module' => $module['key'],
                'module_label' => $module['label'],
                'description' => $module['description'],
                'wired' => $module['wired'],
                'provider' => $config->provider,
                'provider_label' => $this->providers->label($config->provider),
                'model' => $config->model,
                'source' => $config->source,
                'scope' => $config->scope,
                'key_id' => $config->keyId,
                'has_key' => $config->hasKey(),
                'driveable' => $this->providers->isDriveable($config->provider),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed>|null $key */
    private function sourceFor(?array $key): string
    {
        return match ($key['scope'] ?? null) {
            'institute' => 'pool',
            'platform' => 'pool_platform',
            'env' => 'env',
            default => 'config',
        };
    }

    /**
     * The capability's own row — this tenant's first, then the platform's.
     * Two ordered lookups rather than one clever query: this is the code read
     * during an incident.
     */
    private function findModuleRow(?string $moduleKey, string $tenantId): ?object
    {
        if ($moduleKey === null || ! Schema::hasTable('hpbrain_ai_api_keys')) {
            return null;
        }

        foreach ([['module', $tenantId], ['module_platform', Platform::TENANT]] as [$source, $owner]) {
            try {
                $rows = DB::table('hpbrain_ai_api_keys')
                    ->where('status', 1)
                    ->where('ai_module', $moduleKey)
                    ->where('tenant_id', $owner)
                    ->orderByDesc('created_date')
                    ->get();
            } catch (Throwable) {
                return null;
            }

            foreach ($rows as $row) {
                $plain = $this->vault->open($row->api_key ?? null);

                if ($plain === null) {
                    continue;
                }

                $row->source = $source;
                $row->plain_key = $plain;

                return $row;
            }
        }

        return null;
    }

    private function providerFromRow(object $row): string
    {
        $apiType = trim((string) ($row->api_type ?? ''));

        if ($apiType !== '' && $this->providers->exists($apiType)) {
            return $apiType;
        }

        foreach ($this->providers->keys() as $provider) {
            if (strcasecmp($this->providers->apiType($provider), $apiType) === 0) {
                return $provider;
            }
        }

        return $this->defaultProvider();
    }

    /**
     * The saved model, else the model the environment runs for this provider
     * (AI_MODEL, when this provider is AI_PROVIDER), else the catalogue's first.
     *
     * G2G tries the catalogue before config. HP Brain puts config first: AI_MODEL
     * is what the deployment's AiGateway already runs and is priced for, and the
     * catalogue's first row can be a far more expensive model.
     */
    private function modelFor(string $provider, ?string $saved, string $tenantId): ?string
    {
        $saved = trim((string) $saved);

        if ($saved !== '') {
            return $saved;
        }

        return $this->providers->defaultModel($provider)
            ?? $this->models->defaultFor($provider, $tenantId);
    }

    /** `api_limit` doubles as the per-key output-token ceiling, as in G2G. */
    private function maxTokens(mixed $limit): ?int
    {
        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
    }

    public function defaultProvider(): string
    {
        return $this->providers->configuredDriver() ?? 'anthropic';
    }
}
