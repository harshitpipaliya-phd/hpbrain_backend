<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Configuration\AiConfigurationResolver;
use App\Domain\AiIntelligence\Configuration\AiModuleRegistry;
use App\Domain\AiIntelligence\Configuration\ModelCatalog;
use App\Domain\AiIntelligence\Configuration\ModuleModelBindings;
use App\Domain\AiIntelligence\Configuration\ProviderCatalog;
use App\Domain\AiIntelligence\Configuration\ResolvedAiConfiguration;
use App\Domain\AiIntelligence\Stack\AiStackModules;
use App\Domain\AiIntelligence\Support\AiAuditLogger;
use App\Domain\AiIntelligence\Support\AiIntelligenceScope;
use App\Domain\AiIntelligence\Support\ApiKeyVault;
use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * An HP Brain area's own model configuration, managed inside that area — the AI Stack
 * Models tab. Ported from G2G's AiModuleModelController, same response keys.
 *
 * It reads and writes hpbrain_ai_module_model_bindings, keyed on the PRODUCT module.
 * The central console keeps writing hpbrain_ai_api_keys, keyed on the AI CAPABILITY,
 * and the two never touch the same row. A module with no binding inherits what the
 * centre decided; a module with one overrides it for itself — through
 * AiConfigurationResolver's step 0, which applies only to a caller that names the
 * product module.
 *
 * `wired` IS HONEST: AskPipeline passes the module a question was asked from, so the
 * `conversational_ai` row is `wired: true` — a choice saved for it is what that
 * module's assistant questions resolve to. The other consumers still reach their
 * provider through the existing AiGateway without naming a product module, so their
 * rows report `wired: false` — stored and shown, not yet read.
 *
 * Everything is written against the caller's own tenant. A tenant cannot change
 * another tenant's module, nor the platform's ('*') default.
 */
final class AiStackModelController extends AiIntelligenceController
{
    public function __construct(
        private readonly ModuleModelBindings $bindings,
        private readonly AiModuleRegistry $capabilities,
        private readonly ProviderCatalog $providers,
        private readonly ModelCatalog $models,
        private readonly AiConfigurationResolver $resolver,
        private readonly AiStackModules $modules,
        private readonly AiAuditLogger $audit,
        private readonly ApiKeyVault $vault,
    ) {
    }

