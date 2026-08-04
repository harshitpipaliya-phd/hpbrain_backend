<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Industry\Vocabulary;
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
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $signals         = $this->signals->list($tenantId);
        $recommendations = $this->recommendations->list($tenantId);
        $decisions       = $this->decisions->list($tenantId);
        $outcomes        = $this->outcomes->list($tenantId);
        $learnings       = $this->learnings->list($tenantId);

        $pending = array_values(array_filter(
            $recommendations,
            fn ($r) => in_array(strtolower((string) ($r['status'] ?? '')), ['pending', 'proposed'], true)
        ));

        $reusable = array_values(array_filter($learnings, fn ($l) => (bool) ($l['reusable'] ?? false)));

        return response()->json([
            'tenantId' => $tenantId,
            'counts'   => [
                'signals'         => count($signals),
                'recommendations' => count($recommendations),
                'decisions'       => count($decisions),
                'outcomes'        => count($outcomes),
                'learnings'       => count($learnings),
            ],
            'pendingRecommendations' => array_map(fn ($r) => [
                'id'         => (string) $r['id'],
                'title'      => (string) ($r['title'] ?? ''),
                'category'   => (string) ($r['category'] ?? 'uncategorised'),
                'confidence' => $r['confidence'] === null ? 0.0 : (float) $r['confidence'],
                'priority'   => (string) ($r['priority'] ?? 'medium'),
                'status'     => (string) ($r['status'] ?? 'pending'),
            ], array_slice($pending, 0, self::RECENT)),

            'recentSignals' => array_map(fn ($s) => [
                'id'          => (string) $s['id'],
                'source'      => (string) ($s['source'] ?? 'unknown'),
                'severity'    => (string) ($s['severity'] ?? 'unknown'),
                'status'      => (string) ($s['status'] ?? 'unknown'),
                'createdDate' => $s['created_date'] ?? null,
            ], array_slice($signals, 0, self::RECENT)),

            'recentDecisions' => array_map(fn ($d) => [
                'id'           => (string) $d['id'],
                'executorType' => (string) ($d['executor_type'] ?? 'unassigned'),
                'rationale'    => (string) ($d['rationale'] ?? ''),
                'createdDate'  => $d['created_date'] ?? null,
            ], array_slice($decisions, 0, self::RECENT)),

            'recentOutcomes' => array_map(fn ($o) => [
                'id'          => (string) $o['id'],
                'result'      => (string) ($o['result'] ?? 'unknown'),
                'confidence'  => $o['confidence'] === null ? 0.0 : (float) $o['confidence'],
                'createdDate' => $o['created_date'] ?? null,
            ], array_slice($outcomes, 0, self::RECENT)),

            'reusableLearnings' => array_map(fn ($l) => [
                'id'         => (string) $l['id'],
                'pattern'    => (string) ($l['pattern'] ?? ''),
                'confidence' => $l['confidence'] === null ? 0.0 : (float) $l['confidence'],
            ], array_slice($reusable, 0, self::RECENT)),

            // The raw lists the endpoint published before, kept so anything
            // already reading them keeps working.
            'signals'         => array_slice($signals, 0, self::RECENT),
            'recommendations' => array_slice($recommendations, 0, self::RECENT),
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
            $attention[] = [
                'id' => 'depts-without-manager',
                'title' => $vocab->countOf($tenantId, 'OrganizationUnit', $departmentsWithoutManager).' without a manager',
                'description' => ucfirst($units).' with no assigned leadership have nobody accountable for the '
                    .'decisions this system will recommend.',
                'severity' => 'medium',
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
            'attention' => array_slice($attention, 0, 8),
            'dataFreshness' => [
                'erp' => 'live',
                'brain' => now()->format('Y-m-d H:i:s'),
            ],
        ]);
    }
}
