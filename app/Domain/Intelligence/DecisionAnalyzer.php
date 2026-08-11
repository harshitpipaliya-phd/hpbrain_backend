<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use Illuminate\Support\Facades\DB;

/**
 * Are this organization's decisions any good — and is it learning from them?
 *
 * ACCURACY IS THE FIGURE THIS SCREEN EXISTS FOR, AND IT IS USUALLY UNMEASURABLE.
 * Whether a decision was right is knowable only from an outcome recorded against
 * it. Where an organization has recorded none, the honest answer is UNDETERMINED
 * with the missing input named — not a plausible percentage, and not zero. Zero
 * would read as "every decision was wrong", which is a far stronger and more
 * damaging claim than the truth, which is that nobody looked.
 *
 * SO THE SCREEN FALLS BACK ON A QUESTION IT *CAN* ANSWER. If accuracy is
 * unavailable, acceptance is still measurable and so is evidence support, and
 * acceptance plotted against evidence support answers something nearly as useful:
 * how much of what this organization approves, it approves on evidence rather than
 * on trust. A category in the high-acceptance, low-evidence corner is being waved
 * through, and that is worth knowing whether or not outcomes exist.
 *
 * PROVENANCE OF THE DECISIONS THEMSELVES IS PART OF THE ANSWER. A decision row
 * written by a seeder is not an organizational decision, and an acceptance rate
 * computed over seeded rows describes a fixture. Those rows are counted, reported
 * separately, and excluded from nothing — hiding them would be worse — but the
 * response says how many there are so no figure here can be read as behaviour
 * when it is scaffolding.
 */
final class DecisionAnalyzer
{
    /** Statuses that mean the organization decided to act. Matches AnalyticsController. */
    private const APPROVED = ['approved', 'accepted'];

    private const REJECTED = ['rejected', 'declined'];

    /**
     * Actors that indicate a row was generated rather than decided.
     *
     * Matched as a prefix on the ACTOR, never on free text. Searching rationale
     * strings for the word "demo" would misclassify a real decision about a
     * customer demonstration.
     *
     * @var array<int, string>
     */
    private const SYNTHETIC_ACTORS = ['demo-seeder', 'seeder', 'system-seed', 'loop-seeder'];

