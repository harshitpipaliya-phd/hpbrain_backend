<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Operations\IntelligenceLoopMetrics;
use App\Domain\Operations\OperationalIntelligence;
use App\Domain\Operations\OperationalNarrator;
use App\Domain\Operations\OrganizationScorecard;
use App\Domain\Organization\FoundationCounts;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The derived operational intelligence, one endpoint per screen.
 *
 * MODULAR RATHER THAN ONE PAYLOAD, for the same reason
 * OrganizationIntelligenceController is: the composition is expensive and is done
 * once behind a fingerprinted cache, but the Signals screen has no use for the
 * eighteen-month trend series and shipping it costs bytes on every poll.
 *
 * TENANT SCOPE IS NOT THIS CONTROLLER'S DECISION. `tenantId()` reads the value
 * EnsureTenantScope resolved from the authenticated token. A route parameter can
 * narrow to that same tenant and can never switch to another one, including for an
 * admin. Nothing below accepts a tenant from the query string.
 */
final class OperationalIntelligenceController extends Controller
{
    public function __construct(
        private readonly OperationalIntelligence $operations,
        private readonly IntelligenceLoopMetrics $loop,
        private readonly OrganizationScorecard $scorecard,
        private readonly OperationalNarrator $narrator,
        private readonly FoundationCounts $foundation,
    ) {
    }

    /**
     * GET /operations/{tenantId}/overview — the executive summary.
     *
     * The one endpoint the organization landing screen reads: headline counts, the
     * scorecard, the narrative findings and the lifecycle stages, consistent with
     * each other because they are composed from one computation.
     */
    public function overview(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $fresh = $request->boolean('fresh');

        $ops = $this->operations->forTenant($tenantId, $fresh);
        $loop = $this->loop->forTenant($tenantId);
        $scorecard = $this->scorecard->forTenant($tenantId, $fresh);
        $foundation = $this->foundation->forTenant($tenantId);

        return response()->json([
            'tenantId' => $tenantId,
            'dataVersion' => $ops['dataVersion'],
            'computedAt' => $ops['computedAt'],
            'computeMs' => $ops['computeMs'],
            'stale' => $ops['stale'] ?? null,
            'available' => $ops['available'],
            'reason' => $ops['reason'],
            /*
              THE HEADLINE BAND. Every tile carries the number AND where it came
              from, because "Departments 13" from the HR register and
              "Departments 8" from the operational records are both true and a
              reader shown one of them alone will assume the other is wrong.
            */
            'headline' => [
                'departments' => [
                    'value' => $foundation['departments']['active'],
                    'label' => 'Departments',
                    'detail' => 'active units on the register',
                ],
                'people' => [
                    'value' => $foundation['people']['total'],
                    'label' => 'People',
                    'detail' => 'active staff on the register',
                ],
                'operationalRecords' => [
                    'value' => $ops['totals']['records'],
                    'label' => 'Operational Records',
                    'detail' => $ops['totals']['datasets'].' datasets under analysis',
                ],
                'datasets' => [
                    'value' => $ops['totals']['datasets'],
                    'label' => 'Datasets',
                    'detail' => $ops['totals']['largestDataset'] === null
                        ? 'no dataset ingested'
                        : 'largest is '.$ops['totals']['largestDataset'],
                ],
                'intelligenceHealth' => [
                    'value' => $scorecard['overall'],
                    'label' => 'Intelligence Health',
                    'detail' => $scorecard['overall'] === null
                        ? 'not measurable from connected sources'
                        : $scorecard['measuredDimensions'].' measured dimensions',
                    'band' => $scorecard['band'],
                    'unit' => 'percent',
                ],
                'operationalHealth' => [
                    'value' => ($ops['execution']['supported'] ?? false)
                        ? (int) round(((float) $ops['execution']['completionRate']) * 100)
                        : null,
                    'label' => 'Operational Health',
                    'detail' => ($ops['execution']['supported'] ?? false)
                        ? number_format((int) $ops['execution']['completed']).' of '.number_format((int) $ops['execution']['classified']).' complete'
                        : (string) ($ops['execution']['reason'] ?? 'not measurable from connected sources'),
                    'unit' => 'percent',
                ],
            ],
            'scorecard' => $scorecard,
            'insights' => $this->narrator->narrate($ops, $loop, $scorecard),
            'lifecycle' => $loop['stages'],
            'execution' => $ops['execution'],
            'service' => $ops['service'],
            'responsiveness' => $ops['responsiveness'],
            'rankings' => $ops['rankings'],
            'support' => $ops['support'],
            'trend' => [
                'supported' => $ops['trend']['supported'],
                'reason' => $ops['trend']['reason'],
                'points' => $ops['trend']['points'],
                'momentum' => $ops['trend']['momentum'],
                'busiestMonth' => $ops['trend']['busiestMonth'] ?? null,
            ],
            'derivation' => $ops['derivation'],
        ]);
    }

