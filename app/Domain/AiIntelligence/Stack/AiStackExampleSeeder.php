<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Stack;

use App\Domain\AiIntelligence\Configuration\AiConfigurationResolver;
use App\Domain\AiIntelligence\Configuration\ProviderCatalog;
use App\Domain\AiIntelligence\Configuration\ProviderKeyResolver;
use App\Domain\AiIntelligence\Policies\AiPolicyCatalog;
use App\Domain\AiIntelligence\Reports\ModuleDataSourceCatalog;
use App\Domain\AiIntelligence\Support\Platform;
use App\Domain\Tenancy\TenantOwnedTables;
use App\Http\Controllers\Api\AiIntelligence\AiIntelligenceAskController;
use App\Http\Controllers\Api\AiIntelligence\AiIntelligencePolicyController;
use App\Http\Controllers\Api\AiIntelligence\AiIntelligenceTemplateController;
use App\Http\Controllers\Api\AiIntelligence\AiStackModelController;
use App\Http\Controllers\Api\AiIntelligence\AiStackModuleController;
use App\Http\Controllers\Api\AiIntelligence\AiStackReportController;
use App\Http\Controllers\Api\AiIntelligence\AiStackToolAgentController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * One real example of each AI Stack record, per tenant × module — the engine behind
 * `php artisan ai-stack:seed-examples`.
 *
 * NOTHING HERE WRITES A TABLE DIRECTLY. Every example is created by calling the same
 * controller action the AI Stack screens call (policy store, template store, tool-agent
 * store + run, workspace/report build, module model update, ask), with a request that
 * carries the tenant and the actor exactly as the `jwt` / `tenant` middleware would.
 * So each example is precisely what a user action creates — validated, scoped, versioned
 * and audited by the same code — and every one is also recorded on the module's
 * Activity ledger (hpbrain_ai_audit_logs `module.<key>.*`) through the module activity
 * action. The actor is `system:ai-stack-examples` (created_by / user_id / actor_id).
 *
 * IDEMPOTENT PER KIND: an example is created only when the tenant has NO row of that
 * kind for that module yet (its own rows only — a platform row does not count, and a
 * row a person created counts as much as an example). A second run creates nothing.
 *
 * Per-module wording is CONFIGURATION, not display data: the system prompt and central
 * risk are the module descriptors' copy (hp-enterprise-brain src/components/ai-stack/
 * modules/<key>.ts, copy.promptSystemDefault / copy.centralRisk), and the question is
 * about the module's own records. Labels, data sources, presets and module ids are all
 * read from the database at run time.
 */
final class AiStackExampleSeeder
{
    public const ACTOR = 'system:ai-stack-examples';

    public const KINDS = ['policy', 'prompt', 'report_template', 'agent', 'agent_run', 'report', 'model_binding', 'conversation'];

    public const CREATED = 'created';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const WOULD_CREATE = 'would create';

