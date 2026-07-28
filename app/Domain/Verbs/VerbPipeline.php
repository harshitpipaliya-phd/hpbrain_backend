<?php

declare(strict_types=1);

namespace App\Domain\Verbs;

use App\Domain\Undetermined\SufficiencyCheck;
use App\Domain\Undetermined\VerbResult;
use RuntimeException;

/**
 * The fixed four-step pipeline every verb runs (ADR-004 §Decision).
 *
 *   1. Governance pre-check   — Agent Brain authorizes. The executor is NOT
 *                               chosen here; binding is reserved to Agent Brain.
 *   2. Grounding              — retrieve the relevant subgraph. Ungrounded
 *                               generation is prohibited; empty grounding is an
 *                               immediate UNDETERMINED.
 *   3. Reasoning              — versioned prompt + grounding. The model is
 *                               stateless and addressed through an interface,
 *                               never a vendor SDK, so it stays swappable.
 *   4. Guardrail post-check   — validate against schema, run the UODM
 *                               sufficiency check. Insufficient evidence returns
 *                               UNDETERMINED(gaps) as a first-class result.
 *
 * Verbs EMIT EVENTS; they never write the graph directly. Company Brain remains
 * the single writer of record.
 *
 * This structure is the point. Free-form model calls were rejected in ADR-004 as
 * ungovernable and unauditable, and per-feature AI calls were rejected as
 * unmaintainable — each would reinvent grounding and guardrails.
 */
final class VerbPipeline
{
    public function __construct(
        private readonly SufficiencyCheck $sufficiency,
        private readonly bool $executeEnabled = false,
    ) {
    }

    /**
     * @param  callable():array  $governance  returns ['allowed'=>bool,'reason'=>?string]
     * @param  callable():array  $ground      returns the grounding evidence rows
     * @param  callable(array):array  $reason  returns ['value'=>mixed,'frame'=>array]
     */
    public function run(Verb $verb, callable $governance, callable $ground, callable $reason): VerbResult
    {
        // 1. Governance.
        if ($verb->isDark() && ! $this->executeEnabled) {
            throw new RuntimeException('execute_verb_is_dark_in_v1');
        }

        $decision = $governance();

        if (! ($decision['allowed'] ?? false)) {
            throw new RuntimeException('governance_denied: '.($decision['reason'] ?? 'unspecified'));
        }

        // 2. Grounding.
        $grounding = $ground();

        if ($grounding === []) {
            return VerbResult::undetermined(['no_grounding_evidence']);
        }

        // 3. Reasoning.
        $out = $reason($grounding);

        // 4. Guardrail + sufficiency.
        $check = $this->sufficiency->evaluate($out['frame'] ?? [], $grounding);

        if ($check->isUndetermined()) {
            return $check;
        }

        return VerbResult::decided(
            $out['value'] ?? null,
            array_column($grounding, 'id'),
            $out['confidence'] ?? null,
        );
    }
}
