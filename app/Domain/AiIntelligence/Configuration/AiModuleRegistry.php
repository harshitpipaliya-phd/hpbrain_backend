<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Configuration;

/**
 * The AI capabilities an administrator can point at a provider — HP Brain's own list.
 *
 * The counterpart of G2G's AiModuleRegistry, with HP Brain's vocabulary rather
 * than G2G's. Keys are stable snake_case: they are written into
 * hpbrain_ai_api_keys.ai_module, hpbrain_ai_usage_events.ai_module and
 * hpbrain_ai_usage_quotas.ai_module, and must survive a label being reworded.
 *
 * `wired` IS THE HONEST HALF
 *
 * `wired: true` means the capability calls a model through AiModelClient, so a
 * configuration saved for it changes what the next call does. `wired: false`
 * names a real HP Brain consumer that still reaches its provider through the
 * existing AiGateway (config/brain.php, ADR-004); a configuration saved against it
 * is stored and shown, but that path does not read it yet. The screen says so
 * rather than implying a binding that does not exist.
 *
 * Every `consumer` below is a class that exists in this codebase.
 */
final class AiModuleRegistry
{
    /**
     * @var array<int, array{key:string, label:string, description:string, wired:bool, consumer:string}>
     */
    private const MODULES = [
        [
            'key' => 'conversational_ai',
            'label' => 'Conversational AI',
            'description' => 'The AI & Intelligence assistant: answers administrators\' questions grounded in this tenant\'s signals, evidence, cases, decisions and recommendations.',
            'wired' => true,
            'consumer' => 'App\Domain\AiIntelligence\Conversation\AskPipeline',
        ],
        [
            'key' => 'recommendation_ai',
            'label' => 'Recommendation AI',
            'description' => 'Drafting recommendations from reasoning steps and evidence — the RECOMMEND verb of the intelligence loop.',
            'wired' => false,
            'consumer' => 'App\Domain\Verbs\RecommendVerb',
        ],
        [
            'key' => 'signal_intelligence',
            'label' => 'Signal Intelligence',
            'description' => 'Classifying and reasoning over operational signals raised by the signal rules.',
            'wired' => false,
            'consumer' => 'App\Domain\Reasoning\SignalReasoner',
        ],
        [
            'key' => 'evidence_intelligence',
            'label' => 'Evidence Intelligence',
            'description' => 'Summarising and grading the evidence attached to signals, cases and recommendations.',
            'wired' => false,
            'consumer' => 'App\Http\Controllers\Api\AiController',
        ],
        [
            'key' => 'deliberation_ai',
            'label' => 'Deliberation AI',
            'description' => 'Evaluating hypotheses and options inside a case before a decision is proposed — the EVALUATE verb.',
            'wired' => false,
            'consumer' => 'App\Domain\Verbs\EvaluateVerb',
        ],
        [
            'key' => 'agent_reasoning',
            'label' => 'Agent Reasoning',
            'description' => 'Planning and coaching for executors and ESO runs — the COACH verb. Executors carry their own configuration today.',
            'wired' => false,
            'consumer' => 'App\Domain\Verbs\CoachVerb',
        ],
        [
            'key' => 'analytics_ai',
            'label' => 'Decision Analytics AI',
            'description' => 'Executive interpretation of the intelligence dashboards and decision analytics.',
            'wired' => false,
            'consumer' => 'App\Domain\Intelligence\ExecutiveIntelligenceInterpreter',
        ],
        [
            'key' => 'knowledge_ai',
            'label' => 'Knowledge AI',
            'description' => 'Dataset analysis on import and knowledge-library enrichment for organisational memory.',
            'wired' => false,
            'consumer' => 'App\Domain\Ingestion\DatasetAnalysisService',
        ],
        [
            'key' => 'capability_intelligence',
            'label' => 'Capability Intelligence',
            'description' => 'KASBA capability assessment and proficiency-gap analysis across departments and positions.',
            'wired' => false,
            'consumer' => 'App\Domain\Kasba\KasbaService',
        ],
        [
            'key' => 'evaluation_ai',
            'label' => 'AI Evaluation',
            'description' => 'Running AI Evaluation test sets against a template. Billed to its own capability so measuring a template never spends the quota of the capability under test.',
            'wired' => true,
            'consumer' => 'App\Domain\AiIntelligence\Evaluation\EvaluationRunner',
        ],
    ];

    /** @return array<int, array{key:string, label:string, description:string, wired:bool, consumer:string}> */
    public function all(): array
    {
        return self::MODULES;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_column(self::MODULES, 'key');
    }

    public function exists(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    /** @return array{key:string, label:string, description:string, wired:bool, consumer:string}|null */
    public function find(string $key): ?array
    {
        foreach (self::MODULES as $module) {
            if ($module['key'] === $key) {
                return $module;
            }
        }

        return null;
    }

    public function label(string $key): string
    {
        return $this->find($key)['label'] ?? $key;
    }

    public function isWired(string $key): bool
    {
        return (bool) ($this->find($key)['wired'] ?? false);
    }
}
