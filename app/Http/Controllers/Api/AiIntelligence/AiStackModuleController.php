<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Configuration\AiConfigurationResolver;
use App\Domain\AiIntelligence\Reports\ModuleDataSourceCatalog;
use App\Domain\AiIntelligence\Stack\AiStackModules;
use App\Domain\AiIntelligence\Support\AiAuditLogger;
use App\Domain\AiIntelligence\Support\AiIntelligenceScope;
use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What one HP Brain area's AI has done, and what is holding it back — the backend of
 * the per-module AI Stack's Usage & Cost, Guardrails and Activity tabs.
 *
 * Ported from G2G's AiModuleController with the same response keys. What each section
 * reads is HP Brain's:
 *
 *   conversations  hpbrain_ai_conversations.module_key + hpbrain_ai_conversation_turns
 *   generation     hpbrain_ai_usage_events whose `product_module` is this module (the key
 *                  AiModelClient was called from). Older rows without it are attributed
 *                  to no module; nothing is borrowed from a shared capability.
 *   reports        hpbrain_ai_generated_reports.module_key
 *   provider       the credential the module has of its own (a tenant key one of its
 *                  model bindings points at), else what its primary capability resolves
 *                  to; daily calls are this module's metered events
 *   refusals       this module's hpbrain_ai_usage_events whose outcome is `refused` or `failed`
 *   profile        the module's catalogue row, data sources, tools and presets
 *   activity       hpbrain_ai_audit_logs rows under `module.<key>.*` for THIS tenant only
 *                  (G2G also showed `sub_institute_id IS NULL` rows to every organisation)
 *
 * Every figure is measured or null-with-a-reason; nothing is estimated or sampled. The
 * tenant comes from the token (AiIntelligenceScope); `{module}` must be an active
 * hpbrain_ai_modules key this tenant can see, else 404.
 */
final class AiStackModuleController extends AiIntelligenceController
{
    private const RECENT = 25;

    public function __construct(
        private readonly AiStackModules $modules,
        private readonly AiAuditLogger $audit,
        private readonly AiConfigurationResolver $resolver,
        private readonly ModuleDataSourceCatalog $sources,
    ) {
    }

