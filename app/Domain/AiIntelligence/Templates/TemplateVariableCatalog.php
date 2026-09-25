<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Templates;

/**
 * The variables a template may use, and what each holds — the contract between a
 * template author and whatever renders the template.
 *
 * The screen-shaped half (`records`, `metrics`, `record_count`, …) is G2G's list
 * unchanged, so a template means the same thing in both products. The second half
 * is HP Brain's own: the tenant-level figures the grounding briefing and the
 * preview fill from HP Brain's tables — its organisation name and the state of its
 * intelligence loop (open signals, cases, pending recommendations, …).
 *
 * `grounding` marks the variables that carry data. A published prompt must use at
 * least one, or the model answers from general knowledge.
 */
class TemplateVariableCatalog
{
    /**
     * @var array<int, array{key:string, label:string, description:string, grounding:bool}>
     */
    private const VARIABLES = [
        ['key' => 'records', 'label' => 'Records', 'grounding' => true,
            'description' => 'The rows on screen, one per line, as "- Label (field: value, …)". The text is "none listed" when the page reported no rows.'],
        ['key' => 'metrics', 'label' => 'Figures', 'grounding' => true,
            'description' => 'The totals and counters on screen, as "Label: value". The text is "none reported" when there are none.'],
        ['key' => 'record_count', 'label' => 'Total records', 'grounding' => false,
            'description' => 'How many records the page or module holds in total — which can be far more than the rows listed.'],
        ['key' => 'rows_shown', 'label' => 'Rows shown', 'grounding' => false,
            'description' => 'How many rows are actually in {{records}}.'],
        ['key' => 'is_partial', 'label' => 'Partial view', 'grounding' => false,
            'description' => '"yes" when {{records}} is a window onto a larger set, "no" when it is everything. Include it, or the model will report a sample as the whole.'],
        ['key' => 'page_title', 'label' => 'Page title', 'grounding' => false,
            'description' => 'The heading of the screen the request came from.'],
        ['key' => 'page_type', 'label' => 'Page type', 'grounding' => false,
            'description' => 'dashboard, list, report or detail — what kind of screen this is.'],
        ['key' => 'filters', 'label' => 'Active filters', 'grounding' => false,
            'description' => 'The filters in force, as "Label: value". The text is "none" when nothing is filtered.'],
        ['key' => 'search_query', 'label' => 'Search text', 'grounding' => false,
            'description' => 'What the user typed into the page search, if anything.'],
        ['key' => 'data_source', 'label' => 'Data source', 'grounding' => false,
            'description' => '"the page", "the module" or "none" — where the records came from. Useful in a safety rule that must not describe missing data as empty data.'],
        ['key' => 'module', 'label' => 'Module label', 'grounding' => false,
            'description' => 'The HP Brain area the request came from, as a human label — "Signals", "Deliberation", "Intelligence Workspace".'],
        ['key' => 'entity_label', 'label' => 'Record label', 'grounding' => false,
            'description' => 'The name of the selected record, when one is selected. Empty on a list or report screen.'],

        // HP Brain tenant-level figures, read from this tenant's own tables.
        ['key' => 'organisation_name', 'label' => 'Organisation name', 'grounding' => false,
            'description' => 'This tenant\'s organisation name, as the Organization screen holds it.'],
        ['key' => 'open_signals', 'label' => 'Open signals', 'grounding' => false,
            'description' => 'Signals not yet resolved or dismissed.'],
        ['key' => 'open_cases', 'label' => 'Open cases', 'grounding' => false,
            'description' => 'Cases still under deliberation.'],
        ['key' => 'pending_recommendations', 'label' => 'Pending recommendations', 'grounding' => false,
            'description' => 'Recommendations awaiting an approve / reject / defer decision.'],
        ['key' => 'evidence_count', 'label' => 'Evidence records', 'grounding' => false,
            'description' => 'Evidence records held for this tenant.'],
        ['key' => 'decision_count', 'label' => 'Decisions recorded', 'grounding' => false,
            'description' => 'Decisions recorded through the governance gate.'],
        ['key' => 'capability_count', 'label' => 'Capabilities defined', 'grounding' => false,
            'description' => 'Capabilities in this tenant\'s capability model.'],
        ['key' => 'knowledge_asset_count', 'label' => 'Knowledge assets', 'grounding' => false,
            'description' => 'Knowledge-library assets held for this tenant.'],
    ];

    /** @return array<int, array{key:string, label:string, description:string, grounding:bool}> */
    public function all(): array
    {
        return array_map(fn (array $v) => [
            'key' => $v['key'],
            'label' => $v['label'],
            'description' => $v['description'],
            'grounding' => $v['grounding'],
        ], self::VARIABLES);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_column(self::VARIABLES, 'key');
    }

    /** @return array<int, string> */
    public function groundingKeys(): array
    {
        return array_values(array_column(
            array_filter(self::VARIABLES, fn (array $variable) => $variable['grounding']),
            'key'
        ));
    }

    /**
     * Placeholders used in a prompt that nothing will ever fill.
     *
     * @param array<int, array<string, mixed>> $declared
     * @return array<int, string>
     */
    public function unresolvable(string $prompt, array $declared = []): array
    {
        $known = array_merge($this->keys(), array_filter(array_column($declared, 'key')));

        return array_values(array_diff($this->used($prompt), $known));
    }

    /** @return array<int, string> */
    public function used(string $prompt): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $prompt, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
