<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Industry\Vocabulary;
use App\Domain\School\FeeIntelligenceService;
use App\Domain\Signals\RuleCauseMetadata;
use App\Domain\Universal\EntityResolver;
use App\Http\Controllers\Controller;
use App\Repositories\DecisionRepository;
use App\Repositories\LearningRepository;
use App\Repositories\OutcomeRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\SignalRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Intelligence Workspace summary — the composite the SPA's landing screen
 * reads. Mirrors api/src/workspace/workspace.routes.ts.
 *
 * The screen reads data.counts.signals, data.pendingRecommendations,
 * data.recentSignals, data.recentDecisions, data.recentOutcomes and
 * data.reusableLearnings. This endpoint answered with three top-level lists
 * and no counts at all, so `data.counts.signals` threw before the first tile
 * rendered and the whole workspace came up blank.
 *
 * Fields are emitted in camelCase and never null where the client calls a
 * string method on them — recentDecisions does rationale.slice(0, 60), which a
 * null rationale (the column is nullable) turns into a second blank screen.
 */
final class WorkspaceController extends Controller
{
    /** How many rows each "recent" list carries. The screen shows a short list. */
    private const RECENT = 10;

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly SignalRepository $signals,
        private readonly RecommendationRepository $recommendations,
        private readonly LearningRepository $learnings,
        private readonly DecisionRepository $decisions,
        private readonly OutcomeRepository $outcomes,
        private readonly RuleCauseMetadata $ruleCauseMetadata,
        private readonly FeeIntelligenceService $feeIntelligence,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $pending = DB::table('hpbrain_recommendations')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'proposed'])
            ->orderByDesc('created_date')
            ->limit(self::RECENT)
            ->get();

        $recentSignals = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_date')
            ->limit(self::RECENT)
            ->get();

        $recentDecisions = DB::table('hpbrain_decisions')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_date')
            ->limit(self::RECENT)
            ->get();

        $recentOutcomes = DB::table('hpbrain_outcomes')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_date')
            ->limit(self::RECENT)
            ->get();

        $reusable = DB::table('hpbrain_learnings')
            ->where('tenant_id', $tenantId)
            ->where('reusable', 1)
            ->orderByDesc('created_date')
            ->limit(self::RECENT)
            ->get();

        $signalCount = DB::table('hpbrain_signals')->where('tenant_id', $tenantId)->count();
        $recommendationCount = DB::table('hpbrain_recommendations')->where('tenant_id', $tenantId)->count();
        $decisionCount = DB::table('hpbrain_decisions')->where('tenant_id', $tenantId)->count();
        $outcomeCount = DB::table('hpbrain_outcomes')->where('tenant_id', $tenantId)->count();
        $learningCount = DB::table('hpbrain_learnings')->where('tenant_id', $tenantId)->count();

        return response()->json([
            'tenantId' => $tenantId,
            'counts'   => [
                'signals'         => $signalCount,
                'recommendations' => $recommendationCount,
                'decisions'       => $decisionCount,
                'outcomes'        => $outcomeCount,
                'learnings'       => $learningCount,
            ],
            'pendingRecommendations' => $pending->map(fn ($r) => [
                'id'         => (string) $r->id,
                'title'      => (string) ($r->title ?? ''),
                'category'   => (string) ($r->category ?? 'uncategorised'),
                'confidence' => $r->confidence === null ? 0.0 : (float) $r->confidence,
                'priority'   => (string) ($r->priority ?? 'medium'),
                'status'     => (string) ($r->status ?? 'pending'),
            ])->values(),

            'recentSignals' => $recentSignals->map(fn ($s) => [
                'id'          => (string) $s->id,
                'source'      => (string) ($s->source ?? 'unknown'),
                'severity'    => (string) ($s->severity ?? 'unknown'),
                'status'      => (string) ($s->status ?? 'unknown'),
                'createdDate' => $s->created_date ?? null,
            ])->values(),

            'recentDecisions' => $recentDecisions->map(fn ($d) => [
                'id'           => (string) $d->id,
                'executorType' => (string) ($d->executor_type ?? 'unassigned'),
                'rationale'    => (string) ($d->rationale ?? ''),
                'createdDate'  => $d->created_date ?? null,
            ])->values(),

            'recentOutcomes' => $recentOutcomes->map(fn ($o) => [
                'id'          => (string) $o->id,
                'result'      => (string) ($o->result ?? 'unknown'),
                'confidence'  => $o->confidence === null ? 0.0 : (float) $o->confidence,
                'createdDate' => $o->created_date ?? null,
            ])->values(),

            'reusableLearnings' => $reusable->map(fn ($l) => [
                'id'         => (string) $l->id,
                'pattern'    => (string) ($l->pattern ?? ''),
                'confidence' => $l->confidence === null ? 0.0 : (float) $l->confidence,
            ])->values(),

            'signals'         => $recentSignals->map(fn ($s) => [
                'id'          => (string) $s->id,
                'source'      => (string) ($s->source ?? 'unknown'),
                'severity'    => (string) ($s->severity ?? 'unknown'),
                'status'      => (string) ($s->status ?? 'unknown'),
                'createdDate' => $s->created_date ?? null,
            ])->values(),
            'recommendations' => $pending->map(fn ($r) => [
                'id'         => (string) $r->id,
                'title'      => (string) ($r->title ?? ''),
                'category'   => (string) ($r->category ?? 'uncategorised'),
                'confidence' => $r->confidence === null ? 0.0 : (float) $r->confidence,
                'priority'   => (string) ($r->priority ?? 'medium'),
                'status'     => (string) ($r->status ?? 'pending'),
            ])->values(),
        ]);
    }

    /**
     * Organization Intelligence Home metrics.
     *
     * Returns ERP-derived counts and Brain-derived intelligence metrics in one
     * call, so the home screen does not need to fan out to multiple endpoints.
     * Every figure is tenant-scoped and computed from real data.
     */
    public function homeMetrics(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        // THREE COUNTS OVER ONE SET OF ROWS, NOT THREE PASSES OVER THE TABLE.
        //
        // These were five separate COUNT queries — three of them scanning
        // exactly the same employee rows under exactly the same predicate, then
        // discarding all but one tally. On a tenant with a large workforce that
        // is the dominant cost of loading the home screen, and it is paid three
        // times over for no additional information.
        //
        // Conditional aggregation asks the same questions in one pass. SUM(CASE
        // ...) rather than COUNT(CASE ...) because it reads the same on MySQL
        // and SQLite, and the suite runs on the latter.
        $person = $this->resolver->resolve($tenantId, 'Person');
        $unit = $this->resolver->resolve($tenantId, 'OrganizationUnit');

        $personUnit = $person->field('unit');
        $personProfile = $person->field('profile');

        $people = DB::table($person->table)
            ->where($person->tenantKey, $tenantId)
            ->where($person->field('status'), 1)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN {$personUnit} IS NULL OR {$personUnit} = 0 THEN 1 ELSE 0 END) as no_department")
            ->selectRaw("SUM(CASE WHEN {$personProfile} IS NULL OR {$personProfile} = 0 THEN 1 ELSE 0 END) as no_profile")
            ->first();

        $activePeople            = (int) ($people->total ?? 0);
        $peopleWithoutDepartment = (int) ($people->no_department ?? 0);
        $peopleWithoutProfile    = (int) ($people->no_profile ?? 0);

        $unitParent = $unit->field('parent');

        $departments = DB::table($unit->table)
            ->where($unit->tenantKey, $tenantId)
            ->where($unit->field('status'), 1)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN {$unitParent} IS NULL OR {$unitParent} = 0 THEN 1 ELSE 0 END) as no_manager")
            ->first();

        $activeDepartments         = (int) ($departments->total ?? 0);
        $departmentsWithoutManager = (int) ($departments->no_manager ?? 0);

        // High-severity signals are a subset of open ones, so the second query
        // re-read rows the first had already counted.
        $signalCounts = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['resolved', 'closed', 'dismissed'])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN severity IN ('high', 'critical') THEN 1 ELSE 0 END) as high")
            ->first();

        $openSignals = (int) ($signalCounts->total ?? 0);
        $highSignals = (int) ($signalCounts->high ?? 0);

        $pendingRecommendations = DB::table('hpbrain_recommendations')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'proposed'])
            ->count();

        $openDecisions = DB::table('hpbrain_decisions')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'proposed'])
            ->count();

        $pipeline = $this->pipelineStatus($tenantId);
        $attention = [];

        // The tenant's own words. A hospital calls them wards and a bank calls
        // them branches; a dashboard that says "department" to both reads as a
        // report about somebody else's organization.
        $vocab = app(Vocabulary::class);
        $person = $vocab->word($tenantId, 'Person');
        $people = $vocab->words($tenantId, 'Person');
        $unit = $vocab->word($tenantId, 'OrganizationUnit');
        $units = $vocab->words($tenantId, 'OrganizationUnit');

        if ($peopleWithoutDepartment > 0) {
            $attention[] = [
                'id' => 'people-without-dept',
                'title' => $vocab->countOf($tenantId, 'Person', $peopleWithoutDepartment)." without a {$unit}",
                'description' => ucfirst($people)." with no {$unit} sit outside every rollup this system produces — "
                    ."headcount, coverage and capability all under-report until it clears.",
                'severity' => 'medium',
                'link' => 'people',
                'metric' => $peopleWithoutDepartment,
                'confidence' => 1.0,
            ];
        }

        if ($departmentsWithoutManager > 0) {
            /*
             * SAY WHAT THIS ACTUALLY COUNTS. The predicate above is
             * `parent_id IS NULL OR parent_id = 0` — units with no parent, which
             * is to say top-level units. It was published as "N without a
             * manager", described as "no assigned leadership", and shown on the
             * organization overview as a leadership gap.
             *
             * It has never measured leadership. This ERP has no department-head
             * column at all — DepartmentController::map() returns `headId => null`
             * unconditionally, with a comment saying the universal 'head' field
             * has nothing behind it here — so no query in this codebase could
             * report on managers even if one tried. The overview was therefore
             * publishing a leadership finding derived from a hierarchy column,
             * about a field the source system does not have.
             *
             * The field name is left alone; renaming it would ripple through
             * every consumer to no benefit. Only the claim is corrected.
             */
            $attention[] = [
                'id' => 'depts-without-manager',
                'title' => $vocab->countOf($tenantId, 'OrganizationUnit', $departmentsWithoutManager).' not under a parent unit',
                'description' => ucfirst($units).' with no parent sit at the top of the structure, so they do not '
                    .'roll up into anything wider. That is expected for a handful and a sign of a flat or '
                    .'unfinished hierarchy when it is most of them.',
                'severity' => 'low',
                'link' => 'departments',
                'metric' => $departmentsWithoutManager,
                'confidence' => 1.0,
            ];
        }

        if ($peopleWithoutProfile > 0) {
            $attention[] = [
                'id' => 'people-without-profile',
                'title' => $vocab->countOf($tenantId, 'Person', $peopleWithoutProfile).' without a role profile',
                'description' => "A {$person} with no profile cannot be assigned permissions, and cannot be "
                    .'measured against what their role requires.',
                'severity' => 'low',
                'link' => 'people',
                'metric' => $peopleWithoutProfile,
                'confidence' => 1.0,
            ];
        }

        if ($highSignals > 0) {
            $attention[] = [
                'id' => 'high-signals',
                'title' => "{$highSignals} high-severity signal(s) require attention",
                'description' => 'Unresolved high or critical severity signals need review.',
                'severity' => 'high',
                'link' => 'signals',
                'metric' => $highSignals,
                'confidence' => 0.9,
            ];
        }

        if ($pendingRecommendations > 0) {
            $attention[] = [
                'id' => 'pending-recommendations',
                'title' => "{$pendingRecommendations} recommendation(s) awaiting decision",
                'description' => 'Recommendations are waiting for approval or rejection.',
                'severity' => 'medium',
                'link' => 'workspace',
                'metric' => $pendingRecommendations,
                'confidence' => 0.95,
            ];
        }

        if ($openDecisions > 0) {
            $attention[] = [
                'id' => 'open-decisions',
                'title' => "{$openDecisions} decision(s) pending",
                'description' => 'Decisions that have been proposed but not yet approved or rejected.',
                'severity' => 'low',
                'link' => 'workspace',
                'metric' => $openDecisions,
                'confidence' => 0.9,
            ];
        }

        if ($attention === []) {
            $attention[] = [
                'id' => 'all-clear',
                'title' => 'No immediate attention items',
                'description' => 'All signals are resolved and records are complete.',
                'severity' => 'low',
                'link' => null,
                'metric' => 0,
                'confidence' => 1.0,
            ];
        }

        return response()->json([
            'tenantId' => $tenantId,
            'erp' => [
                'activePeople' => $activePeople,
                'activeDepartments' => $activeDepartments,
                'peopleWithoutDepartment' => $peopleWithoutDepartment,
                'departmentsWithoutManager' => $departmentsWithoutManager,
                'peopleWithoutProfile' => $peopleWithoutProfile,
            ],
            'intelligence' => [
                'openSignals' => $openSignals,
                'highSignals' => $highSignals,
                'pendingRecommendations' => $pendingRecommendations,
                'openDecisions' => $openDecisions,
            ],
            'pipeline' => $pipeline,
            /*
             * FEE INTELLIGENCE IS OPT-IN, because computing it dominated this
             * endpoint and no mounted screen read it.
             *
             * MEASURED, on the school tenant (27,000 school_fee records):
             *   tbluser aggregate ............ 39 ms
             *   department count .............. 4 ms
             *   open-signal count ............. 6 ms
             *   ten loop COUNT(*) queries .... 52 ms
             *   distinct rule_key ............. 4 ms
             *   FeeIntelligenceService ... 15,286 ms   <-- 99% of the request
             *
             * forTenant() SELECTs every school_fee row including its `payload`
             * JSON blob, then json_decode()s each one in PHP. The whole endpoint
             * measured 16-25 s wall clock, and intermittently blew the 60-second
             * limit outright and returned a FatalError — for the ONE request the
             * organization overview cannot render without. That is the landing
             * screen after login failing, at random, on the largest tenant.
             *
             * The only consumer of this field is OrganizationIntelligenceHome,
             * which App.tsx documents as no longer mounted anywhere. So every
             * visit paid fifteen seconds for a figure nothing displayed.
             *
             * The capability is kept, not deleted — ask for it with
             * `?include=fees` and the payload is identical to before. Callers
             * that need it say so; the overview, which does not, gets its ~105 ms
             * of counts.
             */
            'domainIntelligence' => [
                'fees' => $this->wantsFees($request) ? $this->feeIntelligence->forTenant($tenantId) : null,
            ],
            'attention' => array_slice($attention, 0, 8),
            'dataFreshness' => [
                'erp' => 'live',
                'brain' => now()->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * Whether the caller asked for the expensive school-fee block.
     *
     * `?include=fees`, or a comma-separated list containing it. Absent means no,
     * which is what every mounted screen wants.
     */
    private function wantsFees(Request $request): bool
    {
        $include = $request->query('include');

        if (! is_string($include) || $include === '') {
            return false;
        }

        $requested = array_map('trim', explode(',', strtolower($include)));

        return in_array('fees', $requested, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelineStatus(string $tenantId): array
    {
        $firedRuleKeys = Schema::hasColumn('hpbrain_signals', 'rule_key')
            ? DB::table('hpbrain_signals')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('rule_key')
                ->distinct()
                ->pluck('rule_key')
                ->map(fn ($key) => (string) $key)
                ->values()
                ->all()
            : [];

        $approvedRuleKeys = $this->ruleCauseMetadata->approvedRuleKeys($tenantId);
        $unclassifiedRuleKeys = array_values(array_diff($firedRuleKeys, $approvedRuleKeys));

        $counts = [
            'operationalRecords' => $this->countTenantRows('hpbrain_operational_records', $tenantId),
            'signals' => $this->countTenantRows('hpbrain_signals', $tenantId),
            // Evidence belongs with the rest of the loop counts. It was the one
            // stage the Organization screen had to reconstruct for itself, which
            // it did by listing every evidence row and taking the array length.
            'evidence' => $this->countTenantRows('hpbrain_evidence', $tenantId),
            'firedRuleKeys' => count($firedRuleKeys),
            'cases' => $this->countTenantRows('hpbrain_cases', $tenantId),
            'hypotheses' => $this->countTenantRows('hpbrain_hypotheses', $tenantId),
            'recommendations' => $this->countTenantRows('hpbrain_recommendations', $tenantId),
            'decisions' => $this->countTenantRows('hpbrain_decisions', $tenantId),
            'executions' => $this->countTenantRows('hpbrain_eso_executions', $tenantId),
            'outcomes' => $this->countTenantRows('hpbrain_outcomes', $tenantId),
            'learnings' => $this->countTenantRows('hpbrain_learnings', $tenantId),
        ];

        $stage = 'configuration';
        $blocker = 'No tenant-scoped operational records are available yet.';
        $nextAction = 'Configure and ingest a real dataset.';

        if ($counts['operationalRecords'] > 0) {
            $stage = 'records_ingested';
            $blocker = 'Operational records exist, but no rule-derived signals have fired yet.';
            $nextAction = 'Run the configured row and operational rules for this tenant.';
        }
        if ($counts['signals'] > 0) {
            $stage = 'signals_detected';
            $blocker = 'Signals exist, but no case has been opened yet.';
            $nextAction = 'Open cases for the real fired signals.';
        }
        if ($counts['cases'] > 0) {
            $stage = 'cases_opened';
            $blocker = 'Cases exist; hypothesis and recommendation generation are intentionally not started from this product workflow.';
            $nextAction = 'Review the cases and cited evidence in the Deliberation workspace.';
        }
        if ($counts['cases'] > 0 && count($unclassifiedRuleKeys) === 0 && count($firedRuleKeys) > 0) {
            $stage = 'eligible_for_hypotheses';
            $blocker = 'Rules are approved, but no hypothesis has been proposed yet.';
            $nextAction = 'Run the existing hypothesis proposal pipeline.';
        }
        if ($counts['hypotheses'] > 0) {
            $stage = 'hypotheses_available';
            $blocker = 'Hypotheses exist, but no recommendation has been produced yet.';
            $nextAction = 'Run the existing EXPLAIN to RECOMMEND path for eligible cases.';
        }
        if ($counts['recommendations'] > 0) {
            $stage = 'recommendations_available';
            $blocker = 'Recommendations exist and are waiting for governance decisions.';
            $nextAction = 'Review recommendations in the deliberation workspace.';
        }
        if ($counts['decisions'] > 0) {
            $stage = 'decisions_available';
            $blocker = 'Decisions exist; execution and outcome tracking are the next observable loop steps.';
            $nextAction = 'Create or monitor governed executions for approved decisions.';
        }
        if ($counts['executions'] > 0) {
            $stage = 'execution_active';
            $blocker = 'Executions exist; outcomes have not been recorded yet.';
            $nextAction = 'Record real outcomes when the action has happened.';
        }
        if ($counts['outcomes'] > 0) {
            $stage = 'outcomes_recorded';
            $blocker = 'Outcomes exist; learnings are not yet available.';
            $nextAction = 'Extract reusable learning from real outcomes.';
        }
        if ($counts['learnings'] > 0) {
            $stage = 'learning_available';
            $blocker = null;
            $nextAction = 'Reuse learning in future grounded intelligence.';
        }

        return [
            'stage' => $stage,
            'blocker' => $blocker,
            'nextAction' => $nextAction,
            'counts' => $counts,
            'review' => [
                'firedRuleKeys' => count($firedRuleKeys),
                'approvedRuleKeys' => count(array_intersect($firedRuleKeys, $approvedRuleKeys)),
                'unclassifiedRuleKeys' => count($unclassifiedRuleKeys),
                'unclassified' => array_slice($unclassifiedRuleKeys, 0, 8),
            ],
        ];
    }

    private function countTenantRows(string $table, string $tenantId): int
    {
        return Schema::hasTable($table)
            ? (int) DB::table($table)->where('tenant_id', $tenantId)->count()
            : 0;
    }
}
