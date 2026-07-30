<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Prompts come from hpbrain_prompt_templates, never from string literals in PHP.
 *
 * WHY THAT MATTERS MORE THAN IT LOOKS. The prompt is the largest single
 * influence on what a model concludes, so a recommendation is only traceable
 * (Invariant 7) if the exact prompt VERSION that produced it can be recovered
 * later. A literal in a method body is versioned by git, not by the row that
 * records the call — and "which prompt were we running on 12 August?" then
 * needs an archaeology dig through deploys instead of a join.
 *
 * Resolution order is tenant-specific first, then the shared '*' row. A tenant
 * may override any prompt without a code change; most never will.
 */
final class PromptTemplates
{
    /** The tenant id under which shared, product-default templates are stored. */
    public const SHARED = '*';

    /**
     * The active template of the highest version.
     *
     * Highest version rather than most recent by date: version is the field the
     * template's own history is threaded by (previous_version_id), and two rows
     * written in the same second have an unambiguous version but an ambiguous
     * timestamp.
     *
     * @return array{id: string, template: string, version: int}
     */
    public function active(string $tenantId, string $name): array
    {
        $row = $this->lookup($tenantId, $name) ?? $this->lookup(self::SHARED, $name);

        if ($row === null) {
            // Loud, not a fallback to a hardcoded default. A missing template
            // means the seed has not run, and silently substituting a literal
            // would reintroduce exactly the untraceability this class removes.
            throw new RuntimeException("prompt_template_not_found: {$name}");
        }

        return ['id' => (string) $row->id, 'template' => (string) $row->template, 'version' => (int) $row->version];
    }

    private function lookup(string $tenantId, string $name): ?object
    {
        return DB::table('hpbrain_prompt_templates')
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Substitute {{variable}} placeholders.
     *
     * An unknown placeholder is left in place rather than blanked. A prompt
     * that silently loses a section is far harder to notice than one that
     * arrives with a visible `{{missing}}` in it, and the second shows up in
     * the very first output a human reads.
     *
     * @param  array<string, string>  $variables
     */
    public function render(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace('{{'.$key.'}}', $value, $template);
        }

        return $template;
    }
}
