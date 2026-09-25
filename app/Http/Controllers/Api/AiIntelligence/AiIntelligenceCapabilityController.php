<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Configuration\ProviderCatalog;
use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The AI & Intelligence console — one read-only endpoint behind all twelve
 * capabilities, answered from HP Brain's own tables for the caller's tenant.
 *
 * Ported from G2G's CapabilityController. The capability slugs are the shared
 * vocabulary; the tables are HP Brain's:
 *
 *   providers          hpbrain_ai_api_keys
 *   models             hpbrain_ai_models (+ config/brain.php driver)
 *   prompts            hpbrain_ai_templates
 *   policies           hpbrain_ai_policies
 *   agents             hpbrain_executors / hpbrain_eso_executions (what Agent Monitor reads)
 *   conversational-ai  hpbrain_ai_conversations / _turns
 *   knowledge-rag      hpbrain_knowledge_assets / hpbrain_evidence
 *   recommendations    hpbrain_recommendations
 *   knowledge-graph    hpbrain_entity_mappings / hpbrain_context_entities
 *   evaluation         hpbrain_ai_eval_runs / _cases
 *   usage-cost         hpbrain_ai_usage_events (+ hpbrain_ai_executions, the AiGateway ledger)
 *   audit              hpbrain_ai_audit_logs (+ hpbrain_audit_logs)
 *
 * `unavailable` (a table is not migrated, named in `missing_tables`) and `empty`
 * (installed, nothing recorded for this tenant) are different facts and reported
 * differently.
 *
 * TENANT SCOPE. Every query goes through scoped(). This layer's own configuration
 * tables include platform rows ('*') because every tenant resolves them; the
 * intelligence-loop tables are this tenant's rows only.
 */
final class AiIntelligenceCapabilityController extends AiIntelligenceController
{
    private const TABLES = [
        'providers' => ['hpbrain_ai_api_keys'],
        'models' => [],
        'prompts' => ['hpbrain_ai_templates'],
        'policies' => ['hpbrain_ai_policies'],
        'agents' => ['hpbrain_executors', 'hpbrain_eso_executions'],
        'conversational-ai' => ['hpbrain_ai_conversations', 'hpbrain_ai_conversation_turns'],
        'knowledge-rag' => ['hpbrain_knowledge_assets', 'hpbrain_evidence'],
        'recommendations' => ['hpbrain_recommendations'],
        'knowledge-graph' => ['hpbrain_entity_mappings', 'hpbrain_context_entities'],
        'evaluation' => ['hpbrain_ai_eval_runs', 'hpbrain_ai_eval_cases'],
        'usage-cost' => ['hpbrain_ai_usage_events'],
        'audit' => ['hpbrain_ai_audit_logs'],
    ];

    /** Configuration tables whose '*' rows every tenant resolves. */
    private const PLATFORM_AWARE = [
        'hpbrain_ai_api_keys', 'hpbrain_ai_models', 'hpbrain_ai_modules',
        'hpbrain_ai_templates', 'hpbrain_ai_policies',
    ];