    /**
     * Below this, a decision was taken while the reasoning behind it was weak.
     *
     * Read from config so it means the same thing here as in the reasoning
     * service, rather than becoming a second, drifting definition of "low".
     */
    private function lowConfidenceFloor(): float
    {
        return (float) config('brain.reasoning.low_confidence_floor', 0.40);
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $risks   RiskAnalyzer::analyse()
     *
     * @return array<string, mixed>
     */
    public function analyse(string $tenantId, array $profile, array $risks): array
    {
        $decisions = DB::table('hpbrain_decisions')->where('tenant_id', $tenantId)->get();
        $total     = $decisions->count();

        $pipeline   = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        $byExecutor = [];
        $synthetic  = 0;
        $noRationale = 0;
        $lowConfidence = 0;
        $floor = $this->lowConfidenceFloor();

        foreach ($decisions as $d) {
            $status = strtolower(trim((string) $d->status));

            if (in_array($status, self::APPROVED, true)) {
                $pipeline['approved']++;
            } elseif (in_array($status, self::REJECTED, true)) {
                $pipeline['rejected']++;
            } else {
                $pipeline['pending']++;
            }

            $executor = (string) ($d->executor_type ?? 'unassigned');
            $byExecutor[$executor] = ($byExecutor[$executor] ?? 0) + 1;

            if ($this->isSynthetic((string) ($d->decided_by ?? ''))) {
                $synthetic++;
            }

            if (trim((string) ($d->rationale ?? '')) === '') {
                $noRationale++;
            }

            if ($d->confidence !== null && (float) $d->confidence < $floor) {
                $lowConfidence++;
            }
        }

        $accuracy = $this->accuracy($tenantId);

        return [
            'state' => [
                'decisions'      => $total,
                'pipeline'       => $pipeline,
                'acceptanceRate' => $total === 0 ? null : round($pipeline['approved'] / $total, 4),
                'rejectionRate'  => $total === 0 ? null : round($pipeline['rejected'] / $total, 4),
                'recommendations' => (int) ($profile['loop']['hpbrain_recommendations']['rows'] ?? 0),
                // How much of what was proposed was ever actually decided on. A
                // large gap here is a backlog of unanswered proposals, which is a
                // different failure from rejecting them.
                'decisionCoverage' => $this->decisionCoverage($tenantId),
                'meanConfidence' => $this->meanDecisionConfidence($tenantId),
                'syntheticDecisions' => $synthetic,
                'provenanceNote' => $synthetic === 0
                    ? null
                    : $synthetic.' of '.$total.' decision'.($total === 1 ? '' : 's').' were written by a seeder rather than taken by a person. Every rate on this screen is computed over all rows, including those, because excluding them silently would be a bigger distortion than reporting them.',
            ],
            'latency'   => $this->latency($tenantId),
            'accuracy'  => $accuracy,
            'quality'   => [
                'withoutRecommendation' => $this->countWithoutRecommendation($tenantId),
                'withoutRationale'      => $noRationale,
                'belowConfidenceFloor'  => $lowConfidence,
                'confidenceFloor'       => $floor,
                'unevidenced'           => $this->unevidencedRecommendations($tenantId),
            ],
            'byExecutor'         => $byExecutor,
            'categoryByExecutor' => $this->categoryByExecutor($tenantId),
            'byCategory'         => $this->byCategory($tenantId),
            'acceptanceVsEvidence' => $this->acceptanceVsEvidence($tenantId),
            'acceptanceVsAccuracy' => $accuracy['measurable']
                ? $this->acceptanceVsAccuracy($tenantId)
                : [
                    'measurable' => false,
                    'points'     => [],
                    'gaps'       => ['hpbrain_outcomes'],
                    'why'        => 'Whether a decision was right is knowable only from an outcome recorded against it. This organization has recorded none, so accuracy is undetermined rather than zero — and acceptance against evidence support is shown in its place, which is measurable and answers a related question.',
                ],
            'openBeyond'   => $this->openDecisionAges($tenantId),
            'rootCause'    => $this->rootCause($tenantId, $risks),
            'confidenceBands' => $this->reasoningConfidenceBands($tenantId),
        ];
    }

    /* ─────────────────────────── accuracy ─────────────────────────── */

    /**
     * How often acting on a recommendation actually worked.
     *
     * Measured over OUTCOMES, not over recommendations. A recommendation nobody
     * acted on has proved nothing either way, and putting it in the denominator
     * would make a cautious organization look inaccurate.
     *
     * @return array<string, mixed>
     */
    private function accuracy(string $tenantId): array
    {
        $outcomes = (array) DB::table('hpbrain_outcomes')->where('tenant_id', $tenantId)
            ->selectRaw("COUNT(*) AS n,
                         SUM(CASE WHEN LOWER(result) = 'success' THEN 1 ELSE 0 END) AS successes,
                         SUM(CASE WHEN LOWER(result) = 'failure' THEN 1 ELSE 0 END) AS failures")
            ->first();

        $n = (int) ($outcomes['n'] ?? 0);

        if ($n === 0) {
            return [
                'measurable' => false,
                'value'      => null,
                'outcomes'   => 0,
                'successes'  => 0,
                'gaps'       => ['hpbrain_outcomes'],
                'why'        => 'No outcome has been recorded for this organization. Accuracy is undetermined — not zero, which would assert that every decision was wrong.',
                'howToFix'   => 'Record the outcome of one decision that has already run. A single outcome turns this from undetermined into measured.',
                'confidence' => Confidence::build()->jsonSerialize(),
                'provenance' => Provenance::of('COUNT over hpbrain_outcomes')->from('hpbrain_outcomes', ['tenant_id' => $tenantId], 0),
            ];
        }

        $successes = (int) ($outcomes['successes'] ?? 0);

        return [
            'measurable' => true,
            'value'      => round($successes / $n, 4),
            'outcomes'   => $n,
            'successes'  => $successes,
            'failures'   => (int) ($outcomes['failures'] ?? 0),
            'gaps'       => [],
            'why'        => 'Successes divided by recorded outcomes. Recommendations nobody acted on are excluded, because they have proved nothing either way.',
            'confidence' => Confidence::build()
                ->add('sampleSize', 0.7, Confidence::volumeAdequacy($n, 30), $n.' recorded outcome'.($n === 1 ? '' : 's').'; a rate over a handful of outcomes moves several points per new row')
                ->add('coverage', 0.3, null, 'outcome coverage against decisions taken is not yet computed here')
                ->jsonSerialize(),
            'provenance' => Provenance::of('successes / outcomes, where result = success')
                ->from('hpbrain_outcomes', ['tenant_id' => $tenantId], $n),
        ];
    }

    /* ─────────────────────────── the two quadrants ─────────────────────────── */

    /**
     * Acceptance against evidence support, per recommendation category.
     *
     * The substitute reading when accuracy is unmeasurable, and useful in its own
     * right. High acceptance with low evidence support is approval on trust.
     *
     * @return array<string, mixed>
     */
    private function acceptanceVsEvidence(string $tenantId): array
    {
        $rows = DB::table('hpbrain_recommendations as r')
            ->where('r.tenant_id', $tenantId)
            ->leftJoin('hpbrain_decisions as d', function ($j) use ($tenantId) {
                $j->on('d.recommendation_id', '=', 'r.id')->where('d.tenant_id', '=', $tenantId);
            })
            ->selectRaw("COALESCE(r.category, 'uncategorised') AS category,
                         COUNT(DISTINCT r.id) AS recommendations,
                         COUNT(DISTINCT CASE WHEN LOWER(d.status) IN ('approved','accepted') THEN r.id END) AS accepted,
                         COUNT(DISTINCT CASE WHEN EXISTS (
                             SELECT 1 FROM hpbrain_recommendation_evidence re
                              WHERE re.recommendation_id = r.id AND re.tenant_id = r.tenant_id
                         ) THEN r.id END) AS evidenced,
                         AVG(r.confidence) AS mean_confidence")
            ->groupBy('category')
            ->get();

        $points = $rows->map(fn ($r) => [
            'category'       => (string) $r->category,
            'recommendations' => (int) $r->recommendations,
            'accepted'       => (int) $r->accepted,
            'evidenced'      => (int) $r->evidenced,
            'acceptance'     => (int) $r->recommendations === 0 ? null : round(((int) $r->accepted) / (int) $r->recommendations, 4),
            'evidenceSupport' => (int) $r->recommendations === 0 ? null : round(((int) $r->evidenced) / (int) $r->recommendations, 4),
            'meanConfidence' => $r->mean_confidence === null ? null : round((float) $r->mean_confidence, 4),
        ])->all();

        usort($points, static fn (array $a, array $b): int => $b['recommendations'] <=> $a['recommendations']);

        return [
            'measurable' => $points !== [],
            'points'     => $points,
            'xLabel'     => 'Accepted',
            'yLabel'     => 'Backed by evidence',
            'hotCorner'  => 'Accepted without evidence',
            'why'        => 'A category in the high-acceptance, low-evidence corner is being approved on trust. Architecture Invariant 1 requires every recommendation to have a traceable evidence path, so anything to the lower-right is a governance finding as well as a quality one.',
            'provenance' => Provenance::of('per recommendation category: accepted = distinct recommendations with an approved/accepted decision; evidenced = distinct recommendations with a row in hpbrain_recommendation_evidence')
                ->from('hpbrain_recommendations', ['tenant_id' => $tenantId], array_sum(array_column($points, 'recommendations'))),
        ];
    }

    /**
     * Acceptance against proved-right, per category. Only reachable with outcomes.
     *
     * @return array<string, mixed>
     */
    private function acceptanceVsAccuracy(string $tenantId): array
    {
        $rows = DB::table('hpbrain_recommendations as r')
            ->where('r.tenant_id', $tenantId)
            ->leftJoin('hpbrain_decisions as d', function ($j) use ($tenantId) {
                $j->on('d.recommendation_id', '=', 'r.id')->where('d.tenant_id', '=', $tenantId);
            })
            ->leftJoin('hpbrain_outcomes as o', function ($j) use ($tenantId) {
                $j->on('o.decision_id', '=', 'd.id')->where('o.tenant_id', '=', $tenantId);
            })
            ->selectRaw("COALESCE(r.category, 'uncategorised') AS category,
                         COUNT(DISTINCT r.id) AS recommendations,
                         COUNT(DISTINCT CASE WHEN LOWER(d.status) IN ('approved','accepted') THEN r.id END) AS accepted,
                         COUNT(DISTINCT o.id) AS outcomes,
                         COUNT(DISTINCT CASE WHEN LOWER(o.result) = 'success' THEN o.id END) AS successes")
            ->groupBy('category')
            ->get();

        $points = [];

        foreach ($rows as $r) {
            $recommendations = (int) $r->recommendations;
            $outcomes        = (int) $r->outcomes;

            $points[] = [
                'category'       => (string) $r->category,
                'recommendations' => $recommendations,
                'accepted'       => (int) $r->accepted,
                'outcomes'       => $outcomes,
                'successes'      => (int) $r->successes,
                'acceptance'     => $recommendations === 0 ? null : round(((int) $r->accepted) / $recommendations, 4),
                // Null, not zero, for a category nobody has measured an outcome in.
                // The chart must leave it off rather than plotting it at the floor.
                'accuracy'       => $outcomes === 0 ? null : round(((int) $r->successes) / $outcomes, 4),
            ];
        }

        usort($points, static fn (array $a, array $b): int => $b['recommendations'] <=> $a['recommendations']);

        return [
            'measurable' => true,
            'points'     => $points,
            'xLabel'     => 'Accepted',
            'yLabel'     => 'Proved right',
            'hotCorner'  => 'Accepted but wrong',
            'why'        => 'Categories to the lower-right were approved and then did not work. Categories with no outcome recorded are returned with accuracy null and are not plotted.',
            'provenance' => Provenance::of('per category: accuracy = successful outcomes / outcomes, joined recommendation -> decision -> outcome')
                ->from('hpbrain_outcomes', ['tenant_id' => $tenantId], array_sum(array_column($points, 'outcomes'))),
        ];
    }

    /* ─────────────────────────── the rest ─────────────────────────── */

    /**
     * How long the organization takes to make up its mind.
     *
     * Measured from the recommendation to the decision. Decisions with no
     * recommendation behind them have no such interval and are excluded rather
     * than counted as instantaneous.
     *
     * @return array<string, mixed>
     */
    private function latency(string $tenantId): array
    {
        // Through SqlDialect so the suite, which runs on in-memory SQLite, can
        // actually execute this. TIMESTAMPDIFF is why the equivalent figure in
        // AnalyticsController has no test coverage.
        $interval = SqlDialect::secondsBetween('r.created_date', 'd.created_date');

        $row = (array) DB::table('hpbrain_decisions as d')
            ->join('hpbrain_recommendations as r', function ($j) use ($tenantId) {
                $j->on('r.id', '=', 'd.recommendation_id')->where('r.tenant_id', '=', $tenantId);
            })
            ->where('d.tenant_id', $tenantId)
            ->whereNotNull('d.created_date')->whereNotNull('r.created_date')
            ->selectRaw('COUNT(*) AS n,
                         AVG('.$interval.') AS mean_seconds,
                         MIN('.$interval.') AS min_seconds,
                         MAX('.$interval.') AS max_seconds')
            ->first();

        $n = (int) ($row['n'] ?? 0);

        return [
            'measurable' => $n > 0,
            'pairs'      => $n,
            'meanHours'  => $row['mean_seconds'] === null ? null : round(((float) $row['mean_seconds']) / 3600, 2),
            'minHours'   => $row['min_seconds'] === null ? null : round(((float) $row['min_seconds']) / 3600, 2),
            'maxHours'   => $row['max_seconds'] === null ? null : round(((float) $row['max_seconds']) / 3600, 2),
            'why'        => $n === 0
                ? 'No decision is linked to a recommendation, so there is no interval to measure.'
                : 'Measured over the '.$n.' decision'.($n === 1 ? '' : 's').' linked to a recommendation. Unlinked decisions are excluded rather than counted as instant.',
            'provenance' => Provenance::of('AVG(TIMESTAMPDIFF(SECOND, recommendation.created_date, decision.created_date)) / 3600')
                ->from('hpbrain_decisions', ['tenant_id' => $tenantId, 'recommendation_id' => 'not null'], $n),
        ];
    }

    /** Share of recommendations that ever reached a decision. */
    private function decisionCoverage(string $tenantId): ?float
    {
        $recommendations = (int) DB::table('hpbrain_recommendations')->where('tenant_id', $tenantId)->count();

        if ($recommendations === 0) {
            return null;
        }

        $decided = (int) DB::table('hpbrain_recommendations as r')
            ->where('r.tenant_id', $tenantId)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('hpbrain_decisions as d')
                ->whereColumn('d.recommendation_id', 'r.id')->where('d.tenant_id', $tenantId))
            ->count();

        return round($decided / $recommendations, 4);
    }

    private function meanDecisionConfidence(string $tenantId): ?float
    {
        $mean = DB::table('hpbrain_decisions')->where('tenant_id', $tenantId)->avg('confidence');

        return $mean === null ? null : round((float) $mean, 4);
    }

    private function countWithoutRecommendation(string $tenantId): int
    {
        return (int) DB::table('hpbrain_decisions')->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereNull('recommendation_id')->orWhere('recommendation_id', ''))
            ->count();
    }

    /**
     * Recommendations with no evidence linked — Architecture Invariant 1.
     *
     * @return array<string, mixed>
     */
    private function unevidencedRecommendations(string $tenantId): array
    {
        $total = (int) DB::table('hpbrain_recommendations')->where('tenant_id', $tenantId)->count();

        if ($total === 0) {
            return ['total' => 0, 'unevidenced' => 0, 'share' => null];
        }

        $evidenced = (int) DB::table('hpbrain_recommendations as r')
            ->where('r.tenant_id', $tenantId)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('hpbrain_recommendation_evidence as re')
                ->whereColumn('re.recommendation_id', 'r.id')->where('re.tenant_id', $tenantId))
            ->count();

        return [
            'total'       => $total,
            'evidenced'   => $evidenced,
            'unevidenced' => $total - $evidenced,
            'share'       => round(($total - $evidenced) / $total, 4),
        ];
    }

    /**
     * Which kinds of work each executor class ends up owning.
     *
     * @return array<string, array<string, int>>
     */
    private function categoryByExecutor(string $tenantId): array
    {
        $rows = DB::table('hpbrain_decisions as d')
            ->leftJoin('hpbrain_recommendations as r', function ($j) use ($tenantId) {
                $j->on('r.id', '=', 'd.recommendation_id')->where('r.tenant_id', '=', $tenantId);
            })
            ->where('d.tenant_id', $tenantId)
            ->selectRaw("COALESCE(r.category, 'uncategorised') AS category,
                         COALESCE(d.executor_type, 'unassigned') AS executor,
                         COUNT(*) AS n")
            ->groupBy('category', 'executor')
            ->get();

        $out = [];

        foreach ($rows as $r) {
            $out[(string) $r->category][(string) $r->executor] = (int) $r->n;
        }

        return $out;
    }

    /**
     * Recommendation volume and quality per category.
     *
     * The urgent-count alias is `urgent_count` rather than the obvious
     * `high_priority`: HIGH_PRIORITY is a reserved word in MySQL and MariaDB (it
     * is a modifier on SELECT), so aliasing to it is a syntax error that only
     * appears at runtime, on this one query, against a real database.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byCategory(string $tenantId): array
    {
        return DB::table('hpbrain_recommendations')->where('tenant_id', $tenantId)
            ->selectRaw("COALESCE(category, 'uncategorised') AS category,
                         COUNT(*) AS recommendations,
                         AVG(confidence) AS mean_confidence,
                         SUM(CASE WHEN LOWER(priority) IN ('critical','high') THEN 1 ELSE 0 END) AS urgent_count")
            ->groupBy('category')->orderByDesc('recommendations')
            ->get()
            ->map(fn ($r) => [
                'category'       => (string) $r->category,
                'recommendations' => (int) $r->recommendations,
                'meanConfidence' => $r->mean_confidence === null ? null : round((float) $r->mean_confidence, 4),
                'highPriority'   => (int) $r->urgent_count,
            ])->all();
    }

    /**
     * How long the undecided have been waiting.
     *
     * @return array<string, mixed>
     */
    private function openDecisionAges(string $tenantId): array
    {
        $age = SqlDialect::daysSince('created_date');

        $rows = DB::table('hpbrain_decisions')->where('tenant_id', $tenantId)
            ->whereNotIn(DB::raw('LOWER(status)'), array_merge(self::APPROVED, self::REJECTED))
            ->selectRaw('COUNT(*) AS n,
                         AVG('.$age.') AS mean_days,
                         MAX('.$age.') AS max_days')
            ->first();

        $row = (array) $rows;

        return [
            'open'     => (int) ($row['n'] ?? 0),
            'meanDays' => $row['mean_days'] === null ? null : round((float) $row['mean_days'], 1),
            'maxDays'  => $row['max_days'] === null ? null : (int) $row['max_days'],
        ];
    }

    /**
     * Root-cause distribution.
     *
     * PREFERS the organization's own hypotheses, because those are what a person
     * actually concluded. Falls back on the root-cause families the risk
     * generators assigned, and SAYS WHICH IT DID — a derived distribution is a
     * statement about the shape of the organization's problems, while a
     * hypothesis-based one is a statement about its diagnosis, and conflating them
     * would let an organization that has diagnosed nothing appear to have
     * diagnosed a lot.
     *
     * @param array<string, mixed> $risks
     *
     * @return array<string, mixed>
     */
    private function rootCause(string $tenantId, array $risks): array
    {
        $hypotheses = DB::table('hpbrain_hypotheses')->where('tenant_id', $tenantId)
            ->selectRaw("COALESCE(root_cause_family, 'unclassified') AS family, COUNT(*) AS n")
            ->groupBy('family')->orderByDesc('n')->get();

        if ($hypotheses->isNotEmpty()) {
            return [
                'source'       => 'hypotheses',
                'distribution' => $hypotheses->map(fn ($h) => ['family' => (string) $h->family, 'count' => (int) $h->n])->all(),
                'why'          => 'Counted from the hypotheses this organization has actually raised and classified. This is a statement about its diagnosis.',
                'provenance'   => Provenance::of('GROUP BY root_cause_family')
                    ->from('hpbrain_hypotheses', ['tenant_id' => $tenantId], (int) $hypotheses->sum('n')),
            ];
        }

        return [
            'source'       => 'derived_from_risks',
            'distribution' => $risks['byRootCause'],
            'why'          => 'This organization has raised no hypotheses, so nothing records what it believes its causes are. Shown instead is the taxonomy family each detected risk\'s generator belongs to — a statement about the shape of its problems, not about its diagnosis of them.',
            'provenance'   => Provenance::of('each risk generator declares one root-cause family; counted across detected risks')
                ->from('hpbrain_operational_records', ['tenant_id' => $tenantId, 'via' => 'risk generators'], (int) ($risks['open'] + $risks['mitigated'])),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reasoningConfidenceBands(string $tenantId): array
    {
        return DB::table('hpbrain_reasoning_steps')->where('tenant_id', $tenantId)
            ->selectRaw("CASE WHEN confidence_score IS NULL THEN 'unstated'
                              WHEN confidence_score >= 0.75 THEN 'high'
                              WHEN confidence_score >= 0.5  THEN 'moderate'
                              WHEN confidence_score >= 0.25 THEN 'low'
                              ELSE 'very low' END AS band, COUNT(*) AS n")
            ->groupBy('band')->get()
            ->map(fn ($r) => ['band' => (string) $r->band, 'count' => (int) $r->n])->all();
    }

    private function isSynthetic(string $actor): bool
    {
        $actor = strtolower(trim($actor));

        foreach (self::SYNTHETIC_ACTORS as $needle) {
            if ($actor === $needle || str_starts_with($actor, $needle)) {
                return true;
            }
        }

        return false;
    }
}