    public function usage(Request $request, string $module): JsonResponse
    {
        try {
            $tenant = $this->scope($request)->tenantId;

            if (! $this->modules->exists($module, $tenant)) {
                return $this->failure('That module is not one this organisation has.', 404);
            }

            return $this->success('Module usage resolved.', [
                'module' => $this->modules->identity($module, $tenant),
                'conversations' => $this->conversationUsage($module, $tenant),
                'generation' => $this->generationUsage($module, $tenant),
                'reports' => $this->reportUsage($module, $tenant),
                'provider' => $this->providerUsage($module, $tenant),
                'recent_turns' => $this->recentTurns($module, $tenant),
                'daily' => $this->dailySeries($module, $tenant),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function guardrails(Request $request, string $module): JsonResponse
    {
        try {
            $tenant = $this->scope($request)->tenantId;

            if (! $this->modules->exists($module, $tenant)) {
                return $this->failure('That module is not one this organisation has.', 404);
            }

            $identity = $this->modules->identity($module, $tenant);

            return $this->success('Module guardrails resolved.', [
                'module' => $identity,
                'capabilities' => $identity['capabilities'],
                'review' => $this->reviewPosture($module, $tenant),
                'refusals' => $this->refusals($module, $tenant),
                'refusal_counts' => $this->refusalCounts($module, $tenant),
                'refusal_totals' => $this->refusalTotals($module, $tenant),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Everything the AI Stack screens need to know ABOUT a module, so they hard-code
     * no catalogue: the hpbrain_ai_modules row (tenant row shadows platform), its
     * read-only data sources (ModuleDataSourceCatalog), the MCP tools those sources
     * are to a tool agent, and the agent presets stored on the module row.
     */
    public function profile(Request $request, string $module): JsonResponse
    {
        try {
            $tenant = $this->scope($request)->tenantId;
            $descriptor = $this->modules->descriptor($module, $tenant);

            if ($descriptor === null) {
                return $this->failure('That module is not one this organisation has.', 404);
            }

            $sources = [];
            $tools = [];

            foreach ($this->sources->forModule($module) as $source) {
                $arguments = $this->sources->argumentSpecs($source['name']);

                $sources[] = [
                    'name' => $source['name'],
                    'label' => $source['label'],
                    'description' => $source['description'],
                    'columns' => $source['columns'],
                    'arguments' => $arguments,
                ];

                $example = [];

                foreach ($arguments as $argument) {
                    $example[$argument['key']] = $argument['default'] ?? null;
                }

                if (array_key_exists('limit', $example)) {
                    $example['limit'] = 50;
                }

                $tools[] = [
                    'key' => $source['name'],
                    'label' => $source['label'],
                    'description' => $source['description'],
                    'module' => $module,
                    'risk' => 'read',
                    'kind' => 'mcp',
                    // Whether this tenant can read it right now (its tables exist and its
                    // ERP entities are mapped) — measured, not assumed.
                    'available' => $this->sources->availability($source['name'], $tenant)['available'],
                    'example_input' => $example,
                ];
            }

            return $this->success('Module profile resolved.', [
                'module' => $descriptor,
                'data_sources' => $sources,
                'tools' => $tools,
                'presets' => $this->modules->presets($module, $tenant),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ------------------------------------------------------------------ usage

    private function conversationUsage(string $module, string $tenant): array
    {
        if (! Schema::hasTable('hpbrain_ai_conversations')) {
            return ['available' => false, 'reason' => 'hpbrain_ai_conversations is not on this deployment.'];
        }

        $conversations = DB::table('hpbrain_ai_conversations')
            ->where('tenant_id', $tenant)
            ->where('module_key', $module);

        $totals = (clone $conversations)
            ->selectRaw('count(*) as total, coalesce(sum(turn_count), 0) as turns, count(distinct user_id) as users, max(last_turn_at) as last_activity, min(created_date) as first_activity')
            ->first();

        $byStatus = (clone $conversations)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();

        $turnStats = ['available' => false];

        if (Schema::hasTable('hpbrain_ai_conversation_turns')) {
            $ids = (clone $conversations)->pluck('id');

            if ($ids->isEmpty()) {
                $turnStats = ['available' => true, 'total' => 0, 'avg_duration_ms' => null, 'max_duration_ms' => null, 'by_status' => []];
            } else {
                // The answer's latency is on the assistant turn, a failed answer is an
                // assistant turn carrying `error`. No intent is ever classified, so there
                // is no `by_intent` breakdown at all rather than an empty or invented one.
                $answers = DB::table('hpbrain_ai_conversation_turns')
                    ->where('tenant_id', $tenant)
                    ->whereIn('conversation_id', $ids)
                    ->where('role', 'assistant');

                $aggregate = (clone $answers)
                    ->selectRaw('count(*) as total, avg(latency_ms) as avg_ms, max(latency_ms) as max_ms')
                    ->first();

                $turnStats = [
                    'available' => true,
                    'total' => (int) ($aggregate->total ?? 0),
                    'avg_duration_ms' => $aggregate->avg_ms === null ? null : (int) round((float) $aggregate->avg_ms),
                    'max_duration_ms' => $aggregate->max_ms === null ? null : (int) $aggregate->max_ms,
                    'by_status' => (clone $answers)
                        ->selectRaw("case when error is null then 'answered' else 'failed' end as s, count(*) as c")
                        ->groupBy('s')
                        ->pluck('c', 's')
                        ->map(fn ($c) => (int) $c)
                        ->all(),
                ];
            }
        }

        return [
            'available' => true,
            'total' => (int) ($totals->total ?? 0),
            'turns_recorded_on_conversation' => (int) ($totals->turns ?? 0),
            'distinct_users' => (int) ($totals->users ?? 0),
            'first_activity' => $totals->first_activity ?? null,
            'last_activity' => $totals->last_activity ?? null,
            'by_status' => $byStatus,
            'turn_detail' => $turnStats,
        ];
    }

    /**
     * Metered calls made FROM this module — hpbrain_ai_usage_events.product_module.
     *
     * Module-specific means product_module only: two modules that share an AI
     * capability no longer show the same numbers. Events recorded before the column
     * existed (or by callers that name no module) carry NULL and are attributed to no
     * module; they still count on the central console, which reads by capability.
     */
    private function generationUsage(string $module, string $tenant): array
    {
        if (! $this->attributionAvailable()) {
            return [
                'available' => false,
                'reason' => 'hpbrain_ai_usage_events has no product_module column on this deployment yet (migration 2026_09_29_100001), so no call can be attributed to a module.',
            ];
        }

        $events = $this->events($tenant, $module);
        $total = (int) (clone $events)->count();

        if ($total === 0) {
            return [
                'available' => true,
                'total' => 0,
                'by_status' => [],
                'tokens' => $this->emptyTokens(
                    'No metered AI call has been recorded from this module yet. Calls made before per-module attribution, '
                    . 'or by callers that do not name a module, are not attributed to any module.'
                ),
            ];
        }

        $byStatus = (clone $events)
            ->selectRaw('outcome, count(*) as c')
            ->groupBy('outcome')
            ->pluck('c', 'outcome')
            ->map(fn ($c) => (int) $c)
            ->all();

        $aggregate = (clone $events)
            ->selectRaw(
                "sum(case when outcome = 'success' then 1 else 0 end) as outputs,"
                . ' sum(input_tokens) as prompt_tokens, sum(output_tokens) as completion_tokens,'
                . ' sum(estimated_cost_usd) as recorded_cost, avg(latency_ms) as avg_latency'
            )
            ->first();

        $prompt = $aggregate->prompt_tokens === null ? null : (int) $aggregate->prompt_tokens;
        $completion = $aggregate->completion_tokens === null ? null : (int) $aggregate->completion_tokens;
        $recorded = $aggregate->recorded_cost === null ? null : (float) $aggregate->recorded_cost;
        $rate = $this->modelRate($module, $tenant);

        [$cost, $source, $reason] = $this->resolveCost($prompt, $completion, $recorded, $rate);

        return [
            'available' => true,
            'total' => $total,
            'by_status' => $byStatus,
            'tokens' => [
                'outputs' => (int) ($aggregate->outputs ?? 0),
                // Not recorded: no metered call carries a "reviewed by a person" flag, so
                // this is null (unknown), never a count of zero.
                'reviewed' => null,
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'avg_latency_ms' => $aggregate->avg_latency === null ? null : (int) round((float) $aggregate->avg_latency),
                'rate' => $rate,
                'cost' => $cost,
                'cost_source' => $source,
                'cost_reason' => $reason,
            ],
        ];
    }

    /** This tenant's metered events attributed to this module. */
    private function events(string $tenant, string $module): Builder
    {
        return DB::table('hpbrain_ai_usage_events')
            ->where('tenant_id', $tenant)
            ->where('product_module', $module);
    }

    private function attributionAvailable(): bool
    {
        try {
            return Schema::hasTable('hpbrain_ai_usage_events') && Schema::hasColumn('hpbrain_ai_usage_events', 'product_module');
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{0: float|null, 1: string, 2: string|null} */
    private function resolveCost(?int $prompt, ?int $completion, ?float $recorded, ?array $rate): array
    {
        if ($recorded !== null && $recorded > 0) {
            return [$recorded, 'recorded', null];
        }

        if ($prompt === null && $completion === null) {
            return [null, 'unavailable', 'No token counts are recorded for this module, so cost cannot be computed.'];
        }

        if ($rate === null || ($rate['input_per_1k'] === null && $rate['output_per_1k'] === null)) {
            return [null, 'unavailable', 'No price is configured for this module\'s model, so tokens are reported without a money figure.'];
        }

        $cost = (($prompt ?? 0) / 1000) * (float) ($rate['input_per_1k'] ?? 0)
            + (($completion ?? 0) / 1000) * (float) ($rate['output_per_1k'] ?? 0);

        return [round($cost, 6), 'computed', null];
    }

    private function emptyTokens(string $reason): array
    {
        return [
            'outputs' => 0, 'reviewed' => null, 'prompt_tokens' => null, 'completion_tokens' => null,
            'avg_latency_ms' => null, 'rate' => null, 'cost' => null,
            'cost_source' => 'unavailable', 'cost_reason' => $reason,
        ];
    }

    /**
     * The capability this module's own figures are about: its first registry consumer,
     * else the conversational lane when the module has that flag.
     */
    private function primaryCapability(string $module, string $tenant): ?string
    {
        $keys = $this->modules->registryKeys($module, $tenant);

        if ($keys !== []) {
            return $keys[0];
        }

        return ! empty($this->modules->capabilities($module, $tenant)['conversational']) ? 'conversational_ai' : null;
    }

    /**
     * The credential THIS module has of its own: an active, tenant-owned
     * hpbrain_ai_api_keys row that one of this module's own model bindings
     * (hpbrain_ai_module_model_bindings, this tenant, this module) points at. A
     * capability key shared with other modules is not "the module's own".
     */
    private function ownCredential(string $module, string $tenant): ?object
    {
        if (! Schema::hasTable('hpbrain_ai_api_keys') || ! Schema::hasTable('hpbrain_ai_module_model_bindings')) {
            return null;
        }

        return DB::table('hpbrain_ai_module_model_bindings as b')
            ->join('hpbrain_ai_api_keys as k', function ($join) {
                $join->on('k.id', '=', 'b.api_key_id')->on('k.tenant_id', '=', 'b.tenant_id');
            })
            ->where('b.tenant_id', $tenant)
            ->where('b.product_module', $module)
            ->where('b.status', 1)
            ->where('k.status', 1)
            ->orderByDesc('b.updated_date')
            ->first(['k.id', 'k.api_type', 'k.model', 'k.api_limit', 'k.tenant_id', 'b.provider as bound_provider', 'b.model as bound_model']);
    }

    private function modelRate(string $module, string $tenant): ?array
    {
        if (! Schema::hasTable('hpbrain_ai_models')) {
            return null;
        }

        // Priced at the model this module's primary capability resolves to — the same
        // resolution the call itself runs, module binding included.
        $capability = $this->primaryCapability($module, $tenant);

        if ($capability === null) {
            return null;
        }

        $effective = $this->resolver->resolve($capability, $tenant, $module);

        $model = Platform::visible(
            DB::table('hpbrain_ai_models')
                ->where('provider', $effective->provider)
                ->when(($effective->model ?? '') !== '', fn ($q) => $q->where('model_id', $effective->model)),
            $tenant
        )
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenant])
            ->first();

        if ($model === null) {
            return null;
        }

        return [
            'provider' => (string) $model->provider,
            'model_id' => (string) $model->model_id,
            'label' => (string) $model->label,
            'input_per_1k' => $model->input_cost_per_1k === null ? null : (float) $model->input_cost_per_1k,
            'output_per_1k' => $model->output_cost_per_1k === null ? null : (float) $model->output_cost_per_1k,
        ];
    }

    private function reportUsage(string $module, string $tenant): array
    {
        if (! Schema::hasTable('hpbrain_ai_generated_reports')) {
            return ['available' => false, 'reason' => 'No report has been built on this deployment yet — hpbrain_ai_generated_reports is created with the AI Stack report builder.'];
        }

        $reports = DB::table('hpbrain_ai_generated_reports')
            ->where('tenant_id', $tenant)
            ->where('module_key', $module)
            ->where('status', 1);

        $aggregate = (clone $reports)
            ->selectRaw('count(*) as total, coalesce(sum(row_count), 0) as rows_reported, max(created_date) as last_created')
            ->first();

        return [
            'available' => true,
            'total' => (int) ($aggregate->total ?? 0),
            'rows_reported' => (int) ($aggregate->rows_reported ?? 0),
            'last_created' => $aggregate->last_created ?? null,
            'by_tool' => (clone $reports)
                ->selectRaw('source_tool, count(*) as c')
                ->groupBy('source_tool')
                ->orderByDesc('c')
                ->limit(10)
                ->get()
                ->map(fn ($row) => ['tool' => $row->source_tool ?? 'unknown', 'count' => (int) $row->c])
                ->all(),
        ];
    }

    private function providerUsage(string $module, string $tenant): array
    {
        if (! Schema::hasTable('hpbrain_ai_api_keys')) {
            return ['available' => false, 'reason' => 'hpbrain_ai_api_keys is not on this deployment.'];
        }

        // `bound` = this module has a credential of its OWN (a tenant key one of its
        // model bindings points at). Otherwise the figures describe what the module's
        // primary capability actually resolves to, shared with other modules.
        $own = $this->ownCredential($module, $tenant);
        $capability = $this->primaryCapability($module, $tenant);
        $effective = $capability === null ? null : $this->resolver->resolve($capability, $tenant, $module);
        $key = $own ?? $this->credentialRow($effective?->keyId, $tenant);

        $calls = null;

        if ($this->attributionAvailable()) {
            $calls = $this->events($tenant, $module)
                ->where('created_date', '>=', Platform::daysAgo(14))
                ->selectRaw('date(created_date) as day, count(*) as c')
                ->groupBy('day')
                ->orderByDesc('day')
                ->limit(14)
                ->get()
                ->map(fn ($row) => ['date' => (string) $row->day, 'count' => (int) $row->c])
                ->all();
        }

        return [
            'available' => true,
            'bound' => $own !== null,
            'capability' => $capability,
            'provider' => $own !== null ? ((string) ($own->bound_provider ?? '') ?: (string) $own->api_type) : $effective?->provider,
            'model' => $own !== null ? ((string) ($own->bound_model ?? '') ?: ($own->model ?? null)) : $effective?->model,
            'daily_limit' => $key === null || ! is_numeric($key->api_limit ?? null) ? null : (int) $key->api_limit,
            'scope' => $key === null ? null : (Platform::isPlatform($key) ? 'platform' : 'institute'),
            'daily_calls' => $calls,
        ];
    }

    /** An active hpbrain_ai_api_keys row this tenant can see, by id. */
    private function credentialRow(?string $id, string $tenant): ?object
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Platform::visible(DB::table('hpbrain_ai_api_keys')->where('id', $id)->where('status', 1), $tenant)
            ->first(['id', 'api_type', 'model', 'api_limit', 'tenant_id']);
    }

    /** The module's most recent questions, each with how its answer ended. */
    private function recentTurns(string $module, string $tenant): array
    {
        if (! Schema::hasTable('hpbrain_ai_conversations') || ! Schema::hasTable('hpbrain_ai_conversation_turns')) {
            return [];
        }

        return DB::table('hpbrain_ai_conversation_turns as u')
            ->join('hpbrain_ai_conversations as c', function ($join) {
                $join->on('c.id', '=', 'u.conversation_id')->on('c.tenant_id', '=', 'u.tenant_id');
            })
            ->leftJoin('hpbrain_ai_conversation_turns as a', function ($join) {
                $join->on('a.conversation_id', '=', 'u.conversation_id')
                    ->on('a.tenant_id', '=', 'u.tenant_id')
                    ->whereRaw('a.turn_index = u.turn_index + 1')
                    ->where('a.role', '=', 'assistant');
            })
            ->where('c.tenant_id', $tenant)
            ->where('c.module_key', $module)
            ->where('u.role', 'user')
            ->orderByDesc('u.created_date')
            ->limit(self::RECENT)
            ->get(['u.id', 'u.content', 'u.created_date', 'c.user_id', 'c.session_key', 'a.id as answer_id', 'a.error', 'a.latency_ms'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'question' => mb_strimwidth((string) $row->content, 0, 160, '…'),
                'intent' => null,
                'confidence' => null,
                'status' => $row->answer_id === null ? 'unanswered' : ($row->error === null ? 'answered' : 'failed'),
                'duration_ms' => $row->latency_ms === null ? null : (int) $row->latency_ms,
                'user_id' => $row->user_id === null ? null : (string) $row->user_id,
                'conversation' => (string) $row->session_key,
                'created_at' => $row->created_date,
            ])
            ->all();
    }

    private function dailySeries(string $module, string $tenant): array
    {
        if (! Schema::hasTable('hpbrain_ai_conversations') || ! Schema::hasTable('hpbrain_ai_conversation_turns')) {
            return [];
        }

        return DB::table('hpbrain_ai_conversation_turns as t')
            ->join('hpbrain_ai_conversations as c', function ($join) {
                $join->on('c.id', '=', 't.conversation_id')->on('c.tenant_id', '=', 't.tenant_id');
            })
            ->where('c.tenant_id', $tenant)
            ->where('c.module_key', $module)
            ->where('t.role', 'user')
            ->where('t.created_date', '>=', Platform::daysAgo(30))
            ->selectRaw('date(t.created_date) as day, count(*) as turns')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['date' => (string) $row->day, 'turns' => (int) $row->turns])
            ->all();
    }

    // ------------------------------------------------------------------ guardrails

    /**
     * The module's template posture, counted over the templates this tenant actually
     * resolves: one per template_key — the tenant's own row shadows the platform's, and
     * only the latest version of the winning owner counts (older versions and the
     * shadowed platform copy are not separate templates).
     */
    private function reviewPosture(string $module, string $tenant): array
    {
        if (! Schema::hasTable('hpbrain_ai_templates')) {
            return ['available' => false, 'reason' => 'hpbrain_ai_templates is not on this deployment.'];
        }

        $rows = Platform::visible(DB::table('hpbrain_ai_templates')->where('module_key', $module), $tenant)
            ->get(['template_key', 'tenant_id', 'version', 'status', 'requires_review', 'allow_as_evidence']);

        $winners = [];

        foreach ($rows as $row) {
            $key = (string) $row->template_key;
            $current = $winners[$key] ?? null;
            $rowIsTenant = (string) $row->tenant_id === $tenant;

            if ($current === null
                || ($rowIsTenant && (string) $current->tenant_id !== $tenant)
                || ($rowIsTenant === ((string) $current->tenant_id === $tenant) && (int) $row->version > (int) $current->version)) {
                $winners[$key] = $row;
            }
        }

        $count = fn (callable $test) => count(array_filter($winners, $test));

        return [
            'available' => true,
            'templates' => count($winners),
            'published' => $count(fn ($r) => (string) $r->status === 'published'),
            'requires_review' => $count(fn ($r) => (bool) $r->requires_review),
            'allowed_as_evidence' => $count(fn ($r) => (bool) $r->allow_as_evidence),
        ];
    }

    /** This module's most recent metered calls that a quota refused, or that failed. */
    private function refusals(string $module, string $tenant): array
    {
        if (! $this->attributionAvailable()) {
            return [];
        }

        return $this->events($tenant, $module)
            ->whereIn('outcome', ['refused', 'failed'])
            ->orderByDesc('created_date')
            ->limit(self::RECENT)
            ->get(['id', 'ai_module', 'outcome', 'error', 'provider', 'model', 'user_id', 'related_type', 'created_date'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'reference' => 'usage-' . $row->id,
                'template_key' => null,
                'purpose' => $row->related_type ?? $row->ai_module,
                'capability' => (string) $row->ai_module,
                'status' => (string) $row->outcome,
                'reason' => $row->error,
                'provider' => $row->provider,
                'model' => $row->model,
                'requested_by' => $row->user_id === null ? null : (string) $row->user_id,
                'requested_by_role' => null,
                'created_at' => $row->created_date,
            ])
            ->all();
    }

    /**
     * Every refused and failed call from this module, counted in SQL over ALL of them
     * (the `refusals` list above is only the most recent). Refused = a quota stopped
     * the call before it was sent; failed = the provider or the network errored.
     */
    private function refusalCounts(string $module, string $tenant): array
    {
        if (! $this->attributionAvailable()) {
            return [];
        }

        return $this->events($tenant, $module)
            ->whereIn('outcome', ['refused', 'failed'])
            ->selectRaw('outcome, count(*) as c')
            ->groupBy('outcome')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($row) => ['status' => (string) $row->outcome, 'count' => (int) $row->c])
            ->all();
    }

    /** @return array{refused:int, failed:int, total:int}|null Totals over every call, null when unattributable. */
    private function refusalTotals(string $module, string $tenant): ?array
    {
        if (! $this->attributionAvailable()) {
            return null;
        }

        $counts = array_column($this->refusalCounts($module, $tenant), 'count', 'status');
        $refused = (int) ($counts['refused'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);

        return ['refused' => $refused, 'failed' => $failed, 'total' => $refused + $failed];
    }

    // ------------------------------------------------------------------ activity ledger

    public function recordActivity(Request $request, string $module): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenant = $scope->tenantId;

            if (! $this->modules->exists($module, $tenant)) {
                return $this->failure('That module is not one this organisation has.', 404);
            }

            $data = $request->validate([
                'operation' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_.-]+$/'],
                'operation_label' => 'nullable|string|max:120',
                'capability' => 'nullable|string|max:40',
                'status' => 'required|string|in:completed,failed,denied,skipped',
                'message' => 'nullable|string|max:2000',
                'subject_entity_key' => 'nullable|string|max:100',
                'subject_id' => 'nullable|string|max:64',
                'subject_label' => 'nullable|string|max:150',
                'reference' => 'nullable|string|max:120',
                'template_id' => 'nullable|string|uuid',
                'prompt_id' => 'nullable|string|uuid',
                'agent_id' => 'nullable|string|max:60',
                'agent_name' => 'nullable|string|max:120',
                'agent_run_id' => 'nullable|string|max:60',
                'workflow' => 'nullable|string|max:120',
                'tool' => 'nullable|string|max:120',
                'result' => 'nullable|array',
            ]);

            $used = [];

            foreach (['template_id' => 'template', 'prompt_id' => 'prompt'] as $field => $slot) {
                if (! isset($data[$field])) {
                    continue;
                }

                $row = $this->resolveTemplate((string) $data[$field], $module, $tenant);

                if ($row === null) {
                    return $this->failure("That {$slot} does not exist for the {$module} module, so the activity was not recorded.", 422);
                }

                $used[$slot] = $row;
            }

            if (($data['agent_id'] ?? null) !== null || ($data['agent_name'] ?? null) !== null) {
                $used['agent'] = [
                    'id' => $data['agent_id'] ?? null,
                    'name' => $data['agent_name'] ?? null,
                    'run_id' => $data['agent_run_id'] ?? null,
                    'source' => 'hpbrain_ai_tool_agents',
                ];
            }

            foreach (['workflow', 'tool'] as $field) {
                if (($data[$field] ?? null) !== null) {
                    $used[$field] = $data[$field];
                }
            }

            $id = $this->audit->record("module.{$module}.{$data['operation']}", $scope, [
                'actor_label' => $this->actorLabel($scope),
                'subject_entity_key' => $data['subject_entity_key'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'related_type' => isset($used['template']) || isset($used['prompt']) ? 'hpbrain_ai_templates' : null,
                'related_id' => $used['template']['id'] ?? $used['prompt']['id'] ?? null,
                'outcome' => match ($data['status']) {
                    'completed' => 'success',
                    'denied' => 'rejected',
                    default => 'failure',
                },
                'message' => $data['message'] ?? ($data['operation_label'] ?? $data['operation']),
                'payload' => [
                    'module' => $module,
                    'operation' => $data['operation'],
                    'operation_label' => $data['operation_label'] ?? null,
                    'capability' => $data['capability'] ?? null,
                    'status' => $data['status'],
                    'subject_label' => $data['subject_label'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'used' => $used,
                    'result' => $data['result'] ?? null,
                ],
            ]);

            return $this->success(
                $id === null ? 'The activity could not be recorded.' : 'Activity recorded.',
                ['recorded' => $id !== null, 'id' => $id],
                $id === null ? 202 : 201
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function activity(Request $request, string $module): JsonResponse
    {
        try {
            $tenant = $this->scope($request)->tenantId;

            if (! $this->modules->exists($module, $tenant)) {
                return $this->failure('That module is not one this organisation has.', 404);
            }

            if (! Schema::hasTable('hpbrain_ai_audit_logs')) {
                return $this->success('No ledger on this deployment.', [
                    'module' => $this->modules->identity($module, $tenant),
                    'available' => false,
                    'reason' => 'hpbrain_ai_audit_logs is not on this deployment.',
                    'total' => 0,
                    'entries' => [],
                    'by_operation' => [],
                ]);
            }

            $prefix = "module.{$module}.";

            // THIS tenant's rows only. `_` is a LIKE wildcard, so the prefix is also
            // compared exactly — `module.knowledge_graph.` must not match a sibling key.
            $query = DB::table('hpbrain_ai_audit_logs')
                ->where('tenant_id', $tenant)
                ->where('event_type', 'like', $prefix . '%')
                ->whereRaw('SUBSTR(event_type, 1, ?) = ?', [strlen($prefix), $prefix]);

            if ($request->filled('operation')) {
                $query->where('event_type', $prefix . (string) $request->input('operation'));
            }

            if ($request->filled('outcome')) {
                $query->where('outcome', (string) $request->input('outcome'));
            }

            if ($request->filled('subject_id')) {
                $query->where('subject_id', (string) $request->input('subject_id'));
            }

            return $this->success('Module activity resolved.', [
                'module' => $this->modules->identity($module, $tenant),
                'available' => true,
                'total' => (int) (clone $query)->count(),
                'entries' => (clone $query)
                    ->orderByDesc('created_date')
                    ->limit($this->limit($request))
                    ->get()
                    ->map(fn ($row) => $this->presentEntry($row, $prefix))
                    ->all(),
                'by_operation' => (clone $query)
                    ->selectRaw('event_type, outcome, count(*) as c')
                    ->groupBy('event_type', 'outcome')
                    ->get()
                    ->map(fn ($row) => [
                        'operation' => substr((string) $row->event_type, strlen($prefix)),
                        'outcome' => (string) $row->outcome,
                        'count' => (int) $row->c,
                    ])
                    ->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    private function presentEntry(object $row, string $prefix): array
    {
        $payload = Platform::decode($row->payload ?? null);

        return [
            'id' => (string) $row->id,
            'operation' => substr((string) $row->event_type, strlen($prefix)),
            'operation_label' => $payload['operation_label'] ?? null,
            'capability' => $payload['capability'] ?? null,
            'status' => $payload['status'] ?? ($row->outcome === 'success' ? 'completed' : 'failed'),
            'outcome' => $row->outcome,
            'message' => $row->message,
            'actor_id' => $row->actor_id === null ? null : (string) $row->actor_id,
            'actor_label' => $row->actor_label,
            'subject_entity_key' => $row->subject_entity_key,
            'subject_id' => $row->subject_id === null ? null : (string) $row->subject_id,
            'subject_label' => $payload['subject_label'] ?? null,
            'reference' => $payload['reference'] ?? null,
            'used' => is_array($payload['used'] ?? null) ? $payload['used'] : [],
            'result' => $payload['result'] ?? null,
            'created_at' => $row->created_date,
        ];
    }

    private function resolveTemplate(string $id, string $module, string $tenant): ?array
    {
        if (! Schema::hasTable('hpbrain_ai_templates')) {
            return null;
        }

        $row = Platform::visible(
            DB::table('hpbrain_ai_templates')->where('id', $id)->where('module_key', $module),
            $tenant
        )->first();

        return $row === null ? null : [
            'id' => (string) $row->id,
            'key' => (string) $row->template_key,
            'name' => (string) $row->name,
            'kind' => (string) ($row->kind ?? 'prompt'),
            'version' => (int) ($row->version ?? 1),
            'status' => (string) $row->status,
        ];
    }

    /** HP Brain's people who sign in live in hpbrain_auth_users. */
    private function actorLabel(AiIntelligenceScope $scope): ?string
    {
        if ($scope->userId === '' || ! Schema::hasTable('hpbrain_auth_users')) {
            return null;
        }

        $name = DB::table('hpbrain_auth_users')
            ->where('id', $scope->userId)
            ->where('tenant_id', $scope->tenantId)
            ->value('name');

        $name = trim((string) $name);

        return $name === '' ? null : $name;
    }
}
