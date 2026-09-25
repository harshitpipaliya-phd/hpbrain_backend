<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Conversation;

use App\Domain\AiIntelligence\Support\TenantFacts;

/**
 * The facts about this tenant that get put in front of the model.
 *
 * Ported from G2G's OrganisationContext, answered from HP Brain's own tables:
 * people and departments through the ERP-backed FoundationCounts, and the state
 * of the intelligence loop — signals, evidence, cases, decisions, recommendations,
 * capabilities, knowledge — plus this layer's own templates and policies.
 *
 * COUNTS AND TAXONOMY ONLY. No person's name, rating, assessment or pay is read
 * here; an assistant that could quote a person's record would need a per-record
 * permission model it does not have. The absence of those reads is the enforcement.
 */
final class OrganisationContext
{
    public function __construct(private readonly TenantFacts $facts)
    {
    }

    /** A short factual briefing, or null when nothing could be read. */
    public function briefing(string $tenantId): ?string
    {
        if (trim($tenantId) === '') {
            return null;
        }

        $facts = $this->facts->all($tenantId);

        $labels = [
            'people' => 'Active people',
            'departments' => 'Departments',
            'open_signals' => 'Open signals',
            'evidence_count' => 'Evidence records',
            'open_cases' => 'Open cases',
            'decision_count' => 'Decisions recorded',
            'pending_recommendations' => 'Recommendations awaiting a decision',
            'capability_count' => 'Capabilities defined',
            'knowledge_asset_count' => 'Knowledge assets',
            'ai_templates_published' => 'AI templates published',
            'ai_policies_active' => 'AI policies active',
        ];

        $lines = [];

        foreach ($labels as $key => $label) {
            $value = $facts[$key] ?? null;

            if ($value !== null) {
                $lines[] = "- {$label}: " . number_format($value);
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    public function systemPrompt(?string $briefing, string $organisationName = 'this organisation'): string
    {
        $prompt = <<<TXT
You are the assistant inside HP Enterprise Brain, an organisational intelligence and
execution system. You are speaking to an administrator of {$organisationName}.

Answer questions about this organisation's signals, evidence, cases, decisions,
recommendations, executions, capabilities and knowledge, and about how HP Brain
works. Be brief and concrete. Prefer a short direct answer to a long hedged one.

RULES YOU MUST NOT BREAK

- Use only the figures supplied below. If a question needs a number that is not
  there, say which HP Brain screen would show it instead of estimating one.
- Never invent a name, a count, a rating or a date.
- You do not have access to any individual person's record. If asked about a named
  person's rating, assessment or pay, say plainly that you cannot see per-person
  data and point at the screen that can.
- If you are unsure, say so. An admitted gap is useful; a confident guess is not.
TXT;

        if ($briefing === null) {
            return $prompt . "\n\nNo figures are available for this organisation, so answer only "
                . 'questions about how HP Brain works, and say when you would need data you do not have.';
        }

        return $prompt . "\n\nCURRENT FIGURES FOR {$organisationName}:\n{$briefing}";
    }
}