    /** module key => descriptor copy (promptSystemDefault / centralRisk). */
    private const COPY = [
        'departments' => [
            'central_risk' => 'reading a department\'s size as a judgement of it. A headcount is how many people are recorded against a unit, not how well it is staffed or run, and no answer may call a department under-resourced, bloated or failing from the roster alone.',
            'prompt_system' => 'You describe an organisation\'s department structure. Work only from the department rows you are given — name, parent, status and people count — never invent a reporting line the parent field does not show, and never judge a department by its size.',
        ],
        'people' => [
            'central_risk' => 'inferring performance or personal traits from roster fields. A role, a position or an incomplete record says what has been recorded about someone, not how good they are at their work or what kind of person they are — and the roster carries no contact details, so none may be guessed or supplied.',
            'prompt_system' => 'You describe the people roster of an organisation. Work only from the name, department, role, position and record completeness you are given; never infer performance, seniority beyond the recorded position, or any personal trait, and never state an email, phone number or address — the roster holds none.',
        ],
        'capabilities' => [
            'central_risk' => 'treating an assignment as proof of proficiency. A capability assigned to a person, department or role says it is required of them, not that they have it, and no answer may describe anyone as capable or lacking from assignments alone.',
            'prompt_system' => 'You describe which capabilities are assigned to which targets. Work only from the assignment rows you are given, never read an assignment as evidence the target holds the capability, and never invent a criticality the record does not carry.',
        ],
        'signals' => [
            'central_risk' => 'presenting a signal as a confirmed finding. A signal is something the data has flagged for attention; until evidence and deliberation have tested it, no answer may state it as what happened, name its cause, or treat its severity as proven.',
            'prompt_system' => 'You summarise open signals. Describe each as flagged, not confirmed: state its source, classification, severity, confidence and the entity it concerns exactly as recorded, never assert a cause, and never raise or lower a severity or confidence the record does not carry.',
        ],
        'evidence' => [
            'central_risk' => 'upgrading confidence. Evidence is held at the confidence it was recorded with, and no answer may describe it as stronger, more certain or more conclusive than that — several weak records do not add up to a strong one.',
            'prompt_system' => 'You summarise the evidence attached to signals. State each record\'s type, source, confidence and status exactly as recorded, never describe evidence as more certain than its recorded confidence, and never combine records into a conclusion they do not state.',
        ],
        'deliberation' => [
            'central_risk' => 'picking a hypothesis as concluded. A case is open because its hypotheses are still being weighed, and no answer may declare one of them the explanation, rank one as the winner, or describe the case as decided — that is for the people deliberating.',
            'prompt_system' => 'You describe open investigation cases. State each case\'s title, status, originating signal and its hypothesis and reasoning-step counts as recorded; never say which hypothesis is right, never rank hypotheses, and never describe an open case as concluded.',
        ],
        'intelligence_workspace' => [
            'central_risk' => 'presenting a recommendation as a decision. A recommendation is a proposal waiting on the governance gate; no answer may say it has been accepted, tell anyone to act on it, or restate its impact, cost or risk as anything other than what was recorded.',
            'prompt_system' => 'You explain recommendations to the people who will decide on them. State each one\'s category, priority, confidence, impact, cost, risk and status exactly as recorded; never describe a pending recommendation as approved, never advise acting on it, and never re-estimate its impact, cost or risk.',
        ],
        'executions' => [
            'central_risk' => 'claiming an execution caused an outcome. An outcome is recorded against the decision an execution served; no answer may say the execution produced it, call a running execution successful, or describe a failed or rolled-back one as anyone\'s fault.',
            'prompt_system' => 'You summarise ESO executions and the outcomes recorded against their decisions. State each execution\'s ESO, status, executor type, dates and recorded outcome as given; never claim an execution caused an outcome, never call a running execution a success, and never assign blame for a failure.',
        ],
        'decision_analytics' => [
            'central_risk' => 'judging a decision by an outcome that has not been measured. A decision with no recorded outcome is unmeasured, not good or bad, and no answer may grade a decision, or the people who approved it, beyond the outcomes actually recorded against it.',
            'prompt_system' => 'You describe decisions and the outcomes recorded against them. State each decision\'s recommendation, status, confidence, approval date and recorded outcomes as given; treat a decision with no outcome as unmeasured, and never grade a decision or its approvers beyond what is recorded.',
        ],
        'mental_models' => [
            'central_risk' => 'substituting a model of its own for the organisation\'s. The mental models here are the ones the organisation has recorded; no answer may add a model, extend one beyond its recorded description and domain, or treat a draft or retired version as the one in use.',
            'prompt_system' => 'You explain the mental models an organisation reasons with. Work only from each model\'s recorded name, description, domain, version and status; never add a model that is not recorded, never stretch one beyond its domain, and always say which version and status you are describing.',
        ],
        'knowledge_graph' => [
            'central_risk' => 'inventing a relationship the graph does not hold. A mapping links one source field to one universal field; no answer may extend a mapping by analogy, infer an edge between entities that no mapping records, or present the graph\'s shape as a fact about the organisation\'s people.',
            'prompt_system' => 'You explain entity mappings to an auditor. State only the source system, source entity, source field, universal entity, universal field and mapping type of the rows you are given, and never infer a mapping or relationship the rows do not record.',
        ],
        'kasba' => [
            'central_risk' => 'turning a proficiency profile into a verdict on a person. KASBA levels are assessed readings at a recorded evidence confidence and date; no answer may rank people by them, call anyone unfit, or fill in a level that was never assessed.',
            'prompt_system' => 'You describe KASBA proficiency profiles. State the knowledge, ability, skill, behaviour and attitude levels, evidence confidence and assessment date exactly as recorded; treat a blank level as not assessed, never rank or compare individuals, and never judge anyone\'s fitness for a role.',
        ],
        'knowledge_library' => [
            'central_risk' => 'treating reuse as validation. How often an asset has been reused says it is popular, not that it is correct; no answer may raise an asset\'s recorded confidence because of its reuse count, or present a draft asset as settled knowledge.',
            'prompt_system' => 'You describe the organisation\'s knowledge assets. State each asset\'s title, category, confidence, status and reuse count exactly as recorded; never treat a high reuse count as proof the asset is right, and always say when an asset is not yet published.',
        ],
        'organisational_memory' => [
            'central_risk' => 'generalising a learning beyond where it was learned. Each learning comes from a recorded outcome in a recorded domain; no answer may apply one outside that domain, present a learning not marked reusable as a rule, or describe it as more certain than its recorded confidence.',
            'prompt_system' => 'You recall what the organisation has learned. State each learning\'s pattern, domain, confidence and whether it is reusable exactly as recorded; never apply a learning outside its domain, and never present one not marked reusable as general guidance.',
        ],
        'eso' => [
            'central_risk' => 'describing an ESO that is not in force as if it were. Only a current, released version governs work; no answer may present a draft, retired, deprecated or superseded ESO as the standard, or restate an objective in words the definition does not use.',
            'prompt_system' => 'You describe executable standard operations. State each ESO\'s code, name, version, status, owner, objective and trust level exactly as recorded; always name its status, and never present a draft, retired, deprecated or superseded version as the standard in force.',
        ],
        'agents' => [
            'central_risk' => 'judging an executor by its run counts. Failed runs and workload are operational records, not a performance rating; no answer may call an executor — least of all a human one — unreliable or underperforming, or raise or lower a trust level the record does not carry.',
            'prompt_system' => 'You describe the executors that carry out work. State each executor\'s type, status, trust level, workload, capacity and run counts exactly as recorded; never rate an executor\'s performance, never blame one for failed runs, and never change a trust level in what you say.',
        ],
        'tasks' => [
            'central_risk' => 'turning the age of a work item into blame. An open item is attributed to an owner and department as imported; no answer may call its owner slow or negligent, reassign it, or present it as overdue against a deadline the record does not carry.',
            'prompt_system' => 'You summarise the open work queue. State each item\'s reference, dataset, category, status, owner, department and date exactly as recorded; never judge the owner, never invent a due date, and never suggest the item be reassigned.',
        ],
        'policies' => [
            'central_risk' => 'stating a business policy that is not a stored row. Every rule an answer cites must be a policy in the catalogue, at its recorded version and status; no answer may paraphrase one into a stricter or looser rule, fill a gap with what a policy "probably" says, or cite a superseded version as current.',
            'prompt_system' => 'You describe the organisation\'s business policies. Cite only policies in the rows you are given, by name, type, scope, version and status; never state a rule no row records, never tighten or loosen one, and never present a superseded version as current.',
        ],
    ];

