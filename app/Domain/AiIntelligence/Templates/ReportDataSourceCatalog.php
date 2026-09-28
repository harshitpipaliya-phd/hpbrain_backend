<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Templates;

/**
 * The read-only HP Brain data sources a `report` template layout can bind to.
 *
 * G2G's ModuleDataSourceCatalog lists one tenant-scoped query per module; this is
 * HP Brain's list, one per intelligence-loop area, each naming the hpbrain_* table
 * it reads. Every source is read-only and tenant-scoped by construction: a source
 * that could write would not be listed.
 *
 * Only the catalogue is served here (the Template Management screen validates a
 * layout's `data_source` against it and lists it in `options`). Running a source
 * belongs to the report routes, which are not part of the AI & Intelligence
 * console port.
 */
final class ReportDataSourceCatalog
{
    /**
     * @var array<int, array{name:string, module:string, label:string, description:string, table:string, arguments:array<int, array{key:string,type:string,description:string,required:bool}>}>
     */
    private const SOURCES = [
        [
            'name' => 'signals.open',
            'module' => 'signals',
            'label' => 'Open signals',
            'description' => 'Signals not yet resolved or dismissed, with source, classification, severity, priority and confidence.',
            'table' => 'hpbrain_signals',
            'arguments' => [
                ['key' => 'severity', 'type' => 'string', 'description' => 'Only signals of this severity.', 'required' => false],
                ['key' => 'limit', 'type' => 'integer', 'description' => 'At most this many rows (max 500).', 'required' => false],
            ],
        ],
        [
            'name' => 'evidence.recent',
            'module' => 'evidence',
            'label' => 'Recent evidence',
            'description' => 'Evidence records with type, source, confidence and status, newest first.',
            'table' => 'hpbrain_evidence',
            'arguments' => [
                ['key' => 'evidence_type', 'type' => 'string', 'description' => 'Only evidence of this type.', 'required' => false],
                ['key' => 'limit', 'type' => 'integer', 'description' => 'At most this many rows (max 500).', 'required' => false],
            ],
        ],
        [
            'name' => 'cases.open',
            'module' => 'deliberation',
            'label' => 'Cases under deliberation',
            'description' => 'Open cases with their title, status and the signal that raised them.',
            'table' => 'hpbrain_cases',
            'arguments' => [
                ['key' => 'limit', 'type' => 'integer', 'description' => 'At most this many rows (max 500).', 'required' => false],
            ],
        ],
        [
            'name' => 'recommendations.pending',
            'module' => 'intelligence_workspace',
            'label' => 'Pending recommendations',
            'description' => 'Recommendations awaiting a decision, with category, priority, urgency, confidence, impact, cost and risk.',
            'table' => 'hpbrain_recommendations',
            'arguments' => [
                ['key' => 'category', 'type' => 'string', 'description' => 'Only recommendations in this category.', 'required' => false],
                ['key' => 'limit', 'type' => 'integer', 'description' => 'At most this many rows (max 500).', 'required' => false],
            ],
        ],
        [
            'name' => 'decisions.recent',
            'module' => 'decision_analytics',
            'label' => 'Decisions',
            'description' => 'Decisions recorded through the governance gate, with executor type, status and rationale.',
            'table' => 'hpbrain_decisions',
            'arguments' => [
                ['key' => 'status', 'type' => 'string', 'description' => 'proposed, approved or rejected.', 'required' => false],
                ['key' => 'limit', 'type' => 'integer', 'description' => 'At most this many rows (max 500).', 'required' => false],
            ],
        ],
        [
            'name' => 'executions.eso',
            'module' => 'executions',
            'label' => 'ESO executions',
            'description' => 'ESO executions with status, executor type and start / completion dates.',
            'table' => 'hpbrain_eso_executions',
            'arguments' => [
                ['key' => 'status', 'type' => 'string', 'description' => 'Only executions in this status.', 'required' => false],
                ['key' => 'limit', 'type' => 'integer', 'description' => 'At most this many rows (max 500).', 'required' => false],
            ],
        ],
        [
            'name' => 'capabilities.model',
            'module' => 'capabilities',
            'label' => 'Capability model',
            'description' => 'Capabilities with code, category, type, difficulty and criticality.',
            'table' => 'hpbrain_capabilities',
            'arguments' => [
                ['key' => 'category', 'type' => 'string', 'description' => 'Only capabilities in this category.', 'required' => false],
            ],
        ],
        [
            'name' => 'knowledge.assets',
            'module' => 'knowledge_library',
            'label' => 'Knowledge assets',
            'description' => 'Knowledge-library assets with category, confidence and how often each has been reused.',
            'table' => 'hpbrain_knowledge_assets',
            'arguments' => [
                ['key' => 'category', 'type' => 'string', 'description' => 'Only assets in this category.', 'required' => false],
            ],
        ],
        [
            'name' => 'positions.list',
            'module' => 'people',
            'label' => 'Positions',
            'description' => 'Positions with employment type and whether each is vacant.',
            'table' => 'hpbrain_positions',
            'arguments' => [],
        ],
        [
            'name' => 'executors.registry',
            'module' => 'agents',
            'label' => 'Executor registry',
            'description' => 'Registered executors — human, AI agent, software and hybrid — with trust level and workload.',
            'table' => 'hpbrain_executors',
            'arguments' => [],
        ],
    ];

    /** @return array<int, array{name:string, module:string, label:string, description:string, arguments:array<int, array<string, mixed>>}> */
    public function all(): array
    {
        return array_map(fn (array $s) => [
            'name' => $s['name'],
            'module' => $s['module'],
            'label' => $s['label'],
            'description' => $s['description'],
            'arguments' => $s['arguments'],
        ], self::SOURCES);
    }

    public function exists(string $name): bool
    {
        return in_array($name, array_column(self::SOURCES, 'name'), true);
    }

    /**
     * The placeholders a report layout can use — the same report-level tags G2G's
     * ReportLayoutRenderer::placeholders() offers.
     *
     * @return array<int, array{key:string, label:string, scope:string}>
     */
    public function placeholders(): array
    {
        return [
            ['key' => 'report_title', 'label' => 'Report title', 'scope' => 'report'],
            ['key' => 'question', 'label' => 'The question that produced the report', 'scope' => 'report'],
            ['key' => 'module', 'label' => 'Module name', 'scope' => 'report'],
            ['key' => 'row_count', 'label' => 'Number of records', 'scope' => 'report'],
            ['key' => 'generated_at', 'label' => 'Date and time generated', 'scope' => 'report'],
            ['key' => 'rows_table', 'label' => 'All records as a table', 'scope' => 'report'],
        ];
    }
}
