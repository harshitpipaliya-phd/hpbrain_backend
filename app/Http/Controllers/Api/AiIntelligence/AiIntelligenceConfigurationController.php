<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Configuration\AiConfigurationResolver;
use App\Domain\AiIntelligence\Configuration\AiModuleRegistry;
use App\Domain\AiIntelligence\Configuration\ModelCatalog;
use App\Domain\AiIntelligence\Configuration\ProviderCatalog;
use App\Domain\AiIntelligence\Support\AiAuditLogger;
use App\Domain\AiIntelligence\Support\AiIntelligenceScope;
use App\Domain\AiIntelligence\Support\ApiKeyVault;
use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * AI Provider & Model Management — the write half of the console.
 *
 * Ported from G2G's AiConfigurationController onto hpbrain_ai_api_keys and
 * hpbrain_ai_models. A credential is never returned: rows carry a masked
 * `key_preview`, `update()` treats an omitted key as "leave it alone", and the
 * key itself is stored encrypted (ApiKeyVault) where G2G stored plaintext.
 *
 * Platform rows (`tenant_id = '*'`) are visible to every tenant and editable by
 * none from here; every write is stamped with the tenant from the token.
 */
final class AiIntelligenceConfigurationController extends AiIntelligenceController
{
    public function __construct(
        private readonly AiModuleRegistry $modules,
        private readonly ProviderCatalog $providers,
        private readonly ModelCatalog $models,
        private readonly AiConfigurationResolver $resolver,
        private readonly AiAuditLogger $audit,
        private readonly ApiKeyVault $vault,
    ) {
    }

