<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Support\AiAuditLogger;
use App\Domain\AiIntelligence\Support\AiIntelligenceScope;
use App\Domain\AiIntelligence\Support\OrganisationProfile;
use App\Domain\AiIntelligence\Templates\ReportDataSourceCatalog;
use App\Domain\AiIntelligence\Templates\TemplateCatalog;
use App\Domain\AiIntelligence\Templates\TemplateModuleCatalog;
use App\Domain\AiIntelligence\Templates\TemplatePreviewData;
use App\Domain\AiIntelligence\Templates\TemplateVariableCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Template Management — one screen for every HP Brain area's AI templates.
 *
 * Ported from G2G's AiTemplateController onto hpbrain_ai_templates. Same routes,
 * same validation, same versioning and copy-on-write behaviour (see
 * TemplateCatalog). The one tightening: `shared: true` — writing a platform row
 * every tenant resolves — is refused with 403 unless the caller is the platform
 * super administrator.
 */
final class AiIntelligenceTemplateController extends AiIntelligenceController
{
    public function __construct(
        private readonly TemplateCatalog $templates,
        private readonly TemplateModuleCatalog $modules,
        private readonly TemplateVariableCatalog $variables,
        private readonly TemplatePreviewData $previewData,
        private readonly AiAuditLogger $audit,
        private readonly ReportDataSourceCatalog $dataSources,
        private readonly OrganisationProfile $organisation,
    ) {
    }

