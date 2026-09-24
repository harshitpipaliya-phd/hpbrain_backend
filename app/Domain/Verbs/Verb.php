<?php

declare(strict_types=1);

namespace App\Domain\Verbs;

/**
 * The seven-verb Capability Interface (ADR-004).
 *
 * These are the ONLY cognitive operations in the system. Every product feature
 * is, underneath, a composition of these seven over the graph — which is what
 * lets one engine serve every domain: only the nodes change, the verbs do not.
 */
enum Verb: string
{
    case EXPLAIN   = 'EXPLAIN';
    case ASSESS    = 'ASSESS';
    case COACH     = 'COACH';
    case SIMULATE  = 'SIMULATE';
    case EVALUATE  = 'EVALUATE';
    case RECOMMEND = 'RECOMMEND';
    case EXECUTE   = 'EXECUTE';

    /**
     * EXECUTE ships DARK in v1 — built, governed, flag-off. It is the only verb
     * that changes the world outside the Brain, so it stays behind a flag until
     * Agent Brain governance and executor binding are solid.
     */
    public function isDark(): bool
    {
        return $this === self::EXECUTE;
    }

    /**
     * Read-oriented verbs are lowest risk and were implemented first.
     *
     * COACH JOINS THEM BECAUSE IT WRITES NOTHING. It was absent from this list
     * while it was unimplemented, not because it changes state: CoachVerb reads
     * resolved context, evidence and memory, and returns a VerbResult. It
     * inserts no domain row — unlike RECOMMEND, which is correctly excluded
     * because it writes hpbrain_recommendations, and EXECUTE, which is the only
     * verb that changes anything outside the Brain at all.
     *
     * This is a classification, not a permission. Each verb still states its
     * own requirement in its governance callable, and COACH requires READ
     * there — so adding it here grants nothing that governance does not also
     * check.
     */
    public function isReadOnly(): bool
    {
        return in_array($this, [self::EXPLAIN, self::ASSESS, self::EVALUATE, self::COACH], true);
    }
}
