<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytics are COMPUTED from the loop tables on request. Nothing here is a
 * stored aggregate, so a figure can never drift from the records it summarises.
 *
 * Every method below answers in the shape its screen actually reads. They did
 * not before, and the consequence was not a wrong number — it was a blank page:
 *
 *   /analytics                      DecisionAnalyticsPanel does
 *                                   Object.entries(analytics.recommendations.byCategory),
 *                                   and `recommendations` was an integer.
 *   /analytics/executive-summary    ExecutiveDashboard and CommandCenter both read
 *                                   intelligenceScore.score, topRisks[],
 *                                   pendingRecommendations[]; none existed.
 *   /analytics/decision-intelligence  DecisionIntelligence reads pipeline.pending
 *                                   and Object.keys(categoryExecutorHeatmap).
 *
 * A rate over an empty set is reported as 0, not null, because the clients type
 * these as numbers and multiply them — and the `total` sitting beside each rate
 * is what distinguishes "nothing happened" from "everything was rejected".
 */
final class AnalyticsController extends Controller
{
    /** Statuses that count as a decision having been accepted. */
    private const APPROVED = ['approved', 'accepted'];
    private const REJECTED = ['rejected', 'declined'];

    public function index(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);

        return response()->json($this->statistics($t) + [
            // The raw loop counters the endpoint has always published, kept so
            // anything reading them keeps working.
            'loop' => [
                'signals'           => DB::table('hpbrain_signals')->where('tenant_id', $t)->count(),
                'evidence'          => DB::table('hpbrain_evidence')->where('tenant_id', $t)->count(),
                'cases'             => DB::table('hpbrain_cases')->where('tenant_id', $t)->count(),
                'recommendations'   => DB::table('hpbrain_recommendations')->where('tenant_id', $t)->count(),
                'decisions'         => DB::table('hpbrain_decisions')->where('tenant_id', $t)->count(),
                'outcomes'          => DB::table('hpbrain_outcomes')->where('tenant_id', $t)->count(),
                'learnings'         => DB::table('hpbrain_learnings')->where('tenant_id', $t)->count(),
                'reusableLearnings' => DB::table('hpbrain_learnings')->where('tenant_id', $t)->where('reusable', 1)->count(),
            ],
        ]);
    }

    /**
     * The five statistic blocks the analytics and executive screens share.
     *
     * @return array<string, mixed>
     */
    private function statistics(string $t): array
    {
        $decisions = DB::table('hpbrain_decisions')->where('tenant_id', $t)->get();
        $approved  = $decisions->filter(fn ($d) => in_array(strtolower((string) $d->status), self::APPROVED, true))->count();
        $rejected  = $decisions->filter(fn ($d) => in_array(strtolower((string) $d->status), self::REJECTED, true))->count();

        $recommendations = DB::table('hpbrain_recommendations')->where('tenant_id', $t)->get();
        $byCategory = [];
        foreach ($recommendations as $r) {
            $key = (string) ($r->category ?? 'uncategorised');
            $byCategory[$key] = ($byCategory[$key] ?? 0) + 1;
        }

        $outcomes   = DB::table('hpbrain_outcomes')->where('tenant_id', $t)->get();
        $successful = $outcomes->filter(fn ($o) => strtolower((string) $o->result) === 'success')->count();

        $risks = DB::table('hpbrain_risks')->where('tenant_id', $t)->get();
        $openRisks = $risks->filter(fn ($r) => strtolower((string) $r->status) !== 'mitigated')->count();
        $risksByCategory = [];
        foreach ($risks as $r) {
            $key = (string) ($r->category ?? 'uncategorised');
            $risksByCategory[$key] = ($risksByCategory[$key] ?? 0) + 1;
        }

        // Evidence quality is the mean confidence of the evidence on file: how
        // firmly the Brain's reasoning is grounded, expressed 0..1.
        $evidenceConfidence = DB::table('hpbrain_evidence')->where('tenant_id', $t)->avg('confidence');

        return [
            'decisions' => [
                'total'          => $decisions->count(),
                'approved'       => $approved,
                'rejected'       => $rejected,
                'acceptanceRate' => $decisions->isEmpty() ? 0.0 : round($approved / $decisions->count(), 4),
            ],
            'recommendations' => [
                'total'      => $recommendations->count(),
                'byCategory' => (object) $byCategory,
            ],
            'outcomes' => [
                'total'      => $outcomes->count(),
                'successful' => $successful,
                // How often acting on a recommendation actually worked. Over
                // outcomes, not over recommendations: a recommendation nobody
                // acted on has proved nothing either way.
                'recommendationAccuracy' => $outcomes->isEmpty() ? 0.0 : round($successful / $outcomes->count(), 4),
            ],
            'risks' => [
                'total'        => $risks->count(),
                'open'         => $openRisks,
                'byCategory'   => (object) $risksByCategory,
                'averageScore' => $risks->isEmpty() ? 0.0 : round((float) $risks->avg('score'), 2),
            ],
            'evidenceQuality' => $evidenceConfidence === null ? 0.0 : round((float) $evidenceConfidence, 4),
        ];
    }

    public function executiveSummary(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);
        $statistics = $this->statistics($t);

        $topRisks = DB::table('hpbrain_risks')
            ->where('tenant_id', $t)
            ->whereRaw('LOWER(status) <> ?', ['mitigated'])
            ->orderByDesc('score')->limit(6)->get()
            ->map(fn ($r) => [
                'id'       => (string) $r->id,
                'category' => (string) ($r->category ?? 'uncategorised'),
                'score'    => (float) $r->score,
                'impact'   => (string) ($r->impact ?? 'unknown'),
            ])->values();

        $pendingRecommendations = DB::table('hpbrain_recommendations')
            ->where('tenant_id', $t)
            ->whereRaw('LOWER(status) IN (?, ?)', ['pending', 'proposed'])
            ->orderByDesc('created_date')->limit(20)->get()
            ->map(fn ($r) => [
                'id'         => (string) $r->id,
                'title'      => (string) ($r->title ?? ''),
                'category'   => (string) ($r->category ?? 'uncategorised'),
                'confidence' => $r->confidence === null ? 0.0 : (float) $r->confidence,
                'priority'   => (string) ($r->priority ?? 'medium'),
            ])->values();

        // What the organization has actually learned, by domain. patternCount
        // counts the learnings reinforcing each model, so a domain with high
        // confidence and one pattern behind it can be told apart from one
        // earned over twenty.
        $patternsByModel = DB::table('hpbrain_learnings')
            ->where('tenant_id', $t)->whereNotNull('mental_model_id')
            ->select('mental_model_id', DB::raw('COUNT(*) as pattern_count'))
            ->groupBy('mental_model_id')->pluck('pattern_count', 'mental_model_id');

        $byDomain = [];
        foreach (DB::table('hpbrain_mental_models')->where('tenant_id', $t)->get() as $m) {
            $domain = (string) ($m->domain ?? 'general');
            $byDomain[$domain] ??= ['domain' => $domain, 'confidenceSum' => 0.0, 'models' => 0, 'reinforcementCount' => 0, 'patternCount' => 0];
            $byDomain[$domain]['confidenceSum']      += (float) ($m->confidence ?? 0);
            $byDomain[$domain]['models']             += 1;
            $byDomain[$domain]['reinforcementCount'] += (int) ($m->reinforcement_count ?? 0);
            $byDomain[$domain]['patternCount']       += (int) ($patternsByModel[$m->id] ?? 0);
        }

        $organizationalKnowledge = array_values(array_map(fn (array $d) => [
            'domain'             => $d['domain'],
            'confidence'         => $d['models'] === 0 ? 0.0 : round($d['confidenceSum'] / $d['models'], 4),
            'reinforcementCount' => $d['reinforcementCount'],
            'patternCount'       => $d['patternCount'],
        ], $byDomain));

        usort($organizationalKnowledge, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        // The composite is an average of the four rates it is built from, each
        // expressed 0-100. Publishing the breakdown alongside the score is the
        // point: a single number nobody can decompose is not intelligence, it
        // is a claim.
        $breakdown = [
            'decisionAcceptance'     => round($statistics['decisions']['acceptanceRate'] * 100, 1),
            'recommendationAccuracy' => round($statistics['outcomes']['recommendationAccuracy'] * 100, 1),
            'evidenceQuality'        => round($statistics['evidenceQuality'] * 100, 1),
            'riskCoverage'           => $statistics['risks']['total'] === 0
                ? 0.0
                : round((1 - $statistics['risks']['open'] / $statistics['risks']['total']) * 100, 1),
        ];

        return response()->json([
            'statistics'              => $statistics,
            'topRisks'                => $topRisks,
            'organizationalKnowledge' => $organizationalKnowledge,
            'pendingRecommendations'  => $pendingRecommendations,
            'openDecisionsCount'      => DB::table('hpbrain_decisions')
                ->where('tenant_id', $t)->whereRaw('LOWER(status) = ?', ['pending'])->count(),
            'intelligenceScore'       => [
                'score'     => round(array_sum($breakdown) / count($breakdown), 1),
                'breakdown' => (object) $breakdown,
            ],
            // Retained from the previous shape — these are real figures and
            // removing them would break anything already reading them.
            'averageConfidence' => ($c = DB::table('hpbrain_reasoning_steps')->where('tenant_id', $t)->avg('confidence_score')) !== null
                ? round((float) $c, 4) : null,
            'openCases'        => DB::table('hpbrain_cases')->where('tenant_id', $t)->whereNotIn('status', ['closed'])->count(),
            'successRate'      => $statistics['outcomes']['recommendationAccuracy'],
        ]);
    }

    public function decisionIntelligence(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);

        $decisions = DB::table('hpbrain_decisions')->where('tenant_id', $t)->get();

        $pipeline = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        $byExecutor = [];

        foreach ($decisions as $d) {
            $status = strtolower((string) $d->status);

            if (in_array($status, self::APPROVED, true)) {
                $pipeline['approved']++;
            } elseif (in_array($status, self::REJECTED, true)) {
                $pipeline['rejected']++;
            } else {
                $pipeline['pending']++;
            }

            $executor = (string) ($d->executor_type ?? 'unassigned');
            $byExecutor[$executor] = ($byExecutor[$executor] ?? 0) + 1;
        }

        // Latency is measured from the recommendation that prompted the
        // decision to the decision itself — the time the organization took to
        // make up its mind. Decisions with no recommendation behind them have
        // no such interval and are left out rather than counted as instant.
        $latency = DB::table('hpbrain_decisions as d')
            ->join('hpbrain_recommendations as r', function ($j) use ($t) {
                $j->on('r.id', '=', 'd.recommendation_id')->where('r.tenant_id', '=', $t);
            })
            ->where('d.tenant_id', $t)
            ->whereNotNull('d.created_date')->whereNotNull('r.created_date')
            ->avg(DB::raw('TIMESTAMPDIFF(SECOND, r.created_date, d.created_date)'));

        // category x executor: which kinds of work each executor type ends up
        // owning. The category comes from the recommendation, so decisions made
        // without one are grouped under 'uncategorised' rather than dropped.
        $heatmapRows = DB::table('hpbrain_decisions as d')
            ->leftJoin('hpbrain_recommendations as r', function ($j) use ($t) {
                $j->on('r.id', '=', 'd.recommendation_id')->where('r.tenant_id', '=', $t);
            })
            ->where('d.tenant_id', $t)
            ->select('r.category', 'd.executor_type', DB::raw('COUNT(*) as count'))
            ->groupBy('r.category', 'd.executor_type')->get();

        $heatmap = [];
        foreach ($heatmapRows as $row) {
            $category = (string) ($row->category ?? 'uncategorised');
            $executor = (string) ($row->executor_type ?? 'unassigned');
            $heatmap[$category] ??= [];
            $heatmap[$category][$executor] = (int) $row->count;
        }

        foreach ($heatmap as $category => $cells) {
            $heatmap[$category] = (object) $cells;
        }

        return response()->json([
            'pipeline'                   => $pipeline,
            'averageDecisionLatencyHours' => $latency === null ? null : round((float) $latency / 3600, 2),
            'decisionsByExecutorType'    => (object) $byExecutor,
            'categoryExecutorHeatmap'    => (object) $heatmap,
            // Kept from the previous shape.
            'byRootCause' => DB::table('hpbrain_hypotheses')->where('tenant_id', $t)
                ->select('root_cause_family', DB::raw('COUNT(*) as count'))
                ->groupBy('root_cause_family')->orderByDesc('count')->get(),
            'confidenceBands' => DB::table('hpbrain_reasoning_steps')->where('tenant_id', $t)
                ->select(DB::raw("CASE
                       WHEN confidence_score >= 0.7 THEN 'high'
                       WHEN confidence_score >= 0.4 THEN 'medium'
                       ELSE 'low' END as band"), DB::raw('COUNT(*) as count'))
                ->groupBy('band')->get(),
        ]);
    }

    /**
     * CSV of every decision, for the export button on the Decision Intelligence
     * screen. The button has always been there; the route it calls was never
     * registered, so it downloaded a 404 page named decisions-<tenant>.csv.
     *
     * Streamed rather than assembled in memory: this is the one analytics
     * response whose size grows without bound as decisions accumulate.
     */
    public function decisionsCsv(Request $request): StreamedResponse
    {
        $t = $this->tenantId($request);

        $rows = DB::table('hpbrain_decisions as d')
            ->leftJoin('hpbrain_recommendations as r', function ($j) use ($t) {
                $j->on('r.id', '=', 'd.recommendation_id')->where('r.tenant_id', '=', $t);
            })
            ->where('d.tenant_id', $t)
            ->select(
                'd.id', 'd.status', 'd.executor_type', 'd.decided_by', 'd.confidence',
                'd.rationale', 'd.created_date', 'd.recommendation_id',
                'r.title as recommendation_title', 'r.category as recommendation_category'
            )
            ->orderByDesc('d.created_date')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'wb');

            fputcsv($out, [
                'id', 'status', 'executor_type', 'decided_by', 'confidence',
                'rationale', 'created_date', 'recommendation_id',
                'recommendation_title', 'recommendation_category',
            ]);

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->id, $r->status, $r->executor_type, $r->decided_by, $r->confidence,
                    $r->rationale, $r->created_date, $r->recommendation_id,
                    $r->recommendation_title, $r->recommendation_category,
                ]);
            }

            fclose($out);
        }, "decisions-{$t}.csv", ['Content-Type' => 'text/csv']);
    }
}