    /** What this module's AI currently runs on, and what it could run on. */
    public function index(Request $request, string $module): JsonResponse
    {
        try {
            $tenant = $this->scope($request)->tenantId;

            if (! $this->modules->exists($module, $tenant)) {
                return $this->failure('That module is not one this organisation has.', 404);
            }

            $saved = $this->bindings->forModule($module, $tenant);
            $rows = [];

            foreach ($this->capabilitiesFor($module, $tenant) as $capability) {
                $key = $capability['key'];
                $binding = $saved[$key] ?? null;

                // What the next call in this module would ACTUALLY use, resolved through
                // the same code the call itself runs.
                $effective = $this->resolver->resolve($key, $tenant, $module);

                $rows[] = [
                    'capability' => $key,
                    'label' => $capability['label'],
                    'description' => $capability['description'],
                    'wired' => $capability['wired'],
                    'binding' => $binding === null ? null : $this->presentBinding($binding),
                    'effective' => $this->presentEffective($effective),
                ];
            }

            return $this->success('Module model configuration resolved.', [
                'module' => ['key' => $module],
                'rows' => $rows,
                'providers' => $this->providerOptions($tenant),
                // The key itself is never returned — only enough to choose one.
                'credentials' => $this->credentialOptions($module, $tenant),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Save this module's choice for one capability. */
    public function update(Request $request, string $module): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenant = $scope->tenantId;

            if (! $this->modules->exists($module, $tenant)) {
                return $this->failure('That module is not one this organisation has.', 404);
            }

            $data = $request->validate([
                'capability' => ['required', 'string', 'max:100'],
                'provider' => ['required', 'string', 'max:60'],
                'model' => ['nullable', 'string', 'max:190'],
                'api_key_id' => ['nullable', 'string', 'max:36'],
                'max_output_tokens' => ['nullable', 'integer', 'min:1', 'max:200000'],
                'status' => ['nullable', 'boolean'],
            ]);

            $capability = (string) $data['capability'];

            if (! $this->capabilities->exists($capability)) {
                return $this->failure('That is not an AI capability this platform has.', 422);
            }

            if (! $this->appliesTo($module, $capability, $tenant)) {
                return $this->failure('This module does not use that capability, so configuring it would change nothing.', 422);
            }

            if (! $this->providers->exists($data['provider'])) {
                return $this->failure('That is not a provider this platform can call.', 422);
            }

            // A credential must be one this tenant can actually see — an id typed into
            // the payload must not bind another tenant's key.
            if (($data['api_key_id'] ?? null) !== null && ! $this->credentialVisible((string) $data['api_key_id'], $tenant)) {
                return $this->failure('That credential is not one this organisation can use.', 422);
            }

            $saved = $this->bindings->save($module, $capability, $tenant, $data, $scope->userId);

            if ($saved === null) {
                return $this->failure('Module model bindings are not available on this deployment.', 503);
            }

            $this->audit->record('ai.module_model.saved', $scope, [
                'related_type' => 'hpbrain_ai_module_model_bindings',
                'related_id' => (string) $saved->id,
                'message' => sprintf('%s in %s set to %s%s.', $capability, $module, $data['provider'], isset($data['model']) ? ' / ' . $data['model'] : ''),
            ]);

            return $this->success('This module will use that model.', [
                'capability' => $capability,
                'binding' => $this->presentBinding($saved),
                'effective' => $this->presentEffective($this->resolver->resolve($capability, $tenant, $module)),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Return this module to whatever the rest of the configuration decides. */
    public function destroy(Request $request, string $module): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenant = $scope->tenantId;

            if (! $this->modules->exists($module, $tenant)) {
                return $this->failure('That module is not one this organisation has.', 404);
            }

            $capability = trim((string) $request->query('capability', (string) $request->input('capability', '')));

            if ($capability === '' || ! $this->capabilities->exists($capability)) {
                return $this->failure('Name the capability to clear.', 422);
            }

            // Only this tenant's own row; a platform default is never cleared from here.
            $cleared = $this->bindings->clear($module, $capability, $tenant);

            if ($cleared) {
                $this->audit->record('ai.module_model.cleared', $scope, [
                    'related_type' => 'hpbrain_ai_module_model_bindings',
                    'message' => sprintf('%s in %s returned to the shared configuration.', $capability, $module),
                ]);
            }

            return $this->success(
                $cleared
                    ? 'This module is back on the configuration the rest of the organisation uses.'
                    : 'This module had no choice of its own to clear.',
                [
                    'capability' => $capability,
                    'cleared' => $cleared,
                    'effective' => $this->presentEffective($this->resolver->resolve($capability, $tenant, $module)),
                ]
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Add a credential — provider, model and API key — from inside this module and use
     * it here immediately. The row is tagged `ai_module` = the capability and
     * `tenant_id` = the caller's tenant, never the platform. The key is sealed with
     * ApiKeyVault like every hpbrain_ai_api_keys row (G2G stored plaintext).
     */
    public function storeCredential(Request $request, string $module): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenant = $scope->tenantId;

            if (! $this->modules->exists($module, $tenant)) {
                return $this->failure('That module is not one this organisation has.', 404);
            }

            if (! Schema::hasTable('hpbrain_ai_api_keys')) {
                return $this->failure('AI credentials are not available on this deployment.', 503);
            }

            $data = $request->validate([
                'capability' => ['required', 'string', 'max:100'],
                'provider' => ['required', 'string', Rule::in($this->providers->keys())],
                'model' => ['required', 'string', 'max:120'],
                'model_label' => ['nullable', 'string', 'max:120'],
                'api_key' => ['required', 'string', 'min:8', 'max:4096'],
                'account_email' => ['nullable', 'email', 'max:191'],
                'api_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
                'max_output_tokens' => ['nullable', 'integer', 'min:1', 'max:200000'],
            ]);

            $capability = (string) $data['capability'];

            if (! $this->capabilities->exists($capability)) {
                return $this->failure('That is not an AI capability this platform has.', 422);
            }

            if (! $this->appliesTo($module, $capability, $tenant)) {
                return $this->failure('This module does not use that capability, so adding a model for it would change nothing.', 422);
            }

            $provider = $data['provider'];

            if (! $this->providers->isDriveable($provider)) {
                return $this->failure(sprintf('%s is not callable from this platform yet.', $this->providers->label($provider)), 422);
            }

            $model = trim($data['model']);
            $apiType = $this->providers->apiType($provider);

            $existing = DB::table('hpbrain_ai_api_keys')
                ->where('ai_module', $capability)
                ->where('api_type', $apiType)
                ->where('tenant_id', $tenant)
                ->first();

            if ($existing !== null) {
                return $this->failure(
                    'This module already has a credential of its own for that provider. Edit it instead of adding another.',
                    409
                );
            }

            $modelId = $this->ensureModelCatalogued($provider, $model, $data['model_label'] ?? null, $scope);

            $now = Platform::now();
            $credentialId = Platform::id();

            DB::table('hpbrain_ai_api_keys')->insert([
                'id' => $credentialId,
                'tenant_id' => $tenant,
                'ai_module' => $capability,
                'api_type' => $apiType,
                'model' => $model,
                'api_key' => $this->vault->seal(trim($data['api_key'])),
                'account_email' => $data['account_email'] ?? null,
                'api_limit' => isset($data['api_limit']) ? (string) $data['api_limit'] : null,
                'status' => 1,
                'created_by' => $scope->userId,
                'created_date' => $now,
                'updated_date' => $now,
            ]);

            $this->audit->record('ai.module_model.credential_created', $scope, [
                'subject_entity_key' => 'ai_module',
                'related_type' => 'hpbrain_ai_api_keys',
                'related_id' => $credentialId,
                'message' => sprintf('A %s credential for %s was added in %s.', $this->providers->label($provider), $capability, $module),
            ]);

            // "Add" and "use it here" are one action.
            $saved = $this->bindings->save($module, $capability, $tenant, [
                'provider' => $provider,
                'model' => $model,
                'api_key_id' => $credentialId,
                'max_output_tokens' => $data['max_output_tokens'] ?? null,
                'status' => 1,
            ], $scope->userId);

            return $this->success('Model added and this module will use it.', [
                'capability' => $capability,
                'credential' => [
                    'id' => $credentialId,
                    'provider' => $provider,
                    'label' => trim((string) ($data['account_email'] ?? '')) ?: $this->credentialLabel($credentialId),
                    'daily_limit' => isset($data['api_limit']) ? (int) $data['api_limit'] : null,
                    'scope' => 'institute',
                ],
                'model_id' => $modelId,
                'binding' => $saved === null ? null : $this->presentBinding($saved),
                'effective' => $this->presentEffective($this->resolver->resolve($capability, $tenant, $module)),
            ], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Edit or enable/disable a credential this tenant added — never a platform row. */
    public function updateCredential(Request $request, string $module, string $credential): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenant = $scope->tenantId;

            if (! $this->modules->exists($module, $tenant)) {
                return $this->failure('That module is not one this organisation has.', 404);
            }

            if (! Schema::hasTable('hpbrain_ai_api_keys')) {
                return $this->failure('AI credentials are not available on this deployment.', 503);
            }

            $row = DB::table('hpbrain_ai_api_keys')->where('id', $credential)->first();

            if ($row === null) {
                return $this->failure('That credential was not found.', 404);
            }

            if (Platform::isPlatform($row)) {
                return $this->failure('This credential is part of the shared platform configuration and cannot be edited from a module.', 403);
            }

            if ((string) $row->tenant_id !== $tenant) {
                return $this->failure('That credential was not found.', 404);
            }

            // Only a credential for one of THIS module's capabilities is editable here —
            // another module's (or an unrelated capability's) key is not this screen's.
            if (! $this->appliesTo($module, (string) $row->ai_module, $tenant)) {
                return $this->failure('That credential is not for a capability this module uses.', 422);
            }

            $data = $request->validate([
                'model' => ['nullable', 'string', 'max:120'],
                'model_label' => ['nullable', 'string', 'max:120'],
                'api_key' => ['nullable', 'string', 'min:8', 'max:4096'],
                'account_email' => ['nullable', 'email', 'max:191'],
                'api_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
                'status' => ['nullable', 'integer', Rule::in([0, 1])],
            ]);

            $update = ['updated_date' => Platform::now()];

            if (array_key_exists('model', $data) && trim((string) $data['model']) !== '') {
                $model = trim((string) $data['model']);
                $this->ensureModelCatalogued((string) $row->api_type, $model, $data['model_label'] ?? null, $scope);
                $update['model'] = $model;
            }

            if (array_key_exists('account_email', $data)) {
                $update['account_email'] = $data['account_email'];
            }

            if (array_key_exists('api_limit', $data)) {
                $update['api_limit'] = $data['api_limit'] === null ? null : (string) $data['api_limit'];
            }

            if (array_key_exists('status', $data) && $data['status'] !== null) {
                $update['status'] = (int) $data['status'];
            }

            $key = $data['api_key'] ?? null;

            if (is_string($key) && trim($key) !== '') {
                $update['api_key'] = $this->vault->seal(trim($key));
            }

            DB::table('hpbrain_ai_api_keys')->where('id', $credential)->where('tenant_id', $tenant)->update($update);

            $this->audit->record('ai.module_model.credential_updated', $scope, [
                'related_type' => 'hpbrain_ai_api_keys',
                'related_id' => $credential,
                'message' => sprintf('A credential for %s was updated in %s.', $row->ai_module, $module),
            ]);

            return $this->success('Credential updated.', [
                'capability' => (string) $row->ai_module,
                'credential' => [
                    'id' => $credential,
                    'provider' => (string) $row->api_type,
                    'label' => trim((string) (array_key_exists('account_email', $update) ? $update['account_email'] : ($row->account_email ?? ''))) ?: $this->credentialLabel($credential),
                    'daily_limit' => array_key_exists('api_limit', $update)
                        ? ($update['api_limit'] === null ? null : (int) $update['api_limit'])
                        : (is_numeric($row->api_limit ?? null) ? (int) $row->api_limit : null),
                    'status' => $update['status'] ?? (int) $row->status,
                    'scope' => 'institute',
                ],
                'effective' => $this->presentEffective($this->resolver->resolve((string) $row->ai_module, $tenant, $module)),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ------------------------------------------------------------------ internals

    private function presentBinding(object $binding): array
    {
        return [
            'provider' => $binding->provider,
            'model' => $binding->model,
            'api_key_id' => $binding->api_key_id === null ? null : (string) $binding->api_key_id,
            'max_output_tokens' => $binding->max_output_tokens === null ? null : (int) $binding->max_output_tokens,
            'scope' => Platform::isPlatform($binding) ? 'platform' : 'institute',
            // A platform row is the default for every tenant and not this tenant's to edit.
            'editable' => ! Platform::isPlatform($binding),
            'updated_at' => $binding->updated_date ?? null,
        ];
    }

    private function presentEffective(ResolvedAiConfiguration $effective): array
    {
        return [
            'provider' => $effective->provider,
            'provider_label' => $this->providers->label($effective->provider),
            'model' => $effective->model,
            'source' => $effective->source,
            'scope' => $effective->scope,
            'has_credential' => $effective->hasKey(),
            'max_output_tokens' => $effective->maxOutputTokens,
        ];
    }

    private function credentialLabel(string $id): string
    {
        return 'Credential #' . substr($id, 0, 8);
    }

    /** Add a model to this tenant's catalogue if it is not visible yet; return its id. */
    private function ensureModelCatalogued(string $provider, string $modelId, ?string $label, AiIntelligenceScope $scope): ?string
    {
        if (! Schema::hasTable('hpbrain_ai_models')) {
            return null;
        }

        $tenant = $scope->tenantId;

        $existing = Platform::visible(
            DB::table('hpbrain_ai_models')->where('provider', $provider)->where('model_id', $modelId),
            $tenant
        )
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenant])
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = Platform::id();
        $now = Platform::now();

        DB::table('hpbrain_ai_models')->insert([
            'id' => $id,
            'tenant_id' => $tenant,
            'provider' => $provider,
            'model_id' => $modelId,
            'label' => trim((string) $label) !== '' ? trim((string) $label) : $modelId,
            'max_output_tokens' => null,
            'input_cost_per_1k' => null,
            'output_cost_per_1k' => null,
            'sort_order' => 0,
            'status' => 1,
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        $this->audit->record('ai.model.created', $scope, [
            'related_type' => 'hpbrain_ai_models',
            'related_id' => $id,
            'message' => sprintf('Model %s added for %s from a module\'s AI Stack.', $modelId, $provider),
        ]);

        return $id;
    }

    /**
     * The AI capabilities this module actually uses: the conversational lane when the
     * module has the flag, then the module's own registry consumers.
     *
     * @return array<int, array{key:string, label:string, description:string, wired:bool}>
     */
    private function capabilitiesFor(string $module, string $tenant): array
    {
        $flags = $this->modules->capabilities($module, $tenant);
        $rows = [];

        if (! empty($flags['conversational'])) {
            $definition = $this->capabilities->find('conversational_ai');

            if ($definition !== null) {
                $rows[] = [
                    'key' => 'conversational_ai',
                    'label' => $definition['label'],
                    'description' => 'The AI & Intelligence assistant, when it is asked from this module. The assistant passes the module it was asked from, so a choice saved here is used for those questions.',
                    'wired' => true,
                ];
            }
        }

        foreach ($this->modules->registryKeys($module, $tenant) as $key) {
            $definition = $this->capabilities->find($key);

            if ($definition === null || $key === 'conversational_ai') {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description']
                    . ' Its generator still reaches its provider through its own configuration, so a choice saved here is recorded but not yet used.',
                'wired' => false,
            ];
        }

        return $rows;
    }

    private function appliesTo(string $module, string $capability, string $tenant): bool
    {
        foreach ($this->capabilitiesFor($module, $tenant) as $row) {
            if ($row['key'] === $capability) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array<string, mixed>> Providers this platform can call, with their models. */
    private function providerOptions(string $tenant): array
    {
        $options = [];

        foreach ($this->providers->keys() as $provider) {
            if (! $this->providers->isDriveable($provider)) {
                continue;
            }

            $options[] = [
                'key' => $provider,
                'label' => $this->providers->label($provider),
                'default_model' => $this->models->defaultFor($provider, $tenant) ?? $this->providers->defaultModel($provider),
                'models' => $this->modelOptions($provider, $tenant),
            ];
        }

        return $options;
    }

    /** @return array<int, array<string, mixed>> */
    private function modelOptions(string $provider, string $tenant): array
    {
        try {
            $models = $this->models->forProvider($provider, $tenant);
        } catch (Throwable) {
            return [];
        }

        return array_map(
            static fn (array $model) => [
                'key' => (string) $model['model_id'],
                'label' => (string) $model['label'],
                'max_output_tokens' => $model['max_output_tokens'] ?? null,
                'scope' => (string) $model['scope'],
            ],
            $models
        );
    }

    /**
     * Credentials for THIS module's capabilities only (its registry consumers, plus
     * conversational_ai when the module has the conversational flag) — never another
     * capability's key, and never the key itself. Active rows of the tenant and the
     * platform, plus this tenant's own disabled rows so they can be re-enabled here;
     * `status` says which is which (a status-0 row cannot be chosen for a binding).
     *
     * @return array<int, array<string, mixed>>
     */
    private function credentialOptions(string $module, string $tenant): array
    {
        $capabilities = array_column($this->capabilitiesFor($module, $tenant), 'key');

        if ($capabilities === [] || ! Schema::hasTable('hpbrain_ai_api_keys')) {
            return [];
        }

        return Platform::visible(DB::table('hpbrain_ai_api_keys')->whereIn('ai_module', $capabilities), $tenant)
            ->where(function ($q) use ($tenant) {
                $q->where('status', 1)->orWhere('tenant_id', $tenant);
            })
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenant])
            ->orderByDesc('status')
            ->orderByDesc('created_date')
            ->limit(50)
            ->get(['id', 'api_type', 'ai_module', 'account_email', 'api_limit', 'status', 'tenant_id'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'provider' => (string) $row->api_type,
                'capability' => (string) $row->ai_module,
                'label' => trim((string) ($row->account_email ?? '')) ?: $this->credentialLabel((string) $row->id),
                'daily_limit' => is_numeric($row->api_limit ?? null) ? (int) $row->api_limit : null,
                'scope' => Platform::isPlatform($row) ? 'platform' : 'institute',
                'status' => (int) $row->status,
            ])
            ->all();
    }

    private function credentialVisible(string $id, string $tenant): bool
    {
        if (! Schema::hasTable('hpbrain_ai_api_keys')) {
            return false;
        }

        return Platform::visible(DB::table('hpbrain_ai_api_keys')->where('id', $id)->where('status', 1), $tenant)->exists();
    }
}