    public function options(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            return $this->success('Template options resolved.', [
                'modules' => $this->modules->all($tenantId),
                'shared_key' => TemplateModuleCatalog::SHARED,
                'variables' => $this->variables->all(),
                'grounding_variables' => $this->variables->groundingKeys(),
                'statuses' => TemplateCatalog::STATUSES,
                'kinds' => TemplateCatalog::KINDS,
                'output_formats' => TemplateCatalog::OUTPUT_FORMATS,
                'categories' => TemplateCatalog::SUGGESTED_CATEGORIES,
                'branding' => $this->organisation->branding($tenantId),
                'data_sources' => $this->dataSources->all(),
                'report_placeholders' => $this->dataSources->placeholders(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            $validated = $request->validate(['module_key' => 'nullable|string|max:80']);
            $moduleKey = $validated['module_key'] ?? null;

            if ($moduleKey !== null && $moduleKey !== '' && ! $this->modules->exists($moduleKey, $tenantId)) {
                return $this->failure('That module is not one this organisation has.', 404);
            }

            $templates = $this->templates->forModule($moduleKey, $tenantId);

            return $this->success('Templates resolved.', [
                'tenant_id' => $tenantId,
                'module_key' => $moduleKey,
                'module_label' => $moduleKey === null ? 'All modules' : $this->modules->label($moduleKey, $tenantId),
                'templates' => $templates,
                'counts' => [
                    'total' => count($templates),
                    'published' => count(array_filter($templates, fn ($row) => $row['status'] === 'published')),
                    'offered' => count(array_filter($templates, fn ($row) => $row['offered_in_module'])),
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $template = $this->templates->find($id, $this->scope($request)->tenantId);

            if ($template === null) {
                return $this->failure('That template could not be found.', 404);
            }

            return $this->success('Template resolved.', ['template' => $template]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            $data = $this->validated($request, $tenantId);
            $data['created_by'] = $scope->userId;

            $asPlatform = $this->wantsPlatform($data, $scope);

            $id = $this->templates->create($data, $tenantId, $asPlatform);

            $this->audit->record('ai.template.created', $scope, [
                'related_type' => 'hpbrain_ai_templates',
                'related_id' => $id,
                'message' => sprintf(
                    'Template "%s" created for %s%s.',
                    $data['name'],
                    $this->modules->label($data['module_key'] ?? null, $tenantId),
                    $asPlatform ? ' (platform-wide)' : ''
                ),
                'payload' => $this->auditPayload($data, $asPlatform),
            ]);

            return $this->success('Template saved.', ['template' => $this->templates->find($id, $tenantId)], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            if ($this->templates->find($id, $tenantId) === null) {
                return $this->failure('That template could not be found.', 404);
            }

            $data = $this->validated($request, $tenantId);
            $data['created_by'] = $scope->userId;

            $asPlatform = $this->wantsPlatform($data, $scope);

            $result = $this->templates->update($id, $data, $tenantId, $request->boolean('new_version'), $asPlatform);

            $this->audit->record('ai.template.' . $result['action'], $scope, [
                'related_type' => 'hpbrain_ai_templates',
                'related_id' => $result['id'],
                'message' => sprintf('Template "%s" %s.', $data['name'], $result['action']),
                'payload' => $this->auditPayload($data, $asPlatform) + ['source_id' => $id],
            ]);

            return $this->success(match ($result['action']) {
                'overridden' => 'This organisation now has its own version of the platform template. '
                    . 'The shared one is unchanged for every other organisation.',
                'versioned' => 'A new version was published. The previous one is archived and can be restored.',
                default => 'Template updated.',
            }, [
                'template' => $this->templates->find($result['id'], $tenantId),
                'action' => $result['action'],
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            $this->templates->archive($id, $scope->tenantId, $scope->isSuperAdmin() && $request->boolean('shared'));

            $this->audit->record('ai.template.archived', $scope, [
                'related_type' => 'hpbrain_ai_templates',
                'related_id' => $id,
                'message' => 'Template retired.',
            ]);

            return $this->success('Template retired.', ['id' => $id]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Render the prompts with this tenant's own values, without calling a model. */
    public function preview(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            $validated = $request->validate([
                'system_prompt' => 'nullable|string|max:20000',
                'user_prompt' => 'required|string|max:20000',
                'values' => 'nullable|array',
                'module_key' => 'nullable|string|max:80',
            ]);

            $values = array_merge(
                $this->previewData->forTenant($tenantId, $validated['module_key'] ?? null),
                $validated['values'] ?? []
            );

            $rendered = $this->templates->preview(
                (string) ($validated['system_prompt'] ?? ''),
                (string) $validated['user_prompt'],
                $values
            );

            return $this->success('Prompt rendered.', $rendered + ['values' => $values]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * `shared` is honoured for the platform super administrator only. Anyone else
     * asking for it is refused outright rather than silently downgraded, so a
     * tenant administrator is never left believing they published for everyone.
     *
     * @param array<string, mixed> $data
     */
    private function wantsPlatform(array $data, AiIntelligenceScope $scope): bool
    {
        if (! ($data['shared'] ?? false)) {
            return false;
        }

        if (! $scope->isSuperAdmin()) {
            throw new AuthorizationException(
                'Only the platform administrator can publish a template to every organisation. Save it for this organisation instead.'
            );
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function auditPayload(array $data, bool $asPlatform): array
    {
        return [
            'template_key' => $data['template_key'] ?? null,
            'module_key' => $data['module_key'] ?? null,
            'kind' => $data['kind'] ?? 'prompt',
            'status' => $data['status'] ?? null,
            'platform' => $asPlatform,
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $tenantId): array
    {
        $moduleKeys = array_merge([TemplateModuleCatalog::SHARED], $this->modules->keys($tenantId));

        $kind = (string) $request->input('kind', 'prompt');
        $isReport = $kind === TemplateCatalog::KINDS[1];

        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:1000',
            'kind' => ['nullable', Rule::in(TemplateCatalog::KINDS)],

            'html_layout' => [$isReport ? 'required' : 'nullable', 'string', 'max:200000'],
            'data_source' => [
                $isReport ? 'required' : 'nullable',
                'string',
                'max:120',
                function (string $attribute, mixed $value, callable $fail) {
                    if ($value !== null && $value !== '' && ! $this->dataSources->exists((string) $value)) {
                        $fail('That data source is not an available read-only source.');
                    }
                },
            ],
            'data_arguments' => 'nullable|array',

            'shared' => 'nullable|boolean',

            'template_key' => 'nullable|string|max:120|regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/',
            'module_key' => ['required', 'string', Rule::in($moduleKeys)],
            'domain' => 'nullable|string|max:40',
            'category' => 'nullable|string|max:60',
            'status' => ['required', Rule::in(TemplateCatalog::STATUSES)],

            'system_prompt' => 'nullable|string|max:20000',
            'user_prompt' => [$isReport ? 'nullable' : 'required', 'string', 'max:20000'],

            'variables' => 'nullable|array',
            'variables.*.key' => 'required|string|max:60|regex:/^[a-zA-Z0-9_.]+$/',
            'variables.*.label' => 'nullable|string|max:150',
            'variables.*.required' => 'nullable|boolean',
            'variables.*.type' => 'nullable|string|max:30',

            'output_format' => ['nullable', Rule::in(TemplateCatalog::OUTPUT_FORMATS)],
            'output_schema' => 'nullable|array',

            'provider' => 'nullable|string|max:40',
            'model' => 'nullable|string|max:120',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'max_tokens' => 'nullable|integer|min:1|max:200000',

            'safety_rules' => 'nullable|array',
            'safety_rules.*' => 'string|max:500',

            'allow_as_evidence' => 'nullable|boolean',
            'requires_review' => 'nullable|boolean',

            'offer_in_module' => 'nullable|boolean',
            'suggestion_label' => 'nullable|string|max:150',
            'requires_entity' => 'nullable|boolean',
        ]);

        // A published prompt with no data variable would be answered from general
        // knowledge. Refused rather than warned. Reports are exempt — nothing in a
        // report is written by a model.
        if (! $isReport && ($validated['status'] ?? 'draft') === 'published') {
            $prompt = ($validated['user_prompt'] ?? '') . ' ' . ($validated['system_prompt'] ?? '');
            $used = $this->variables->used($prompt);
            $declared = array_column($validated['variables'] ?? [], 'key');

            if (array_intersect($this->variables->groundingKeys(), array_merge($used, $declared)) === []) {
                throw ValidationException::withMessages([
                    'user_prompt' => [
                        'A published template must include at least one data variable — '
                        . implode(' or ', array_map(fn ($key) => '{{' . $key . '}}', $this->variables->groundingKeys()))
                        . ' — or the model has nothing to work from and will answer from general knowledge. '
                        . 'Save it as a draft if it is not finished.',
                    ],
                ]);
            }
        }

        $validated['kind'] = in_array($kind, TemplateCatalog::KINDS, true) ? $kind : 'prompt';

        if ($isReport) {
            $validated['user_prompt'] ??= '';
        }

        $validated['template_key'] = $validated['template_key'] ?? null;

        return $validated;
    }
}
