<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

/**
 * Catalogue rows for the per-module AI Stack — platform rows (`tenant_id = '*'`) only.
 *
 * 1. `mental_models` ("Organizational Knowledge") — the mental models the organisation
 *    reasons with (hpbrain_mental_models) had no hpbrain_ai_modules row of its own.
 *    Inserted if absent.
 *
 * 2. `capabilities` / `registry_keys` for the 18 AI Stack areas.
 *
 *    capabilities   which AI Stack capabilities the area uses (conversational /
 *                   generative / agent / workflow / ontology). Every area has an
 *                   Automations tab backed by its own read-only data source, so
 *                   `agent` is true throughout.
 *    registry_keys  the AiModuleRegistry consumers behind the area — the join from an
 *                   area to hpbrain_ai_usage_events.ai_module and to the capability a
 *                   Models-tab binding configures:
 *
 *      signals                                  signal_intelligence (SignalReasoner)
 *      evidence                                 evidence_intelligence
 *      deliberation                             deliberation_ai (EvaluateVerb)
 *      intelligence_workspace                   recommendation_ai (RecommendVerb)
 *      decision_analytics                       analytics_ai (ExecutiveIntelligenceInterpreter)
 *      executions, eso, agents, tasks           agent_reasoning (CoachVerb — executor / ESO runs)
 *      departments, people, capabilities, kasba capability_intelligence (KasbaService)
 *      knowledge_graph, knowledge_library,
 *      organisational_memory, mental_models     knowledge_ai
 *      policies                                 none — no AI consumer generates anything
 *                                               for executor policies, so the area is
 *                                               honestly "no consumer of its own" rather
 *                                               than borrowing analytics_ai's usage.
 *
 * Only platform rows are updated; a tenant's own shadowing row is its own. No example
 * templates, agents or reports are seeded. Portable Query Builder writes, so the
 * in-memory SQLite test schema can run this file as-is. Idempotent.
 */
return new class extends Migration
{
    private const PLATFORM = '*';

    /** key => [conversational, generative, agent, workflow, ontology], registry keys */
    private const STACK = [
        'signals' => [[true, true, true, false, false], ['signal_intelligence']],
        'evidence' => [[true, true, true, false, false], ['evidence_intelligence']],
        'deliberation' => [[true, true, true, false, false], ['deliberation_ai']],
        'intelligence_workspace' => [[true, true, true, true, false], ['recommendation_ai']],
        'decision_analytics' => [[true, true, true, false, false], ['analytics_ai']],
        'executions' => [[true, false, true, true, false], ['agent_reasoning']],
        'eso' => [[true, true, true, true, true], ['agent_reasoning']],
        'agents' => [[true, false, true, true, false], ['agent_reasoning']],
        'tasks' => [[true, false, true, true, false], ['agent_reasoning']],
        'departments' => [[true, false, true, false, false], ['capability_intelligence']],
        'people' => [[true, false, true, false, false], ['capability_intelligence']],
        'capabilities' => [[true, true, true, false, true], ['capability_intelligence']],
        'kasba' => [[true, true, true, false, true], ['capability_intelligence']],
        'knowledge_graph' => [[true, false, true, false, true], ['knowledge_ai']],
        'knowledge_library' => [[true, true, true, false, true], ['knowledge_ai']],
        'organisational_memory' => [[true, true, true, false, true], ['knowledge_ai']],
        'mental_models' => [[true, true, true, false, true], ['knowledge_ai']],
        'policies' => [[true, false, true, true, false], []],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('hpbrain_ai_modules')) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        $exists = DB::table('hpbrain_ai_modules')
            ->where('module_key', 'mental_models')
            ->where('tenant_id', self::PLATFORM)
            ->exists();

        if (! $exists) {
            DB::table('hpbrain_ai_modules')->insert([
                'id' => Uuid::uuid4()->toString(),
                'tenant_id' => self::PLATFORM,
                'module_key' => 'mental_models',
                'label' => 'Organizational Knowledge',
                'domain' => 'hpbrain',
                'description' => 'The mental models the organisation reasons with — their domain, rules, version and status.',
                'icon' => 'lightbulb',
                'sort_order' => 165,
                'status' => 1,
                'created_date' => $now,
                'updated_date' => $now,
            ]);
        }

        if (! Schema::hasColumn('hpbrain_ai_modules', 'capabilities') || ! Schema::hasColumn('hpbrain_ai_modules', 'registry_keys')) {
            return;
        }

        foreach (self::STACK as $key => [$flags, $registryKeys]) {
            [$conversational, $generative, $agent, $workflow, $ontology] = $flags;

            DB::table('hpbrain_ai_modules')
                ->where('module_key', $key)
                ->where('tenant_id', self::PLATFORM)
                ->update([
                    'capabilities' => json_encode(compact('conversational', 'generative', 'agent', 'workflow', 'ontology')),
                    'registry_keys' => json_encode($registryKeys),
                    'updated_date' => $now,
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('hpbrain_ai_modules')) {
            return;
        }

        if (Schema::hasColumn('hpbrain_ai_modules', 'capabilities') && Schema::hasColumn('hpbrain_ai_modules', 'registry_keys')) {
            DB::table('hpbrain_ai_modules')
                ->where('tenant_id', self::PLATFORM)
                ->whereIn('module_key', array_keys(self::STACK))
                ->update(['capabilities' => null, 'registry_keys' => null]);
        }

        DB::table('hpbrain_ai_modules')
            ->where('module_key', 'mental_models')
            ->where('tenant_id', self::PLATFORM)
            ->delete();
    }
};
