<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Ai\PromptTemplates;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Product-default prompts for the reasoning verbs.
 *
 * Seeded under tenant '*' (PromptTemplates::SHARED) rather than per tenant.
 * hpbrain_prompt_templates.tenant_id is NOT NULL and a seeder cannot know which
 * tenants exist, so the shared row is the default and any tenant may override a
 * prompt by inserting its own row with the same name — PromptTemplates::active()
 * prefers the tenant-specific one.
 *
 * Idempotent: re-running does not append a second version. A prompt version is
 * a fact recorded on every execution row that used it, so versions must be
 * created deliberately, never as a side effect of running the seeder twice.
 */
final class PromptTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $name => $template) {
            $exists = DB::table('hpbrain_prompt_templates')
                ->where('tenant_id', PromptTemplates::SHARED)
                ->where('name', $name)
                ->exists();

            if ($exists) {
                $this->command?->info("  prompt '{$name}' already seeded");
                continue;
            }

            DB::table('hpbrain_prompt_templates')->insert([
                'id'           => Uuid::uuid4()->toString(),
                'tenant_id'    => PromptTemplates::SHARED,
                'name'         => $name,
                'template'     => $template,
                'variables'    => json_encode(['categories', 'priorities']),
                'version'      => 1,
                'status'       => 'active',
                'created_by'   => 'system',
                'created_date' => now()->format('Y-m-d H:i:s'),
            ]);

            $this->command?->info("  prompt '{$name}' seeded at version 1");
        }
    }

    /** @return array<string, string> */
    private function templates(): array
    {
        // Every instruction below exists to make the guardrail enforceable
        // rather than merely hoped for: strict JSON so the response can be
        // validated, ids from the supplied set so fabricated citations are
        // detectable, and an explicit instruction to return fewer claims rather
        // than pad — a model told to produce three recommendations will produce
        // three whether or not the evidence supports three.
        return [
            'recommend' => <<<'PROMPT'
                You recommend organizational actions for HP Enterprise Brain.

                RULES, in order of importance:
                1. Reply with STRICT JSON only. No prose, no markdown fence, no commentary.
                2. Cite ONLY the evidence ids given to you in GROUNDING. Never invent an id.
                   A claim citing an id that was not supplied will be discarded.
                3. Every claim must carry at least one evidenceRefs entry.
                4. Recommend FEWER items rather than padding. If the grounding supports one
                   recommendation, return one. If it supports none, return an empty list.
                5. category must be one of: {{categories}}
                6. priority must be one of: {{priorities}}
                7. A category of 'intervene' or 'escalate' MUST include an esoId naming the
                   executable operation. Without one, the claim will be discarded.

                Shape:
                {"claims":[{"title":"...","category":"...","priority":"...","confidence":0.0,
                "rationale":"...","evidenceRefs":["..."],"esoId":null}]}
                PROMPT,

            'evaluate' => <<<'PROMPT'
                You evaluate the strength of the case for a signal in HP Enterprise Brain.

                RULES, in order of importance:
                1. Reply with STRICT JSON only. No prose, no markdown fence, no commentary.
                2. Cite ONLY the ids given to you in GROUNDING. Never invent an id.
                3. confidence is your assessed strength of the case, between 0 and 1. State a
                   LOW number when the evidence is thin. Do not inflate to sound useful —
                   an honest low confidence is more valuable here than a confident guess.
                4. Assess what the evidence actually supports, not what would be convenient.

                Shape:
                {"assessments":[{"assessment":"...","confidence":0.0,"evidenceRefs":["..."]}]}
                PROMPT,
        ];
    }
}
