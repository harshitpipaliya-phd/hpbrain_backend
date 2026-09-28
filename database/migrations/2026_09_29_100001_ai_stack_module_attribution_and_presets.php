<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-module attribution for the AI Stack, and the module catalogue moved fully into
 * the database.
 *
 *   hpbrain_ai_usage_events.product_module  VARCHAR(64) NULL (+ index)
 *       The hpbrain_ai_modules key a metered call was made FROM (AiModelClient's
 *       $productModule). Usage, cost, quota refusals and failures on a module's AI
 *       Stack read this column only, so two modules that share an AI capability
 *       (e.g. signals / evidence / … sharing conversational_ai) no longer show the
 *       same numbers. Rows written before this column existed stay NULL: they are
 *       not attributed to any module (they still count on the central console,
 *       which reads by capability).
 *
 *   hpbrain_ai_policies.forked_from  VARCHAR(36) NULL (+ index)
 *       The platform policy a tenant's copy was forked from. Set by the policy
 *       controller on fork; the module-scoped policy list hides a platform policy
 *       this tenant has replaced with its own copy. Forks made before this column
 *       carry NULL and cannot be linked retroactively.
 *
 *   hpbrain_ai_modules.presets  TEXT NULL (JSON)
 *       The read-only tool-agent presets an area's Automations tab offers,
 *       [{name, description, module, tools_allowed, instructions, status}]. Served
 *       by GET ai-intelligence/modules/{module}/profile so the screens stop
 *       hard-coding them. A tenant row with its own `presets` shadows the platform's.
 *
 * Data: the 18 platform ('*') AI Stack rows get one read-only preset each (wording
 * identical to the frontend descriptors it replaces) and labels matching the HP Brain
 * screens. Tenant rows are never touched. Schema-builder DDL, each step guarded, so
 * the file is idempotent and also runs on the in-memory SQLite test schema.
 */
