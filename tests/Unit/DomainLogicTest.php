<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Evidence\EvidenceService;
use App\Domain\Kasba\KasbaService;
use App\Domain\Learning\LearningService;
use App\Domain\Policy\PolicyService;
use App\Domain\Reasoning\ReasoningService;
use App\Domain\Recommendation\RecommendationService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * These assert BEHAVIOUR carried over from the TypeScript build, not
 * implementation. Every expected value was produced by running the original
 * Node code over the same inputs — see PORT_STATUS.md "Equivalence proof".
 *
 * Deliberately free of Laravel dependencies so they run under plain PHPUnit
 * before the framework is installed.
 */
final class DomainLogicTest extends TestCase
{
    private function at(int $daysAgo): string
    {
        $base = new DateTimeImmutable('2026-07-27T00:00:00Z');

        return ($daysAgo >= 0
            ? $base->modify("-{$daysAgo} days")
            : $base->modify('+'.abs($daysAgo).' days')
        )->format('c');
    }

    public function test_freshness_halves_at_the_half_life(): void
    {
        $e = new EvidenceService(90);
        $now = new DateTimeImmutable('2026-07-27T00:00:00Z');

        self::assertSame(1.0, round($e->computeFreshness($this->at(0), $now), 6));
        self::assertSame(0.5, round($e->computeFreshness($this->at(90), $now), 6));
        self::assertSame(0.25, round($e->computeFreshness($this->at(180), $now), 6));
    }

    public function test_future_dated_evidence_cannot_exceed_full_freshness(): void
    {
        $e = new EvidenceService(90);
        $now = new DateTimeImmutable('2026-07-27T00:00:00Z');

        self::assertSame(1.0, $e->computeFreshness($this->at(-30), $now));
    }

    public function test_confidence_matches_the_typescript_original(): void
    {
        $r = new ReasoningService(new EvidenceService(90));
        $ev = fn (float $c, int $d) => ['confidence' => $c, 'observedDate' => $this->at($d)];

        // Pinned to the same instant at() measures from. Without this the
        // ages below are relative to the real clock, so every expected value
        // decays by a point every few days and the suite rots on its own.
        $now = new DateTimeImmutable('2026-07-27T00:00:00Z');

        // Values produced by running the Node implementation. 0.9 × 1.0 × 0.15
        // + 0.30 is 0.435, which both Math.round and PHP's round() take up to
        // 0.44 — verified against the Node expression directly. The 0.43 that
        // stood here was never reproducible; it only passed because the score
        // was being decayed against the real clock (see computeConfidence).
        self::assertSame(0.3,  $r->computeConfidence([], $now));
        self::assertSame(0.44, $r->computeConfidence([$ev(0.9, 0)], $now));
        self::assertSame(0.37, $r->computeConfidence([$ev(0.9, 90)], $now));
        self::assertSame(0.75, $r->computeConfidence([$ev(1.0, 0), $ev(1.0, 0), $ev(1.0, 0)], $now));
    }

    public function test_confidence_never_reaches_certainty(): void
    {
        $r = new ReasoningService(new EvidenceService(90));
        $flood = array_fill(0, 50, ['confidence' => 1.0, 'observedDate' => $this->at(0)]);

        self::assertSame(0.95, $r->computeConfidence($flood));
    }

    public function test_low_confidence_forces_the_watch_category(): void
    {
        $s = new RecommendationService();

        self::assertSame('watch', $s->resolveCategory('opportunity', 0.2));
        self::assertSame('opportunity', $s->resolveCategory('opportunity', 0.8));
    }

    public function test_compliance_risk_outranks_a_confident_opportunity(): void
    {
        $s = new RecommendationService();

        self::assertSame('immediate', $s->deriveUrgency('compliance', 0.65));
        self::assertSame('normal', $s->deriveUrgency('opportunity', 0.65));
    }

    public function test_unassessed_kasba_dimension_is_null_not_zero(): void
    {
        $k = new KasbaService();
        $scores = $k->computeScores(['knowledge_level' => 4, 'skill_level' => 2]);

        self::assertSame(4, $scores['knowledge']);
        self::assertNull($scores['ability'], 'unassessed dimension must stay null');
        self::assertSame(3.0, $scores['overall'], 'overall averages only assessed dimensions');
    }

    public function test_no_assessment_at_all_yields_all_nulls(): void
    {
        $scores = (new KasbaService())->computeScores(null);

        foreach ($scores as $value) {
            self::assertNull($value);
        }
    }

    public function test_dimension_without_a_target_is_skipped_not_zero_gap(): void
    {
        $k = new KasbaService();
        $gaps = $k->computeGaps(
            ['knowledge_level' => 2, 'skill_level' => 1],
            ['knowledge' => ['targetLevel' => 4], 'ability' => null]
        );

        self::assertCount(1, $gaps);
        self::assertSame('knowledge', $gaps[0]['dimension']);
        self::assertSame(2.0, $gaps[0]['gap']);
    }

    public function test_failed_outcome_is_recorded_but_not_reusable(): void
    {
        $l = new LearningService();

        self::assertTrue($l->isReusable('success', 0.8));
        self::assertFalse($l->isReusable('failure', 0.9), 'failure must never be reusable');
        self::assertFalse($l->isReusable('success', 0.2), 'low confidence must not be reusable');
    }

    public function test_policy_equality_is_type_strict(): void
    {
        $p = new PolicyService();
        $rule = ['field' => 'level', 'operator' => 'eq', 'value' => 5];

        self::assertTrue($p->evaluateRule($rule, ['level' => 5]));
        self::assertFalse($p->evaluateRule($rule, ['level' => '5']), 'string 5 must not equal int 5');
    }

    public function test_policy_composite_any_and_all(): void
    {
        $p = new PolicyService();
        $conditions = [
            ['field' => 'confidence', 'operator' => 'gte', 'value' => 0.7],
            ['field' => 'category', 'operator' => 'eq', 'value' => 'risk'],
        ];

        $ctx = ['confidence' => 0.9, 'category' => 'opportunity'];

        self::assertTrue($p->evaluateRule(['conditions' => $conditions, 'match' => 'any'], $ctx));
        self::assertFalse($p->evaluateRule(['conditions' => $conditions, 'match' => 'all'], $ctx));
    }
}