    public function __construct(private readonly ProviderCatalog $providers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            $capabilities = [];

            foreach (array_keys(self::TABLES) as $key) {
                $capabilities[] = ['key' => $key] + $this->summarise($key, $tenantId);
            }

            return $this->success('AI capabilities resolved.', [
                'tenant_id' => $tenantId,
                'capabilities' => $capabilities,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function show(Request $request, string $capability): JsonResponse
    {
        try {
            $capability = strtolower(trim($capability));

            if (! array_key_exists($capability, self::TABLES)) {
                return $this->failure("\"{$capability}\" is not an AI capability.", 404);
            }

            $tenantId = $this->scope($request)->tenantId;
            $missing = $this->missingTables($capability);

            if ($missing !== []) {
                return $this->success('This capability is not installed on this deployment.', [
                    'key' => $capability,
                    'state' => 'unavailable',
                    'missing_tables' => $missing,
                    'metrics' => [],
                    'table' => null,
                ]);
            }

            $limit = $this->limit($request, 25, 100);

            $detail = match ($capability) {
                'providers' => $this->providersDetail($tenantId, $limit),
                'models' => $this->models($tenantId, $limit),
                'prompts' => $this->prompts($tenantId, $limit),
                'policies' => $this->policies($tenantId, $limit),
                'agents' => $this->agents($tenantId, $limit),
                'conversational-ai' => $this->conversational($tenantId, $limit),
                'knowledge-rag' => $this->knowledge($tenantId, $limit),
                'recommendations' => $this->recommendations($tenantId, $limit),
                'knowledge-graph' => $this->knowledgeGraph($tenantId, $limit),
                'evaluation' => $this->evaluation($tenantId, $limit),
                'usage-cost' => $this->usage($tenantId, $limit),
                'audit' => $this->audit($tenantId, $limit),
            };

            return $this->success('Capability resolved.', [
                'key' => $capability,
                'tenant_id' => $tenantId,
                'state' => ($detail['table']['rows'] ?? []) === [] ? 'empty' : 'live',
            ] + $detail);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ---------------------------------------------------------------------

    private function providersDetail(string $tenantId, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('keys', 'Credentials', $this->count('hpbrain_ai_api_keys', $tenantId)),
                $this->metric('active', 'Active', $this->count('hpbrain_ai_api_keys', $tenantId, ['status' => 1])),
                $this->metric('modules', 'Modules bound', $this->distinct('hpbrain_ai_api_keys', $tenantId, 'ai_module')),
            ],
            // `api_key` is never selected: a console that can display a credential can leak one.
            'table' => $this->rows('hpbrain_ai_api_keys', $tenantId, [
                'ai_module' => 'Module',
                'api_type' => 'Provider',
                'model' => 'Model',
                'account_email' => 'Account',
                'status' => 'Status',
                'updated_date' => 'Updated',
            ], $limit),
        ];
    }

    private function models(string $tenantId, int $limit): array
    {
        $rows = [];
        $active = $this->providers->configuredDriver() ?? 'anthropic';

        if (Schema::hasTable('hpbrain_ai_models')) {
            $catalogue = $this->scoped('hpbrain_ai_models', $tenantId)
                ->where('status', 1)
                ->orderBy('provider')
                ->orderBy('sort_order')
                ->limit($limit)
                ->get();

            foreach ($catalogue as $row) {
                $rows[] = [
                    'provider' => (string) $row->provider,
                    'model' => (string) $row->model_id,
                    'label' => (string) $row->label,
                    'max_output_tokens' => $row->max_output_tokens === null ? '—' : (string) $row->max_output_tokens,
                    'scope' => Platform::isPlatform($row) ? 'Platform' : 'This organisation',
                    'state' => (string) $row->provider === $active ? 'Active driver' : 'Selectable',
                ];
            }
        }

        return [
            'metrics' => [
                $this->metric('models', 'Models in catalogue', $this->count('hpbrain_ai_models', $tenantId, ['status' => 1])),
                $this->metric('providers', 'Providers offered', $this->distinct('hpbrain_ai_models', $tenantId, 'provider')),
                $this->metric('pinned', 'Templates pinning a model', $this->distinct('hpbrain_ai_templates', $tenantId, 'model')),
            ],
            'table' => [
                'columns' => [
                    ['key' => 'provider', 'label' => 'Provider'],
                    ['key' => 'model', 'label' => 'Model'],
                    ['key' => 'label', 'label' => 'Name'],
                    ['key' => 'max_output_tokens', 'label' => 'Max output tokens'],
                    ['key' => 'scope', 'label' => 'Scope'],
                    ['key' => 'state', 'label' => 'State'],
                ],
                'rows' => $rows,
            ],
        ];
    }

    private function prompts(string $tenantId, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('templates', 'Templates', $this->count('hpbrain_ai_templates', $tenantId)),
                $this->metric('active', 'Published', $this->count('hpbrain_ai_templates', $tenantId, ['status' => 'published'])),
                $this->metric('modules', 'Modules covered', $this->distinct('hpbrain_ai_templates', $tenantId, 'module_key')),
            ],
            'table' => $this->rows('hpbrain_ai_templates', $tenantId, [
                'template_key' => 'Key',
                'name' => 'Name',
                'module_key' => 'Module',
                'version' => 'Version',
                'provider' => 'Provider',
                'status' => 'Status',
            ], $limit),
        ];
    }

    private function policies(string $tenantId, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('policies', 'Policies', $this->count('hpbrain_ai_policies', $tenantId)),
                $this->metric('active', 'Active', $this->count('hpbrain_ai_policies', $tenantId, ['status' => 1])),
                $this->metric('assignments', 'Assignments', $this->count('hpbrain_ai_policy_assignments', $tenantId)),
            ],
            'table' => $this->rows('hpbrain_ai_policies', $tenantId, [
                'name' => 'Policy',
                'policy_type' => 'Type',
                'status' => 'Status',
                'require_disclosure' => 'Disclosure',
                'updated_date' => 'Updated',
            ], $limit),
        ];
    }

    /** The executor registry Agent Monitor shows, and the ESO executions they run. */
    private function agents(string $tenantId, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('agents', 'Registered executors', $this->count('hpbrain_executors', $tenantId)),
                $this->metric('deployed', 'AI agent executors', $this->count('hpbrain_executors', $tenantId, ['executor_type' => 'ai'])),
                $this->metric('runs', 'ESO executions', $this->count('hpbrain_eso_executions', $tenantId)),
            ],
            'table' => $this->rows('hpbrain_eso_executions', $tenantId, [
                'eso_id' => 'ESO',
                'executor_type' => 'Executor type',
                'executed_by' => 'Executed by',
                'status' => 'Status',
                'started_date' => 'Started',
                'completed_date' => 'Completed',
            ], $limit),
        ];
    }

    private function conversational(string $tenantId, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('conversations', 'Conversations', $this->count('hpbrain_ai_conversations', $tenantId)),
                $this->metric('turns', 'Turns', $this->count('hpbrain_ai_conversation_turns', $tenantId)),
                $this->metric('failed', 'Turns that failed', (int) $this->scoped('hpbrain_ai_conversation_turns', $tenantId)->whereNotNull('error')->count()),
            ],
            'table' => $this->rows('hpbrain_ai_conversations', $tenantId, [
                'title' => 'Conversation',
                'module_key' => 'Module',
                'turn_count' => 'Turns',
                'status' => 'Status',
                'last_turn_at' => 'Last activity',
            ], $limit),
        ];
    }

    private function knowledge(string $tenantId, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('assets', 'Knowledge assets', $this->count('hpbrain_knowledge_assets', $tenantId)),
                $this->metric('evidence', 'Evidence records', $this->count('hpbrain_evidence', $tenantId)),
                $this->metric('categories', 'Categories', $this->distinct('hpbrain_knowledge_assets', $tenantId, 'category')),
            ],
            'table' => $this->rows('hpbrain_knowledge_assets', $tenantId, [
                'title' => 'Document',
                'category' => 'Category',
                'confidence' => 'Confidence',
                'reuse_count' => 'Reused',
                'status' => 'Status',
                'updated_date' => 'Updated',
            ], $limit),
        ];
    }

    private function recommendations(string $tenantId, int $limit): array
    {
        $open = (int) $this->scoped('hpbrain_recommendations', $tenantId)
            ->whereIn(DB::raw('LOWER(TRIM(status))'), ['open', 'pending'])
            ->count();

        return [
            'metrics' => [
                $this->metric('total', 'Recommendations', $this->count('hpbrain_recommendations', $tenantId)),
                $this->metric('open', 'Open', $open),
                $this->metric('categories', 'Categories', $this->distinct('hpbrain_recommendations', $tenantId, 'category')),
            ],
            'table' => $this->rows('hpbrain_recommendations', $tenantId, [
                'title' => 'Recommendation',
                'category' => 'Category',
                'priority' => 'Priority',
                'confidence' => 'Confidence',
                'impact' => 'Impact',
                'status' => 'Status',
            ], $limit),
        ];
    }

    private function knowledgeGraph(string $tenantId, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('mappings', 'Entity mappings', $this->count('hpbrain_entity_mappings', $tenantId)),
                $this->metric('entities', 'Universal entities', $this->distinct('hpbrain_entity_mappings', $tenantId, 'universal_entity')),
                $this->metric('terms', 'Context terms', $this->count('hpbrain_context_entities', $tenantId)),
            ],
            'table' => $this->rows('hpbrain_entity_mappings', $tenantId, [
                'source_system' => 'Source system',
                'source_entity' => 'Source entity',
                'universal_entity' => 'Universal entity',
                'universal_field' => 'Universal field',
                'mapping_type' => 'Mapping',
                'is_active' => 'Active',
            ], $limit),
        ];
    }

    private function evaluation(string $tenantId, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('evaluations', 'Evaluations', $this->count('hpbrain_ai_eval_runs', $tenantId)),
                $this->metric('completed', 'Runs completed', $this->count('hpbrain_ai_eval_runs', $tenantId, ['status' => 'completed'])),
                $this->metric('cases', 'Cases', $this->count('hpbrain_ai_eval_cases', $tenantId)),
            ],
            'table' => $this->rows('hpbrain_ai_eval_runs', $tenantId, [
                'name' => 'Evaluation',
                'template_key' => 'Template',
                'model' => 'Model',
                'status' => 'Status',
                'score' => 'Score',
                'finished_at' => 'Run',
            ], $limit),
        ];
    }

    private function usage(string $tenantId, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('calls', 'Model calls metered', $this->count('hpbrain_ai_usage_events', $tenantId)),
                $this->metric('tokens', 'Tokens consumed', (int) $this->scoped('hpbrain_ai_usage_events', $tenantId)->sum(DB::raw('input_tokens + output_tokens'))),
                $this->metric('modules', 'Modules calling', $this->distinct('hpbrain_ai_usage_events', $tenantId, 'ai_module')),
                // The intelligence loop's own ledger, written by AiGateway — reported
                // beside this meter, not merged into it.
                $this->metric('gateway_executions', 'Loop AI executions (other ledger)', $this->count('hpbrain_ai_executions', $tenantId)),
            ],
            'table' => $this->rows('hpbrain_ai_usage_events', $tenantId, [
                'ai_module' => 'Module',
                'provider' => 'Provider',
                'model' => 'Model',
                'input_tokens' => 'In',
                'output_tokens' => 'Out',
                'estimated_cost_usd' => 'Cost (USD)',
                'outcome' => 'Outcome',
                'created_date' => 'When',
            ], $limit),
        ];
    }

    private function audit(string $tenantId, int $limit): array
    {
        return [
            'metrics' => [
                $this->metric('events', 'AI events recorded', $this->count('hpbrain_ai_audit_logs', $tenantId)),
                $this->metric('types', 'Event types', $this->distinct('hpbrain_ai_audit_logs', $tenantId, 'event_type')),
                $this->metric('platform_events', 'Platform audit entries', $this->count('hpbrain_audit_logs', $tenantId)),
            ],
            'table' => $this->rows('hpbrain_ai_audit_logs', $tenantId, [
                'event_type' => 'Event',
                'actor_id' => 'Actor',
                'actor_type' => 'Actor type',
                'outcome' => 'Outcome',
                'message' => 'Message',
                'created_date' => 'When',
            ], $limit),
        ];
    }

    // ---------------------------------------------------------------------

    private function summarise(string $capability, string $tenantId): array
    {
        $missing = $this->missingTables($capability);

        if ($missing !== []) {
            return ['state' => 'unavailable', 'count' => 0, 'missing_tables' => $missing];
        }

        $primary = self::TABLES[$capability][0] ?? 'hpbrain_ai_models';
        $count = $this->count($primary, $tenantId);

        return [
            'state' => $count > 0 ? 'live' : 'empty',
            'count' => $count,
            'primary_table' => $primary,
        ];
    }

    /** @return array<int, string> */
    private function missingTables(string $capability): array
    {
        return array_values(array_filter(
            self::TABLES[$capability],
            fn (string $table) => ! Schema::hasTable($table)
        ));
    }

    /** @param array<string, mixed> $where */
    private function count(string $table, string $tenantId, array $where = []): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = $this->scoped($table, $tenantId);

        foreach ($where as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $query->where($column, $value);
            }
        }

        return (int) $query->count();
    }

    private function distinct(string $table, string $tenantId, string $column): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (int) $this->scoped($table, $tenantId)->distinct()->count($column);
    }

    /**
     * A table's most recent rows, restricted to columns that exist.
     *
     * @param  array<string, string>  $columns
     * @return array{columns:array<int, array{key:string,label:string}>, rows:array<int, array<string, string|null>>}
     */
    private function rows(string $table, string $tenantId, array $columns, int $limit): array
    {
        if (! Schema::hasTable($table)) {
            return ['columns' => [], 'rows' => []];
        }

        $present = array_filter(
            $columns,
            fn (string $label, string $column) => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH
        );

        if ($present === []) {
            return ['columns' => [], 'rows' => []];
        }

        $rows = $this->scoped($table, $tenantId)
            ->select(array_keys($present))
            ->orderByDesc($this->orderColumn($table))
            ->limit($limit)
            ->get()
            ->map(fn ($row) => array_map(
                fn ($value) => $value === null ? null : (string) $value,
                (array) $row
            ))
            ->all();

        return [
            'columns' => array_map(
                fn (string $column, string $label) => ['key' => $column, 'label' => $label],
                array_keys($present),
                array_values($present)
            ),
            'rows' => $rows,
        ];
    }

    /** UUID ids are not chronological, so a timestamp is preferred wherever one exists. */
    private function orderColumn(string $table): string
    {
        foreach (['created_date', 'created_at', 'started_date', 'linked_date'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return 'id';
    }

    /** The one place a tenant filter is written. */
    private function scoped(string $table, string $tenantId): Builder
    {
        $query = DB::table($table);

        in_array($table, self::PLATFORM_AWARE, true)
            ? Platform::visible($query, $tenantId)
            : $query->where('tenant_id', $tenantId);

        foreach (['deleted_at', 'deleted_date'] as $softDelete) {
            if (Schema::hasColumn($table, $softDelete)) {
                $query->whereNull($softDelete);
            }
        }

        return $query;
    }

    /** @return array{key:string, label:string, value:int} */
    private function metric(string $key, string $label, int $value): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value];
    }
}