return new class extends Migration
{
    private const PLATFORM = '*';

    /** Platform labels — the headings of the HP Brain screens. */
    private const LABELS = [
        'departments' => 'Departments',
        'people' => 'People',
        'capabilities' => 'Capabilities',
        'signals' => 'Signals',
        'evidence' => 'Evidence',
        'deliberation' => 'Deliberation',
        'intelligence_workspace' => 'Intelligence Workspace',
        'executions' => 'Execution Center',
        'decision_analytics' => 'Decision Intelligence',
        'mental_models' => 'Organizational Knowledge',
        'knowledge_graph' => 'Graph Explorer',
        'kasba' => 'KASBA Explorer',
        'knowledge_library' => 'Knowledge Library',
        'organisational_memory' => 'Memory',
        'eso' => 'ESO Library',
        'agents' => 'Agent Monitor',
        'tasks' => 'Task Orchestrator',
        'policies' => 'Policy Management',
    ];

    /** One read-only preset per area (hp-enterprise-brain src/components/ai-stack/modules/<key>.ts). */
    private const PRESETS = [
        'departments' => [
            'name' => 'Department roster reader',
            'description' => 'Reads every department with its parent, status and how many active people it has. Changes nothing.',
            'tools_allowed' => ['departments.roster'],
            'instructions' => 'Report departments exactly as recorded. A blank parent is a top-level unit, not a missing one. A people count is a record count — never describe a department as understaffed or overstaffed from it.',
        ],
        'people' => [
            'name' => 'People directory reader',
            'description' => 'Reads the roster — name, department, role, position and record completeness, never contact details. Changes nothing.',
            'tools_allowed' => ['people.directory'],
            'instructions' => 'Report the roster exactly as recorded. An incomplete record is a data gap to be filled, not a fact about the person — name the missing fields, nothing more. Never infer performance or personal traits, and never supply contact details: the roster has none.',
        ],
        'capabilities' => [
            'name' => 'Capability assignment reader',
            'description' => 'Reads capability assignments with code, category, criticality, target and status. Changes nothing.',
            'tools_allowed' => ['capabilities.assignments'],
            'instructions' => 'Report assignments exactly as recorded. An assignment is a requirement placed on a target, not evidence the target meets it — never say anyone has or lacks a capability from this list.',
        ],
        'signals' => [
            'name' => 'Open signal reader',
            'description' => 'Reads unresolved signals with source, classification, severity, priority, confidence and the entity concerned. Changes nothing.',
            'tools_allowed' => ['signals.open'],
            'instructions' => 'Report open signals exactly as recorded and call each one flagged, never confirmed. Do not name a cause, and do not restate a severity or confidence other than the recorded one.',
        ],
        'evidence' => [
            'name' => 'Evidence reader',
            'description' => 'Reads evidence records with the signal each supports, type, source, confidence and status. Changes nothing.',
            'tools_allowed' => ['evidence.by_signal'],
            'instructions' => 'Report evidence exactly as recorded, at its recorded confidence. Never describe a record as stronger than that, and never sum several records into a conclusion.',
        ],
        'deliberation' => [
            'name' => 'Open case reader',
            'description' => 'Reads open cases with their originating signal and counts of hypotheses, open hypotheses and reasoning steps. Changes nothing.',
            'tools_allowed' => ['deliberation.open_cases'],
            'instructions' => 'Report open cases exactly as recorded. A case with open hypotheses is undecided — never say which hypothesis is correct or likeliest, and never call a case concluded.',
        ],
        'intelligence_workspace' => [
            'name' => 'Recommendation reader',
            'description' => 'Reads recommendations with category, priority, confidence, impact, cost, risk and status. Changes nothing.',
            'tools_allowed' => ['workspace.recommendations'],
            'instructions' => 'Report recommendations exactly as recorded. A pending recommendation is a proposal, not a decision — never say it was accepted, never urge action on it, and never re-estimate its impact, cost or risk.',
        ],
        'executions' => [
            'name' => 'Execution outcome reader',
            'description' => 'Reads ESO executions with status, executor type, dates, decision and the latest recorded outcome. Changes nothing.',
            'tools_allowed' => ['executions.outcomes'],
            'instructions' => 'Report executions and outcomes exactly as recorded. A blank outcome means none has been measured yet. Never say an execution caused an outcome, and never blame anyone for a failed or rolled-back run.',
        ],
        'decision_analytics' => [
            'name' => 'Decision outcome reader',
            'description' => 'Reads decisions with the recommendation decided, status, confidence, approval date and recorded outcomes. Changes nothing.',
            'tools_allowed' => ['decisions.outcomes'],
            'instructions' => 'Report decisions and outcomes exactly as recorded. A decision with zero outcomes recorded is unmeasured — never call it a success or a failure, and never judge who approved it.',
        ],
        'mental_models' => [
            'name' => 'Mental model reader',
            'description' => 'Reads the mental models the organisation reasons with — description, domain, version and status. Changes nothing.',
            'tools_allowed' => ['knowledge.mental_models'],
            'instructions' => 'Report mental models exactly as recorded, naming the version and status of each. Never add a model that is not recorded and never apply one outside its recorded domain.',
        ],
        'knowledge_graph' => [
            'name' => 'Entity mapping reader',
            'description' => 'Reads how the organisation\'s source records map onto the knowledge graph\'s universal entities. Changes nothing.',
            'tools_allowed' => ['graph.entity_mappings'],
            'instructions' => 'Report mappings exactly as recorded, one source field to one universal field. Never extend a mapping by analogy or infer a relationship no row records.',
        ],
        'kasba' => [
            'name' => 'KASBA profile reader',
            'description' => 'Reads KASBA proficiency levels per capability assignment, with evidence confidence and assessment date. Changes nothing.',
            'tools_allowed' => ['kasba.profile'],
            'instructions' => 'Report KASBA levels exactly as recorded, with their evidence confidence and date. A blank level is not assessed, not low. Never rank people or judge anyone unfit from a profile.',
        ],
        'knowledge_library' => [
            'name' => 'Knowledge asset reader',
            'description' => 'Reads knowledge assets with category, confidence, status, department and reuse count. Changes nothing.',
            'tools_allowed' => ['knowledge.assets'],
            'instructions' => 'Report knowledge assets exactly as recorded. A reuse count is how often an asset was used, not how reliable it is — never raise its confidence on that basis.',
        ],
        'organisational_memory' => [
            'name' => 'Organisational memory reader',
            'description' => 'Reads recorded learnings with pattern, domain, confidence, reusable flag and originating outcome. Changes nothing.',
            'tools_allowed' => ['memory.items'],
            'instructions' => 'Report learnings exactly as recorded. A learning holds in its own domain at its own confidence — never apply it elsewhere, and never present a learning not marked reusable as a rule.',
        ],
        'eso' => [
            'name' => 'ESO catalogue reader',
            'description' => 'Reads ESO definitions with code, version, status, owner, objective, trust level and provenance. Changes nothing.',
            'tools_allowed' => ['eso.catalogue'],
            'instructions' => 'Report ESOs exactly as recorded and always name the status. Never present a draft, retired, deprecated or superseded ESO as the standard in force, and never paraphrase an objective into something it does not say.',
        ],
        'agents' => [
            'name' => 'Executor run reader',
            'description' => 'Reads executors with type, status, trust level, workload and their run, completed and failed counts. Changes nothing.',
            'tools_allowed' => ['agents.runs'],
            'instructions' => 'Report executors and their run counts exactly as recorded. Failed runs are a count, not a verdict — never rate an executor, and never describe a human executor as underperforming.',
        ],
        'tasks' => [
            'name' => 'Work queue reader',
            'description' => 'Reads open operational work items with category, status, owner, department and date. Changes nothing.',
            'tools_allowed' => ['tasks.queue'],
            'instructions' => 'Report open work items exactly as recorded, oldest first. An old item is old, not late — never invent a deadline, never judge its owner, and never propose reassigning it.',
        ],
        'policies' => [
            'name' => 'Policy catalogue reader',
            'description' => 'Reads business policies with type, scope, version and status. Changes nothing.',
            'tools_allowed' => ['policies.catalogue'],
            'instructions' => 'Report policies exactly as recorded, by name, version and status. Never state a rule that is not a stored policy, never reword one into a different rule, and never cite a superseded version as current.',
        ],
    ];

    public function up(): void
    {
        if (Schema::hasTable('hpbrain_ai_usage_events') && ! Schema::hasColumn('hpbrain_ai_usage_events', 'product_module')) {
            Schema::table('hpbrain_ai_usage_events', function (Blueprint $table) {
                $table->string('product_module', 64)->nullable();
                $table->index(['tenant_id', 'product_module', 'created_date'], 'idx_hb_ai_usage_tenant_pm_time');
            });
        }

        if (Schema::hasTable('hpbrain_ai_policies') && ! Schema::hasColumn('hpbrain_ai_policies', 'forked_from')) {
            Schema::table('hpbrain_ai_policies', function (Blueprint $table) {
                $table->string('forked_from', 36)->nullable();
                $table->index(['tenant_id', 'forked_from'], 'idx_hb_ai_policies_forked_from');
            });
        }

        if (! Schema::hasTable('hpbrain_ai_modules')) {
            return;
        }

        if (! Schema::hasColumn('hpbrain_ai_modules', 'presets')) {
            Schema::table('hpbrain_ai_modules', function (Blueprint $table) {
                $table->text('presets')->nullable();
            });
        }

        $now = gmdate('Y-m-d H:i:s');

        foreach (self::LABELS as $key => $label) {
            $preset = self::PRESETS[$key];

            DB::table('hpbrain_ai_modules')
                ->where('module_key', $key)
                ->where('tenant_id', self::PLATFORM)
                ->update([
                    'label' => $label,
                    'presets' => json_encode([[
                        'name' => $preset['name'],
                        'description' => $preset['description'],
                        'module' => $key,
                        'tools_allowed' => $preset['tools_allowed'],
                        'instructions' => $preset['instructions'],
                        'status' => 'active',
                    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'updated_date' => $now,
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hpbrain_ai_modules') && Schema::hasColumn('hpbrain_ai_modules', 'presets')) {
            Schema::table('hpbrain_ai_modules', function (Blueprint $table) {
                $table->dropColumn('presets');
            });
        }

        if (Schema::hasTable('hpbrain_ai_policies') && Schema::hasColumn('hpbrain_ai_policies', 'forked_from')) {
            Schema::table('hpbrain_ai_policies', function (Blueprint $table) {
                $table->dropIndex('idx_hb_ai_policies_forked_from');
                $table->dropColumn('forked_from');
            });
        }

        if (Schema::hasTable('hpbrain_ai_usage_events') && Schema::hasColumn('hpbrain_ai_usage_events', 'product_module')) {
            Schema::table('hpbrain_ai_usage_events', function (Blueprint $table) {
                $table->dropIndex('idx_hb_ai_usage_tenant_pm_time');
                $table->dropColumn('product_module');
            });
        }

        // Labels are left as they are: the previous platform labels were placeholders
        // the screens never showed.
    }
};
