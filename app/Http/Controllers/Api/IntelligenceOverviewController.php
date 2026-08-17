<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Cases\RecommendationCaseContext;
use App\Domain\Universal\EntityResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class IntelligenceOverviewController extends Controller
{
    /**
     * Request-scoped memo for resolveCaseIdThroughCitation(): recommendation id
     * (tenant-qualified) => case id, or '' for one that reaches no single case.
     *
     * @var array<string, string>
     */
    private array $caseIdByRecommendation = [];

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly RecommendationCaseContext $recommendationCaseContext,
    ) {
    }

    public function deliberationOverview(Request $request): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(5, min(20, (int) $request->query('pageSize', 8)));
        $offset = ($page - 1) * $pageSize;

        $organization = $this->organizationSummary($tenant);
        $allCases = collect(DB::table('hpbrain_cases')->where('tenant_id', $tenant)->orderByDesc('updated_date')->orderByDesc('created_date')->get());
        $caseIds = $allCases->slice($offset, $pageSize)->pluck('id')->map(fn ($id) => (string) $id)->values()->all();

        $signalRows = DB::table('hpbrain_signals')->where('tenant_id', $tenant)->get()->keyBy('id');
        $evidenceCounts = DB::table('hpbrain_case_evidence')
            ->where('tenant_id', $tenant)
            ->select('case_id', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('case_id')
            ->pluck('aggregate_count', 'case_id');
        $hypotheses = collect(DB::table('hpbrain_hypotheses')->where('tenant_id', $tenant)->orderByDesc('created_date')->get());
        $reasoningSteps = collect(DB::table('hpbrain_reasoning_steps')->where('tenant_id', $tenant)->orderBy('step_order')->orderBy('created_date')->get());
        $recommendations = collect(DB::table('hpbrain_recommendations as r')
            ->leftJoin('hpbrain_reasoning_steps as rs', function ($join) use ($tenant) {
                $join->on('rs.id', '=', 'r.reasoning_step_id')->where('rs.tenant_id', '=', $tenant);
            })
            ->where('r.tenant_id', $tenant)
            ->orderByDesc('r.updated_date')
            ->orderByDesc('r.created_date')
            ->get([
                'r.*',
                'rs.case_id as linked_case_id',
                'rs.description as reasoning_description',
            ]));
        $decisions = collect(DB::table('hpbrain_decisions as d')
            ->leftJoin('hpbrain_recommendations as r', function ($join) use ($tenant) {
                $join->on('r.id', '=', 'd.recommendation_id')->where('r.tenant_id', '=', $tenant);
            })
            ->leftJoin('hpbrain_reasoning_steps as rs', function ($join) use ($tenant) {
                $join->on('rs.id', '=', 'r.reasoning_step_id')->where('rs.tenant_id', '=', $tenant);
            })
            ->where('d.tenant_id', $tenant)
            ->orderByDesc(DB::raw('COALESCE(d.approved_date, d.created_date)'))
            ->get([
                'd.*',
                'r.title as recommendation_title',
                'r.priority as recommendation_priority',
                'r.confidence as recommendation_confidence',
                'rs.case_id as linked_case_id',
            ]));
        $risks = collect(DB::table('hpbrain_risks')->where('tenant_id', $tenant)->get());
        $evidenceRows = collect($caseIds === []
            ? []
            : DB::table('hpbrain_case_evidence as ce')
                ->join('hpbrain_evidence as e', function ($join) use ($tenant) {
                    $join->on('e.id', '=', 'ce.evidence_id')->where('e.tenant_id', '=', $tenant);
                })
                ->where('ce.tenant_id', $tenant)
                ->whereIn('ce.case_id', $caseIds)
                ->orderByDesc('e.created_date')
                ->get([
                    'ce.case_id',
                    'e.id',
                    'e.source',
                    'e.confidence',
                    'e.status',
                    'e.content',
                    'e.created_date',
                ]));

        $recommendationsByCase = $recommendations->groupBy(fn ($row) => $this->caseIdForRecommendation($tenant, $row));
        $decisionsByCase = $decisions->groupBy(fn ($row) => $this->caseIdForDecision($tenant, $row));
        $hypothesesByCase = $hypotheses->groupBy(fn ($row) => (string) $row->case_id);
        $reasoningByCase = $reasoningSteps->groupBy(fn ($row) => (string) ($row->case_id ?? ''));
        $evidenceByCase = $evidenceRows->groupBy(fn ($row) => (string) $row->case_id);

        $openCases = $allCases->filter(fn ($row) => !in_array(strtolower((string) $row->status), ['resolved', 'closed'], true));
        $activeInvestigations = $openCases->filter(fn ($row) => in_array(strtolower((string) $row->status), ['open', 'investigating', 'triaged'], true));
        $pendingRecommendations = $recommendations->filter(fn ($row) => in_array(strtolower((string) $row->status), ['pending', 'proposed', 'under_review'], true));
        $pendingDecisions = $decisions->filter(fn ($row) => in_array(strtolower((string) $row->status), ['pending', 'proposed'], true));
        $decisionAgeDays = $this->averageAgeDays($pendingDecisions->pluck('created_date')->all());
        $evidenceCoverage = $openCases->count() > 0
            ? round($openCases->filter(fn ($row) => (int) ($evidenceCounts[(string) $row->id] ?? 0) > 0)->count() / $openCases->count(), 4)
            : null;
        $highCriticalRisks = $risks->filter(fn ($row) => (float) ($row->score ?? 0) >= 0.5)->count();

        $caseItems = $allCases->slice($offset, $pageSize)->map(function ($case) use ($signalRows, $evidenceCounts, $hypothesesByCase, $recommendationsByCase, $decisionsByCase) {
            $caseId = (string) $case->id;
            $signal = $signalRows->get($case->signal_id);
            $hypothesis = $hypothesesByCase->get($caseId)?->sortByDesc('created_date')->first();
            $recommendation = $recommendationsByCase->get($caseId)?->sortByDesc('updated_date')->first();
            $decision = $decisionsByCase->get($caseId)?->sortByDesc('approved_date')->sortByDesc('created_date')->first();
            $confidence = $hypothesis?->confidence ?? $recommendation?->confidence ?? $signal?->confidence ?? null;

            return [
                'id' => $caseId,
                'title' => (string) $case->title,
                'status' => (string) $case->status,
                'severity' => $signal?->severity ? (string) $signal->severity : null,
                'classification' => $signal?->classification ? (string) $signal->classification : null,
                'ageDays' => $this->ageInDays($case->created_date),
                'owner' => $decision?->approved_by ? (string) $decision->approved_by : (string) $case->created_by,
                'evidenceCount' => (int) ($evidenceCounts[$caseId] ?? 0),
                'confidence' => $confidence === null ? null : round((float) $confidence, 4),
                'lastUpdated' => $case->updated_date ?? $case->created_date,
                'currentHypothesis' => $hypothesis ? [
                    'id' => (string) $hypothesis->id,
                    'statement' => (string) $hypothesis->statement,
                    'status' => (string) $hypothesis->status,
                    'confidence' => round((float) $hypothesis->confidence, 4),
                ] : null,
                'nextAction' => $decision ? 'Review decision' : ($recommendation ? 'Review recommendation' : 'Collect evidence'),
            ];
        })->values();

        $caseDetails = [];
        foreach ($caseItems as $item) {
            $caseId = (string) $item['id'];
            $caseRow = $allCases->firstWhere('id', $caseId);
            $signal = $caseRow ? $signalRows->get($caseRow->signal_id) : null;

            $caseDetails[$caseId] = [
                'summary' => [
                    'caseId' => $caseId,
                    'title' => $item['title'],
                    'status' => $item['status'],
                    'classification' => $item['classification'],
                    'severity' => $item['severity'],
                    'confidence' => $item['confidence'],
                    'ageDays' => $item['ageDays'],
                    'owner' => $item['owner'],
                    'lastUpdated' => $item['lastUpdated'],
                ],
                'timeline' => [
                    ['stage' => 'Signal', 'items' => array_values(array_filter([$this->signalTimelineItem($signal)]))],
                    ['stage' => 'Evidence', 'items' => $evidenceByCase->get($caseId, collect())->map(fn ($row) => [
                        'id' => (string) $row->id,
                        'title' => (string) $row->source,
                        'status' => (string) ($row->status ?? 'captured'),
                        'confidence' => $row->confidence === null ? null : round((float) $row->confidence, 4),
                        'timestamp' => $row->created_date,
                    ])->values()->all()],
                    ['stage' => 'Hypothesis', 'items' => $hypothesesByCase->get($caseId, collect())->map(fn ($row) => [
                        'id' => (string) $row->id,
                        'title' => (string) $row->statement,
                        'status' => (string) $row->status,
                        'confidence' => round((float) $row->confidence, 4),
                        'timestamp' => $row->created_date,
                    ])->values()->all()],
                    ['stage' => 'Reasoning', 'items' => $reasoningByCase->get($caseId, collect())->map(fn ($row) => [
                        'id' => (string) $row->id,
                        'title' => (string) $row->description,
                        'status' => 'recorded',
                        'confidence' => $row->confidence_score === null ? null : round((float) $row->confidence_score, 4),
                        'timestamp' => $row->created_date,
                    ])->values()->all()],
                    ['stage' => 'Recommendation', 'items' => $recommendationsByCase->get($caseId, collect())->map(fn ($row) => [
                        'id' => (string) $row->id,
                        'title' => (string) $row->title,
                        'status' => (string) $row->status,
                        'confidence' => $row->confidence === null ? null : round((float) $row->confidence, 4),
                        'timestamp' => $row->updated_date ?? $row->created_date,
                    ])->values()->all()],
                    ['stage' => 'Decision', 'items' => $decisionsByCase->get($caseId, collect())->map(fn ($row) => [
                        'id' => (string) $row->id,
                        'title' => (string) ($row->recommendation_title ?: 'Decision'),
                        'status' => (string) $row->status,
                        'confidence' => $row->recommendation_confidence === null ? null : round((float) $row->recommendation_confidence, 4),
                        'timestamp' => $row->approved_date ?? $row->created_date,
                    ])->values()->all()],
                ],
                'evidence' => $evidenceByCase->get($caseId, collect())->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'source' => (string) $row->source,
                    'summary' => null,
                    'status' => (string) ($row->status ?? 'captured'),
                    'confidence' => $row->confidence === null ? null : round((float) $row->confidence, 4),
                    'createdDate' => $row->created_date,
                ])->values()->all(),
                'hypotheses' => $hypothesesByCase->get($caseId, collect())->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'statement' => (string) $row->statement,
                    'rootCauseFamily' => (string) $row->root_cause_family,
                    'status' => (string) $row->status,
                    'confidence' => round((float) $row->confidence, 4),
                    'supportingEvidenceCount' => count(json_decode((string) $row->supporting_evidence_ids, true) ?: []),
                    'createdDate' => $row->created_date,
                ])->values()->all(),
                'reasoning' => $reasoningByCase->get($caseId, collect())->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'description' => (string) $row->description,
                    'confidence' => $row->confidence_score === null ? null : round((float) $row->confidence_score, 4),
                    'createdDate' => $row->created_date,
                ])->values()->all(),
                'recommendations' => $recommendationsByCase->get($caseId, collect())->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'title' => (string) $row->title,
                    'description' => $row->description ? (string) $row->description : null,
                    // The verb's own category — watch/investigate/intervene/
                    // escalate — which is what says whether a recommendation is
                    // asking to be read or asking to be acted on.
                    'category' => $row->category ? (string) $row->category : null,
                    'priority' => (string) $row->priority,
                    'status' => (string) $row->status,
                    'confidence' => $row->confidence === null ? null : round((float) $row->confidence, 4),
                    'impact' => $row->impact ? (string) $row->impact : null,
                    'updatedDate' => $row->updated_date ?? $row->created_date,
                ])->values()->all(),
                'decisions' => $decisionsByCase->get($caseId, collect())->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'status' => (string) $row->status,
                    'recommendationId' => $row->recommendation_id ? (string) $row->recommendation_id : null,
                    'recommendationTitle' => $row->recommendation_title ? (string) $row->recommendation_title : null,
                    'owner' => $row->approved_by ? (string) $row->approved_by : (string) $row->decided_by,
                    'ageDays' => $this->ageInDays($row->created_date),
                    'createdDate' => $row->created_date,
                    'approvedDate' => $row->approved_date,
                ])->values()->all(),
            ];
        }

        // caseId goes through the SAME resolver the grouping above uses, and not
        // through $row->linked_case_id. This row is the reason the fix is not a
        // one-line change: the queue read the raw column directly, so correcting
        // only the grouping would have produced a screen where a decision sits
        // under a case in the timeline while the queue below it reports no case
        // for that same decision. Two answers to one question is worse than the
        // single wrong answer it replaced.
        $decisionQueueItems = $pendingDecisions->sortByDesc('created_date')->take(10)->map(fn ($row) => [
            'id' => (string) $row->id,
            'decision' => (string) ($row->recommendation_title ?: 'Decision'),
            'caseId' => $this->caseIdForDecision($tenant, $row) ?: null,
            'recommendationId' => $row->recommendation_id ? (string) $row->recommendation_id : null,
            'recommendation' => $row->recommendation_title ? (string) $row->recommendation_title : null,
            'confidence' => $row->recommendation_confidence === null ? null : round((float) $row->recommendation_confidence, 4),
            'priority' => $row->recommendation_priority ? (string) $row->recommendation_priority : null,
            'owner' => $row->approved_by ? (string) $row->approved_by : (string) $row->decided_by,
            'ageDays' => $this->ageInDays($row->created_date),
            'status' => (string) $row->status,
        ])->values()->all();

        $pipeline = $this->buildDeliberationPipeline($signalRows->count(), $evidenceCounts->sum(), $allCases->count(), $hypotheses->count(), $reasoningSteps->count(), $recommendations->count(), $decisions->count(), $pendingRecommendations->count(), $pendingDecisions->count());

        return response()->json([
            'organization' => $organization,
            'generatedAt' => gmdate('Y-m-d H:i:s'),
            'freshness' => $this->latestTimestamp([
                $organization['updatedDate'] ?? null,
                $this->latestFromCollection($signalRows->all(), ['updated_date', 'created_date']),
                $this->latestFromCollection($allCases->all(), ['updated_date', 'created_date']),
                $this->latestFromCollection($recommendations->all(), ['updated_date', 'created_date']),
                $this->latestFromCollection($decisions->all(), ['approved_date', 'created_date']),
            ]),
            'summary' => [
                'openCases' => $openCases->count(),
                'activeInvestigations' => $activeInvestigations->count(),
                'pendingRecommendations' => $pendingRecommendations->count(),
                'pendingDecisions' => $pendingDecisions->count(),
                'averageDecisionAgeDays' => $decisionAgeDays,
                'evidenceCoverage' => $evidenceCoverage,
                'highCriticalRisks' => $highCriticalRisks,
                'overdueDecisions' => null,
                'overdueDecisionNote' => 'Insufficient data: decision due dates are not modeled in the current schema.',
            ],
            'pipeline' => $pipeline,
            'focus' => [
                'selectedCaseId' => $caseItems[0]['id'] ?? null,
                'biggestBottleneck' => collect($pipeline)->filter(fn ($stage) => $stage['conversionRate'] !== null)->sortBy('conversionRate')->values()->first(),
            ],
            'cases' => [
                'items' => $caseItems->all(),
                'detailsById' => $caseDetails,
                'total' => $allCases->count(),
                'page' => $page,
                'pageSize' => $pageSize,
            ],
            'decisionQueue' => [
                'items' => $decisionQueueItems,
                'total' => $pendingDecisions->count(),
            ],
        ]);
    }

    /**
     * The case a recommendation belongs to, by whichever link actually exists.
     *
     * WHY TWO PATHS AND NOT ONE. The leftJoin above reaches a case through
     * hpbrain_recommendations.reasoning_step_id, and for the recommendations
     * SignalReasoner wrote that column is populated and the join is correct.
     * For every recommendation RecommendVerb writes it is null — deliberately,
     * see RecommendVerb::persist — so the join yields nothing and those rows
     * grouped under the empty key, which is why no model-authored
     * recommendation has ever appeared against its case on this screen. They
     * were counted in the summary and invisible everywhere else.
     *
     * The second path is NOT a second implementation of that resolution.
     * RecommendationCaseContext already owns the only link that works for the
     * verb pipeline — dependencies -> hpbrain_evidence.signal_id ->
     * hpbrain_case_signals -> the case — including its refusal to guess when
     * cited evidence spans two cases. This calls that class and takes its
     * answer. A copy of the join here would be a second thing to keep in step
     * with a class whose whole documented purpose is to be the one place it
     * lives.
     *
     * THE REASONING STEP STILL WINS WHEN IT EXISTS. It is the explicit,
     * recorded link; the evidence path is an inference from what the
     * recommendation cited. Preferring the explicit one also means nothing
     * about the existing SignalReasoner rows changes.
     *
     * COST: the fallback runs only for rows with no reasoning step, and it
     * queries per row. That is bounded by the number of model-authored
     * recommendations in the tenant, not by the page size.
     */
    private function caseIdForRecommendation(string $tenant, object $row): string
    {
        $viaReasoningStep = (string) ($row->linked_case_id ?? '');

        if ($viaReasoningStep !== '') {
            return $viaReasoningStep;
        }

        return $this->resolveCaseIdThroughCitation($tenant, (string) $row->id);
    }

    /**
     * The case a DECISION belongs to — the same gap, one hop further along.
     *
     * A decision reaches its case through the recommendation it approves:
     * d.recommendation_id -> r.reasoning_step_id -> rs.case_id. That middle
     * column is the same hardcoded null RecommendVerb writes, so a decision on
     * a model-authored recommendation was orphaned exactly as the
     * recommendation itself was — it simply had not bitten yet, because until
     * now every decision in the installation hung off a SignalReasoner
     * recommendation whose reasoning step was populated.
     *
     * THE NULL GUARD IS NOT DEFENSIVE PADDING. hpbrain_decisions.recommendation_id
     * is nullable and the leftJoin above preserves decisions that have none.
     * Passing that null on as a string would ask RecommendationCaseContext to
     * look up the recommendation '' and get told, correctly, that no such
     * recommendation exists — a wasted query whose answer was already known
     * here. A decision with no recommendation has no citation to resolve
     * through, and the empty key is the honest place for it.
     */
    private function caseIdForDecision(string $tenant, object $row): string
    {
        $viaReasoningStep = (string) ($row->linked_case_id ?? '');

        if ($viaReasoningStep !== '') {
            return $viaReasoningStep;
        }

        $recommendationId = $row->recommendation_id ?? null;

        if ($recommendationId === null || $recommendationId === '') {
            return '';
        }

        return $this->resolveCaseIdThroughCitation($tenant, (string) $recommendationId);
    }

    /**
     * One recommendation's case, through what it cited — asked once per request.
     *
     * THE MEMO EXISTS BECAUSE THERE ARE NOW TWO CALLERS. A case's recommendation
     * and the decision approving that same recommendation resolve the identical
     * id, so without this the request pays for the same three-query walk twice
     * for every decided case. Keyed by recommendation id and held on the
     * controller, which Laravel builds fresh per request — so this is a
     * request-scoped memo, not a cache with a staleness question attached.
     *
     * Nulls are memoised too. "This recommendation reaches no case" is an answer
     * worth not recomputing, and it is the answer for every recommendation whose
     * cited evidence spans two cases.
     */
    private function resolveCaseIdThroughCitation(string $tenant, string $recommendationId): string
    {
        $key = $tenant.'|'.$recommendationId;

        if (! array_key_exists($key, $this->caseIdByRecommendation)) {
            $context = $this->recommendationCaseContext->forRecommendation($tenant, $recommendationId);

            // Null caseId is the honest answer for "no case links this" and for
            // "the cited evidence spans several". Both stay under the empty key,
            // exactly as before — this widens what can be resolved, it does not
            // invent a case for what cannot be.
            $this->caseIdByRecommendation[$key] = (string) ($context['caseId'] ?? '');
        }

        return $this->caseIdByRecommendation[$key];
    }

    public function enterpriseOverview(Request $request): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $organization = $this->organizationSummary($tenant);
        $departments = $this->departmentRows($tenant);
        $people = $this->personRows($tenant);
        $capabilities = collect(DB::table('hpbrain_capabilities')->where('tenant_id', $tenant)->get());
        $assignments = collect(DB::table('hpbrain_capability_assignments')->where('tenant_id', $tenant)->where('status', 'active')->get());
        $recommendations = collect(DB::table('hpbrain_recommendations')->where('tenant_id', $tenant)->get());
        $risks = collect(DB::table('hpbrain_risks')->where('tenant_id', $tenant)->get());
        $decisions = collect(DB::table('hpbrain_decisions')->where('tenant_id', $tenant)->get());
        $executions = collect(DB::table('hpbrain_eso_executions')->where('tenant_id', $tenant)->get());
        $outcomes = collect(DB::table('hpbrain_outcomes')->where('tenant_id', $tenant)->get());
        $learnings = collect(DB::table('hpbrain_learnings')->where('tenant_id', $tenant)->get());
        $signals = collect(DB::table('hpbrain_signals')->where('tenant_id', $tenant)->get());
        $cases = collect(DB::table('hpbrain_cases')->where('tenant_id', $tenant)->get());
        $evidence = collect(DB::table('hpbrain_evidence')->where('tenant_id', $tenant)->get());
        $executiveSummary = app(AnalyticsController::class)->executiveSummary($request)->getData(true);
        $organizationReport = app(AnalyticsController::class)->organizationReport($request, $tenant)->getData(true);

        $activeDepartments = $departments->filter(fn ($row) => strtolower((string) ($row['status'] ?? '')) === 'active');
        $peopleWithoutDepartment = $people->filter(fn ($row) => empty($row['departmentId']));
        $departmentsWithoutLeaders = $activeDepartments->filter(fn ($row) => empty($row['headId']));
        $leadershipCoverage = $activeDepartments->count() > 0 && $departmentsWithoutLeaders->count() !== $activeDepartments->count()
            ? round(($activeDepartments->count() - $departmentsWithoutLeaders->count()) / $activeDepartments->count(), 4)
            : ($departmentsWithoutLeaders->count() === 0 && $activeDepartments->count() > 0 ? 1.0 : null);
        $capabilityCoverage = $capabilities->count() > 0
            ? round($assignments->pluck('capability_id')->unique()->count() / $capabilities->count(), 4)
            : null;
        $openRisks = $risks->filter(fn ($row) => strtolower((string) ($row->status ?? '')) !== 'mitigated');
        $pendingDecisions = $decisions->filter(fn ($row) => in_array(strtolower((string) $row->status), ['pending', 'proposed'], true));
        $approvedDecisions = $decisions->filter(fn ($row) => strtolower((string) $row->status) === 'approved');
        $activeExecutions = $executions->filter(fn ($row) => in_array(strtolower((string) $row->status), ['queued', 'running', 'blocked'], true));
        $measuredOutcomes = $outcomes->filter(fn ($row) => !empty($row->decision_id));
        $dataCompleteness = $organizationReport['dataQuality']['score'] ?? null;
        $pendingRecommendations = $recommendations->filter(fn ($row) => in_array(strtolower((string) $row->status), ['pending', 'proposed', 'under_review'], true));
        $staleEvidenceCount = $evidence->filter(fn ($row) => is_string($row->created_date ?? null) && strtotime((string) $row->created_date) < strtotime(now()->subDays(30)->format('Y-m-d H:i:s')))->count();
        $averageEvidenceConfidence = $evidence->count() > 0 ? round((float) $evidence->avg('confidence'), 4) : null;
        $signalSeverityCounts = [
            'critical' => $signals->filter(fn ($row) => strtolower((string) ($row->severity ?? '')) === 'critical')->count(),
            'high' => $signals->filter(fn ($row) => strtolower((string) ($row->severity ?? '')) === 'high')->count(),
            'medium' => $signals->filter(fn ($row) => strtolower((string) ($row->severity ?? '')) === 'medium')->count(),
            'low' => $signals->filter(fn ($row) => strtolower((string) ($row->severity ?? '')) === 'low')->count(),
        ];
        $signalStatusCounts = $this->groupCollectionCounts($signals->all(), 'status');
        $decisionAgeDays = $this->averageAgeDays($pendingDecisions->pluck('created_date')->all());
        $decisionVelocity = $approvedDecisions->count() > 0 ? round($approvedDecisions->count() / max(1, $decisions->count()), 4) : null;
        $executionCompletionRate = $executions->count() > 0 ? round($executions->filter(fn ($row) => strtolower((string) $row->status) === 'completed')->count() / $executions->count(), 4) : null;
        $outcomeMeasurementRate = $executions->filter(fn ($row) => strtolower((string) $row->status) === 'completed')->count() > 0
            ? round($measuredOutcomes->count() / max(1, $executions->filter(fn ($row) => strtolower((string) $row->status) === 'completed')->count()), 4)
            : null;

        $dimensions = [
            $this->scoreDimension('Workforce', $people->count() > 0 ? max(0, 1 - ($peopleWithoutDepartment->count() / max(1, $people->count()))) : null, 'People mapped to departments.'),
            $this->scoreDimension('Departments', $activeDepartments->count() > 0 ? 1.0 : null, 'Active organization-unit coverage.'),
            $this->scoreDimension('Capabilities', $capabilityCoverage, 'Capabilities with at least one active assignment.'),
            $this->scoreDimension('Operations', $signals->count() > 0 ? max(0, 1 - ($cases->filter(fn ($row) => strtolower((string) $row->status) === 'open')->count() / max(1, $signals->count()))) : null, 'Signals converted into active investigations.'),
            $this->scoreDimension('Risk', $risks->count() > 0 ? max(0, 1 - ($openRisks->count() / max(1, $risks->count()))) : null, 'Share of risks not still open.'),
            $this->scoreDimension('Decisions', isset($executiveSummary['intelligenceScore']['breakdown']['decisionAcceptance']) ? ((float) $executiveSummary['intelligenceScore']['breakdown']['decisionAcceptance'] / 100) : null, 'Decision acceptance from existing analytics engine.'),
            $this->scoreDimension('Execution', $executions->count() > 0 ? ($executions->filter(fn ($row) => strtolower((string) $row->status) === 'completed')->count() / max(1, $executions->count())) : null, 'Completed execution share.'),
            $this->scoreDimension('Data Quality', $dataCompleteness !== null ? ((float) $dataCompleteness / 100) : null, 'Existing organization report completeness score.'),
        ];

        $scoredDimensions = array_values(array_filter(array_map(fn ($row) => $row['score'], $dimensions), fn ($value) => $value !== null));
        $healthScore = $scoredDimensions === [] ? null : round(array_sum($scoredDimensions) / count($scoredDimensions), 4);

        $managementAttention = collect(array_merge(
            $departmentsWithoutLeaders->take(3)->map(fn ($row) => [
                'id' => 'dept-leader-'.$row['id'],
                'title' => 'Department without leadership',
                'description' => $row['name'].' has no mapped department head.',
                'impact' => 'Leadership coverage',
                'confidence' => null,
                'relatedEntity' => ['type' => 'Department', 'id' => $row['id']],
                'tone' => 'warn',
            ])->all(),
            $openRisks->sortByDesc('score')->take(3)->map(fn ($row) => [
                'id' => 'risk-'.$row->id,
                'title' => (string) ($row->title ?? $row->category ?? 'Open risk'),
                'description' => (string) ($row->impact ?? $row->description ?? 'Risk remains unresolved.'),
                'impact' => 'Risk exposure',
                'confidence' => $row->score === null ? null : round((float) $row->score, 4),
                'relatedEntity' => ['type' => 'Risk', 'id' => (string) $row->id],
                'tone' => (float) ($row->score ?? 0) >= 0.75 ? 'crit' : 'warn',
            ])->all(),
            ($activeExecutions->count() > 0 && $executions->filter(fn ($row) => strtolower((string) $row->status) === 'completed')->count() > $measuredOutcomes->count()) ? [[
                'id' => 'outcome-gap',
                'title' => 'Completed executions missing outcomes',
                'description' => ($executions->filter(fn ($row) => strtolower((string) $row->status) === 'completed')->count() - $measuredOutcomes->count()).' execution(s) completed without a measured outcome.',
                'impact' => 'Outcome realization',
                'confidence' => null,
                'relatedEntity' => ['type' => 'Outcome', 'id' => null],
                'tone' => 'crit',
            ]] : []
        ))->take(8)->values()->all();

        $keyInsights = array_values(array_filter([
            $healthScore !== null ? [
                'id' => 'overall-health',
                'title' => 'Overall organizational health',
                'narrative' => 'Composite health is '.round($healthScore * 100, 1).'%, based only on measurable dimensions.',
                'priority' => $healthScore < 0.45 ? 'critical' : ($healthScore < 0.65 ? 'high' : 'medium'),
                'why' => 'Average of workforce, department, capability, operations, risk, decision, execution, and data-quality dimensions where data exists.',
            ] : null,
            $leadershipCoverage !== null && $leadershipCoverage < 1 ? [
                'id' => 'leadership-gap',
                'title' => 'Leadership gaps are weakening department coverage',
                'narrative' => $departmentsWithoutLeaders->count().' active department(s) have no mapped leader.',
                'priority' => $leadershipCoverage < 0.7 ? 'critical' : 'high',
                'why' => 'Departments without a leader reduce accountability and slow local execution.',
            ] : null,
            $capabilityCoverage !== null ? [
                'id' => 'capability-coverage',
                'title' => 'Capability coverage is '.round($capabilityCoverage * 100, 1).'%',
                'narrative' => ($capabilities->count() - $assignments->pluck('capability_id')->unique()->count()).' capability definition(s) have no active assignment.',
                'priority' => $capabilityCoverage < 0.45 ? 'critical' : ($capabilityCoverage < 0.7 ? 'high' : 'medium'),
                'why' => 'Unassigned capabilities indicate demand without clear organizational ownership.',
            ] : null,
            $decisionAgeDays !== null ? [
                'id' => 'decision-latency',
                'title' => 'Pending decisions are aging',
                'narrative' => 'Open decisions average '.$decisionAgeDays.' day(s) old.',
                'priority' => $decisionAgeDays > 14 ? 'critical' : ($decisionAgeDays > 7 ? 'high' : 'medium'),
                'why' => 'Decision age is a direct signal of governance throughput.',
            ] : null,
            $staleEvidenceCount > 0 ? [
                'id' => 'stale-evidence',
                'title' => 'Evidence base is going stale',
                'narrative' => $staleEvidenceCount.' evidence record(s) are older than 30 days.',
                'priority' => $staleEvidenceCount > 10 ? 'high' : 'medium',
                'why' => 'Stale evidence lowers trust in current recommendations and risk judgments.',
            ] : null,
            $outcomeMeasurementRate !== null ? [
                'id' => 'outcome-measurement',
                'title' => 'Outcome measurement coverage',
                'narrative' => round($outcomeMeasurementRate * 100, 1).'% of completed executions have measured outcomes.',
                'priority' => $outcomeMeasurementRate < 0.4 ? 'critical' : ($outcomeMeasurementRate < 0.7 ? 'high' : 'medium'),
                'why' => 'Completed work without outcomes weakens organizational learning.',
            ] : null,
        ]));

        $derivedRecommendations = array_values(array_filter([
            $departmentsWithoutLeaders->count() > 0 ? [
                'id' => 'action-leadership',
                'title' => 'Assign leadership ownership to uncovered departments',
                'priority' => $departmentsWithoutLeaders->count() >= 3 ? 'critical' : 'high',
                'confidence' => null,
                'why' => $departmentsWithoutLeaders->count().' active department(s) do not have a mapped leader.',
                'recommendedAction' => 'Backfill department heads or update the leadership mapping in the source system.',
                'riskIfIgnored' => 'Execution accountability and escalation speed will remain weak.',
                'sourceType' => 'derived',
                'supportingMetric' => $leadershipCoverage,
            ] : null,
            $capabilityCoverage !== null && $capabilityCoverage < 0.75 ? [
                'id' => 'action-capability',
                'title' => 'Close unassigned capability gaps',
                'priority' => $capabilityCoverage < 0.45 ? 'critical' : 'high',
                'confidence' => null,
                'why' => ($capabilities->count() - $assignments->pluck('capability_id')->unique()->count()).' capabilities have no active assignment.',
                'recommendedAction' => 'Map capability ownership by department or person before expanding new initiatives.',
                'riskIfIgnored' => 'Capability demand will remain disconnected from organizational supply.',
                'sourceType' => 'derived',
                'supportingMetric' => $capabilityCoverage,
            ] : null,
            $pendingDecisions->count() > 0 ? [
                'id' => 'action-decisions',
                'title' => 'Clear the decision queue with oldest-first review',
                'priority' => $pendingDecisions->count() > 5 ? 'high' : 'medium',
                'confidence' => null,
                'why' => $pendingDecisions->count().' decision(s) are still pending and average age is '.($decisionAgeDays ?? 'unknown').' day(s).',
                'recommendedAction' => 'Review oldest pending decisions and separate those blocked by missing evidence from those ready to approve or reject.',
                'riskIfIgnored' => 'Operational issues will accumulate faster than governance can resolve them.',
                'sourceType' => 'derived',
                'supportingMetric' => $decisionAgeDays,
            ] : null,
            $outcomeMeasurementRate !== null && $outcomeMeasurementRate < 0.7 ? [
                'id' => 'action-outcomes',
                'title' => 'Raise outcome measurement coverage on completed execution',
                'priority' => $outcomeMeasurementRate < 0.4 ? 'critical' : 'high',
                'confidence' => null,
                'why' => round($outcomeMeasurementRate * 100, 1).'% of completed executions have measured outcomes.',
                'recommendedAction' => 'Attach outcome capture to execution close-out and ensure evidence IDs are available before completion.',
                'riskIfIgnored' => 'Management will continue investing in actions with weak proof of impact.',
                'sourceType' => 'derived',
                'supportingMetric' => $outcomeMeasurementRate,
            ] : null,
        ]));

        $recordRecommendations = $pendingRecommendations
            ->sortBy(fn ($row) => $this->priorityRank((string) ($row->priority ?? 'medium')))
            ->take(5)
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'priority' => (string) ($row->priority ?? 'medium'),
                'confidence' => $row->confidence === null ? null : round((float) $row->confidence, 4),
                'why' => $row->description ? (string) $row->description : 'Pending recommendation generated from the current evidence chain.',
                'recommendedAction' => $row->impact ? (string) $row->impact : 'Review this recommendation in governance.',
                'riskIfIgnored' => $row->risk ? (string) $row->risk : 'Associated issue may continue without intervention.',
                'sourceType' => 'record',
                'supportingMetric' => null,
            ])->values()->all();

        $recentDecisions = DB::table('hpbrain_decisions as d')
            ->leftJoin('hpbrain_recommendations as r', function ($join) use ($tenant) {
                $join->on('r.id', '=', 'd.recommendation_id')->where('r.tenant_id', '=', $tenant);
            })
            ->where('d.tenant_id', $tenant)
            ->orderByDesc(DB::raw('COALESCE(d.approved_date, d.created_date)'))
            ->limit(6)
            ->get([
                'd.id',
                'd.status',
                'd.created_date',
                'd.approved_date',
                'd.approval_note',
                'r.title as recommendation_title',
                'r.priority as recommendation_priority',
                'r.confidence as recommendation_confidence',
            ])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => $row->recommendation_title ? (string) $row->recommendation_title : 'Decision',
                'status' => (string) $row->status,
                'priority' => $row->recommendation_priority ? (string) $row->recommendation_priority : null,
                'confidence' => $row->recommendation_confidence === null ? null : round((float) $row->recommendation_confidence, 4),
                'createdDate' => $row->created_date,
                'approvedDate' => $row->approved_date,
                'note' => $row->approval_note ? (string) $row->approval_note : null,
            ])->all();

        $recentOutcomes = DB::table('hpbrain_outcomes as o')
            ->leftJoin('hpbrain_decisions as d', function ($join) use ($tenant) {
                $join->on('d.id', '=', 'o.decision_id')->where('d.tenant_id', '=', $tenant);
            })
            ->leftJoin('hpbrain_recommendations as r', function ($join) use ($tenant) {
                $join->on('r.id', '=', 'd.recommendation_id')->where('r.tenant_id', '=', $tenant);
            })
            ->where('o.tenant_id', $tenant)
            ->orderByDesc('o.created_date')
            ->limit(6)
            ->get([
                'o.id',
                'o.result',
                'o.confidence',
                'o.created_date',
                'r.title as recommendation_title',
            ])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => $row->recommendation_title ? (string) $row->recommendation_title : 'Outcome',
                'result' => (string) $row->result,
                'confidence' => $row->confidence === null ? null : round((float) $row->confidence, 4),
                'createdDate' => $row->created_date,
            ])->all();

        $reusableLearnings = $learnings
            ->filter(fn ($row) => (bool) ($row->reusable ?? false))
            ->sortByDesc('created_date')
            ->take(6)
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'pattern' => (string) $row->pattern,
                'description' => $row->description ? (string) $row->description : null,
                'confidence' => $row->confidence === null ? null : round((float) $row->confidence, 4),
                'createdDate' => $row->created_date,
            ])->values()->all();

        $emergingIssues = array_values(array_filter([
            $signalSeverityCounts['high'] > 0 && $cases->count() < $signals->count() ? [
                'id' => 'emerging-signal-backlog',
                'title' => 'Signal backlog may convert into case pressure',
                'priority' => ($signals->count() - $cases->count()) > 10 ? 'high' : 'medium',
                'evidence' => ($signals->count() - $cases->count()).' signal(s) are not yet represented as cases.',
                'ifNoAction' => 'Untriaged signals can accumulate into hidden operational or risk debt.',
            ] : null,
            $staleEvidenceCount > 0 ? [
                'id' => 'emerging-stale-evidence',
                'title' => 'Aging evidence may reduce confidence in new decisions',
                'priority' => $staleEvidenceCount > 10 ? 'high' : 'medium',
                'evidence' => $staleEvidenceCount.' evidence record(s) are older than 30 days.',
                'ifNoAction' => 'Recommendation quality may degrade as decisions rely on older context.',
            ] : null,
            $pendingDecisions->count() > 0 && $decisionAgeDays !== null && $decisionAgeDays > 7 ? [
                'id' => 'emerging-decision-bottleneck',
                'title' => 'Decision bottleneck is forming',
                'priority' => $decisionAgeDays > 14 ? 'critical' : 'high',
                'evidence' => $pendingDecisions->count().' pending decision(s) averaging '.$decisionAgeDays.' day(s) old.',
                'ifNoAction' => 'Execution start times will slip and more issues will remain unresolved.',
            ] : null,
        ]));

        $riskMatrix = [
            'critical' => $openRisks->filter(fn ($row) => (float) ($row->score ?? 0) >= 0.75)->count(),
            'high' => $openRisks->filter(fn ($row) => (float) ($row->score ?? 0) >= 0.5 && (float) ($row->score ?? 0) < 0.75)->count(),
            'medium' => $openRisks->filter(fn ($row) => (float) ($row->score ?? 0) >= 0.25 && (float) ($row->score ?? 0) < 0.5)->count(),
            'mitigated' => $risks->filter(fn ($row) => strtolower((string) ($row->status ?? '')) === 'mitigated')->count(),
            'topItems' => $openRisks->sortByDesc('score')->take(5)->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => (string) ($row->title ?? $row->category ?? 'Open risk'),
                'owner' => $row->owner_id ? (string) $row->owner_id : null,
                'score' => $row->score === null ? null : round((float) $row->score, 4),
                'status' => (string) ($row->status ?? 'open'),
                'impact' => $row->impact ? (string) $row->impact : null,
            ])->values()->all(),
        ];

        $departmentPeople = $people->groupBy(fn ($row) => (string) ($row['departmentId'] ?? 'unassigned'));
        $capabilityAssignmentsByDepartment = $assignments->filter(fn ($row) => $row->target_type === 'Department')->groupBy('target_id');
        $capabilityHeatmap = $activeDepartments->take(8)->map(fn ($row) => [
            'departmentId' => $row['id'],
            'departmentName' => $row['name'],
            'peopleCount' => $departmentPeople->get($row['id'], collect())->count(),
            'capabilityAssignments' => $capabilityAssignmentsByDepartment->get($row['id'], collect())->count(),
        ])->values()->all();

        $dataTrust = [
            'completeness' => $dataCompleteness === null ? null : round(((float) $dataCompleteness / 100), 4),
            'missingEmployeeDepartment' => $peopleWithoutDepartment->count(),
            'missingDepartmentLeadership' => $departmentsWithoutLeaders->count(),
            'missingCapabilityMapping' => $capabilities->count() - $assignments->pluck('capability_id')->unique()->count(),
            'staleEvidence' => $staleEvidenceCount,
            'failedImports' => $this->countIfTableExists('hpbrain_import_runs', $tenant, 'status', 'failed'),
            'rejectedRows' => $this->countIfTableExists('hpbrain_import_rows', $tenant, 'status', 'rejected'),
            'lastRefresh' => $this->latestTimestamp([
                $organization['updatedDate'] ?? null,
                $this->latestFromCollection($people->all(), ['updatedDate', 'createdDate']),
                $this->latestFromCollection($signals->all(), ['updated_date', 'created_date']),
            ]),
            'confidence' => isset($executiveSummary['statistics']['evidenceQuality']) ? round((float) $executiveSummary['statistics']['evidenceQuality'], 4) : null,
        ];

        $recommendationsById = $recommendations->keyBy('id');
        $decisionsById = $decisions->keyBy('id');
        $capabilityAssignmentsByCapability = $assignments->groupBy('capability_id');
        $capabilityAssignmentsByDepartment = $assignments->filter(fn ($row) => strtolower((string) ($row->target_type ?? '')) === 'department')->groupBy('target_id');

        $scoreBreakdown = [
            'decisionAcceptance' => isset($executiveSummary['intelligenceScore']['breakdown']['decisionAcceptance'])
                ? round(((float) $executiveSummary['intelligenceScore']['breakdown']['decisionAcceptance']) / 100, 4)
                : null,
            'recommendationAccuracy' => isset($executiveSummary['intelligenceScore']['breakdown']['recommendationAccuracy'])
                ? round(((float) $executiveSummary['intelligenceScore']['breakdown']['recommendationAccuracy']) / 100, 4)
                : null,
            'evidenceQuality' => isset($executiveSummary['intelligenceScore']['breakdown']['evidenceQuality'])
                ? round(((float) $executiveSummary['intelligenceScore']['breakdown']['evidenceQuality']) / 100, 4)
                : null,
            'riskCoverage' => isset($executiveSummary['intelligenceScore']['breakdown']['riskCoverage'])
                ? round(((float) $executiveSummary['intelligenceScore']['breakdown']['riskCoverage']) / 100, 4)
                : null,
        ];

        $pricedLeakageItems = $pendingRecommendations
            ->map(function ($row) {
                $pricedValue = $row->expected_roi !== null
                    ? round((float) $row->expected_roi, 4)
                    : $this->numericMetricFromMixed($row->impact ?? null);

                if ($pricedValue === null) {
                    return null;
                }

                return [
                    'id' => (string) $row->id,
                    'title' => (string) ($row->title ?? 'Recommendation'),
                    'value' => $pricedValue,
                    'confidence' => $row->confidence === null ? null : round((float) $row->confidence, 4),
                    'why' => $row->description ? (string) $row->description : 'Pending recommendation with measurable expected value.',
                    'source' => 'recommendation',
                ];
            })
            ->filter()
            ->sortByDesc('value')
            ->values();

        $recoveredValueItems = $outcomes
            ->map(function ($row) use ($decisionsById, $recommendationsById) {
                $decision = $decisionsById->get($row->decision_id);
                $recommendation = $decision ? $recommendationsById->get($decision->recommendation_id) : null;
                $metrics = $this->decodeJsonObject($row->metrics);
                $kpis = $this->decodeJsonObject($row->kpis);
                $realizedValue = $this->numericMetricFromMixed($metrics) ?? $this->numericMetricFromMixed($kpis);

                if ($realizedValue === null) {
                    return null;
                }

                return [
                    'id' => (string) $row->id,
                    'title' => $recommendation?->title ? (string) $recommendation->title : 'Measured outcome',
                    'value' => $realizedValue,
                    'predictedValue' => $recommendation?->expected_roi !== null
                        ? round((float) $recommendation->expected_roi, 4)
                        : $this->numericMetricFromMixed($recommendation?->impact ?? null),
                    'confidence' => $row->confidence === null ? null : round((float) $row->confidence, 4),
                    'why' => (string) ($row->result ?? 'Outcome recorded'),
                    'source' => 'outcome',
                ];
            })
            ->filter()
            ->sortByDesc('value')
            ->values();

        $unpricedValueItems = collect(array_merge(
            $pendingRecommendations
                ->filter(fn ($row) => $row->expected_roi === null && $this->numericMetricFromMixed($row->impact ?? null) === null)
                ->take(3)
                ->map(fn ($row) => [
                    'id' => 'unpriced-recommendation-'.$row->id,
                    'title' => (string) ($row->title ?? 'Recommendation'),
                    'why' => 'The recommendation exists, but no defensible numeric impact is stored on the record.',
                    'source' => 'recommendation',
                ])->all(),
            $departmentsWithoutLeaders->count() > 0 ? [[
                'id' => 'unpriced-leadership-gap',
                'title' => 'Departments without mapped leaders',
                'why' => $departmentsWithoutLeaders->count().' department(s) are missing leadership coverage, but no monetary value is stored to price the exposure honestly.',
                'source' => 'department',
            ]] : [],
            $peopleWithoutDepartment->count() > 0 ? [[
                'id' => 'unpriced-people-gap',
                'title' => 'People outside department rollups',
                'why' => $peopleWithoutDepartment->count().' people are unmapped to departments. This affects planning quality, but no direct priced impact exists in the current schema.',
                'source' => 'workforce',
            ]] : [],
            $staleEvidenceCount > 0 ? [[
                'id' => 'unpriced-stale-evidence',
                'title' => 'Stale evidence is weakening trust',
                'why' => $staleEvidenceCount.' evidence record(s) are older than 30 days, but the cost of weaker trust is not stored as a priced figure.',
                'source' => 'evidence',
            ]] : []
        ))->take(6)->values()->all();

        $healthTrend = $this->trendSeries($tenant, 'score.decisionAcceptance', 180);
        $riskTrend = $this->trendSeries($tenant, 'score.riskCoverage', 180);
        $capabilityTrend = $this->trendSeries($tenant, 'capability.coverage', 180);
        $decisionTrend = $this->trendSeries($tenant, 'decision.latencyMedianHours', 180);
        $executionTrend = $this->trendSeries($tenant, 'recommendations.pending', 180);
        $outcomeTrend = $this->trendSeries($tenant, 'score.recommendationAccuracy', 180);

        $healthTrendSeries = collect($healthTrend['series'] ?? [])->pluck('value')->filter(fn ($value) => $value !== null)->values();
        $healthTrendFirst = $healthTrendSeries->first();
        $healthTrendLast = $healthTrendSeries->last();
        $healthTrendDelta = ($healthTrendFirst !== null && $healthTrendLast !== null)
            ? round((float) $healthTrendLast - (float) $healthTrendFirst, 4)
            : null;

        $forecast = [
            'continuation' => $healthTrend['status'] === 'ok' && $healthTrendDelta !== null
                ? ($healthTrendDelta >= 0
                    ? 'Recent health-related decision performance has improved over the available history; if current behavior continues, the organization is more likely to stabilize than deteriorate.'
                    : 'Recent health-related decision performance has declined over the available history; if current behavior continues, risk and execution pressure are more likely to intensify.')
                : 'Insufficient historical data',
            'actionPath' => count($derivedRecommendations) > 0
                ? 'If the highest-confidence actions are executed, the most likely gains are in '.collect($derivedRecommendations)->pluck('title')->take(3)->implode(', ').'.'
                : 'Not enough evidence',
            'status' => $healthTrend['status'] === 'ok' && $healthTrendDelta !== null ? 'ok' : 'insufficient_data',
        ];

        $loopStages = collect([
            ['key' => 'signals', 'label' => 'Signals', 'count' => $signals->count()],
            ['key' => 'evidence', 'label' => 'Evidence', 'count' => $evidence->count()],
            ['key' => 'cases', 'label' => 'Cases', 'count' => $cases->count()],
            ['key' => 'recommendations', 'label' => 'Recommendations', 'count' => $recommendations->count()],
            ['key' => 'decisions', 'label' => 'Decisions', 'count' => $decisions->count()],
            ['key' => 'executions', 'label' => 'Executions', 'count' => $executions->count()],
            ['key' => 'outcomes', 'label' => 'Outcomes', 'count' => $outcomes->count()],
            ['key' => 'learnings', 'label' => 'Learnings', 'count' => $learnings->count()],
        ])->values()->all();

        $loopContinuity = collect($loopStages)->map(function (array $stage, int $index) use ($loopStages) {
            $previous = $index > 0 ? $loopStages[$index - 1] : null;
            $conversion = $previous && ($previous['count'] ?? 0) > 0
                ? round($stage['count'] / max(1, $previous['count']), 4)
                : null;

            return $stage + ['conversionRate' => $conversion];
        })->values();

        $departmentIntelligence = $activeDepartments
            ->map(function (array $row) use ($departmentPeople, $capabilityAssignmentsByDepartment) {
                $peopleCount = $departmentPeople->get($row['id'], collect())->count();
                $assignmentCount = $capabilityAssignmentsByDepartment->get($row['id'], collect())->count();
                $attentionScore = (empty($row['headId']) ? 2 : 0) + ($peopleCount === 0 ? 1 : 0) + ($assignmentCount === 0 ? 1 : 0);

                return [
                    'departmentId' => $row['id'],
                    'departmentName' => $row['name'],
                    'peopleCount' => $peopleCount,
                    'hasLeader' => ! empty($row['headId']),
                    'capabilityAssignments' => $assignmentCount,
                    'attentionScore' => $attentionScore,
                    'attentionLabel' => $attentionScore >= 2 ? 'Needs attention' : ($attentionScore === 1 ? 'Watch' : 'Stable'),
                ];
            })
            ->sortByDesc('attentionScore')
            ->values()
            ->take(8)
            ->all();

        $capabilityIntelligence = $capabilities
            ->map(function ($row) use ($capabilityAssignmentsByCapability) {
                $assignmentCount = $capabilityAssignmentsByCapability->get((string) $row->id, collect())->count();
                $criticality = strtolower((string) ($row->criticality ?? 'unknown'));
                $isCritical = in_array($criticality, ['high', 'critical', '4', '5'], true);

                return [
                    'capabilityId' => (string) $row->id,
                    'capabilityName' => (string) ($row->name ?? $row->title ?? $row->capability_name ?? 'Capability'),
                    'criticality' => (string) ($row->criticality ?? 'unknown'),
                    'assignmentCount' => $assignmentCount,
                    'coverage' => $assignmentCount > 0 ? 1 : 0,
                    'attentionLabel' => $isCritical && $assignmentCount === 0
                        ? 'Critical gap'
                        : ($assignmentCount === 0 ? 'Unassigned' : 'Covered'),
                ];
            })
            ->sortBy(function (array $row) {
                return ($row['attentionLabel'] === 'Critical gap' ? 0 : ($row['attentionLabel'] === 'Unassigned' ? 1 : 2));
            })
            ->values()
            ->take(8)
            ->all();

        $topBoardAction = collect(array_merge($derivedRecommendations, $recordRecommendations))->first();
        $boardAsk = [
            'headline' => $topBoardAction ? 'Management action is needed on '.$topBoardAction['title'].'.' : 'No board-level action request is ready yet.',
            'decisionQueue' => $pendingDecisions->count(),
            'topActions' => collect(array_merge($derivedRecommendations, $recordRecommendations))->take(3)->values()->all(),
            'continuityRisk' => $loopContinuity->filter(fn ($stage) => $stage['conversionRate'] !== null)->sortBy('conversionRate')->first(),
        ];

        return response()->json([
            'organization' => $organization,
            'generatedAt' => gmdate('Y-m-d H:i:s'),
            'summary' => [
                'healthScore' => $healthScore,
                'dataConfidence' => $dataTrust['confidence'],
                'freshness' => $dataTrust['lastRefresh'],
                'methodology' => 'Transparent average of measurable dimensions only; unmapped dimensions are excluded rather than fabricated.',
                'whereToday' => $this->whereTodayNarrative($healthScore, $openRisks->count(), $pendingDecisions->count(), $outcomeMeasurementRate),
                'whatCouldHappenNext' => $emergingIssues[0]['ifNoAction'] ?? null,
            ],
            'dimensions' => $dimensions,
            'kpis' => [
                ['label' => 'People', 'value' => $people->count(), 'detail' => $peopleWithoutDepartment->count().' without department'],
                ['label' => 'Departments', 'value' => $departments->count(), 'detail' => $activeDepartments->count().' active'],
                ['label' => 'Capability Coverage', 'value' => $capabilityCoverage, 'detail' => $capabilities->count().' capabilities'],
                ['label' => 'Leadership Coverage', 'value' => $leadershipCoverage, 'detail' => $departmentsWithoutLeaders->count().' gaps'],
                ['label' => 'Open Risks', 'value' => $openRisks->count(), 'detail' => $risks->count().' total'],
                ['label' => 'Pending Decisions', 'value' => $pendingDecisions->count(), 'detail' => $decisions->count().' total'],
                ['label' => 'Active Executions', 'value' => $activeExecutions->count(), 'detail' => $executions->count().' total'],
                ['label' => 'Outcomes', 'value' => $measuredOutcomes->count(), 'detail' => $outcomes->count().' recorded'],
                ['label' => 'Data Completeness', 'value' => $dataCompleteness === null ? null : round(((float) $dataCompleteness / 100), 4), 'detail' => 'Existing organization report'],
            ],
            'executiveSummary' => [
                'strengths' => array_values(array_filter([
                    $leadershipCoverage !== null && $leadershipCoverage >= 0.9 ? 'Leadership coverage is strong across active departments.' : null,
                    $capabilityCoverage !== null && $capabilityCoverage >= 0.75 ? 'Capability ownership is broadly mapped.' : null,
                    $dataTrust['confidence'] !== null && $dataTrust['confidence'] >= 0.7 ? 'Evidence confidence is supporting decision quality.' : null,
                    $executionCompletionRate !== null && $executionCompletionRate >= 0.7 ? 'Execution completion is healthy relative to total runs.' : null,
                ])),
                'weaknesses' => array_values(array_filter([
                    $departmentsWithoutLeaders->count() > 0 ? $departmentsWithoutLeaders->count().' department leadership gaps remain open.' : null,
                    $peopleWithoutDepartment->count() > 0 ? $peopleWithoutDepartment->count().' people are not mapped to departments.' : null,
                    $pendingDecisions->count() > 0 ? $pendingDecisions->count().' decisions are waiting in governance.' : null,
                    $staleEvidenceCount > 0 ? $staleEvidenceCount.' evidence records are stale.' : null,
                ])),
                'attentionHeadline' => $managementAttention[0]['title'] ?? null,
            ],
            'keyInsights' => $keyInsights,
            'aiRecommendations' => array_values(array_slice(array_merge($derivedRecommendations, $recordRecommendations), 0, 8)),
            'managementAttention' => $managementAttention,
            'intelligenceScore' => [
                'score' => $healthScore,
                'breakdown' => $scoreBreakdown,
            ],
            'valueRealization' => [
                'pricedLeakage' => [
                    'total' => round((float) $pricedLeakageItems->sum('value'), 4),
                    'items' => $pricedLeakageItems->take(6)->all(),
                ],
                'recovered' => [
                    'total' => round((float) $recoveredValueItems->sum('value'), 4),
                    'items' => $recoveredValueItems->take(6)->all(),
                ],
                'unpriced' => $unpricedValueItems,
            ],
            'forecast' => $forecast,
            'boardAsk' => $boardAsk,
            'riskMatrix' => $riskMatrix,
            'organizationalHealth' => [
                'decisionVelocity' => $decisionVelocity,
                'executionCompletionRate' => $executionCompletionRate,
                'outcomeMeasurementRate' => $outcomeMeasurementRate,
                'averageDecisionAgeDays' => $decisionAgeDays,
            ],
            'loopContinuity' => [
                'stages' => $loopContinuity->all(),
                'weakestStage' => $loopContinuity->filter(fn ($stage) => $stage['conversionRate'] !== null)->sortBy('conversionRate')->first(),
            ],
            'workforceDepartment' => [
                'totalPeople' => $people->count(),
                'totalDepartments' => $departments->count(),
                'activeDepartments' => $activeDepartments->count(),
                'peopleWithoutDepartment' => $peopleWithoutDepartment->count(),
                'departmentsWithoutLeaders' => $departmentsWithoutLeaders->count(),
                'leadershipCoverage' => $leadershipCoverage,
                'largestDepartments' => $activeDepartments->map(fn ($row) => [
                    'departmentId' => $row['id'],
                    'departmentName' => $row['name'],
                    'peopleCount' => $departmentPeople->get($row['id'], collect())->count(),
                ])->sortByDesc('peopleCount')->take(6)->values()->all(),
                'attention' => $departmentIntelligence,
            ],
            'capabilityWorkforce' => [
                'peopleWithoutDepartment' => $peopleWithoutDepartment->count(),
                'departmentsWithoutLeaders' => $departmentsWithoutLeaders->count(),
                'capabilityCoverage' => $capabilityCoverage,
                'totalCapabilities' => $capabilities->count(),
                'assignedCapabilities' => $assignments->pluck('capability_id')->unique()->count(),
                'criticalCapabilities' => $capabilities->filter(fn ($row) => in_array(strtolower((string) ($row->criticality ?? '')), ['high', 'critical'], true))->count(),
                'heatmap' => $capabilityHeatmap,
                'attention' => $capabilityIntelligence,
            ],
            'signalsEvidence' => [
                'signalsTotal' => $signals->count(),
                'evidenceTotal' => $evidence->count(),
                'signalsByStatus' => $signalStatusCounts,
                'signalsBySeverity' => $signalSeverityCounts,
                'averageEvidenceConfidence' => $averageEvidenceConfidence,
                'staleEvidence' => $staleEvidenceCount,
                'evidencePerSignal' => $signals->count() > 0 ? round($evidence->count() / $signals->count(), 4) : null,
            ],
            'decisionIntelligence' => [
                'decisionsTotal' => $decisions->count(),
                'pendingDecisions' => $pendingDecisions->count(),
                'approvedDecisions' => $approvedDecisions->count(),
                'pendingRecommendations' => $pendingRecommendations->count(),
                'decisionVelocity' => $decisionVelocity,
                'averageDecisionAgeDays' => $decisionAgeDays,
            ],
            'growthOpportunity' => [
                'items' => array_values(array_filter([
                    $activeDepartments->count() > 0 && $leadershipCoverage !== null && $leadershipCoverage < 1 ? [
                        'id' => 'leadership-coverage',
                        'title' => 'Leadership coverage opportunity',
                        'description' => $departmentsWithoutLeaders->count().' active department(s) have no mapped leader.',
                        'supportingMetric' => $leadershipCoverage,
                    ] : null,
                    $capabilityCoverage !== null && $capabilityCoverage < 0.75 ? [
                        'id' => 'capability-coverage',
                        'title' => 'Capability coverage can improve',
                        'description' => 'Only '.round($capabilityCoverage * 100, 1).'% of capabilities have active assignments.',
                        'supportingMetric' => $capabilityCoverage,
                    ] : null,
                    $signals->count() > 0 && $cases->count() < $signals->count() ? [
                        'id' => 'signal-triage',
                        'title' => 'Untapped signal backlog',
                        'description' => ($signals->count() - $cases->count()).' signal(s) are not yet represented as cases.',
                        'supportingMetric' => $signals->count() > 0 ? round(($signals->count() - $cases->count()) / $signals->count(), 4) : null,
                    ] : null,
                ])),
            ],
            'dataTrust' => $dataTrust,
            'predictedIssues' => $emergingIssues,
            'recommendedActions' => $derivedRecommendations,
            'recentDecisions' => $recentDecisions,
            'recentOutcomes' => $recentOutcomes,
            'reusableLearnings' => $reusableLearnings,
            'trends' => [
                'healthScore' => $healthTrend,
                'risk' => $riskTrend,
                'capability' => $capabilityTrend,
                'decision' => $decisionTrend,
                'execution' => $executionTrend,
                'outcome' => $outcomeTrend,
            ],
        ]);
    }

    public function executionOverview(Request $request): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(5, min(20, (int) $request->query('pageSize', 12)));
        $status = trim((string) $request->query('status', 'active'));
        $organization = $this->organizationSummary($tenant);

        $recommendations = collect(DB::table('hpbrain_recommendations')->where('tenant_id', $tenant)->get());
        $decisions = collect(DB::table('hpbrain_decisions')->where('tenant_id', $tenant)->get());
        $executions = collect(DB::table('hpbrain_eso_executions')->where('tenant_id', $tenant)->orderByDesc('created_date')->get());
        $outcomes = collect(DB::table('hpbrain_outcomes')->where('tenant_id', $tenant)->get());
        $learnings = collect(DB::table('hpbrain_learnings')->where('tenant_id', $tenant)->get());
        $departments = $this->departmentRows($tenant)->keyBy('id');
        $people = $this->personRows($tenant)->keyBy('id');

        $recommendationsById = $recommendations->keyBy('id');
        $decisionsById = $decisions->keyBy('id');
        $outcomesByDecision = $outcomes->groupBy(fn ($row) => (string) $row->decision_id);
        $learningsByOutcome = $learnings->groupBy(fn ($row) => (string) $row->outcome_id);
        $executedDecisionIds = $executions->pluck('decision_id')->filter()->map(fn ($id) => (string) $id)->unique()->values();
        $measurementPlansByDecision = collect(DB::table('hpbrain_measurement_plans')
            ->where('tenant_id', $tenant)
            ->orderByDesc('created_date')
            ->get())
            ->groupBy(fn ($row) => (string) $row->decision_id);
        $reasoningCaseByStep = collect(DB::table('hpbrain_reasoning_steps')
            ->where('tenant_id', $tenant)
            ->whereNotNull('case_id')
            ->pluck('case_id', 'id'));

        $approvedDecisions = $decisions->filter(fn ($row) => strtolower((string) $row->status) === 'approved');
        $queued = $executions->filter(fn ($row) => strtolower((string) $row->status) === 'queued');
        $running = $executions->filter(fn ($row) => strtolower((string) $row->status) === 'running');
        $completed = $executions->filter(fn ($row) => strtolower((string) $row->status) === 'completed');
        $failed = $executions->filter(fn ($row) => strtolower((string) $row->status) === 'failed');
        $rolledBack = $executions->filter(fn ($row) => strtolower((string) $row->status) === 'rolled_back');
        $blocked = $executions->filter(fn ($row) => strtolower((string) $row->status) === 'blocked');
        $measuredOutcomes = $completed->filter(fn ($row) => $outcomesByDecision->has((string) $row->decision_id));
        $successRate = ($completed->count() + $failed->count() + $rolledBack->count()) > 0
            ? round($completed->count() / max(1, $completed->count() + $failed->count() + $rolledBack->count()), 4)
            : null;
        $outcomeMeasurementRate = $completed->count() > 0 ? round($measuredOutcomes->count() / $completed->count(), 4) : null;
        $averageExecutionHours = $this->averageDurationHours($completed->all());

        $executionRows = $executions->map(function ($execution) use ($decisionsById, $recommendationsById, $outcomesByDecision, $people, $departments) {
            $decision = $decisionsById->get($execution->decision_id);
            $recommendation = $decision ? $recommendationsById->get($decision->recommendation_id) : null;
            $ownerId = $execution->executed_by ?: ($decision->approved_by ?: $decision->decided_by ?? null);
            $person = $ownerId ? $people->get((string) $ownerId) : null;
            $department = $person && !empty($person['departmentId']) ? $departments->get((string) $person['departmentId']) : null;
            $outcome = $outcomesByDecision->get((string) $execution->decision_id, collect())->sortByDesc('created_date')->first();

            return [
                'id' => (string) $execution->id,
                'execution' => (string) $execution->id,
                'esoDefinitionId' => $execution->eso_definition_id ? (string) $execution->eso_definition_id : ((string) ($execution->eso_id ?? '') ?: null),
                'decisionId' => $execution->decision_id ? (string) $execution->decision_id : null,
                'decision' => $recommendation?->title ? (string) $recommendation->title : 'Decision',
                'owner' => $ownerId ? (string) $ownerId : null,
                'department' => $department['name'] ?? null,
                'status' => (string) $execution->status,
                'progress' => $this->executionProgress((string) $execution->status),
                'started' => $execution->started_date,
                'expectedCompletion' => null,
                'durationDays' => $this->ageInDays($execution->started_date),
                'risk' => strtolower((string) $execution->status) === 'failed' ? 'high' : (strtolower((string) $execution->status) === 'blocked' ? 'critical' : 'normal'),
                'outcomeStatus' => $outcome ? (string) $outcome->result : 'Outcome not measured',
                'recommendation' => $recommendation ? [
                    'id' => (string) $recommendation->id,
                    'title' => (string) $recommendation->title,
                    'impact' => $recommendation->impact ? (string) $recommendation->impact : null,
                    'confidence' => $recommendation->confidence === null ? null : round((float) $recommendation->confidence, 4),
                    'citationEvidenceIds' => $this->citationEvidenceIds($recommendation->dependencies ?? '[]'),
                ] : null,
                'citationEvidenceIds' => $recommendation ? $this->citationEvidenceIds($recommendation->dependencies ?? '[]') : [],
                'outcome' => $outcome ? [
                    'id' => (string) $outcome->id,
                    'result' => (string) $outcome->result,
                    'confidence' => $outcome->confidence === null ? null : round((float) $outcome->confidence, 4),
                    'metrics' => $this->decodeJsonObject($outcome->metrics),
                    'createdDate' => $outcome->created_date,
                ] : null,
            ];
        });

        $executionQueue = $approvedDecisions
            ->reject(fn ($decision) => $executedDecisionIds->contains((string) $decision->id))
            ->sortByDesc(fn ($decision) => $decision->approved_date ?? $decision->created_date)
            ->values()
            ->map(function ($decision) use ($tenant, $recommendationsById, $measurementPlansByDecision, $reasoningCaseByStep) {
                $recommendation = $decision->recommendation_id ? $recommendationsById->get((string) $decision->recommendation_id) : null;
                $latestPlan = $measurementPlansByDecision->get((string) $decision->id, collect())->first();
                $decisionForCase = (object) array_merge((array) $decision, [
                    'linked_case_id' => $recommendation?->reasoning_step_id
                        ? (string) ($reasoningCaseByStep->get((string) $recommendation->reasoning_step_id) ?? '')
                        : '',
                ]);

                return [
                    'id' => (string) $decision->id,
                    'decision' => $recommendation?->title ? (string) $recommendation->title : 'Decision',
                    'caseId' => $this->caseIdForDecision($tenant, $decisionForCase) ?: null,
                    'recommendationId' => $decision->recommendation_id ? (string) $decision->recommendation_id : null,
                    'owner' => $decision->approved_by ? (string) $decision->approved_by : (string) $decision->decided_by,
                    'approvedDate' => $decision->approved_date ?? $decision->created_date,
                    'hasMeasurementPlan' => $latestPlan !== null,
                    'measurementPlan' => $latestPlan ? [
                        'id' => (string) $latestPlan->id,
                        'baselineMetric' => (string) $latestPlan->baseline_metric,
                        'baselineValue' => $latestPlan->baseline_value === null ? null : (float) $latestPlan->baseline_value,
                        'targetValue' => $latestPlan->target_value === null ? null : (float) $latestPlan->target_value,
                        'metricUnit' => $latestPlan->metric_unit === null ? null : (string) $latestPlan->metric_unit,
                        'measurementWindowDays' => $latestPlan->measurement_window_days === null ? null : (int) $latestPlan->measurement_window_days,
                    ] : null,
                    'recommendation' => $recommendation ? [
                        'id' => (string) $recommendation->id,
                        'title' => (string) $recommendation->title,
                        'category' => $recommendation->category ? (string) $recommendation->category : null,
                        'priority' => (string) $recommendation->priority,
                        'confidence' => $recommendation->confidence === null ? null : round((float) $recommendation->confidence, 4),
                        'impact' => $recommendation->impact ? (string) $recommendation->impact : null,
                        'esoId' => $recommendation->eso_id ? (string) $recommendation->eso_id : null,
                        'citationEvidenceIds' => $this->citationEvidenceIds($recommendation->dependencies ?? '[]'),
                    ] : null,
                ];
            });

        $filteredExecutions = match ($status) {
            'all' => $executionRows,
            'active' => $executionRows->filter(fn ($row) => in_array(strtolower((string) $row['status']), ['queued', 'running', 'blocked'], true)),
            default => $executionRows->filter(fn ($row) => strtolower((string) $row['status']) === strtolower($status)),
        };

        $bottlenecks = collect([
            [
                'key' => 'decision-conversion',
                'label' => 'Approved decisions without execution',
                'count' => max(0, $approvedDecisions->count() - $executions->pluck('decision_id')->filter()->unique()->count()),
                'detail' => 'Governance approved the decision but no execution row exists yet.',
            ],
            [
                'key' => 'blocked',
                'label' => 'Blocked executions',
                'count' => $blocked->count(),
                'detail' => 'Execution status is blocked.',
            ],
            [
                'key' => 'long-running',
                'label' => 'Long-running executions',
                'count' => $executionRows->filter(fn ($row) => strtolower((string) $row['status']) === 'running' && ($row['durationDays'] ?? 0) > 7)->count(),
                'detail' => 'Still running after more than 7 days.',
            ],
            [
                'key' => 'failed',
                'label' => 'Failed executions',
                'count' => $failed->count(),
                'detail' => 'Execution ended in failure.',
            ],
            [
                'key' => 'missing-outcome',
                'label' => 'Completed executions without outcomes',
                'count' => $completed->count() - $measuredOutcomes->count(),
                'detail' => 'Execution completed but no outcome was captured.',
            ],
        ])->sortByDesc('count')->values();

        $predictedVsRealized = $executionRows->map(function ($row) {
            $predicted = $this->numericMetricFromMixed($row['recommendation']['impact'] ?? null);
            $realized = $this->numericMetricFromMixed($row['outcome']['metrics'] ?? null);
            if ($predicted === null || $realized === null) {
                return null;
            }

            return [
                'executionId' => $row['id'],
                'label' => $row['recommendation']['title'] ?? $row['execution'],
                'predicted' => $predicted,
                'realized' => $realized,
                'variance' => round($realized - $predicted, 4),
            ];
        })->filter()->values();

        $effectiveness = $executionRows->groupBy(fn ($row) => (string) ($row['recommendation']['title'] ?? $row['execution']))->map(function ($group, $key) {
            $completedRuns = $group->filter(fn ($row) => strtolower((string) $row['status']) === 'completed')->count();
            $failedRuns = $group->filter(fn ($row) => in_array(strtolower((string) $row['status']), ['failed', 'rolled_back'], true))->count();
            $measured = $group->filter(fn ($row) => is_array($row['outcome']))->count();

            return [
                'action' => $key,
                'runs' => $group->count(),
                'successfulRuns' => $completedRuns,
                'failureRate' => $group->count() > 0 ? round($failedRuns / $group->count(), 4) : null,
                'averageDurationHours' => $this->averageDurationHours(array_map(fn ($row) => (object) [
                    'started_date' => $row['started'],
                    'completed_date' => $row['outcome']['createdDate'] ?? null,
                ], $group->all())),
                'outcomeSuccessRate' => $group->count() > 0 ? round($measured / $group->count(), 4) : null,
                'lastRun' => collect($group)->pluck('started')->filter()->sortDesc()->first(),
            ];
        })->values()->take(8)->all();

        $pageRows = $filteredExecutions->values()->slice(($page - 1) * $pageSize, $pageSize)->values()->all();

        $outcomeLoop = $completed->take(8)->map(function ($execution) use ($outcomesByDecision, $learningsByOutcome) {
            $outcome = $outcomesByDecision->get((string) $execution->decision_id, collect())->sortByDesc('created_date')->first();
            $learningRows = $outcome ? $learningsByOutcome->get((string) $outcome->id, collect()) : collect();

            return [
                'executionId' => (string) $execution->id,
                'status' => (string) $execution->status,
                'outcome' => $outcome ? (string) $outcome->result : 'Outcome not measured',
                'targetVsActual' => $outcome ? $this->decodeJsonObject($outcome->metrics) : null,
                'learningCount' => $learningRows->count(),
                'reusableLearningCount' => $learningRows->filter(fn ($row) => (bool) ($row->reusable ?? false))->count(),
            ];
        })->values()->all();

        return response()->json([
            'organization' => $organization,
            'generatedAt' => gmdate('Y-m-d H:i:s'),
            'freshness' => $this->latestTimestamp([
                $organization['updatedDate'] ?? null,
                $this->latestFromCollection($executions->all(), ['completed_date', 'started_date', 'created_date']),
                $this->latestFromCollection($outcomes->all(), ['created_date']),
            ]),
            'summary' => [
                'approvedDecisions' => $approvedDecisions->count(),
                'queuedExecutions' => $queued->count(),
                'runningExecutions' => $running->count(),
                'completedExecutions' => $completed->count(),
                'failedExecutions' => $failed->count(),
                'rolledBackExecutions' => $rolledBack->count(),
                'averageExecutionHours' => $averageExecutionHours,
                'outcomeMeasurementRate' => $outcomeMeasurementRate,
                'successRate' => $successRate,
                'blockedExecutions' => $blocked->count(),
                'approvedDecisionsWithoutExecution' => $executionQueue->count(),
            ],
            'pipeline' => [
                ['label' => 'Approved', 'count' => $approvedDecisions->count()],
                ['label' => 'Queued', 'count' => $queued->count()],
                ['label' => 'Running', 'count' => $running->count()],
                ['label' => 'Completed', 'count' => $completed->count()],
                ['label' => 'Outcome Measured', 'count' => $measuredOutcomes->count()],
            ],
            'funnel' => [
                ['label' => 'Recommendations', 'count' => $recommendations->count()],
                ['label' => 'Approved Decisions', 'count' => $approvedDecisions->count()],
                ['label' => 'Executions Created', 'count' => $executions->count()],
                ['label' => 'Executions Started', 'count' => $executions->filter(fn ($row) => !empty($row->started_date))->count()],
                ['label' => 'Executions Completed', 'count' => $completed->count()],
                ['label' => 'Outcomes Measured', 'count' => $outcomes->count()],
                ['label' => 'Outcomes Met', 'count' => $outcomes->filter(fn ($row) => strtolower((string) $row->result) === 'success')->count()],
            ],
            'activeExecutions' => [
                'items' => $pageRows,
                'total' => $filteredExecutions->count(),
                'page' => $page,
                'pageSize' => $pageSize,
                'filter' => $status,
            ],
            'executionQueue' => [
                'items' => $executionQueue->take(10)->values()->all(),
                'total' => $executionQueue->count(),
            ],
            'health' => [
                'successRate' => $successRate,
                'failureRate' => $successRate === null ? null : round(1 - $successRate, 4),
                'averageExecutionHours' => $averageExecutionHours,
                'blockedExecutions' => $blocked->count(),
                'overdueExecutions' => $executionRows->filter(fn ($row) => in_array(strtolower((string) $row['status']), ['running', 'blocked'], true) && ($row['durationDays'] ?? 0) > 7)->count(),
                'rollbacks' => $rolledBack->count(),
                'outcomeMeasurementRate' => $outcomeMeasurementRate,
            ],
            'bottlenecks' => [
                'primary' => $bottlenecks->first(),
                'items' => $bottlenecks->all(),
            ],
            'predictedVsRealized' => [
                'items' => $predictedVsRealized->take(8)->all(),
                'predictionAccuracy' => $predictedVsRealized->count() > 0
                    ? round($predictedVsRealized->filter(fn ($row) => abs((float) $row['variance']) <= (abs((float) $row['predicted']) * 0.1))->count() / $predictedVsRealized->count(), 4)
                    : null,
                'totalRealizedValue' => $predictedVsRealized->count() > 0 ? round($predictedVsRealized->sum('realized'), 4) : null,
            ],
            'effectiveness' => $effectiveness,
            'outcomeLoop' => $outcomeLoop,
        ]);
    }

    private function organizationSummary(string $tenant): array
    {
        $organization = $this->resolver->resolve($tenant, 'Organization');
        $row = DB::table($organization->table)
            ->where($organization->tenantKey, $tenant)
            ->whereNull('deleted_at')
            ->first();

        return [
            'id' => $row ? (string) ($row->{$organization->primaryKey} ?? $tenant) : $tenant,
            'tenantId' => $tenant,
            'name' => $row ? (string) ($row->{$organization->field('name')} ?? 'Organization') : 'Organization',
            'industry' => $row && $organization->has('industry') ? ($row->{$organization->field('industry')} ? (string) $row->{$organization->field('industry')} : null) : null,
            'updatedDate' => $row->updated_at ?? null,
        ];
    }

    private function departmentRows(string $tenant)
    {
        if (! $this->resolver->has($tenant, 'OrganizationUnit')) {
            return collect();
        }

        $unit = $this->resolver->resolve($tenant, 'OrganizationUnit');
        $rows = DB::table($unit->table)
            ->where($unit->tenantKey, $tenant)
            ->whereNull('deleted_at')
            ->get();

        return $rows->map(fn ($row) => [
            'id' => (string) $row->{$unit->primaryKey},
            'name' => (string) ($row->{$unit->field('name')} ?? ''),
            'status' => isset($row->{$unit->field('status')}) && (int) $row->{$unit->field('status')} === 1 ? 'active' : 'inactive',
            'headId' => $unit->has('head') ? ($row->{$unit->field('head')} ? (string) $row->{$unit->field('head')} : null) : null,
        ]);
    }

    private function personRows(string $tenant)
    {
        if (! $this->resolver->has($tenant, 'Person')) {
            return collect();
        }

        $person = $this->resolver->resolve($tenant, 'Person');
        $rows = DB::table($person->table)
            ->where($person->tenantKey, $tenant)
            ->whereNull('deleted_at')
            ->where($person->field('status'), 1)
            ->get();

        return $rows->map(fn ($row) => [
            'id' => (string) $row->{$person->primaryKey},
            'departmentId' => $person->has('unit') && !empty($row->{$person->field('unit')}) ? (string) $row->{$person->field('unit')} : null,
            'updatedDate' => $row->updated_at ?? null,
            'createdDate' => $row->created_at ?? null,
        ]);
    }

    private function buildDeliberationPipeline(int $signals, int $evidence, int $cases, int $hypotheses, int $reasoning, int $recommendations, int $decisions, int $pendingRecommendations, int $pendingDecisions): array
    {
        return [
            ['label' => 'Signal', 'count' => $signals, 'pending' => null, 'averageAgeDays' => null, 'conversionRate' => null],
            ['label' => 'Evidence', 'count' => $evidence, 'pending' => null, 'averageAgeDays' => null, 'conversionRate' => $signals > 0 ? round($evidence / $signals, 4) : null],
            ['label' => 'Case', 'count' => $cases, 'pending' => null, 'averageAgeDays' => null, 'conversionRate' => $evidence > 0 ? round($cases / $evidence, 4) : null],
            ['label' => 'Hypothesis', 'count' => $hypotheses, 'pending' => null, 'averageAgeDays' => null, 'conversionRate' => $cases > 0 ? round($hypotheses / $cases, 4) : null],
            ['label' => 'Reasoning', 'count' => $reasoning, 'pending' => null, 'averageAgeDays' => null, 'conversionRate' => $hypotheses > 0 ? round($reasoning / $hypotheses, 4) : null],
            ['label' => 'Recommendation', 'count' => $recommendations, 'pending' => $pendingRecommendations, 'averageAgeDays' => null, 'conversionRate' => $reasoning > 0 ? round($recommendations / $reasoning, 4) : null],
            ['label' => 'Decision', 'count' => $decisions, 'pending' => $pendingDecisions, 'averageAgeDays' => null, 'conversionRate' => $recommendations > 0 ? round($decisions / $recommendations, 4) : null],
        ];
    }

    private function scoreDimension(string $label, ?float $score, string $methodology): array
    {
        return [
            'label' => $label,
            'score' => $score === null ? null : round($score, 4),
            'methodology' => $methodology,
        ];
    }

    private function whereTodayNarrative(?float $healthScore, int $openRisks, int $pendingDecisions, ?float $outcomeMeasurementRate): string
    {
        $healthText = $healthScore === null
            ? 'overall health cannot yet be scored from the available evidence'
            : 'overall health is '.round($healthScore * 100, 1).'%';
        $outcomeText = $outcomeMeasurementRate === null
            ? 'outcome measurement coverage is not yet measurable'
            : 'outcome measurement coverage is '.round($outcomeMeasurementRate * 100, 1).'%';

        return "Today, {$healthText}, with {$openRisks} open risk(s), {$pendingDecisions} pending decision(s), and {$outcomeText}.";
    }

    private function trendSeries(string $tenant, string $metricKey, int $days): array
    {
        $from = now()->subDays($days)->toDateString();
        $rows = DB::table('hpbrain_metric_snapshots')
            ->where('tenant_id', $tenant)
            ->where('metric_key', $metricKey)
            ->where('snapshot_date', '>=', $from)
            ->orderBy('snapshot_date')
            ->get(['snapshot_date', 'value', 'sample_n']);

        if ($rows->count() < 2) {
            return [
                'status' => 'insufficient_data',
                'message' => 'Insufficient historical data',
                'series' => [],
            ];
        }

        return [
            'status' => 'ok',
            'series' => $rows->map(fn ($row) => [
                'date' => (string) $row->snapshot_date,
                'value' => $row->value === null ? null : (float) $row->value,
                'sampleN' => $row->sample_n === null ? null : (int) $row->sample_n,
            ])->all(),
        ];
    }

    private function averageAgeDays(array $timestamps): ?float
    {
        $ages = array_values(array_filter(array_map(fn ($timestamp) => $this->ageInDays($timestamp), $timestamps), fn ($value) => $value !== null));

        return $ages === [] ? null : round(array_sum($ages) / count($ages), 2);
    }

    private function ageInDays(mixed $timestamp): ?int
    {
        if (! is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }

        try {
            return max(0, now('UTC')->diffInDays($timestamp));
        } catch (\Throwable) {
            return null;
        }
    }

    private function averageDurationHours(array $rows): ?float
    {
        $hours = [];
        foreach ($rows as $row) {
            $started = $row->started_date ?? null;
            $completed = $row->completed_date ?? null;
            if (! is_string($started) || ! is_string($completed) || $started === '' || $completed === '') {
                continue;
            }
            try {
                $hours[] = round((strtotime($completed) - strtotime($started)) / 3600, 2);
            } catch (\Throwable) {
                continue;
            }
        }

        return $hours === [] ? null : round(array_sum($hours) / count($hours), 2);
    }

    private function latestTimestamp(array $timestamps): ?string
    {
        $values = array_values(array_filter($timestamps, fn ($value) => is_string($value) && trim($value) !== ''));
        if ($values === []) {
            return null;
        }

        rsort($values);

        return $values[0];
    }

    private function latestFromCollection(array $rows, array $fields): ?string
    {
        $timestamps = [];
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                if (is_array($row) && !empty($row[$field])) {
                    $timestamps[] = (string) $row[$field];
                }
                if (is_object($row) && !empty($row->{$field})) {
                    $timestamps[] = (string) $row->{$field};
                }
            }
        }

        return $this->latestTimestamp($timestamps);
    }

    private function executionProgress(string $status): ?float
    {
        $normalized = strtolower($status);
        if ($normalized === 'completed') {
            return 1.0;
        }
        if ($normalized === 'queued') {
            return 0.1;
        }
        if ($normalized === 'blocked') {
            return 0.5;
        }
        if ($normalized === 'running') {
            return 0.7;
        }
        if ($normalized === 'failed' || $normalized === 'rolled_back') {
            return 1.0;
        }

        return null;
    }

    private function decodeJsonObject(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /** @return array<int, string> */
    private function citationEvidenceIds(mixed $value): array
    {
        if (is_array($value)) {
            $decoded = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
        } else {
            $decoded = [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $decoded,
            fn ($candidate): bool => is_string($candidate) && $candidate !== ''
        )));
    }

    private function numericMetricFromMixed(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return round((float) $value, 4);
        }
        if (is_array($value)) {
            foreach ($value as $candidate) {
                if (is_numeric($candidate)) {
                    return round((float) $candidate, 4);
                }
            }

            return null;
        }
        if (is_string($value) && preg_match('/-?\d+(?:\.\d+)?/', $value, $match) === 1) {
            return round((float) $match[0], 4);
        }

        return null;
    }

    private function groupCollectionCounts(array $rows, string $field): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $key = strtolower((string) ((is_object($row) ? ($row->{$field} ?? null) : ($row[$field] ?? null)) ?: 'unknown'));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    private function priorityRank(string $priority): int
    {
        return match (strtolower($priority)) {
            'critical' => 0,
            'high' => 1,
            'medium' => 2,
            'low' => 3,
            default => 4,
        };
    }

    private function signalTimelineItem(mixed $signal): ?array
    {
        if (! is_object($signal)) {
            return null;
        }

        return [
            'id' => (string) $signal->id,
            'title' => (string) ($signal->classification ?? 'Signal'),
            'status' => (string) ($signal->status ?? 'new'),
            'confidence' => $signal->confidence === null ? null : round((float) $signal->confidence, 4),
            'timestamp' => $signal->updated_date ?? $signal->created_date,
        ];
    }

    private function countIfTableExists(string $table, string $tenant, ?string $field = null, mixed $value = null): ?int
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return null;
        }

        $query = DB::table($table)->where('tenant_id', $tenant);
        if ($field !== null) {
            $query->where($field, $value);
        }

        return $query->count();
    }
}
