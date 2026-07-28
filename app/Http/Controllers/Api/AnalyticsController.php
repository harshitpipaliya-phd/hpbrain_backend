<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Analytics are COMPUTED from the loop tables on request. Nothing here is a
 * stored aggregate, so a figure can never drift from the records it summarises.
 */
final class AnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);

        return response()->json([
            'signals'         => DB::table('hpbrain_signals')->where('tenant_id', $t)->count(),
            'evidence'        => DB::table('hpbrain_evidence')->where('tenant_id', $t)->count(),
            'cases'           => DB::table('hpbrain_cases')->where('tenant_id', $t)->count(),
            'recommendations' => DB::table('hpbrain_recommendations')->where('tenant_id', $t)->count(),
            'decisions'       => DB::table('hpbrain_decisions')->where('tenant_id', $t)->count(),
            'outcomes'        => DB::table('hpbrain_outcomes')->where('tenant_id', $t)->count(),
            'learnings'       => DB::table('hpbrain_learnings')->where('tenant_id', $t)->count(),
            'reusableLearnings' => DB::table('hpbrain_learnings')->where('tenant_id', $t)->where('reusable', 1)->count(),
        ]);
    }

    public function executiveSummary(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);

        $avgConfidence = DB::table('hpbrain_reasoning_steps')->where('tenant_id', $t)->avg('confidence_score');
        $outcomes = DB::table('hpbrain_outcomes')->where('tenant_id', $t)
            ->select('result', DB::raw('COUNT(*) as count'))->groupBy('result')->get();

        $total = $outcomes->sum('count');
        $success = (int) ($outcomes->firstWhere('result', 'success')->count ?? 0);

        return response()->json([
            // Null, not zero, when nothing has been reasoned yet — "no data" is
            // a different claim from "average confidence is zero".
            'averageConfidence' => $avgConfidence !== null ? round((float) $avgConfidence, 4) : null,
            'outcomesByResult'  => $outcomes,
            'successRate'       => $total > 0 ? round($success / $total, 4) : null,
            'openCases'         => DB::table('hpbrain_cases')->where('tenant_id', $t)->whereNotIn('status', ['closed'])->count(),
            'pendingDecisions'  => DB::table('hpbrain_decisions')->where('tenant_id', $t)->where('status', 'pending')->count(),
        ]);
    }

    public function decisionIntelligence(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);

        return response()->json([
            'byStatus'      => DB::table('hpbrain_decisions')->where('tenant_id', $t)
                                 ->select('status', DB::raw('COUNT(*) as count'))->groupBy('status')->get(),
            'byExecutor'    => DB::table('hpbrain_decisions')->where('tenant_id', $t)
                                 ->select('executor_type', DB::raw('COUNT(*) as count'))->groupBy('executor_type')->get(),
            'byRootCause'   => DB::table('hpbrain_hypotheses')->where('tenant_id', $t)
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
}
