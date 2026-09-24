<?php

declare(strict_types=1);

namespace App\Domain\Recommendation;

/**
 * Invariant 3, in one place: every action is executable.
 *
 * A recommendation whose category tells someone to ACT must name the ESO that
 * defines the act. Advice labelled as an action is the failure this prevents.
 *
 * Extracted here because there are now two writers of hpbrain_recommendations —
 * the HTTP endpoint and the RECOMMEND verb — and an invariant with two
 * implementations has two chances to drift apart. It is the rule, not the
 * enforcement: each caller still decides what to do when it is violated (422
 * for a request, a dropped claim and a gap for a model).
 */
final class EsoBindingRule
{
    /** Categories that instruct someone to do something, as opposed to watch. */
    public const ACTIONABLE = ['intervene', 'escalate'];

    public static function requiresEso(string $category): bool
    {
        return in_array($category, self::ACTIONABLE, true);
    }

    public static function isSatisfied(string $category, ?string $esoId): bool
    {
        return ! self::requiresEso($category) || ($esoId !== null && $esoId !== '');
    }
}
