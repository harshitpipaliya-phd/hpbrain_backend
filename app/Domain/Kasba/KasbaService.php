<?php

declare(strict_types=1);

namespace App\Domain\Kasba;

/**
 * KASBA Assessment Engine. Ported from api/src/kasba/assessment-engine.ts.
 *
 * The rule that must not be softened: **an unassessed dimension is null, never
 * zero.** Defaulting to 0 would assert that a person has been measured and
 * found to have no knowledge, when in fact nothing is known. The Product Bible
 * states this as "a fact the system hasn't verified is null, never defaulted to
 * 0", and the Node build enforced it by test in every scoring function.
 *
 * LEVEL AND STATE ARE TWO DIFFERENT ANSWERS, and this class only computes the
 * first. A level answers "how good"; a STATE answers "how firmly do we know
 * that" — Unknown -> Asserted -> Inferred -> Assessed -> Demonstrated ->
 * Mastered (Observed for Behaviour and Attitude), advancing only on evidence
 * and never regressing or inflating silently (Architecture Invariant 6).
 *
 * THAT STATE MODEL IS IMPLEMENTED — this docstring previously said it was not,
 * and was wrong. It lives in App\Domain\Capability\CapabilityState, which owns
 * the ranking and the guarded transition, and it is persisted on
 * hpbrain_capability_proficiency.capability_state alongside evidence_ref,
 * state_source, state_changed_date and state_change_reason. The writer is
 * KasbaController::recordProficiency, which advances from the assignment's
 * CURRENT state rather than from Unknown, refuses an advance without evidence,
 * and refuses a regression without an explicit reason.
 *
 * The division of labour is deliberate: scoring stays numeric and stateless
 * here, so a change to the confidence model cannot quietly alter a score.
 */
final class KasbaService
{
    /** @var array<int, string> */
    private array $dimensions;

    public function __construct(?array $dimensions = null)
    {
        $this->dimensions = $dimensions ?? ['knowledge', 'ability', 'skill', 'behaviour', 'attitude'];
    }

    /**
     * The same engine bound to a tenant's own dimensions.
     *
     * Returns a new instance rather than mutating: this service is a container
     * singleton, and a setter would let one request's four-dimension model leak
     * into the next request's five-dimension one.
     */
    public function forModel(AssessmentModel $model): self
    {
        return new self($model->dimensions);
    }

    /**
     * @param  array<string, float|null>|null  $latest
     * @return array<string, float|null>
     */
    public function computeScores(?array $latest): array
    {
        $scores = [];

        foreach ($this->dimensions as $d) {
            $scores[$d] = $latest[$d.'_level'] ?? null;
        }

        $assessed = array_values(array_filter($scores, fn ($v) => $v !== null));

        $scores['overall'] = $assessed === []
            ? null
            : round(array_sum($assessed) / count($assessed), 2);

        return $scores;
    }

    /**
     * Gap analysis against the Capability's own stated target per dimension.
     * A dimension with no target is SKIPPED, not treated as a zero gap —
     * "no target set" is not the same claim as "target met".
     *
     * @return array<int, array{dimension: string, currentLevel: float|null, targetLevel: float, gap: float}>
     */
    public function computeGaps(?array $latest, array $targets): array
    {
        $findings = [];

        foreach ($this->dimensions as $d) {
            $target = $targets[$d]['targetLevel'] ?? null;

            if ($target === null) {
                continue;
            }

            $current = $latest[$d.'_level'] ?? null;
            $gap = round((float) $target - (float) ($current ?? 0), 2);

            if ($gap > 0) {
                $findings[] = [
                    'dimension'    => $d,
                    'currentLevel' => $current,
                    'targetLevel'  => (float) $target,
                    'gap'          => $gap,
                ];
            }
        }

        usort($findings, fn ($a, $b) => $b['gap'] <=> $a['gap']);

        return $findings;
    }
}