    /**
     * GET /operations/{tenantId}/datasets — what was ingested, and what each
     * dataset can and cannot support.
     */
    public function datasets(Request $request): JsonResponse
    {
        $ops = $this->operations->forTenant($this->tenantId($request), $request->boolean('fresh'));

        return response()->json([
            'tenantId' => $ops['tenantId'],
            'dataVersion' => $ops['dataVersion'],
            'available' => $ops['available'],
            'reason' => $ops['reason'],
            'totals' => $ops['totals'],
            'support' => $ops['support'],
            'datasets' => $ops['datasets'],
            'derivation' => $ops['derivation'],
        ]);
    }

    /**
     * GET /operations/{tenantId}/departments — every unit's operational activity,
     * ranked against the organization.
     */
    public function departments(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $ops = $this->operations->forTenant($tenantId, $request->boolean('fresh'));

        $departments = $ops['departments'];
        $rates = array_values(array_filter(array_column($departments, 'completionRate'), fn ($r) => $r !== null));
        $average = $rates === [] ? null : array_sum($rates) / count($rates);

        /*
          RELATIVE POSITION, STATED IN POINTS RATHER THAN AS A RANK ALONE.

          "#3 of 13" says nothing about the gap. A unit three points below the
          organization average and one forty points below both rank third, and only
          one of them is a finding. Both are published.
        */
        foreach ($departments as $i => $department) {
            $departments[$i]['relative'] = $department['completionRate'] === null || $average === null
                ? [
                    'supported' => false,
                    'reason' => 'This unit has too few records with a resolvable status for a comparable rate.',
                ]
                : [
                    'supported' => true,
                    'pointsVsAverage' => round((((float) $department['completionRate']) - $average) * 100, 1),
                    'organizationAverage' => round($average, 4),
                    'statement' => $this->relativeStatement((float) $department['completionRate'], $average),
                ];
        }

        return response()->json([
            'tenantId' => $tenantId,
            'dataVersion' => $ops['dataVersion'],
            'supported' => $ops['support']['department'] ?? false,
            'reason' => $ops['support']['reasons']['department'] ?? null,
            'departments' => $departments,
            'organizationAverageCompletionRate' => $average === null ? null : round($average, 4),
            'concentration' => $ops['rankings']['concentration']['departments'] ?? null,
            'derivation' => $ops['derivation'],
        ]);
    }

    /**
     * GET /operations/{tenantId}/loop — signals, evidence, cases and the
     * lifecycle stages, with the reason each empty stage is empty.
     */
    public function loop(Request $request): JsonResponse
    {
        return response()->json($this->loop->forTenant($this->tenantId($request)));
    }

    /**
     * GET /operations/{tenantId}/trends — the plotted series, for the analytics
     * screen.
     */
    public function trends(Request $request): JsonResponse
    {
        $ops = $this->operations->forTenant($this->tenantId($request), $request->boolean('fresh'));

        return response()->json([
            'tenantId' => $ops['tenantId'],
            'dataVersion' => $ops['dataVersion'],
            'trend' => $ops['trend'],
            'rankings' => $ops['rankings'],
            'departments' => array_map(
                fn ($d) => ['label' => $d['label'], 'records' => $d['records'], 'share' => $d['share'], 'trend' => $d['trend'], 'momentum' => $d['momentum']],
                $ops['departments'],
            ),
            'byDataset' => array_map(
                fn ($d) => [
                    'label' => $d['label'],
                    'records' => $d['records'],
                    'share' => $d['share'],
                    'completionRate' => $d['execution']['completionRate'],
                    'cancellationRate' => $d['execution']['cancellationRate'],
                    'backlog' => $d['execution']['backlog'],
                    'averageTurnaroundHours' => $d['turnaround']['averageHours'] ?? null,
                    'repeatRate' => $d['recurrence']['repeatRate'] ?? null,
                    'trend' => $d['trend'],
                    'momentum' => $d['momentum'],
                    'categories' => $d['categories'],
                ],
                $ops['datasets'],
            ),
            'support' => $ops['support'],
            'derivation' => $ops['derivation'],
        ]);
    }

    /**
     * GET /operations/{tenantId}/scorecard — the score and its arithmetic alone.
     */
    public function scorecard(Request $request): JsonResponse
    {
        return response()->json($this->scorecard->forTenant($this->tenantId($request), $request->boolean('fresh')));
    }

    private function relativeStatement(float $rate, float $average): string
    {
        $points = round(($rate - $average) * 100, 1);

        if (abs($points) < 1) {
            return 'In line with the organization average.';
        }

        return $points > 0
            ? 'Above the organization average by '.$points.' points.'
            : 'Below the organization average by '.abs($points).' points.';
    }
}
