<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Templates;

use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Reads and writes hpbrain_ai_templates for the Template Management screen.
 *
 * Ported from G2G's TemplateCatalog with its versioning, copy-on-write override
 * and module-binding behaviour intact:
 *
 *   - A platform template (`tenant_id = '*'`) is never edited by a tenant: an edit
 *     writes that tenant its own copy (`overridden`), and a second edit updates
 *     that copy rather than colliding on the unique index.
 *   - `new_version` inserts a new version and archives the previous published one
 *     (`versioned`), so a bad prompt is rolled back by republishing.
 *   - Publishing a template against a module writes the binding row
 *     (hpbrain_ai_suggestions) that offers it in that module; archiving withdraws it.
 *
 * One HP Brain difference, on purpose: only the platform super administrator may
 * write a platform row (`shared`), edit one in place or retire one. In G2G any
 * organisation administrator could publish a template to every organisation.
 */
class TemplateCatalog
{
    public const STATUSES = ['draft', 'published', 'archived'];

    /** Index 1 is the report kind, as in G2G and LMS_K12. */
    public const KINDS = ['prompt', 'report'];

    public const OUTPUT_FORMATS = ['text', 'markdown', 'json'];

    /** Suggestions for a datalist, not a validation rule — HP Brain's vocabulary. */
    public const SUGGESTED_CATEGORIES = [
        'summary', 'explanation', 'recommendation', 'signal_triage', 'evidence_review',
        'deliberation', 'decision_brief', 'execution_plan', 'capability_gap', 'analysis',
    ];

    private const TABLE = 'hpbrain_ai_templates';

    private const BINDINGS = 'hpbrain_ai_suggestions';

    private const DOMAIN = 'hpbrain';

    public function __construct(
        private readonly TemplateModuleCatalog $modules,
        private readonly TemplateVariableCatalog $variables,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function forModule(?string $moduleKey, string $tenantId): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }

        $query = Platform::visible(DB::table(self::TABLE), $tenantId);

        if ($moduleKey === TemplateModuleCatalog::SHARED) {
            $query->whereNull('module_key');
        } elseif ($moduleKey !== null && $moduleKey !== '') {
            $query->where('module_key', $moduleKey);
        }

        $rows = $query->orderBy('template_key')->orderByDesc('version')->get();

        $bindings = $this->bindings($tenantId);