    /** A question about each module's own records, for the --with-model-calls conversation. */
    private const QUESTIONS = [
        'departments' => 'How many departments are recorded, and which have the most active people?',
        'people' => 'How many people are on the roster, and how many records are missing a department, role or position?',
        'capabilities' => 'Which capabilities are assigned most often, and to which kinds of target?',
        'signals' => 'How many open signals are there and what are the most severe?',
        'evidence' => 'How much evidence is recorded, of which types, and at what confidence?',
        'deliberation' => 'Which cases are still open, and how many hypotheses are still being weighed?',
        'intelligence_workspace' => 'How many recommendations are pending, and which have the highest priority?',
        'executions' => 'How many ESO executions are running, completed or failed, and what outcomes are recorded against them?',
        'decision_analytics' => 'Which decisions have outcomes recorded, and which are still unmeasured?',
        'mental_models' => 'Which mental models are recorded, in which domains, and which are active?',
        'knowledge_graph' => 'Which universal entities do this organisation\'s source records map onto?',
        'kasba' => 'Which capabilities have KASBA assessments recorded, and which levels are still not assessed?',
        'knowledge_library' => 'Which knowledge assets are reused most, and which are not yet published?',
        'organisational_memory' => 'What has the organisation recorded as learnings, and which are marked reusable?',
        'eso' => 'Which ESOs are in force, and which are drafts, retired or superseded?',
        'agents' => 'Which executors are registered, and what are their run counts and workloads?',
        'tasks' => 'How many open work items are in the queue, and which are the oldest?',
        'policies' => 'Which business policies are active, and which versions are superseded?',
    ];

    public function __construct(
        private readonly AiStackModules $modules,
        private readonly ModuleDataSourceCatalog $sources,
        private readonly AiPolicyCatalog $policies,
        private readonly AiConfigurationResolver $resolver,
        private readonly ProviderCatalog $providers,
        private readonly ProviderKeyResolver $keys,
    ) {
    }

    /** @return array<int, string> Every module the command knows how to seed, in order. */
    public function knownModules(): array
    {
        return array_keys(self::COPY);
    }

