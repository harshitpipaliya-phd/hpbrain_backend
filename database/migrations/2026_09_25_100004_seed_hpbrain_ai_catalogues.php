<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

/**
 * Platform-wide seed rows (`tenant_id = '*'`) for AI & Intelligence:
 *
 *   hpbrain_ai_models   the providers HP Brain can drive. Anthropic, Gemini and
 *                       DeepSeek come from config/brain.php (the keys and the
 *                       pricing map the existing AiGateway already uses — cost
 *                       per 1k = the map's per-million rate / 1000), plus
 *                       AI_MODEL under AI_PROVIDER if the map does not list it.
 *                       The OpenAI-compatible providers (OpenRouter, OpenAI,
 *                       Groq, Mistral) are the ones AiModelClient's chat-
 *                       completions wire reaches; their cost is left NULL — a
 *                       migration must not assert a vendor's price.
 *   hpbrain_ai_modules  HP Brain's own product areas, the list a template or a
 *                       policy is filed under.
 *
 * Idempotent: a row already present at platform scope is skipped. Nothing here
 * reads or writes G2G's ai_* tables.
 */
return new class extends Migration
{
    private const PLATFORM = '*';

    /** provider, model_id, label, max output tokens. */
    private const OPENAI_COMPATIBLE = [
        ['openrouter', 'deepseek/deepseek-chat', 'DeepSeek Chat (OpenRouter)', 4096],
        ['openrouter', 'openai/gpt-4o-mini', 'GPT-4o mini (OpenRouter)', 4096],
        ['openai', 'gpt-4o-mini', 'GPT-4o mini', 4096],
        ['openai', 'gpt-4o', 'GPT-4o', 4096],
        ['groq', 'llama-3.3-70b-versatile', 'Llama 3.3 70B', 4096],
        ['mistral', 'mistral-large-latest', 'Mistral Large', 4096],
    ];

    /** key, label, icon, description. Order is sort order. */
    private const MODULES = [
        ['signals', 'Signals', 'activity', 'Operational signals raised by the signal rules, and their triage.'],
        ['evidence', 'Evidence', 'file-search', 'Evidence records attached to signals, cases and recommendations.'],
        ['deliberation', 'Deliberation', 'scale', 'Cases, hypotheses and reasoning steps worked through before a decision.'],
        ['intelligence_workspace', 'Intelligence Workspace', 'brain', 'The workspace where recommendations are reviewed and acted on.'],
        ['decision_analytics', 'Decision Analytics', 'bar-chart-3', 'Decisions, outcomes and the analytics over them.'],
        ['executions', 'Executions', 'play-circle', 'ESO executions and their outcomes.'],
        ['eso', 'ESO Catalogue', 'list-checks', 'Executable standard operations and their definitions.'],
        ['agents', 'Agents & Executors', 'bot', 'The executor registry — human, AI, system and external executors.'],
        ['tasks', 'Tasks', 'check-square', 'Tasks and the work attributed to departments and people.'],
        ['departments', 'Departments', 'building-2', 'Department structure and department intelligence.'],
        ['people', 'People', 'users', 'People, positions and their profiles.'],
        ['capabilities', 'Capabilities', 'target', 'The capability model and proficiency.'],
        ['kasba', 'KASBA Assessment', 'gauge', 'Knowledge, ability, skill, behaviour and attitude assessment.'],
        ['knowledge_graph', 'Knowledge Graph', 'share-2', 'Entity mappings and the organisational graph.'],
        ['knowledge_library', 'Knowledge Library', 'library', 'Knowledge assets and their reuse.'],
        ['organisational_memory', 'Organisational Memory', 'archive', 'Learnings and organisational memory.'],
        ['policies', 'Policies', 'shield-check', 'Executor and governance policies.'],
        ['conversational_assistant', 'Conversational Assistant', 'message-square', 'The AI & Intelligence assistant.'],
    ];

    public function up(): void
    {
        $this->seedModels();
        $this->seedModules();
    }

    public function down(): void
    {
        if (Schema::hasTable('hpbrain_ai_models')) {
            $keys = array_map(fn (array $row) => $row['provider'] . '|' . $row['model_id'], $this->models());

            DB::table('hpbrain_ai_models')
                ->where('tenant_id', self::PLATFORM)
                ->get(['id', 'provider', 'model_id'])
                ->filter(fn ($row) => in_array($row->provider . '|' . $row->model_id, $keys, true))
                ->each(fn ($row) => DB::table('hpbrain_ai_models')->where('id', $row->id)->delete());
        }

        if (Schema::hasTable('hpbrain_ai_modules')) {
            DB::table('hpbrain_ai_modules')
                ->where('tenant_id', self::PLATFORM)
                ->whereIn('module_key', array_column(self::MODULES, 0))
                ->delete();
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function models(): array
    {
        $pricing = (array) config('brain.ai.pricing', []);
        $driver = trim((string) config('brain.ai.provider', ''));
        $driverModel = trim((string) config('brain.ai.model', ''));

        $rate = function (string $model, string $side) use ($pricing): ?float {
            $perMillion = $pricing[$model][$side] ?? null;

            return is_numeric($perMillion) ? round((float) $perMillion / 1000, 6) : null;
        };

        $rows = [];

        // Anthropic and DeepSeek: every model the pricing map knows, by vendor prefix.
        foreach (array_keys($pricing) as $model) {
            $provider = str_starts_with($model, 'claude-') ? 'anthropic'
                : (str_starts_with($model, 'deepseek-') ? 'deepseek'
                : (str_starts_with($model, 'gemini-') ? 'gemini' : null));

            if ($provider === null) {
                continue;
            }

            $rows[$provider . '|' . $model] = [
                'provider' => $provider,
                'model_id' => $model,
                'label' => $this->label($model),
                'max_output_tokens' => $provider === 'anthropic' ? 8192 : 4096,
                'input_cost_per_1k' => $rate($model, 'input'),
                'output_cost_per_1k' => $rate($model, 'output'),
            ];
        }

        // Gemini: the model AiServiceProvider falls back to for the gemini driver.
        $rows['gemini|gemini-2.5-flash'] ??= [
            'provider' => 'gemini',
            'model_id' => 'gemini-2.5-flash',
            'label' => 'Gemini 2.5 Flash',
            'max_output_tokens' => 8192,
            'input_cost_per_1k' => $rate('gemini-2.5-flash', 'input'),
            'output_cost_per_1k' => $rate('gemini-2.5-flash', 'output'),
        ];

        // The model this deployment actually runs, if the map does not list it.
        if (in_array($driver, ['anthropic', 'gemini', 'deepseek'], true) && $driverModel !== '') {
            $rows[$driver . '|' . $driverModel] ??= [
                'provider' => $driver,
                'model_id' => $driverModel,
                'label' => $this->label($driverModel),
                'max_output_tokens' => null,
                'input_cost_per_1k' => $rate($driverModel, 'input'),
                'output_cost_per_1k' => $rate($driverModel, 'output'),
            ];
        }

        foreach (self::OPENAI_COMPATIBLE as [$provider, $model, $label, $maxOut]) {
            $rows[$provider . '|' . $model] ??= [
                'provider' => $provider,
                'model_id' => $model,
                'label' => $label,
                'max_output_tokens' => $maxOut,
                'input_cost_per_1k' => null,
                'output_cost_per_1k' => null,
            ];
        }

        // Sort order: the configured driver model first for its provider, then the
        // cheapest priced model, so a capability with no model named never
        // defaults to the most expensive one. Unpriced models keep catalogue order.
        uasort($rows, function (array $a, array $b) {
            return [$a['provider'], $a['input_cost_per_1k'] === null ? 1 : 0, (float) $a['input_cost_per_1k']]
                <=> [$b['provider'], $b['input_cost_per_1k'] === null ? 1 : 0, (float) $b['input_cost_per_1k']];
        });

        $order = [];

        foreach ($rows as $key => &$row) {
            $provider = $row['provider'];
            $order[$provider] ??= 1;
            $row['sort_order'] = ($provider === $driver && $row['model_id'] === $driverModel) ? 0 : $order[$provider]++;
        }
        unset($row);

        return array_values($rows);
    }

    private function seedModels(): void
    {
        if (! Schema::hasTable('hpbrain_ai_models')) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        foreach ($this->models() as $row) {
            $exists = DB::table('hpbrain_ai_models')
                ->where('provider', $row['provider'])
                ->where('model_id', $row['model_id'])
                ->where('tenant_id', self::PLATFORM)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('hpbrain_ai_models')->insert($row + [
                'id' => Uuid::uuid4()->toString(),
                'tenant_id' => self::PLATFORM,
                'status' => 1,
                'created_date' => $now,
                'updated_date' => $now,
            ]);
        }
    }

    private function seedModules(): void
    {
        if (! Schema::hasTable('hpbrain_ai_modules')) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        foreach (self::MODULES as $index => [$key, $label, $icon, $description]) {
            $exists = DB::table('hpbrain_ai_modules')
                ->where('module_key', $key)
                ->where('tenant_id', self::PLATFORM)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('hpbrain_ai_modules')->insert([
                'id' => Uuid::uuid4()->toString(),
                'tenant_id' => self::PLATFORM,
                'module_key' => $key,
                'label' => $label,
                'domain' => 'hpbrain',
                'description' => $description,
                'icon' => $icon,
                'sort_order' => ($index + 1) * 10,
                'status' => 1,
                'created_date' => $now,
                'updated_date' => $now,
            ]);
        }
    }

    private function label(string $model): string
    {
        $known = [
            'claude-opus-5' => 'Claude Opus 5',
            'claude-sonnet-5' => 'Claude Sonnet 5',
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
            'deepseek-v4-flash' => 'DeepSeek V4 Flash',
        ];

        return $known[$model] ?? $model;
    }
};
