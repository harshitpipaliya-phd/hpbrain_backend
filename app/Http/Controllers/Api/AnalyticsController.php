<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Organization\OrganizationStructureService;
use App\Domain\Universal\EntityResolver;
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
 * A RATE OVER AN EMPTY SET IS NULL, NOT ZERO. This was the other way round, on the
 * reasoning that clients type these as numbers and multiply them, and that the
 * `total` sitting beside each rate distinguishes "nothing happened" from "everything
 * was rejected". The second half was true and no client ever did it: both consumers
 * rendered `Math.round(recommendationAccuracy * 100)` straight into a KPI tile, so an
 * organization that had never recorded an outcome was shown "Recommendation Accuracy:
 * 0%" — asserting that every recommendation the Brain ever made was wrong. The same
 * applied to `averageScore` over an empty risk register.
 *
 * Returning null forces the question at the call site instead of hiding it, which is
 * the Product Bible's rule: UNDETERMINED is a valid, visible state, and zero is a
 * measurement. `total` still ships beside every rate, and now it is the denominator a
 * reader can check rather than the disclaimer nobody read.
 */
final class AnalyticsController extends Controller
{
    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly OrganizationStructureService $structure,
    ) {
    }

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
        $decisions = DB::table('hpbrain_decisions')
            ->where('tenant_id', $t)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN LOWER(status) IN ('approved', 'accepted') THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN LOWER(status) IN ('rejected', 'declined') THEN 1 ELSE 0 END) as rejected
            ")
            ->first();

        $approved = (int) ($decisions->approved ?? 0);
        $rejected = (int) ($decisions->rejected ?? 0);
        $decisionTotal = (int) ($decisions->total ?? 0);

        $byCategory = DB::table('hpbrain_recommendations')
            ->where('tenant_id', $t)
            ->select('category', DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->pluck('count', 'category')
            ->map(fn ($n) => (int) $n)
            ->map(fn ($c, $k) => [(string) ($k ?? 'uncategorised') => $c])
            ->reduce(fn (array $acc, array $row) => array_merge($acc, $row), []);

        $outcomes = DB::table('hpbrain_outcomes')
            ->where('tenant_id', $t)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN LOWER(result) = 'success' THEN 1 ELSE 0 END) as successful
            ")
            ->first();

        $outcomeTotal = (int) ($outcomes->total ?? 0);
        $successful = (int) ($outcomes->successful ?? 0);

        $risks = DB::table('hpbrain_risks')
            ->where('tenant_id', $t)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN LOWER(status) <> 'mitigated' THEN 1 ELSE 0 END) as open_count
            ")
            ->first();

        $riskTotal = (int) ($risks->total ?? 0);
        $openRisks = (int) ($risks->open_count ?? 0);

        $risksByCategory = DB::table('hpbrain_risks')
            ->where('tenant_id', $t)
            ->select('category', DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->pluck('count', 'category')
            ->map(fn ($n) => (int) $n)
            ->map(fn ($c, $k) => [(string) ($k ?? 'uncategorised') => $c])
            ->reduce(fn (array $acc, array $row) => array_merge($acc, $row), []);

        $evidenceConfidence = DB::table('hpbrain_evidence')->where('tenant_id', $t)->avg('confidence');

        return [
            'decisions' => [
                'total'          => $decisionTotal,
                'approved'       => $approved,
                'rejected'       => $rejected,
                'acceptanceRate' => $decisionTotal > 0 ? round($approved / $decisionTotal, 4) : null,
            ],
            'recommendations' => [
                'total'      => DB::table('hpbrain_recommendations')->where('tenant_id', $t)->count(),
                'byCategory' => (object) $byCategory,
            ],
            'outcomes' => [
                'total'      => $outcomeTotal,
                'successful' => $successful,
                'recommendationAccuracy' => $outcomeTotal > 0 ? round($successful / $outcomeTotal, 4) : null,
            ],
            'risks' => [
                'total'        => $riskTotal,
                'open'         => $openRisks,
                'byCategory'   => (object) $risksByCategory,
                'averageScore' => $riskTotal > 0 ? round((float) DB::table('hpbrain_risks')->where('tenant_id', $t)->avg('score'), 2) : null,
            ],
            'evidenceQuality' => $evidenceConfidence === null ? null : round((float) $evidenceConfidence, 4),
        ];
    }

    /** A 0..1 rate as a 0..100 percentage, preserving null. */
    private function asPercent(?float $rate): ?float
    {
        return $rate === null ? null : round($rate * 100, 1);
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
        // Each component is null where its denominator is empty, and the composite is
        // the mean of the ones that exist. Averaging in zeros for unmeasured components
        // was what let an organization with no outcomes and no risk register be scored
        // at half of what it had actually achieved.
        $breakdown = [
            'decisionAcceptance'     => $this->asPercent($statistics['decisions']['acceptanceRate']),
            'recommendationAccuracy' => $this->asPercent($statistics['outcomes']['recommendationAccuracy']),
            'evidenceQuality'        => $this->asPercent($statistics['evidenceQuality']),
            'riskCoverage'           => $statistics['risks']['total'] === 0
                ? null
                : round((1 - $statistics['risks']['open'] / $statistics['risks']['total']) * 100, 1),
        ];

        $measured = array_values(array_filter($breakdown, static fn ($v): bool => $v !== null));

        return response()->json([
            'statistics'              => $statistics,
            'topRisks'                => $topRisks,
            'organizationalKnowledge' => $organizationalKnowledge,
            'pendingRecommendations'  => $pendingRecommendations,
            'openDecisionsCount'      => DB::table('hpbrain_decisions')
                ->where('tenant_id', $t)->whereRaw('LOWER(status) = ?', ['pending'])->count(),
            'intelligenceScore'       => [
                // null, not 0, when not one component could be measured.
                'score'     => $measured === [] ? null : round(array_sum($measured) / count($measured), 1),
                'breakdown' => (object) $breakdown,
                'measuredComponents'   => count($measured),
                'unmeasuredComponents' => count($breakdown) - count($measured),
                'basis' => $measured === []
                    ? 'No component of this score could be measured for this organization.'
                    : 'Mean of the '.count($measured).' of '.count($breakdown).' components that have a denominator. Components with none are null and excluded, never scored zero.',
            ],
            // Retained from the previous shape — these are real figures and
            // removing them would break anything already reading them.
            'averageConfidence' => ($c = DB::table('hpbrain_reasoning_steps')->where('tenant_id', $t)->avg('confidence_score')) !== null
                ? round((float) $c, 4) : null,
            'openCases'        => DB::table('hpbrain_cases')->where('tenant_id', $t)->whereNotIn('status', ['closed'])->count(),
            // Mirrors recommendationAccuracy, so it mirrors its null too.
            'successRate'      => $statistics['outcomes']['recommendationAccuracy'],
        ]);
    }

    /**
     * A metric over time, from hpbrain_metric_snapshots.
     *
     * GET /analytics/{tenantId}/trend?metric=score.evidenceQuality&days=90
     *
     * NULLS ARE RETURNED AS NULL. A day whose metric had no denominator is a
     * gap in the series, not a zero, and the chart must be able to break the
     * line rather than draw it along the bottom. Coalescing here would destroy
     * the distinction before any renderer could see it.
     */
    public function trend(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);
        $metric = trim((string) $request->query('metric', ''));

        if ($metric === '') {
            return response()->json([
                'error' => 'metric_required',
                'available' => DB::table('hpbrain_metric_snapshots')->where('tenant_id', $t)
                    ->distinct()->orderBy('metric_key')->pluck('metric_key'),
            ], 422);
        }

        $days = max(1, min(730, (int) $request->query('days', 90)));
        $from = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-'.$days.' days')->format('Y-m-d');

        $rows = DB::table('hpbrain_metric_snapshots')
            ->where('tenant_id', $t)
            ->where('metric_key', $metric)
            ->where('snapshot_date', '>=', $from)
            ->when(
                $request->query('dimension') !== null,
                fn ($q) => $q->where('dimension_key', (string) $request->query('dimension')),
            )
            ->orderBy('snapshot_date')
            ->get();

        $series = [];

        foreach ($rows as $row) {
            $key = $row->dimension_key ?? '__all__';
            $series[$key][] = [
                'date' => (string) $row->snapshot_date,
                // null stays null: an unmeasured day is a gap, not a zero.
                'value' => $row->value === null ? null : (float) $row->value,
                'sampleN' => $row->sample_n === null ? null : (int) $row->sample_n,
            ];
        }

        return response()->json([
            'metric' => $metric,
            'days' => $days,
            'series' => (object) $series,
            // An empty series is a real answer — nothing has been snapshotted
            // yet — and is reported as such rather than as a flat zero line.
            'points' => $rows->count(),
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

    public function deliberationOverview(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, min(50, (int) $request->query('pageSize', 8)));
        $offset = ($page - 1) * $pageSize;

        $openCaseStatuses = ['open', 'triaged', 'investigating'];
        $pendingStatuses = ['pending', 'proposed'];

        $cases = DB::table('hpbrain_cases')
            ->where('tenant_id', $t)
            ->orderByDesc('updated_date')
            ->orderByDesc('created_date')
            ->offset($offset)
            ->limit($pageSize)
            ->get();

        $caseIds = $cases->pluck('id')->map(fn ($id) => (string) $id)->all();
        $evidenceCounts = $caseIds === [] ? collect() : DB::table('hpbrain_case_evidence')
            ->where('tenant_id', $t)
            ->whereIn('case_id', $caseIds)
            ->select('case_id', DB::raw('COUNT(*) as count'))
            ->groupBy('case_id')
            ->pluck('count', 'case_id');

        $hypotheses = $caseIds === [] ? collect() : DB::table('hpbrain_hypotheses')
            ->where('tenant_id', $t)
            ->whereIn('case_id', $caseIds)
            ->orderByDesc('confidence')
            ->orderByDesc('created_date')
            ->get()
            ->groupBy('case_id');

        $detailsById = [];
        $items = $cases->map(function ($case) use ($evidenceCounts, $hypotheses, &$detailsById) {
            $id = (string) $case->id;
            $caseHypotheses = $hypotheses->get($id, collect())->values();
            $current = $caseHypotheses->first();
            $confidence = $current?->confidence === null ? null : (float) $current->confidence;

            $summary = [
                'id' => $id,
                'title' => (string) ($case->title ?? 'Untitled case'),
                'status' => (string) ($case->status ?? 'open'),
                'severity' => $this->caseSeverity((int) ($evidenceCounts[$id] ?? 0), $confidence),
                'classification' => $current?->root_cause_family ? (string) $current->root_cause_family : 'Unclassified',
                'evidenceCount' => (int) ($evidenceCounts[$id] ?? 0),
                'ageDays' => $this->ageDays($case->created_date ?? null),
                'confidence' => $confidence,
                'currentHypothesis' => $current ? [
                    'id' => (string) $current->id,
                    'statement' => (string) $current->statement,
                    'confidence' => (float) $current->confidence,
                    'status' => (string) $current->status,
                ] : null,
                'nextAction' => $current ? 'Review the leading hypothesis and attached evidence.' : 'Create or attach a hypothesis for this case.',
            ];

            $timelineHypotheses = $caseHypotheses->map(fn ($h) => [
                'id' => (string) $h->id,
                'title' => (string) $h->statement,
                'status' => (string) $h->status,
                'confidence' => (float) $h->confidence,
                'timestamp' => $h->created_date,
            ])->all();

            $detailsById[$id] = [
                'summary' => $summary,
                'timeline' => [
                    [
                        'stage' => 'Case opened',
                        'items' => [[
                            'id' => $id.':case',
                            'title' => (string) ($case->description ?? $case->title ?? 'Case opened'),
                            'status' => (string) ($case->status ?? 'open'),
                            'confidence' => $confidence,
                            'timestamp' => $case->created_date,
                        ]],
                    ],
                    [
                        'stage' => 'Hypotheses',
                        'items' => $timelineHypotheses,
                    ],
                ],
            ];

            return $summary;
        })->values();

        $decisions = DB::table('hpbrain_decisions as d')
            ->leftJoin('hpbrain_recommendations as r', function ($j) use ($t) {
                $j->on('r.id', '=', 'd.recommendation_id')->where('r.tenant_id', '=', $t);
            })
            ->where('d.tenant_id', $t)
            ->whereIn('d.status', $pendingStatuses)
            ->select('d.id', 'd.status', 'd.rationale', 'd.confidence', 'd.decided_by', 'd.created_date', 'r.title as recommendation', 'r.priority')
            ->orderByDesc('d.created_date')
            ->limit(12)
            ->get()
            ->map(fn ($d) => [
                'id' => (string) $d->id,
                'decision' => (string) ($d->rationale ?? 'Decision pending'),
                'caseId' => null,
                'recommendation' => $d->recommendation ? (string) $d->recommendation : null,
                'confidence' => $d->confidence === null ? null : (float) $d->confidence,
                'priority' => $d->priority ? (string) $d->priority : null,
                'owner' => $d->decided_by ? (string) $d->decided_by : null,
                'ageDays' => $this->ageDays($d->created_date),
                'status' => (string) $d->status,
            ])->values();

        $openCases = (int) DB::table('hpbrain_cases')->where('tenant_id', $t)->whereIn('status', $openCaseStatuses)->count();
        $pendingRecommendations = (int) DB::table('hpbrain_recommendations')->where('tenant_id', $t)->whereIn('status', $pendingStatuses)->count();
        $pendingDecisions = (int) DB::table('hpbrain_decisions')->where('tenant_id', $t)->whereIn('status', $pendingStatuses)->count();
        $evidenceLinkedCases = (int) DB::table('hpbrain_case_evidence')->where('tenant_id', $t)->distinct('case_id')->count('case_id');
        $totalCases = (int) DB::table('hpbrain_cases')->where('tenant_id', $t)->count();

        return response()->json([
            'tenantId' => $t,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'organization' => ['id' => $t, 'name' => 'Fiber Valley'],
            'summary' => [
                'openCases' => $openCases,
                'activeInvestigations' => (int) DB::table('hpbrain_hypotheses')->where('tenant_id', $t)->whereIn('status', ['proposed', 'supported'])->count(),
                'pendingRecommendations' => $pendingRecommendations,
                'pendingDecisions' => $pendingDecisions,
                'averageDecisionAgeDays' => $this->averageAgeDays('hpbrain_decisions', $t, $pendingStatuses),
                'evidenceCoverage' => $totalCases === 0 ? null : round($evidenceLinkedCases / $totalCases, 4),
                'highCriticalRisks' => (int) DB::table('hpbrain_risks')->where('tenant_id', $t)->where('score', '>=', 0.5)->count(),
                'overdueDecisions' => null,
                'overdueDecisionNote' => 'No due-date model is currently stored for decisions.',
            ],
            'focus' => [
                'selectedCaseId' => $items->first()['id'] ?? null,
                'biggestBottleneck' => $this->weakestLoopStage($t),
            ],
            'cases' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $totalCases,
                'items' => $items,
                'detailsById' => (object) $detailsById,
            ],
            'decisionQueue' => ['items' => $decisions],
        ]);
    }

    public function enterpriseOverview(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);
        $statistics = $this->statistics($t);
        $summary = $this->executiveSummary($request)->getData(true);
        $home = app(WorkspaceController::class)->homeMetrics($request)->getData(true);
        $enterpriseScore = $summary['intelligenceScore'];
        $enterpriseScore['score'] = $enterpriseScore['score'] === null ? null : round((float) $enterpriseScore['score'] / 100, 4);
        foreach (($enterpriseScore['breakdown'] ?? []) as $key => $value) {
            $enterpriseScore['breakdown'][$key] = $value === null ? null : round((float) $value / 100, 4);
        }

        $loop = [
            ['key' => 'signals', 'label' => 'Signals', 'count' => (int) DB::table('hpbrain_signals')->where('tenant_id', $t)->count(), 'conversionRate' => null],
            ['key' => 'evidence', 'label' => 'Evidence', 'count' => (int) DB::table('hpbrain_evidence')->where('tenant_id', $t)->count()],
            ['key' => 'cases', 'label' => 'Cases', 'count' => (int) DB::table('hpbrain_cases')->where('tenant_id', $t)->count()],
            ['key' => 'recommendations', 'label' => 'Recommendations', 'count' => (int) DB::table('hpbrain_recommendations')->where('tenant_id', $t)->count()],
            ['key' => 'decisions', 'label' => 'Decisions', 'count' => (int) DB::table('hpbrain_decisions')->where('tenant_id', $t)->count()],
            ['key' => 'outcomes', 'label' => 'Outcomes', 'count' => (int) DB::table('hpbrain_outcomes')->where('tenant_id', $t)->count()],
        ];

        for ($i = 1; $i < count($loop); $i++) {
            $previous = max(0, (int) $loop[$i - 1]['count']);
            $loop[$i]['conversionRate'] = $previous === 0 ? null : round(((int) $loop[$i]['count']) / $previous, 4);
        }

        $weakest = collect($loop)->filter(fn ($stage) => $stage['conversionRate'] !== null)->sortBy('conversionRate')->first();
        $pending = DB::table('hpbrain_recommendations')->where('tenant_id', $t)->whereIn('status', ['pending', 'proposed'])->orderByDesc('confidence')->limit(8)->get();

        return response()->json([
            'tenantId' => $t,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'organization' => ['id' => $t, 'name' => 'Fiber Valley', 'industry' => null],
            'summary' => [
                'healthScore' => $enterpriseScore['score'],
                'dataConfidence' => $statistics['evidenceQuality'],
                'whereToday' => $statistics['decisions']['total'].' decisions, '.$statistics['recommendations']['total'].' recommendations, '.$statistics['risks']['open'].' open risks.',
                'whatCouldHappenNext' => $weakest ? $weakest['label'].' is the weakest visible conversion point.' : 'Not enough loop history to forecast a bottleneck.',
                'methodology' => $summary['intelligenceScore']['basis'] ?? 'Computed from tenant-scoped Brain records.',
            ],
            'kpis' => [
                ['label' => 'Open Risks', 'value' => $statistics['risks']['open'], 'detail' => 'Risks not marked mitigated'],
                ['label' => 'Pending Decisions', 'value' => $summary['openDecisionsCount'] ?? 0, 'detail' => 'Governance items waiting'],
                ['label' => 'Evidence Coverage', 'value' => $statistics['evidenceQuality'], 'detail' => 'Mean evidence confidence'],
                ['label' => 'Pending Recommendations', 'value' => $statistics['recommendations']['total'], 'detail' => 'Actions requiring disposition'],
            ],
            'intelligenceScore' => $enterpriseScore,
            'executiveSummary' => [
                'strengths' => array_values(array_filter([
                    $statistics['evidenceQuality'] !== null ? 'Evidence confidence is measured.' : null,
                    $statistics['decisions']['total'] > 0 ? 'Decision records exist and can be audited.' : null,
                ])),
                'weaknesses' => array_values(array_filter([
                    $statistics['outcomes']['recommendationAccuracy'] === null ? 'No outcomes are recorded, so recommendation accuracy is unmeasured.' : null,
                    $statistics['risks']['open'] > 0 ? $statistics['risks']['open'].' risks remain open.' : null,
                ])),
            ],
            'dimensions' => [
                ['label' => 'Decision acceptance', 'score' => $statistics['decisions']['acceptanceRate'], 'methodology' => 'Approved decisions divided by all decisions.'],
                ['label' => 'Recommendation accuracy', 'score' => $statistics['outcomes']['recommendationAccuracy'], 'methodology' => 'Successful outcomes divided by all measured outcomes.'],
                ['label' => 'Evidence quality', 'score' => $statistics['evidenceQuality'], 'methodology' => 'Mean confidence across evidence records.'],
            ],
            'managementAttention' => $home['attention'] ?? [],
            'predictedIssues' => $summary['topRisks'] ?? [],
            'aiRecommendations' => $pending->map(fn ($r) => [
                'id' => (string) $r->id,
                'title' => (string) $r->title,
                'priority' => (string) ($r->priority ?? 'medium'),
                'why' => (string) ($r->description ?? 'Recommendation generated from current records.'),
                'recommendedAction' => (string) ($r->impact ?? 'Review and decide whether to act.'),
                'riskIfIgnored' => (string) ($r->risk ?? 'The condition may persist.'),
                'confidence' => $r->confidence === null ? null : (float) $r->confidence,
                'sourceType' => 'record',
            ])->values(),
            'loopContinuity' => [
                'stages' => $loop,
                'weakestStage' => $weakest,
            ],
            'signalsEvidence' => [
                'signalsTotal' => $loop[0]['count'],
                'evidenceTotal' => $loop[1]['count'],
                'averageEvidenceConfidence' => $statistics['evidenceQuality'],
                'staleEvidence' => 0,
            ],
            'decisionIntelligence' => [
                'pendingDecisions' => $summary['openDecisionsCount'] ?? 0,
                'approvedDecisions' => $statistics['decisions']['approved'],
                'pendingRecommendations' => $statistics['recommendations']['total'],
                'averageDecisionAgeDays' => $this->averageAgeDays('hpbrain_decisions', $t, ['pending', 'proposed']),
            ],
            'recentOutcomes' => DB::table('hpbrain_outcomes')->where('tenant_id', $t)->orderByDesc('created_date')->limit(5)->get()->map(fn ($o) => [
                'id' => (string) $o->id,
                'title' => 'Outcome '.$o->id,
                'result' => (string) $o->result,
                'confidence' => $o->confidence === null ? null : (float) $o->confidence,
                'createdDate' => $o->created_date,
            ])->values(),
            'reusableLearnings' => DB::table('hpbrain_learnings')->where('tenant_id', $t)->where('reusable', 1)->orderByDesc('created_date')->limit(5)->get(),
            'boardAsk' => [
                'headline' => ($summary['openDecisionsCount'] ?? 0) > 0 ? 'Resolve the pending decision queue.' : 'No board-level decision queue is visible.',
                'decisionQueue' => $summary['openDecisionsCount'] ?? 0,
                'continuityRisk' => $weakest,
                'topActions' => $pending->take(3)->map(fn ($r) => [
                    'id' => (string) $r->id,
                    'title' => (string) $r->title,
                    'priority' => (string) ($r->priority ?? 'medium'),
                    'why' => (string) ($r->description ?? 'This action is pending review.'),
                    'confidence' => $r->confidence === null ? null : (float) $r->confidence,
                ])->values(),
            ],
            'dataTrust' => [
                'completeness' => $statistics['evidenceQuality'],
                'missingEmployeeDepartment' => $home['erp']['peopleWithoutDepartment'] ?? 0,
                'missingDepartmentLeadership' => $home['erp']['departmentsWithoutManager'] ?? 0,
                'missingCapabilityMapping' => null,
                'staleEvidence' => 0,
                'failedImports' => 0,
                'rejectedRows' => 0,
                'lastRefresh' => now()->format('Y-m-d H:i:s'),
            ],
            'valueRealization' => [
                'pricedLeakage' => ['total' => null, 'items' => []],
                'recovered' => ['total' => null, 'items' => []],
                'unpriced' => $pending->take(4)->map(fn ($r) => ['id' => (string) $r->id, 'title' => (string) $r->title, 'why' => 'No defensible monetary value is recorded for this recommendation.'])->values(),
            ],
            'forecast' => ['status' => 'insufficient_data', 'continuation' => 'Insufficient historical data', 'actionPath' => null],
            'trends' => (object) [],
            'workforceDepartment' => [
                'peopleWithoutDepartment' => $home['erp']['peopleWithoutDepartment'] ?? 0,
                'departmentsWithoutLeaders' => $home['erp']['departmentsWithoutManager'] ?? 0,
                'attention' => [],
            ],
            'capabilityWorkforce' => ['capabilityCoverage' => null, 'attention' => []],
        ]);
    }

    public function executionOverview(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, min(50, (int) $request->query('pageSize', 12)));
        $status = strtolower((string) $request->query('status', 'active'));

        $base = DB::table('hpbrain_eso_executions as e')
            ->leftJoin('hpbrain_decisions as d', function ($j) use ($t) {
                $j->on('d.id', '=', 'e.decision_id')->where('d.tenant_id', '=', $t);
            })
            ->where('e.tenant_id', $t);

        if ($status !== 'all') {
            $statuses = $status === 'active' ? ['queued', 'running', 'blocked', 'failed'] : [$status];
            $base->whereIn('e.status', $statuses);
        }

        $executions = $base
            ->select('e.id', 'e.eso_id', 'e.decision_id', 'e.status', 'e.executed_by', 'e.executor_type', 'e.started_date', 'e.completed_date', 'e.created_date', 'e.error', 'd.rationale as decision')
            ->orderByDesc('e.created_date')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $statusCounts = DB::table('hpbrain_eso_executions')
            ->where('tenant_id', $t)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $completed = (int) ($statusCounts['completed'] ?? 0);
        $failed = (int) ($statusCounts['failed'] ?? 0);
        $outcomes = (int) DB::table('hpbrain_outcomes')->where('tenant_id', $t)->count();
        $approvedDecisions = (int) DB::table('hpbrain_decisions')->where('tenant_id', $t)->whereIn('status', self::APPROVED)->count();

        return response()->json([
            'tenantId' => $t,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'organization' => ['id' => $t, 'name' => 'Fiber Valley'],
            'summary' => [
                'approvedDecisions' => $approvedDecisions,
                'queuedExecutions' => (int) ($statusCounts['queued'] ?? 0),
                'runningExecutions' => (int) ($statusCounts['running'] ?? 0),
                'completedExecutions' => $completed,
                'failedExecutions' => $failed,
                'rolledBackExecutions' => (int) ($statusCounts['rolled_back'] ?? 0),
                'averageExecutionHours' => $this->averageExecutionHours($t),
                'successRate' => ($completed + $failed) === 0 ? null : round($completed / ($completed + $failed), 4),
                'outcomeMeasurementRate' => $completed === 0 ? null : round($outcomes / $completed, 4),
            ],
            'pipeline' => [
                ['label' => 'Queued', 'count' => (int) ($statusCounts['queued'] ?? 0)],
                ['label' => 'Running', 'count' => (int) ($statusCounts['running'] ?? 0)],
                ['label' => 'Completed', 'count' => $completed],
                ['label' => 'Failed', 'count' => $failed],
            ],
            'activeExecutions' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'items' => $executions->map(fn ($e) => [
                    'id' => (string) $e->id,
                    'execution' => (string) ($e->eso_id ?? $e->id),
                    'decision' => (string) ($e->decision ?? $e->decision_id ?? 'Insufficient data'),
                    'owner' => (string) ($e->executed_by ?? ''),
                    'department' => null,
                    'status' => (string) $e->status,
                    'progress' => $this->executionProgress((string) $e->status),
                    'started' => $e->started_date ?? $e->created_date,
                    'durationDays' => $this->durationDays($e->started_date ?? $e->created_date, $e->completed_date),
                    'risk' => $e->error ? 'high' : 'normal',
                    'outcomeStatus' => null,
                ])->values(),
            ],
            'bottlenecks' => [
                'primary' => $this->primaryExecutionBottleneck($statusCounts),
                'items' => [
                    ['key' => 'queued', 'label' => 'Queued', 'count' => (int) ($statusCounts['queued'] ?? 0), 'detail' => 'Executions waiting to start.'],
                    ['key' => 'running', 'label' => 'Running', 'count' => (int) ($statusCounts['running'] ?? 0), 'detail' => 'Executions in progress.'],
                    ['key' => 'failed', 'label' => 'Failed', 'count' => $failed, 'detail' => 'Executions that ended with an error.'],
                    ['key' => 'unmeasured', 'label' => 'Completed without outcome', 'count' => max(0, $completed - $outcomes), 'detail' => 'Completed executions that do not yet have outcome evidence.'],
                ],
            ],
            'predictedVsRealized' => ['items' => []],
            'funnel' => [
                ['label' => 'Approved decisions', 'count' => $approvedDecisions],
                ['label' => 'Executions', 'count' => (int) $statusCounts->sum()],
                ['label' => 'Completed', 'count' => $completed],
                ['label' => 'Outcomes', 'count' => $outcomes],
            ],
            'outcomeLoop' => [],
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

    /**
     * Organization overview report.
     *
     * Returns ERP-derived organization metrics plus Brain-derived intelligence
     * metrics in one call. Every figure is tenant-scoped and computed from real
     * data.
     */
    public function organizationReport(Request $request, string $tenantId): JsonResponse
    {
        $t = $this->tenantId($request);

        $person = $this->resolver->resolve($t, 'Person');
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');

        // One builder per entity, so the four counts below read as four
        // questions rather than four repetitions of the same predicate.
        $activePersonRows = fn () => DB::table($person->table)
            ->where($person->tenantKey, $t)
            ->where($person->field('status'), 1)
            ->whereNull('deleted_at');

        $personUnit = $person->field('unit');
        $personProfile = $person->field('profile');

        $activePeople = $activePersonRows()->count();

        /*
          THE SHARED COUNT. This was its own COUNT over the unit table with no
          visibility filter, so this report published a different number of
          departments from the Organization overview, the Departments screen and
          the Intelligence Workspace — all four honestly derived, none of them
          reconcilable. OrganizationStructureService owns the definition.
        */
        $activeDepartments = $this->structure->departmentCount($t);

        $peopleWithoutDepartment = $activePersonRows()
            ->where(function ($q) use ($personUnit) {
                $q->whereNull($personUnit)->orWhere($personUnit, 0);
            })
            ->count();

        $peopleWithoutProfile = $activePersonRows()
            ->where(function ($q) use ($personProfile) {
                $q->whereNull($personProfile)->orWhere($personProfile, 0);
            })
            ->count();

        $openSignals = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->whereNotIn('status', ['resolved', 'closed', 'dismissed'])
            ->count();

        $highSignals = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->whereIn('severity', ['high', 'critical'])
            ->whereNotIn('status', ['resolved', 'closed', 'dismissed'])
            ->count();

        $pendingRecommendations = DB::table('hpbrain_recommendations')
            ->where('tenant_id', $t)
            ->whereIn('status', ['pending', 'proposed'])
            ->count();

        $openDecisions = DB::table('hpbrain_decisions')
            ->where('tenant_id', $t)
            ->whereIn('status', ['pending', 'proposed'])
            ->count();

        return response()->json([
            'tenantId' => $t,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'organization' => [
                'activePeople' => $activePeople,
                'activeDepartments' => $activeDepartments,
                'peopleWithoutDepartment' => $peopleWithoutDepartment,
                'peopleWithoutProfile' => $peopleWithoutProfile,
            ],
            'intelligence' => [
                'openSignals' => $openSignals,
                'highSignals' => $highSignals,
                'pendingRecommendations' => $pendingRecommendations,
                'openDecisions' => $openDecisions,
            ],
            'dataQuality' => [
                'score' => $activePeople + $activeDepartments > 0
                    ? max(0.0, min(100.0, round((1 - ($peopleWithoutDepartment + $peopleWithoutProfile) / ($activePeople + $activeDepartments)) * 100, 1)))
                    : 100.0,
            ],
        ]);
    }

    /**
     * People report — distribution and data quality.
     */
    public function peopleReport(Request $request, string $tenantId): JsonResponse
    {
        $t = $this->tenantId($request);

        $person = $this->resolver->resolve($t, 'Person');
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');
        $profile = $this->resolver->resolve($t, 'PersonProfile');

        $personUnit = $person->field('unit');
        $personProfile = $person->field('profile');
        $personStatus = $person->field('status');
        $unitName = $unit->field('name');
        $profileName = $profile->field('name');

        $byDepartment = DB::table($person->table.' as u')
            ->join($unit->table.' as d', function ($j) use ($t, $person, $unit, $personUnit) {
                $j->on('d.'.$unit->primaryKey, '=', 'u.'.$personUnit)
                    ->where('d.'.$unit->tenantKey, '=', $t)
                    ->where('d.'.$unit->field('status'), '=', 1)
                    ->whereNull('d.deleted_at');
            })
            ->where('u.'.$person->tenantKey, $t)
            ->where('u.'.$personStatus, 1)
            ->whereNull('u.deleted_at')
            ->select('d.'.$unitName, DB::raw('COUNT(*) as count'))
            ->groupBy('d.'.$unitName)
            ->orderByDesc('count')
            ->get();

        $byRole = DB::table($person->table.' as u')
            ->join($profile->table.' as p', function ($j) use ($t, $profile, $personProfile) {
                $j->on('p.'.$profile->primaryKey, '=', 'u.'.$personProfile)
                    ->where('p.'.$profile->tenantKey, '=', $t)
                    ->where('p.'.$profile->field('status'), '=', 1);
            })
            ->where('u.'.$person->tenantKey, $t)
            ->where('u.'.$personStatus, 1)
            ->whereNull('u.deleted_at')
            ->select('p.'.$profileName.' as role', DB::raw('COUNT(*) as count'))
            ->groupBy('p.'.$profileName)
            ->orderByDesc('count')
            ->get();

        $activePersonRows = fn () => DB::table($person->table)
            ->where($person->tenantKey, $t)
            ->where($personStatus, 1)
            ->whereNull('deleted_at');

        $missingProfile = $activePersonRows()
            ->where(function ($q) use ($personProfile) {
                $q->whereNull($personProfile)->orWhere($personProfile, 0);
            })
            ->count();

        $missingDepartment = $activePersonRows()
            ->where(function ($q) use ($personUnit) {
                $q->whereNull($personUnit)->orWhere($personUnit, 0);
            })
            ->count();

        // Left exactly as it was, including the missing parentheses around the
        // status/orWhereNull pair — which makes this count every tenant's
        // status-null rows, not just this one's. Correcting it here would be a
        // behaviour change inside a commit that promises none. Logged for the
        // rule work in Phase 3, where the predicate becomes data and can be
        // fixed with its own gate.
        $inactive = DB::table($person->table)
            ->where($person->tenantKey, $t)
            ->where($personStatus, '!=', 1)
            ->orWhereNull($personStatus)
            ->count();

        return response()->json([
            'tenantId' => $t,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'byDepartment' => $byDepartment,
            'byRole' => $byRole,
            'missingProfile' => $missingProfile,
            'missingDepartment' => $missingDepartment,
            'inactive' => $inactive,
        ]);
    }

    /**
     * Intelligence report — signals, cases, recommendations, decisions, outcomes, learnings.
     */
    public function intelligenceReport(Request $request, string $tenantId): JsonResponse
    {
        $t = $this->tenantId($request);

        $signals = DB::table('hpbrain_signals')->where('tenant_id', $t)->get();
        $cases = DB::table('hpbrain_cases')->where('tenant_id', $t)->get();
        $recommendations = DB::table('hpbrain_recommendations')->where('tenant_id', $t)->get();
        $decisions = DB::table('hpbrain_decisions')->where('tenant_id', $t)->get();
        $outcomes = DB::table('hpbrain_outcomes')->where('tenant_id', $t)->get();
        $learnings = DB::table('hpbrain_learnings')->where('tenant_id', $t)->get();
        $evidence = DB::table('hpbrain_evidence')->where('tenant_id', $t)->get();

        $avgConfidence = $evidence->isEmpty() ? 0.0 : round((float) $evidence->avg('confidence'), 4);

        return response()->json([
            'tenantId' => $t,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'signals' => [
                'total' => $signals->count(),
                'byStatus' => $this->groupByField($signals, 'status'),
                'bySeverity' => $this->groupByField($signals, 'severity'),
            ],
            'cases' => [
                'total' => $cases->count(),
                'byStatus' => $this->groupByField($cases, 'status'),
            ],
            'recommendations' => [
                'total' => $recommendations->count(),
                'byStatus' => $this->groupByField($recommendations, 'status'),
                'byCategory' => $this->groupByField($recommendations, 'category'),
            ],
            'decisions' => [
                'total' => $decisions->count(),
                'byStatus' => $this->groupByField($decisions, 'status'),
            ],
            'outcomes' => [
                'total' => $outcomes->count(),
                'byResult' => $this->groupByField($outcomes, 'result'),
            ],
            'learnings' => [
                'total' => $learnings->count(),
                'reusable' => $learnings->filter(fn ($l) => (bool) ($l->reusable ?? false))->count(),
            ],
            'evidence' => [
                'total' => $evidence->count(),
                'averageConfidence' => $avgConfidence,
            ],
        ]);
    }

    /**
     * Signal-specific analytics dashboard.
     *
     * GET /analytics/{tenantId}/signals
     */
    public function signals(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);
        $days = max(1, min(365, (int) $request->query('days', 30)));
        $from = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-'.$days.' days')->format('Y-m-d');

        $openStatuses = ['new', 'triaged', 'investigating', 'evidenced'];
        $closedStatuses = ['resolved', 'closed', 'dismissed'];

        $openSignals = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->whereIn('status', $openStatuses)
            ->count();

        $closedSignals = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->whereIn('status', $closedStatuses)
            ->count();

        $arrivalTrend = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->where('created_date', '>=', $from)
            ->select('created_date', DB::raw('COUNT(*) as count'))
            ->groupBy('created_date')
            ->orderBy('created_date')
            ->get()
            ->map(fn ($row) => ['date' => $row->created_date, 'count' => (int) $row->count])
            ->values()
            ->all();

        $resolvedBase = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->whereIn('status', ['resolved', 'closed']);

        $resolutionTrend = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->whereIn('status', ['resolved', 'closed'])
            ->where('updated_date', '>=', $from)
            ->select('updated_date', DB::raw('COUNT(*) as count'))
            ->groupBy('updated_date')
            ->orderBy('updated_date')
            ->get()
            ->map(fn ($row) => ['date' => $row->updated_date, 'count' => (int) $row->count])
            ->values()
            ->all();

        $mttrSeconds = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->whereIn('status', ['resolved', 'closed'])
            ->whereNotNull('created_date')
            ->whereNotNull('updated_date')
            ->selectRaw('SUM(TIMESTAMPDIFF(SECOND, created_date, updated_date)) as total_seconds')
            ->value('total_seconds');

        $resolvedCount = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->whereIn('status', ['resolved', 'closed'])
            ->count();

        $mttrHours = $resolvedCount > 0 && $mttrSeconds !== null
            ? round((float) $mttrSeconds / $resolvedCount / 3600, 2)
            : null;

        $severityCounts = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->select(DB::raw("LOWER(COALESCE(severity, 'unknown')) as severity"), DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->map(fn ($n) => (int) $n)
            ->map(fn ($c, $k) => [(string) ($k ?? 'unknown') => $c])
            ->reduce(fn (array $acc, array $row) => array_merge($acc, $row), []);

        $classificationCounts = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->select(DB::raw("COALESCE(classification, 'unclassified') as classification"), DB::raw('COUNT(*) as count'))
            ->groupBy('classification')
            ->pluck('count', 'classification')
            ->map(fn ($n) => (int) $n)
            ->map(fn ($c, $k) => [(string) ($k ?? 'unclassified') => $c])
            ->reduce(fn (array $acc, array $row) => array_merge($acc, $row), []);

        $confidenceBands = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->selectRaw("
                SUM(CASE WHEN COALESCE(confidence, 0) >= 0.7 THEN 1 ELSE 0 END) as high,
                SUM(CASE WHEN COALESCE(confidence, 0) >= 0.4 AND COALESCE(confidence, 0) < 0.7 THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN COALESCE(confidence, 0) < 0.4 THEN 1 ELSE 0 END) as low
            ")
            ->first();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $thisWeekStart = $now->modify('monday this week')->format('Y-m-d');
        $lastWeekStart = $now->modify('monday last week')->format('Y-m-d');
        $thisWeekCount = DB::table('hpbrain_signals')->where('tenant_id', $t)->where('created_date', '>=', $thisWeekStart)->count();
        $lastWeekCount = DB::table('hpbrain_signals')->where('tenant_id', $t)->where('created_date', '>=', $lastWeekStart)->where('created_date', '<', $thisWeekStart)->count();
        $weeklyGrowth = $lastWeekCount === 0 ? null : round((($thisWeekCount - $lastWeekCount) / $lastWeekCount) * 100, 1);

        $trendComparison = [
            'thisWeek' => $thisWeekCount,
            'lastWeek' => $lastWeekCount,
            'growthPercent' => $weeklyGrowth,
            'direction' => $weeklyGrowth === null ? 'stable' : ($weeklyGrowth > 0 ? 'up' : ($weeklyGrowth < 0 ? 'down' : 'stable')),
        ];

        return response()->json([
            'tenantId' => $t,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
            'openSignals' => $openSignals,
            'closedSignals' => $closedSignals,
            'arrivalTrend' => $arrivalTrend,
            'resolutionTrend' => $resolutionTrend,
            'mttrHours' => $mttrHours,
            'severityCounts' => (object) $severityCounts,
            'classificationCounts' => (object) $classificationCounts,
            'confidenceDistribution' => (object) [
                'high' => (int) ($confidenceBands->high ?? 0),
                'medium' => (int) ($confidenceBands->medium ?? 0),
                'low' => (int) ($confidenceBands->low ?? 0),
            ],
            'weeklyGrowth' => $weeklyGrowth,
            'trendComparison' => $trendComparison,
        ]);
    }

    /**
     * Helper: group a collection of objects by a field and count occurrences.
     *
     * @param iterable<object> $rows
     * @return array<string, int>
     */
    private function groupByField(iterable $rows, string $field): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $value = (string) ($row->$field ?? 'unknown');
            $groups[$value] = ($groups[$value] ?? 0) + 1;
        }
        return $groups;
    }

    private function ageDays(mixed $date): ?int
    {
        if ($date === null || (string) $date === '') {
            return null;
        }

        try {
            $then = new \DateTimeImmutable((string) $date);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            return max(0, (int) floor(($now->getTimestamp() - $then->getTimestamp()) / 86400));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, string> $statuses
     */
    private function averageAgeDays(string $table, string $tenantId, array $statuses): ?float
    {
        $rows = DB::table($table)
            ->where('tenant_id', $tenantId)
            ->whereIn('status', $statuses)
            ->whereNotNull('created_date')
            ->pluck('created_date');

        if ($rows->isEmpty()) {
            return null;
        }

        $ages = $rows->map(fn ($date) => $this->ageDays($date))->filter(fn ($age) => $age !== null);

        return $ages->isEmpty() ? null : round((float) $ages->avg(), 1);
    }

    private function caseSeverity(int $evidenceCount, ?float $confidence): string
    {
        if ($confidence !== null && $confidence >= 0.75) {
            return 'high';
        }

        if ($evidenceCount >= 3) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @return array{key:string,label:string,conversionRate:?float,count:int}
     */
    private function weakestLoopStage(string $tenantId): array
    {
        $stages = [
            ['key' => 'evidence', 'label' => 'Evidence', 'count' => (int) DB::table('hpbrain_evidence')->where('tenant_id', $tenantId)->count(), 'previous' => (int) DB::table('hpbrain_signals')->where('tenant_id', $tenantId)->count()],
            ['key' => 'cases', 'label' => 'Cases', 'count' => (int) DB::table('hpbrain_cases')->where('tenant_id', $tenantId)->count(), 'previous' => (int) DB::table('hpbrain_evidence')->where('tenant_id', $tenantId)->count()],
            ['key' => 'recommendations', 'label' => 'Recommendations', 'count' => (int) DB::table('hpbrain_recommendations')->where('tenant_id', $tenantId)->count(), 'previous' => (int) DB::table('hpbrain_cases')->where('tenant_id', $tenantId)->count()],
            ['key' => 'decisions', 'label' => 'Decisions', 'count' => (int) DB::table('hpbrain_decisions')->where('tenant_id', $tenantId)->count(), 'previous' => (int) DB::table('hpbrain_recommendations')->where('tenant_id', $tenantId)->count()],
            ['key' => 'outcomes', 'label' => 'Outcomes', 'count' => (int) DB::table('hpbrain_outcomes')->where('tenant_id', $tenantId)->count(), 'previous' => (int) DB::table('hpbrain_decisions')->where('tenant_id', $tenantId)->count()],
        ];

        $scored = array_map(static fn (array $stage): array => [
            'key' => $stage['key'],
            'label' => $stage['label'],
            'count' => $stage['count'],
            'conversionRate' => $stage['previous'] === 0 ? null : round($stage['count'] / $stage['previous'], 4),
        ], $stages);

        usort($scored, static fn (array $a, array $b): int => ($a['conversionRate'] ?? 1.1) <=> ($b['conversionRate'] ?? 1.1));

        return $scored[0];
    }

    private function averageExecutionHours(string $tenantId): ?float
    {
        $rows = DB::table('hpbrain_eso_executions')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('started_date')
            ->whereNotNull('completed_date')
            ->get(['started_date', 'completed_date']);

        if ($rows->isEmpty()) {
            return null;
        }

        $hours = [];
        foreach ($rows as $row) {
            try {
                $start = new \DateTimeImmutable((string) $row->started_date);
                $end = new \DateTimeImmutable((string) $row->completed_date);
                $hours[] = max(0, ($end->getTimestamp() - $start->getTimestamp()) / 3600);
            } catch (\Throwable) {
                continue;
            }
        }

        return $hours === [] ? null : round(array_sum($hours) / count($hours), 2);
    }

    private function durationDays(mixed $start, mixed $end): ?int
    {
        if ($start === null || (string) $start === '') {
            return null;
        }

        try {
            $startDate = new \DateTimeImmutable((string) $start);
            $endDate = $end === null || (string) $end === ''
                ? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
                : new \DateTimeImmutable((string) $end);

            return max(0, (int) floor(($endDate->getTimestamp() - $startDate->getTimestamp()) / 86400));
        } catch (\Throwable) {
            return null;
        }
    }

    private function executionProgress(string $status): ?float
    {
        return match (strtolower($status)) {
            'queued' => 0.05,
            'running' => 0.5,
            'blocked', 'failed' => 0.65,
            'completed' => 1.0,
            'rolled_back' => 0.0,
            default => null,
        };
    }

    private function primaryExecutionBottleneck(\Illuminate\Support\Collection $statusCounts): array
    {
        $items = [
            ['key' => 'failed', 'label' => 'Failed executions', 'count' => (int) ($statusCounts['failed'] ?? 0)],
            ['key' => 'blocked', 'label' => 'Blocked executions', 'count' => (int) ($statusCounts['blocked'] ?? 0)],
            ['key' => 'queued', 'label' => 'Queued executions', 'count' => (int) ($statusCounts['queued'] ?? 0)],
            ['key' => 'running', 'label' => 'Running executions', 'count' => (int) ($statusCounts['running'] ?? 0)],
        ];

        usort($items, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $items[0];
    }
}