    /**
     * Tenants that hold HP Brain data: an active entity mapping, an imported
     * operational record or a signal. Reserved ids ('*', 'platform', …) never.
     *
     * @return array<int, string>
     */
    public function tenants(): array
    {
        $ids = [];

        foreach ([
            'hpbrain_entity_mappings' => fn ($q) => $q->where('is_active', 1),
            'hpbrain_operational_records' => null,
            'hpbrain_signals' => null,
        ] as $table => $filter) {
            try {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $query = DB::table($table)->select('tenant_id')->distinct();

                if ($filter !== null) {
                    $filter($query);
                }

                foreach ($query->pluck('tenant_id') as $id) {
                    $ids[(string) $id] = true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        // array_keys() turns numeric-looking ids ("4") into ints; tenant ids are strings.
        $tenants = array_values(array_filter(
            array_map('strval', array_keys($ids)),
            fn (string $id) => trim($id) !== '' && ! in_array(strtolower($id), TenantOwnedTables::RESERVED_TENANT_IDS, true)
        ));

        sort($tenants, SORT_NATURAL);

        return $tenants;
    }

    /**
     * The modules this tenant has an active AI Stack for, that the command can seed.
     *
     * @param  array<int, string>  $only
     * @return array<int, string>
     */
    public function modulesFor(string $tenant, array $only = []): array
    {
        $stack = $this->modules->stackKeys($tenant);

        return array_values(array_filter(
            $this->knownModules(),
            fn (string $key) => in_array($key, $stack, true) && ($only === [] || in_array($key, $only, true))
        ));
    }

    /**
     * Seed one tenant × module. Each kind reports what happened to it.
     *
     * @return array<string, array{status:string, detail:string}>
     */
    public function seed(string $tenant, string $module, bool $withModelCalls = false, bool $dryRun = false): array
    {
        $label = $this->modules->label($module, $tenant);
        $out = [];

        foreach (self::KINDS as $kind) {
            if ($kind === 'conversation' && ! $withModelCalls) {
                $out[$kind] = ['status' => self::SKIPPED, 'detail' => 'not requested (--with-model-calls)'];

                continue;
            }

            try {
                $out[$kind] = match ($kind) {
                    'policy' => $this->policy($tenant, $module, $label, $dryRun),
                    'prompt' => $this->prompt($tenant, $module, $label, $dryRun),
                    'report_template' => $this->reportTemplate($tenant, $module, $label, $dryRun),
                    'agent' => $this->agent($tenant, $module, $dryRun),
                    'agent_run' => $this->agentRun($tenant, $module, $dryRun, $out['agent']['status'] ?? null),
                    'report' => $this->report($tenant, $module, $dryRun, $out['report_template']['status'] ?? null),
                    'model_binding' => $this->modelBinding($tenant, $module, $dryRun),
                    'conversation' => $this->conversation($tenant, $module, $dryRun),
                };
            } catch (Throwable $exception) {
                report($exception);
                $out[$kind] = ['status' => self::FAILED, 'detail' => mb_strimwidth($exception->getMessage(), 0, 300, '…')];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ 1. policy

    private function policy(string $tenant, string $module, string $label, bool $dryRun): array
    {
        $moduleIds = $this->policies->moduleIds($module, $tenant);

        $exists = $moduleIds !== [] && DB::table('hpbrain_ai_policies as p')
            ->where('p.tenant_id', $tenant)
            ->whereExists(function ($q) use ($moduleIds) {
                $q->from('hpbrain_ai_policy_assignments as a')
                    ->whereColumn('a.policy_id', 'p.id')
                    ->whereColumn('a.tenant_id', 'p.tenant_id')
                    ->where('a.scope_type', 'module')
                    ->whereIn('a.scope_id', $moduleIds);
            })
            ->exists();

        if ($exists) {
            return $this->skip('the organisation already has a policy assigned to this module');
        }

        // The module id THIS tenant resolves (its own row when it shadows the platform's).
        $target = null;

        foreach ($this->policies->moduleOptions($tenant) as $option) {
            if ($option['key'] === $module) {
                $target = $option['id'];
            }
        }

        if ($target === null) {
            return $this->fail('the module is not a policy scope target for this organisation');
        }

        $name = "{$label} — AI use policy";

        if ($dryRun) {
            return $this->would("policy \"{$name}\"");
        }

        $rules = [];

        foreach ($this->policies->ruleCatalogue() as $rule) {
            $rules[$rule['key']] = (bool) $rule['default'];
        }

        $response = $this->call(AiIntelligencePolicyController::class, 'store', $tenant, 'POST', '/api/v1/ai-intelligence/policies', [
            'name' => $name,
            'description' => sprintf(
                'How the AI may be used in %s. Above all, it must avoid %s',
                $label,
                self::COPY[$module]['central_risk']
            ),
            'policy_type' => 'ai_assisted',
            'status' => 1,
            'require_disclosure' => 1,
            'rules' => $rules,
            'assignments' => [['scope_type' => 'module', 'scope_id' => $target, 'status' => 1]],
        ]);

        if (! $response['ok']) {
            return $this->fail($response['message']);
        }

        $id = (string) ($response['data']['policy']['id'] ?? '');

        $this->activity($tenant, $module, [
            'operation' => 'policy_saved',
            'operation_label' => 'AI policy saved',
            'status' => 'completed',
            'message' => "Policy \"{$name}\" saved and assigned to {$label}.",
            'reference' => $id,
            'result' => ['policy_id' => $id, 'example' => true],
        ]);

        return $this->created("policy {$id}");
    }

    // ------------------------------------------------------------------ 2. prompt template

    private function prompt(string $tenant, string $module, string $label, bool $dryRun): array
    {
        if ($this->hasTemplate($tenant, $module, 'prompt')) {
            return $this->skip('the organisation already has a prompt template for this module');
        }

        $source = $this->sources->forModule($module)[0] ?? null;
        $name = "{$label} — record summary";

        if ($dryRun) {
            return $this->would("prompt \"{$name}\"");
        }

        $risk = self::COPY[$module]['central_risk'];

        $response = $this->call(AiIntelligenceTemplateController::class, 'store', $tenant, 'POST', '/api/v1/ai-intelligence/templates', [
            'name' => $name,
            'description' => sprintf(
                'Summarises this organisation\'s %s records%s without going beyond what they state.',
                $label,
                $source === null ? '' : ' (' . $source['label'] . ')'
            ),
            'kind' => 'prompt',
            'module_key' => $module,
            'status' => 'published',
            'category' => 'summary',
            'system_prompt' => self::COPY[$module]['prompt_system'] . "\n\nAbove all, avoid " . $risk,
            'user_prompt' => "Organisation: {{organisation_name}}\n"
                . "Module: {{module}} — {{page_title}}\n"
                . "Records ({{rows_shown}} shown of {{record_count}}; partial view: {{is_partial}}; from: {{data_source}}):\n"
                . "{{records}}\n\n"
                . "Figures: {{metrics}}\n\n"
                . 'Summarise these {{module}} records for an administrator in at most five sentences. '
                . 'Use only what the records state. If the view is partial, say so. '
                . 'If no records are listed, say that nothing is recorded — not that nothing is wrong.',
            'output_format' => 'text',
            'safety_rules' => [mb_substr('Above all, avoid ' . $risk, 0, 500)],
            'requires_review' => true,
            'allow_as_evidence' => false,
            'offer_in_module' => true,
            'suggestion_label' => "Summarise {$label} records",
        ]);

        if (! $response['ok']) {
            return $this->fail($response['message']);
        }

        $id = (string) ($response['data']['template']['id'] ?? '');

        $this->activity($tenant, $module, [
            'operation' => 'prompt_template_saved',
            'operation_label' => 'Prompt template published',
            'capability' => 'generative',
            'status' => 'completed',
            'message' => "Prompt template \"{$name}\" published for {$label}.",
            'prompt_id' => $id,
            'result' => ['example' => true],
        ]);

        return $this->created("prompt template {$id}");
    }

    // ------------------------------------------------------------------ 3. report template

    private function reportTemplate(string $tenant, string $module, string $label, bool $dryRun): array
    {
        if ($this->hasTemplate($tenant, $module, 'report')) {
            return $this->skip('the organisation already has a report template for this module');
        }

        $source = $this->sources->forModule($module)[0] ?? null;

        if ($source === null) {
            return $this->fail('the module has no read-only data source to bind a report to');
        }

        $name = "{$source['label']} report";

        if ($dryRun) {
            return $this->would("report layout \"{$name}\" on {$source['name']}");
        }

        $columns = implode(', ', array_column($source['columns'], 'label'));

        $layout = '<h2><<report_title>></h2>'
            . '<p><strong><<institute_name>></strong> · <<module>></p>'
            . '<p><small><<row_count>> record(s) in total · <<rows_shown>> shown · generated <<generated_at>></small></p>'
            . '<p><small>' . e($source['description']) . ' Columns: ' . e($columns) . '.</small></p>'
            . '<<rows_table>>';

        $response = $this->call(AiIntelligenceTemplateController::class, 'store', $tenant, 'POST', '/api/v1/ai-intelligence/templates', [
            'name' => $name,
            'description' => "The {$label} register, filled from {$source['name']} — read-only rows, no model.",
            'kind' => 'report',
            'module_key' => $module,
            'status' => 'published',
            'category' => 'analysis',
            'html_layout' => $layout,
            'data_source' => $source['name'],
            'data_arguments' => ['limit' => ModuleDataSourceCatalog::DEFAULT_LIMIT],
            'suggestion_label' => "Build the {$source['label']} report",
        ]);

        if (! $response['ok']) {
            return $this->fail($response['message']);
        }

        $id = (string) ($response['data']['template']['id'] ?? '');

        $this->activity($tenant, $module, [
            'operation' => 'report_template_saved',
            'operation_label' => 'Report template published',
            'status' => 'completed',
            'message' => "Report layout \"{$name}\" published for {$label} on {$source['name']}.",
            'template_id' => $id,
            'result' => ['data_source' => $source['name'], 'example' => true],
        ]);

        return $this->created("report template {$id}");
    }

    // ------------------------------------------------------------------ 4. tool agent + run

    private function agent(string $tenant, string $module, bool $dryRun): array
    {
        if (DB::table('hpbrain_ai_tool_agents')->where('tenant_id', $tenant)->where('module', $module)->exists()) {
            return $this->skip('the organisation already has a tool agent for this module');
        }

        $preset = $this->modules->presets($module, $tenant)[0] ?? null;

        if ($preset === null) {
            return $this->fail('no preset is stored on this module (hpbrain_ai_modules.presets)');
        }

        if ($dryRun) {
            return $this->would("agent \"{$preset['name']}\"");
        }

        $response = $this->call(AiStackToolAgentController::class, 'store', $tenant, 'POST', '/api/v1/ai-intelligence/tool-agents', [
            'name' => $preset['name'],
            'description' => $preset['description'],
            'module' => $module,
            'tools_allowed' => $preset['tools_allowed'],
            'instructions' => $preset['instructions'],
            'status' => 'active',
        ]);

        if (! $response['ok']) {
            return $this->fail($response['message']);
        }

        $agent = $response['data']['agent'] ?? [];

        $this->activity($tenant, $module, [
            'operation' => 'agent_created',
            'operation_label' => 'Tool agent created',
            'capability' => 'agent',
            'status' => 'completed',
            'message' => sprintf('Tool agent "%s" created (active) for %s.', $preset['name'], $module),
            'agent_id' => (string) ($agent['id'] ?? ''),
            'agent_name' => $preset['name'],
            'result' => ['tools_allowed' => $preset['tools_allowed'], 'example' => true],
        ]);

        return $this->created('agent ' . ($agent['id'] ?? ''));
    }

    private function agentRun(string $tenant, string $module, bool $dryRun, ?string $agentStatus): array
    {
        if (DB::table('hpbrain_ai_tool_agent_runs')->where('tenant_id', $tenant)->where('module', $module)->exists()) {
            return $this->skip('the organisation already has a tool-agent run for this module');
        }

        $agent = DB::table('hpbrain_ai_tool_agents')
            ->where('tenant_id', $tenant)
            ->where('module', $module)
            ->where('status', 'active')
            ->orderBy('created_date')
            ->first();

        if ($dryRun) {
            return $agent !== null || $agentStatus === self::WOULD_CREATE
                ? $this->would('one run (limit 20)')
                : $this->skip('no active tool agent to run');
        }

        if ($agent === null) {
            return $this->skip('no active tool agent to run');
        }

        $response = $this->call(AiStackToolAgentController::class, 'run', $tenant, 'POST', "/api/v1/ai-intelligence/tool-agents/{$agent->id}/run", [
            'arguments' => ['limit' => 20],
        ], [(string) $agent->id]);

        if (! $response['ok']) {
            return $this->fail($response['message']);
        }

        $run = $response['data']['run'] ?? [];

        return ($run['status'] ?? null) === 'success'
            ? $this->created(sprintf('run %s (%d rows)', $run['id'] ?? '', (int) ($run['output']['total'] ?? 0)))
            : $this->fail(sprintf('run %s recorded as %s: %s', $run['id'] ?? '', $run['status'] ?? '?', $run['error'] ?? ''));
    }

    // ------------------------------------------------------------------ 5. generated report

    private function report(string $tenant, string $module, bool $dryRun, ?string $templateStatus): array
    {
        if (DB::table('hpbrain_ai_generated_reports')->where('tenant_id', $tenant)->where('module_key', $module)->exists()) {
            return $this->skip('the organisation already has a generated report for this module');
        }

        $layout = DB::table('hpbrain_ai_templates')
            ->where('tenant_id', $tenant)
            ->where('module_key', $module)
            ->where('kind', 'report')
            ->where('status', 'published')
            ->orderByDesc('version')
            ->orderByDesc('updated_date')
            ->first();

        if ($dryRun) {
            return $this->would($layout !== null || $templateStatus === self::WOULD_CREATE
                ? 'one report from the module\'s report template'
                : 'one report from the module\'s default source');
        }

        $body = ['module' => $module, 'keep_empty' => true];

        if ($layout !== null) {
            $body['template_id'] = (string) $layout->id;
        }

        $response = $this->call(AiStackReportController::class, 'build', $tenant, 'POST', '/api/v1/ai-intelligence/workspace/report', $body);

        if (! $response['ok'] || ($response['data']['template_id'] ?? null) === null) {
            $this->activity($tenant, $module, [
                'operation' => "{$module}_report",
                'operation_label' => 'Report built',
                'status' => 'failed',
                'message' => $response['message'],
                'template_id' => $layout === null ? null : (string) $layout->id,
                'result' => ['example' => true],
            ]);

            return $this->fail($response['message']);
        }

        $data = $response['data'];

        $this->activity($tenant, $module, [
            'operation' => "{$module}_report",
            'operation_label' => 'Report built',
            'status' => 'completed',
            'message' => sprintf('Report "%s" built from %s (%d records).', $data['title'] ?? '', $data['source_tool'] ?? '', (int) ($data['row_count'] ?? 0)),
            'reference' => (string) $data['template_id'],
            'template_id' => $data['layout_template_id'] ?? null,
            'tool' => $data['source_tool'] ?? null,
            'result' => ['report_id' => $data['template_id'], 'row_count' => (int) ($data['row_count'] ?? 0), 'example' => true],
        ]);

        return $this->created(sprintf('report %s (%d records)', $data['template_id'], (int) ($data['row_count'] ?? 0)));
    }

    // ------------------------------------------------------------------ 6. model binding

    private function modelBinding(string $tenant, string $module, bool $dryRun): array
    {
        if (DB::table('hpbrain_ai_module_model_bindings')->where('tenant_id', $tenant)->where('product_module', $module)->exists()) {
            return $this->skip('the organisation already has a model choice for this module');
        }

        $keys = $this->modules->registryKeys($module, $tenant);
        $capability = $keys[0] ?? (! empty($this->modules->capabilities($module, $tenant)['conversational']) ? 'conversational_ai' : null);

        if ($capability === null) {
            return $this->skip('the module uses no AI capability a model could be chosen for');
        }

        // Pin what the next call ALREADY resolves to — provider and model — with no
        // credential of its own, so the Models tab shows a module-own choice that changes
        // nothing. A binding with no credential takes the provider's pool / env key; if
        // that is not the key in use now (a capability-specific key), pinning would
        // change which key is used, so it is not created.
        $effective = $this->resolver->resolve($capability, $tenant, $module);

        if (! $this->providers->exists($effective->provider)) {
            return $this->fail("the resolved provider {$effective->provider} is not in the provider catalogue");
        }

        $pool = $this->keys->resolve($this->providers->apiType($effective->provider), $tenant, $this->providers->envKey($effective->provider));

        if (($pool['id'] ?? null) !== $effective->keyId || (($pool['api_key'] ?? null) === null) !== ! $effective->hasKey()) {
            return $this->skip(sprintf(
                '%s runs on a capability-specific credential (%s); a binding without it would change the key used',
                $capability,
                $effective->source
            ));
        }

        $target = sprintf('%s → %s / %s', $capability, $effective->provider, $effective->model ?? 'provider default');

        if ($dryRun) {
            return $this->would($target);
        }

        $response = $this->call(AiStackModelController::class, 'update', $tenant, 'PUT', "/api/v1/ai-intelligence/modules/{$module}/models", array_filter([
            'capability' => $capability,
            'provider' => $effective->provider,
            'model' => $effective->model,
        ], fn ($v) => $v !== null && $v !== ''), [$module]);

        if (! $response['ok']) {
            return $this->fail($response['message']);
        }

        $this->activity($tenant, $module, [
            'operation' => 'model_binding_saved',
            'operation_label' => 'Module model choice saved',
            'status' => 'completed',
            'message' => "Model choice saved: {$target} (the model this module already resolved to).",
            'result' => ['capability' => $capability, 'provider' => $effective->provider, 'model' => $effective->model, 'example' => true],
        ]);

        return $this->created($target);
    }

    // ------------------------------------------------------------------ 7. conversation

    private function conversation(string $tenant, string $module, bool $dryRun): array
    {
        if (DB::table('hpbrain_ai_conversations')->where('tenant_id', $tenant)->where('module_key', $module)->exists()) {
            return $this->skip('the organisation already has a conversation asked from this module');
        }

        $question = self::QUESTIONS[$module];

        if ($dryRun) {
            return $this->would("ask \"{$question}\"");
        }

        $response = $this->call(AiIntelligenceAskController::class, 'ask', $tenant, 'POST', '/api/v1/ai-intelligence/ask', [
            'message' => $question,
            'module_key' => $module,
            'session_key' => 'ai-stack-example-' . substr(Uuid::uuid4()->toString(), 0, 18) . '-' . substr($module, 0, 20),
        ]);

        $data = $response['data'] ?? [];
        $answered = $response['ok'] && ($data['answer'] ?? null) !== null;

        $this->activity($tenant, $module, [
            'operation' => 'assistant_asked',
            'operation_label' => 'Assistant asked from this module',
            'capability' => 'conversational',
            'status' => $answered ? 'completed' : 'failed',
            'message' => $answered ? "Asked: {$question}" : ('The assistant could not answer: ' . ($data['error'] ?? $response['message'])),
            'reference' => isset($data['conversation_id']) ? (string) $data['conversation_id'] : null,
            'result' => ['example' => true, 'configured' => $data['configured'] ?? null],
        ]);

        if (! $answered) {
            return $this->fail('conversation recorded, no answer: ' . ($data['error'] ?? $response['message']));
        }

        return $this->created('conversation ' . ($data['conversation_id'] ?? ''));
    }

    // ------------------------------------------------------------------ internals

    private function hasTemplate(string $tenant, string $module, string $kind): bool
    {
        return DB::table('hpbrain_ai_templates')
            ->where('tenant_id', $tenant)
            ->where('module_key', $module)
            ->where('kind', $kind)
            ->exists();
    }

    /** Record one entry on the module's Activity ledger through the module activity action. */
    private function activity(string $tenant, string $module, array $entry): void
    {
        $this->call(AiStackModuleController::class, 'recordActivity', $tenant, 'POST', "/api/v1/ai-intelligence/modules/{$module}/activity",
            array_filter($entry, fn ($v) => $v !== null && $v !== ''), [$module]);
    }

    /**
     * Call a controller action as an AI Stack screen would, with the tenant and actor
     * on the request exactly where the `jwt` / `tenant` middleware put them.
     *
     * @param  array<string, mixed>  $body
     * @param  array<int, string>    $routeArguments
     * @return array{ok:bool, status:int, message:string, data:mixed}
     */
    private function call(string $controller, string $method, string $tenant, string $verb, string $uri, array $body = [], array $routeArguments = []): array
    {
        $request = Request::create($uri, $verb, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $request->attributes->set('tenantId', $tenant);
        $request->attributes->set('auth.tenantId', $tenant);
        $request->attributes->set('auth.userId', self::ACTOR);
        // An organisation administrator — never the platform super administrator, so no
        // example can be written as a platform ('*') row.
        $request->attributes->set('auth.role', 'tenant_admin');

        /** @var JsonResponse $response */
        $response = app($controller)->{$method}($request, ...$routeArguments);
        $payload = $response->getData(true);
        $status = $response->getStatusCode();

        $message = (string) ($payload['message'] ?? '');

        if (! empty($payload['errors']) && is_array($payload['errors'])) {
            $message .= ' ' . json_encode($payload['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return [
            'ok' => $status < 300 && ($payload['success'] ?? false) === true,
            'status' => $status,
            'message' => trim($message),
            'data' => $payload['data'] ?? null,
        ];
    }

    private function created(string $detail): array
    {
        return ['status' => self::CREATED, 'detail' => $detail];
    }

    private function skip(string $detail): array
    {
        return ['status' => self::SKIPPED, 'detail' => $detail];
    }

    private function fail(string $detail): array
    {
        return ['status' => self::FAILED, 'detail' => $detail];
    }

    private function would(string $detail): array
    {
        return ['status' => self::WOULD_CREATE, 'detail' => $detail];
    }
}