        return $rows->map(fn ($row) => $this->present($row, $tenantId, $bindings))->values()->all();
    }

    /** @return array<string, mixed>|null */
    public function find(string $id, string $tenantId): ?array
    {
        $row = $this->row($id, $tenantId);

        return $row === null ? null : $this->present($row, $tenantId, $this->bindings($tenantId));
    }

    public function row(string $id, string $tenantId): ?object
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }

        return Platform::visible(DB::table(self::TABLE)->where('id', $id), $tenantId)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $asPlatform  Write the row for every tenant. The controller only
     *                            passes true for the platform super administrator.
     */
    public function create(array $data, string $tenantId, bool $asPlatform = false): string
    {
        $moduleKey = $this->modules->toColumn($data['module_key'] ?? null);
        $owner = $asPlatform ? Platform::TENANT : $tenantId;

        $templateKey = $this->resolveKey($data, $moduleKey, $tenantId);
        $version = $this->nextVersion($templateKey, $owner);
        $now = Platform::now();
        $id = Platform::id();

        DB::table(self::TABLE)->insert(
            $this->columns($data, $templateKey, $moduleKey, $version) + [
                'id' => $id,
                'created_by' => $data['created_by'] ?? null,
                'tenant_id' => $owner,
                'created_date' => $now,
                'updated_date' => $now,
            ]
        );

        $this->syncBinding($templateKey, $moduleKey, $data, $owner);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $asPlatform  The platform super administrator editing a platform
     *                            row in place (`shared: true`). Everyone else editing a
     *                            platform row gets their own copy.
     * @return array{id:string, action:string}
     */
    public function update(string $id, array $data, string $tenantId, bool $asNewVersion = false, bool $asPlatform = false): array
    {
        $row = $this->row($id, $tenantId);

        if ($row === null) {
            throw new RuntimeException('That template could not be found.');
        }

        $moduleKey = $this->modules->toColumn($data['module_key'] ?? null);
        $templateKey = trim((string) ($data['template_key'] ?? $row->template_key));
        $now = Platform::now();

        if (Platform::isPlatform($row) && ! $asPlatform) {
            $existing = DB::table(self::TABLE)
                ->where('template_key', $templateKey)
                ->where('version', (int) $row->version)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($existing !== null) {
                DB::table(self::TABLE)
                    ->where('id', $existing->id)
                    ->where('tenant_id', $tenantId)
                    ->update($this->columns($data, $templateKey, $moduleKey, (int) $existing->version) + [
                        'updated_date' => $now,
                    ]);

                $this->syncBinding($templateKey, $moduleKey, $data, $tenantId);

                return ['id' => (string) $existing->id, 'action' => 'updated'];
            }

            $copyId = Platform::id();

            DB::table(self::TABLE)->insert(
                $this->columns($data, $templateKey, $moduleKey, (int) $row->version) + [
                    'id' => $copyId,
                    'created_by' => $data['created_by'] ?? null,
                    'tenant_id' => $tenantId,
                    'created_date' => $now,
                    'updated_date' => $now,
                ]
            );

            $this->syncBinding($templateKey, $moduleKey, $data, $tenantId);

            return ['id' => $copyId, 'action' => 'overridden'];
        }

        // From here the row belongs to its own owner: this tenant, or — for the
        // super administrator with `shared` — the platform.
        $owner = (string) $row->tenant_id;

        if ($asNewVersion) {
            $version = $this->nextVersion($templateKey, $owner);
            $newId = Platform::id();

            DB::table(self::TABLE)->insert(
                $this->columns($data, $templateKey, $moduleKey, $version) + [
                    'id' => $newId,
                    'created_by' => $data['created_by'] ?? null,
                    'tenant_id' => $owner,
                    'created_date' => $now,
                    'updated_date' => $now,
                ]
            );

            DB::table(self::TABLE)
                ->where('id', $row->id)
                ->where('tenant_id', $owner)
                ->where('status', 'published')
                ->update(['status' => 'archived', 'updated_date' => $now]);

            $this->syncBinding($templateKey, $moduleKey, $data, $owner);

            return ['id' => $newId, 'action' => 'versioned'];
        }

        DB::table(self::TABLE)
            ->where('id', $row->id)
            ->where('tenant_id', $owner)
            ->update($this->columns($data, $templateKey, $moduleKey, (int) $row->version) + [
                'updated_date' => $now,
            ]);

        if ((string) $row->template_key !== $templateKey || $row->module_key !== $moduleKey) {
            $this->withdrawBinding((string) $row->template_key, $row->module_key, $owner);
        }

        $this->syncBinding($templateKey, $moduleKey, $data, $owner);

        return ['id' => (string) $row->id, 'action' => 'updated'];
    }

    public function archive(string $id, string $tenantId, bool $asPlatform = false): void
    {
        $row = $this->row($id, $tenantId);

        if ($row === null) {
            throw new RuntimeException('That template could not be found.');
        }

        if (Platform::isPlatform($row) && ! $asPlatform) {
            throw new RuntimeException(
                'This is a platform template shared by every organisation, so it cannot be retired from here. '
                . 'Save your own version of it instead, or remove it from the module it is bound to.'
            );
        }

        $owner = (string) $row->tenant_id;

        DB::table(self::TABLE)
            ->where('id', $row->id)
            ->where('tenant_id', $owner)
            ->update(['status' => 'archived', 'updated_date' => Platform::now()]);

        $this->withdrawBinding((string) $row->template_key, $row->module_key, $owner);
    }

    /**
     * Substitute placeholders with values, without calling a model.
     *
     * @param  array<string, mixed>  $values
     * @return array{system:?string, user:string, unresolved:array<int, string>}
     */
    public function preview(string $systemPrompt, string $userPrompt, array $values): array
    {
        $render = function (string $text) use ($values): string {
            return preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
                function (array $match) use ($values) {
                    $key = $match[1];

                    if (! array_key_exists($key, $values)) {
                        return $match[0];
                    }

                    $value = $values[$key];

                    return mb_substr(is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value), 0, 8000);
                },
                $text
            ) ?? $text;
        };

        $system = $systemPrompt === '' ? null : $render($systemPrompt);
        $user = $render($userPrompt);

        $unresolved = array_values(array_unique(array_merge(
            $this->variables->used($system ?? ''),
            $this->variables->used($user)
        )));

        return [
            'system' => $system,
            'user' => $user,
            'unresolved' => array_values(array_filter(
                $unresolved,
                fn (string $key) => ! array_key_exists($key, $values)
            )),
        ];
    }

    /** @param array<string, mixed> $data */
    private function syncBinding(string $templateKey, ?string $moduleKey, array $data, string $owner): void
    {
        if (! Schema::hasTable(self::BINDINGS)) {
            return;
        }

        $offer = ($data['offer_in_module'] ?? true)
            && $moduleKey !== null
            && ($data['status'] ?? 'draft') === 'published';

        if (! $offer) {
            $this->withdrawBinding($templateKey, $moduleKey, $owner);

            return;
        }

        $label = trim((string) ($data['suggestion_label'] ?? '')) !== ''
            ? trim((string) $data['suggestion_label'])
            : trim((string) ($data['name'] ?? $templateKey));

        $existing = DB::table(self::BINDINGS)
            ->where('module_key', $moduleKey)
            ->where('capability', 'generative')
            ->where('action_type', 'generate')
            ->where('action_ref', $templateKey)
            ->where('tenant_id', $owner)
            ->first();

        $now = Platform::now();

        $payload = [
            'label' => mb_substr($label, 0, 150),
            'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                ? mb_substr(trim((string) $data['description']), 0, 500)
                : null,
            'requires_entity' => (bool) ($data['requires_entity'] ?? false),
            'status' => 1,
            'updated_date' => $now,
        ];

        if ($existing !== null) {
            DB::table(self::BINDINGS)->where('id', $existing->id)->where('tenant_id', $owner)->update($payload);

            return;
        }

        DB::table(self::BINDINGS)->insert($payload + [
            'id' => Platform::id(),
            'tenant_id' => $owner,
            'module_key' => $moduleKey,
            'capability' => 'generative',
            'icon' => null,
            'action_type' => 'generate',
            'action_ref' => $templateKey,
            'prompt' => null,
            'payload' => null,
            'allowed_roles' => null,
            'required_permissions' => null,
            'sort_order' => $this->nextSortOrder($moduleKey, $owner),
            'created_date' => $now,
        ]);
    }

    private function withdrawBinding(string $templateKey, ?string $moduleKey, string $owner): void
    {
        if (! Schema::hasTable(self::BINDINGS)) {
            return;
        }

        $query = DB::table(self::BINDINGS)
            ->where('capability', 'generative')
            ->where('action_type', 'generate')
            ->where('action_ref', $templateKey)
            ->where('tenant_id', $owner);

        if ($moduleKey !== null) {
            $query->where('module_key', $moduleKey);
        }

        $query->update(['status' => 0, 'updated_date' => Platform::now()]);
    }

    /** @return array<string, array{module_key:string, label:string, status:int, requires_entity:bool}> */
    private function bindings(string $tenantId): array
    {
        if (! Schema::hasTable(self::BINDINGS)) {
            return [];
        }

        $rows = Platform::visible(
            DB::table(self::BINDINGS)
                ->where('capability', 'generative')
                ->where('action_type', 'generate')
                ->whereNotNull('action_ref'),
            $tenantId
        )
            // A tenant binding overrides the platform one for the same template.
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
            ->get(['module_key', 'label', 'action_ref', 'status', 'requires_entity', 'tenant_id']);

        $bindings = [];

        foreach ($rows as $row) {
            $key = (string) $row->action_ref;

            if (isset($bindings[$key])) {
                continue;
            }

            $bindings[$key] = [
                'module_key' => (string) $row->module_key,
                'label' => (string) $row->label,
                'status' => (int) $row->status,
                'requires_entity' => (bool) $row->requires_entity,
            ];
        }

        return $bindings;
    }

    /**
     * @param  array<string, array{module_key:string, label:string, status:int, requires_entity:bool}>  $bindings
     * @return array<string, mixed>
     */
    public function present(object $row, string $tenantId, array $bindings = []): array
    {
        $binding = $bindings[(string) $row->template_key] ?? null;
        $variables = Platform::decode($row->variables ?? null);
        $userPrompt = (string) ($row->user_prompt ?? '');
        $systemPrompt = (string) ($row->system_prompt ?? '');
        $isPlatform = Platform::isPlatform($row);

        $usedGrounding = array_intersect(
            $this->variables->groundingKeys(),
            $this->variables->used($userPrompt . ' ' . $systemPrompt)
        );

        return [
            'id' => (string) $row->id,
            'template_key' => (string) $row->template_key,
            'name' => (string) $row->name,
            'description' => $row->description === null ? null : (string) $row->description,
            'module_key' => $this->modules->fromColumn($row->module_key ?? null),
            'module_label' => $this->modules->label($row->module_key ?? null, $tenantId),
            'kind' => (string) ($row->kind ?? 'prompt'),
            'html_layout' => ($row->html_layout ?? '') === '' ? null : (string) $row->html_layout,
            'data_source' => ($row->data_source ?? '') === '' ? null : (string) $row->data_source,
            'data_arguments' => Platform::decode($row->data_arguments ?? null),
            'domain' => (string) ($row->domain ?? self::DOMAIN),
            'category' => $row->category === null ? null : (string) $row->category,
            'version' => (int) $row->version,
            'status' => (string) $row->status,
            'system_prompt' => $systemPrompt === '' ? null : $systemPrompt,
            'user_prompt' => $userPrompt,
            'variables' => $variables,
            'output_format' => (string) ($row->output_format ?? 'text'),
            'output_schema' => Platform::decode($row->output_schema ?? null),
            'provider' => $row->provider === null ? null : (string) $row->provider,
            'model' => $row->model === null ? null : (string) $row->model,
            'temperature' => $row->temperature === null ? null : (float) $row->temperature,
            'max_tokens' => $row->max_tokens === null ? null : (int) $row->max_tokens,
            'safety_rules' => Platform::decode($row->safety_rules ?? null),
            'allow_as_evidence' => (bool) $row->allow_as_evidence,
            'requires_review' => (bool) $row->requires_review,

            'tenant_id' => Platform::present((string) $row->tenant_id),
            'is_platform' => $isPlatform,
            // G2G semantics: a platform row is never edited in place by an organisation
            // — Edit means "make your own copy".
            'editable_in_place' => ! $isPlatform,

            'offered_in_module' => $binding !== null && $binding['status'] === 1,
            'offer_label' => $binding['label'] ?? null,
            'offer_module_key' => $binding['module_key'] ?? null,
            'offer_requires_entity' => (bool) ($binding['requires_entity'] ?? false),

            'grounding_variables' => array_values($usedGrounding),
            'unresolvable_variables' => $this->variables->unresolvable($userPrompt . ' ' . $systemPrompt, $variables),

            'updated_at' => $row->updated_date === null ? null : (string) $row->updated_date,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data, string $templateKey, ?string $moduleKey, int $version): array
    {
        return [
            'template_key' => $templateKey,
            'name' => trim((string) $data['name']),
            'description' => $this->nullable($data['description'] ?? null),
            'domain' => trim((string) ($data['domain'] ?? self::DOMAIN)) ?: self::DOMAIN,
            'module_key' => $moduleKey,
            'kind' => in_array($data['kind'] ?? 'prompt', self::KINDS, true) ? (string) $data['kind'] : 'prompt',
            'html_layout' => $this->nullable($data['html_layout'] ?? null),
            'data_source' => $this->nullable($data['data_source'] ?? null),
            'data_arguments' => Platform::encode($data['data_arguments'] ?? []),
            'category' => $this->nullable($data['category'] ?? null),
            'version' => $version,
            'status' => (string) ($data['status'] ?? 'draft'),
            'system_prompt' => $this->nullable($data['system_prompt'] ?? null),
            'user_prompt' => (string) ($data['user_prompt'] ?? ''),
            'variables' => Platform::encode($data['variables'] ?? []),
            'output_schema' => Platform::encode($data['output_schema'] ?? null),
            'output_format' => (string) ($data['output_format'] ?? 'text'),
            'provider' => $this->nullable($data['provider'] ?? null),
            'model' => $this->nullable($data['model'] ?? null),
            'temperature' => ($data['temperature'] ?? null) === null || $data['temperature'] === ''
                ? null
                : (float) $data['temperature'],
            'max_tokens' => ($data['max_tokens'] ?? null) === null || $data['max_tokens'] === ''
                ? null
                : (int) $data['max_tokens'],
            'safety_rules' => Platform::encode($data['safety_rules'] ?? []),
            'allow_as_evidence' => (bool) ($data['allow_as_evidence'] ?? false),
            'requires_review' => (bool) ($data['requires_review'] ?? false),
        ];
    }

    /** `<domain>.<module>.<name>`, de-duplicated against what this tenant can see. */
    private function resolveKey(array $data, ?string $moduleKey, string $tenantId): string
    {
        $supplied = trim((string) ($data['template_key'] ?? ''));

        if ($supplied !== '') {
            return $supplied;
        }

        $domain = trim((string) ($data['domain'] ?? self::DOMAIN)) ?: self::DOMAIN;
        $slug = $this->slug((string) ($data['name'] ?? 'template'));
        $module = $moduleKey === null ? 'shared' : $this->slug($moduleKey);

        $base = mb_substr("{$domain}.{$module}.{$slug}", 0, 110);
        $candidate = $base;
        $suffix = 2;

        while (Platform::visible(DB::table(self::TABLE)->where('template_key', $candidate), $tenantId)->exists()) {
            $candidate = $base . '_' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function nextVersion(string $templateKey, string $owner): int
    {
        $current = DB::table(self::TABLE)
            ->where('template_key', $templateKey)
            ->where('tenant_id', $owner)
            ->max('version');

        return ((int) $current) + 1;
    }

    private function nextSortOrder(string $moduleKey, string $owner): int
    {
        $current = DB::table(self::BINDINGS)
            ->where('module_key', $moduleKey)
            ->where('capability', 'generative')
            ->where('tenant_id', $owner)
            ->max('sort_order');

        return ((int) $current) + 10;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';

        return trim($slug, '_') ?: 'template';
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
