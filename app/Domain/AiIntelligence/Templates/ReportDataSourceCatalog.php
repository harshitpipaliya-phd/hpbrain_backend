<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Templates;

use App\Domain\AiIntelligence\Reports\ModuleDataSourceCatalog;

/**
 * The read-only HP Brain data sources a `report` template layout can bind to, as the
 * Template Management screen sees them (`templates/options` → `data_sources`, and the
 * `data_source` check on save).
 *
 * The list itself is App\Domain\AiIntelligence\Reports\ModuleDataSourceCatalog — the
 * one catalogue the per-module AI Stack runs (reports, the Knowledge Base "Check", tool
 * agents). This class keeps the console's response shape exactly: each entry is
 * {name, module, label, description, arguments}, as before; the catalogue's extra
 * `columns` stay on the AI Stack routes.
 */
final class ReportDataSourceCatalog
{
    public function __construct(private readonly ModuleDataSourceCatalog $sources)
    {
    }

    /** @return array<int, array{name:string, module:string, label:string, description:string, arguments:array<int, array<string, mixed>>}> */
    public function all(): array
    {
        return array_map(fn (array $s) => [
            'name' => $s['name'],
            'module' => $s['module'],
            'label' => $s['label'],
            'description' => $s['description'],
            'arguments' => $s['arguments'],
        ], $this->sources->all());
    }

    public function exists(string $name): bool
    {
        return $this->sources->exists($name);
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