    public function options(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            return $this->success('AI configuration options resolved.', [
                'modules' => $this->modules->all(),
                'providers' => $this->providers->all(),
                'models' => $this->models->grouped($tenantId, false),
                'active_driver' => $this->resolver->defaultProvider(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            return $this->success('AI configurations resolved.', [
                'tenant_id' => $tenantId,
                'configurations' => $this->configurations($tenantId),
                'resolved' => $this->resolver->overview($tenantId),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            $data = $this->validated($request, $tenantId, requireKey: true);

            // One row per (capability, provider, tenant): saving the same pairing twice
            // is an edit, not a second row.
            $existing = DB::table('hpbrain_ai_api_keys')
                ->where('ai_module', $data['ai_module'])
                ->where('api_type', $data['api_type'])
                ->where('tenant_id', $tenantId)
                ->first();

            if ($existing !== null) {
                return $this->failure(
                    'A configuration for this module and provider already exists. Edit it instead.',
                    409,
                    ['id' => (string) $existing->id]
                );
            }

            $now = Platform::now();
            $id = Platform::id();

            DB::table('hpbrain_ai_api_keys')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'ai_module' => $data['ai_module'],
                'api_type' => $data['api_type'],
                'model' => $data['model'],
                'api_key' => $this->vault->seal((string) $data['api_key']),
                'account_email' => $data['account_email'],
                'api_limit' => $data['api_limit'],
                'status' => $data['status'],
                'created_by' => $scope->userId,
                'created_date' => $now,
                'updated_date' => $now,
            ]);

            $this->recordChange('created', $id, $data, $scope);

            return $this->success('AI configuration saved.', [
                'configuration' => $this->configuration($id, $tenantId),
            ], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            if ($this->ownedRow($id, $tenantId) === null) {
                return $this->failure('That AI configuration was not found.', 404);
            }

            $data = $this->validated($request, $tenantId, requireKey: false);

            // The pairing may have been changed onto one that already has a row.
            $clash = DB::table('hpbrain_ai_api_keys')
                ->where('ai_module', $data['ai_module'])
                ->where('api_type', $data['api_type'])
                ->where('tenant_id', $tenantId)
                ->where('id', '!=', $id)
                ->first();

            if ($clash !== null) {
                return $this->failure(
                    'A configuration for this module and provider already exists. Edit it instead.',
                    409,
                    ['id' => (string) $clash->id]
                );
            }

            $update = [
                'ai_module' => $data['ai_module'],
                'api_type' => $data['api_type'],
                'model' => $data['model'],
                'account_email' => $data['account_email'],
                'api_limit' => $data['api_limit'],
                'status' => $data['status'],
                'updated_date' => Platform::now(),
            ];

            if ($data['api_key'] !== null) {
                $update['api_key'] = $this->vault->seal($data['api_key']);
            }

            DB::table('hpbrain_ai_api_keys')->where('id', $id)->where('tenant_id', $tenantId)->update($update);

            $this->recordChange('updated', $id, $data, $scope);

            return $this->success('AI configuration updated.', [
                'configuration' => $this->configuration($id, $tenantId),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Retire (status = 0) rather than delete: usage rows point at it. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            if ($this->ownedRow($id, $tenantId) === null) {
                return $this->failure('That AI configuration was not found.', 404);
            }

            DB::table('hpbrain_ai_api_keys')->where('id', $id)->where('tenant_id', $tenantId)->update([
                'status' => 0,
                'updated_date' => Platform::now(),
            ]);

            $this->audit->record('ai.configuration.retired', $scope, [
                'related_type' => 'hpbrain_ai_api_keys',
                'related_id' => $id,
                'message' => 'AI configuration retired.',
            ]);

            return $this->success('AI configuration retired.', ['id' => $id]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ---------------------------------------------------------------------
    // Model catalogue
    // ---------------------------------------------------------------------

    public function models(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            return $this->success('AI models resolved.', [
                'tenant_id' => $tenantId,
                'providers' => $this->providers->all(),
                'models' => $this->models->grouped($tenantId),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function storeModel(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            if (! Schema::hasTable('hpbrain_ai_models')) {
                return $this->failure('The model catalogue is not installed on this deployment.', 422);
            }

            $data = $this->validatedModel($request);

            $exists = DB::table('hpbrain_ai_models')
                ->where('provider', $data['provider'])
                ->where('model_id', $data['model_id'])
                ->where('tenant_id', $tenantId)
                ->exists();

            if ($exists) {
                return $this->failure('That model is already in the catalogue for this organisation.', 409);
            }

            $now = Platform::now();
            $id = Platform::id();

            DB::table('hpbrain_ai_models')->insert($data + [
                'id' => $id,
                'tenant_id' => $tenantId,
                'created_date' => $now,
                'updated_date' => $now,
            ]);

            $this->audit->record('ai.model.created', $scope, [
                'related_type' => 'hpbrain_ai_models',
                'related_id' => $id,
                'message' => sprintf('Model %s added for %s.', $data['model_id'], $data['provider']),
                'payload' => $data,
            ]);

            return $this->success('Model added.', ['model' => $this->models->find($id, $tenantId)], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function updateModel(Request $request, string $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            if (! Schema::hasTable('hpbrain_ai_models')) {
                return $this->failure('The model catalogue is not installed on this deployment.', 422);
            }

            $row = Platform::visible(DB::table('hpbrain_ai_models')->where('id', $id), $tenantId)->first();

            if ($row === null) {
                return $this->failure('That model was not found.', 404);
            }

            if (Platform::isPlatform($row)) {
                return $this->failure(
                    'This model is part of the shared platform catalogue and cannot be edited here.',
                    403
                );
            }

            $data = $this->validatedModel($request);

            $clash = DB::table('hpbrain_ai_models')
                ->where('provider', $data['provider'])
                ->where('model_id', $data['model_id'])
                ->where('tenant_id', $tenantId)
                ->where('id', '!=', $id)
                ->exists();

            if ($clash) {
                return $this->failure('That model is already in the catalogue for this organisation.', 409);
            }

            DB::table('hpbrain_ai_models')
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->update($data + ['updated_date' => Platform::now()]);

            $this->audit->record('ai.model.updated', $scope, [
                'related_type' => 'hpbrain_ai_models',
                'related_id' => $id,
                'message' => sprintf('Model %s updated.', $data['model_id']),
                'payload' => $data,
            ]);

            return $this->success('Model updated.', ['model' => $this->models->find($id, $tenantId)]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function validated(Request $request, string $tenantId, bool $requireKey): array
    {
        $validated = $request->validate([
            'ai_module' => ['required', 'string', Rule::in($this->modules->keys())],
            'provider' => ['required', 'string', Rule::in($this->providers->keys())],
            'model' => ['nullable', 'string', 'max:120'],
            'api_key' => [$requireKey ? 'required' : 'nullable', 'string', 'min:8', 'max:4096'],
            'account_email' => ['nullable', 'email', 'max:191'],
            'api_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'status' => ['nullable', 'integer', Rule::in([0, 1])],
        ]);

        $provider = $validated['provider'];

        if (! $this->providers->isDriveable($provider)) {
            throw ValidationException::withMessages([
                'provider' => [sprintf(
                    '%s is not callable from this platform yet, so it cannot be saved as a module\'s provider.',
                    $this->providers->label($provider)
                )],
            ]);
        }

        $model = trim((string) ($validated['model'] ?? ''));
        $model = $model === '' ? null : $model;

        if ($model !== null && ! $this->models->offers($provider, $model, $tenantId)) {
            throw ValidationException::withMessages([
                'model' => ['That model is not in the catalogue for this provider. Add it in Model Management first.'],
            ]);
        }

        $key = $validated['api_key'] ?? null;
        $key = is_string($key) && trim($key) !== '' ? trim($key) : null;

        return [
            'ai_module' => $validated['ai_module'],
            'provider' => $provider,
            'api_type' => $this->providers->apiType($provider),
            'model' => $model,
            'api_key' => $key,
            'account_email' => $validated['account_email'] ?? null,
            'api_limit' => isset($validated['api_limit']) ? (string) $validated['api_limit'] : null,
            'status' => (int) ($validated['status'] ?? 1),
        ];
    }

    /** @return array<string, mixed> */
    private function validatedModel(Request $request): array
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in($this->providers->keys())],
            'model_id' => ['required', 'string', 'max:120'],
            'label' => ['required', 'string', 'max:120'],
            'max_output_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'input_cost_per_1k' => ['nullable', 'numeric', 'min:0'],
            'output_cost_per_1k' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => ['nullable', 'integer', Rule::in([0, 1])],
        ]);

        return [
            'provider' => $validated['provider'],
            'model_id' => trim($validated['model_id']),
            'label' => trim($validated['label']),
            'max_output_tokens' => $validated['max_output_tokens'] ?? null,
            'input_cost_per_1k' => $validated['input_cost_per_1k'] ?? null,
            'output_cost_per_1k' => $validated['output_cost_per_1k'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'status' => (int) ($validated['status'] ?? 1),
        ];
    }

    // ---------------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------------

    private function ownedRow(string $id, string $tenantId): ?object
    {
        return DB::table('hpbrain_ai_api_keys')->where('id', $id)->where('tenant_id', $tenantId)->first();
    }

    /** @return array<int, array<string, mixed>> Tenant rows first, then the platform's. */
    private function configurations(string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_ai_api_keys')) {
            return [];
        }

        return Platform::visible(DB::table('hpbrain_ai_api_keys'), $tenantId)
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 1 ELSE 0 END', [Platform::TENANT])
            ->orderByDesc('created_date')
            ->get()
            ->map(fn ($row) => $this->present($row, $tenantId))
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function configuration(string $id, string $tenantId): ?array
    {
        $row = Platform::visible(DB::table('hpbrain_ai_api_keys')->where('id', $id), $tenantId)->first();

        return $row ? $this->present($row, $tenantId) : null;
    }

    /** @return array<string, mixed> */
    private function present(object $row, string $tenantId): array
    {
        $module = $row->ai_module ?? null;
        $provider = $this->providerFor($row);
        $isPlatform = Platform::isPlatform($row);

        return [
            'id' => (string) $row->id,
            'ai_module' => $module,
            'module_label' => $module === null ? 'All modules (shared pool)' : $this->modules->label($module),
            'module_wired' => $module !== null && $this->modules->isWired($module),
            'provider' => $provider,
            'provider_label' => $this->providers->label($provider),
            'api_type' => (string) ($row->api_type ?? ''),
            'model' => $row->model ?? null,
            'account_email' => $row->account_email ?? null,
            'api_limit' => $row->api_limit ?? null,
            'status' => (int) $row->status,
            'scope' => $isPlatform ? 'platform' : 'institute',
            'editable' => ! $isPlatform && (string) $row->tenant_id === $tenantId,
            'key_preview' => $this->vault->preview($row->api_key ?? null),
            'updated_at' => $row->updated_date ?? null,
        ];
    }

    private function providerFor(object $row): string
    {
        $apiType = trim((string) ($row->api_type ?? ''));

        if ($this->providers->exists($apiType)) {
            return $apiType;
        }

        foreach ($this->providers->keys() as $provider) {
            if (strcasecmp($this->providers->apiType($provider), $apiType) === 0) {
                return $provider;
            }
        }

        return $apiType !== '' ? $apiType : 'unknown';
    }

    /** @param array<string, mixed> $data */
    private function recordChange(string $verb, string $id, array $data, AiIntelligenceScope $scope): void
    {
        $this->audit->record("ai.configuration.{$verb}", $scope, [
            'related_type' => 'hpbrain_ai_api_keys',
            'related_id' => $id,
            'message' => sprintf(
                'AI configuration %s: %s → %s / %s.',
                $verb,
                $this->modules->label($data['ai_module']),
                $this->providers->label($data['provider']),
                $data['model'] ?? 'provider default'
            ),
            // The key is never put in here; the logger would redact it anyway.
            'payload' => [
                'ai_module' => $data['ai_module'],
                'provider' => $data['provider'],
                'model' => $data['model'],
                'status' => $data['status'],
                'key_rotated' => $data['api_key'] !== null,
            ],
        ]);
    }
}
