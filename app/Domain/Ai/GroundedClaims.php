<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * The guardrail between a model's output and anything this system believes.
 *
 * TWO RULES, AND THE SECOND IS THE IMPORTANT ONE.
 *
 * 1. STRICT JSON OR NOTHING. A response that does not parse to the declared
 *    shape yields no claims and the gap `ai_response_unparseable`. There is no
 *    path that returns the raw text as a conclusion — a paragraph the model
 *    wrote instead of the object it was told to produce is not a
 *    lower-confidence answer, it is a different kind of thing, and presenting
 *    it as a recommendation is how a system starts laundering prose into
 *    decisions.
 *
 * 2. EVERY CITATION MUST BE ONE WE SUPPLIED. A claim citing an evidence id
 *    that was not in the grounding set is dropped, not down-weighted. A model
 *    citing evidence it was never given has invented a source, and an invented
 *    source is the most dangerous output this system can produce: it looks
 *    exactly like a well-grounded claim, right down to the clickable id. The
 *    honesty principle is not "prefer true things", it is "never present
 *    something as grounded when it is not".
 *
 * A claim with NO citations is also dropped. Ungrounded generation is
 * prohibited (ADR-004), and an uncited claim is ungrounded by definition.
 */
final class GroundedClaims
{
    /**
     * @param  array<int, array<string, mixed>>  $claims  the claims that survived
     * @param  array<int, string>  $gaps  why anything was dropped, or why nothing parsed
     */
    private function __construct(
        public readonly array $claims,
        public readonly array $gaps,
    ) {
    }

    /**
     * @param  array<int, string>  $groundingIds  ids actually supplied to the model
     * @param  array<int, string>  $requiredFields  fields a claim must carry to be usable
     */
    public static function fromResponse(
        AiResponse $response,
        array $groundingIds,
        string $collectionKey = 'claims',
        array $requiredFields = [],
    ): self {
        $parsed = $response->json();

        if ($parsed === null || ! isset($parsed[$collectionKey]) || ! is_array($parsed[$collectionKey])) {
            return new self([], ['ai_response_unparseable']);
        }

        $kept = [];
        $gaps = [];

        foreach ($parsed[$collectionKey] as $claim) {
            if (! is_array($claim)) {
                $gaps[] = 'ai_response_unparseable';
                continue;
            }

            // A field the schema demanded but the model omitted. The claim is
            // dropped rather than defaulted: filling in a missing priority or
            // confidence would be the system inventing the very thing it asked
            // the model for.
            $missing = array_values(array_filter(
                $requiredFields,
                fn (string $f) => ! isset($claim[$f]) || $claim[$f] === '' || $claim[$f] === []
            ));

            if ($missing !== []) {
                $gaps[] = 'ai_claim_missing_fields:'.implode('+', $missing);
                continue;
            }

            $cited = array_values(array_filter(
                is_array($claim['evidenceRefs'] ?? null) ? $claim['evidenceRefs'] : [],
                'is_string'
            ));

            if ($cited === []) {
                $gaps[] = 'ai_claim_without_evidence';
                continue;
            }

            $fabricated = array_values(array_diff($cited, $groundingIds));

            if ($fabricated !== []) {
                // Named, so the gap is diagnosable rather than merely alarming.
                $gaps[] = 'ai_claim_cited_ungrounded_evidence:'.implode(',', $fabricated);
                continue;
            }

            $claim['evidenceRefs'] = $cited;
            $kept[] = $claim;
        }

        if ($kept === [] && $gaps === []) {
            $gaps[] = 'ai_returned_no_claims';
        }

        return new self($kept, array_values(array_unique($gaps)));
    }

    public function isEmpty(): bool
    {
        return $this->claims === [];
    }
}
