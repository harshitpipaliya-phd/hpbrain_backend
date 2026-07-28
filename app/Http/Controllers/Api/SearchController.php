<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cross-entity search and signal-chain traversal.
 *
 * The chain endpoint is the loop made inspectable: Signal -> Evidence -> Case ->
 * Hypotheses -> Reasoning -> Recommendation -> Decision -> Execution -> Outcome
 * -> Learning. Being able to walk that in one call is what makes "show me why"
 * answerable, which the Product Bible treats as non-negotiable.
 */
final class SearchController extends Controller
{
    private const SEARCHABLE = [
        'signals'         => ['table' => 'hpbrain_signals',         'fields' => ['classification', 'source']],
        'evidence'        => ['table' => 'hpbrain_evidence',        'fields' => ['content', 'source']],
        'cases'           => ['table' => 'hpbrain_cases',           'fields' => ['title']],
        'recommendations' => ['table' => 'hpbrain_recommendations', 'fields' => ['title']],
        'learnings'       => ['table' => 'hpbrain_learnings',       'fields' => ['pattern']],
        'capabilities'    => ['table' => 'hpbrain_capabilities',    'fields' => ['name', 'capability_code']],
    ];

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return response()->json(['query' => '', 'results' => []]);
        }

        $tenant = $this->tenantId($request);
        $wanted = array_filter(explode(',', (string) $request->query('types', '')));
        $results = [];

        foreach (self::SEARCHABLE as $type => $spec) {
            if ($wanted !== [] && ! in_array($type, $wanted, true)) {
                continue;
            }

            $rows = DB::table($spec['table'])->where('tenant_id', $tenant)
                ->where(function ($w) use ($spec, $term) {
                    foreach ($spec['fields'] as $i => $field) {
                        $i === 0 ? $w->where($field, 'like', "%{$term}%")
                                 : $w->orWhere($field, 'like', "%{$term}%");
                    }
                })
                ->limit(25)->get();

            foreach ($rows as $row) {
                $results[] = ['type' => $type, 'record' => $row];
            }
        }

        return response()->json(['query' => $term, 'count' => count($results), 'results' => $results]);
    }

    public function signalChain(Request $request, string $tenantId, string $signalId): JsonResponse
    {
        $t = $this->tenantId($request);

        $signal = DB::table('hpbrain_signals')->where('tenant_id', $t)->where('id', $signalId)->first();

        if (! $signal) {
            return response()->json(['error' => 'signal_not_found'], 404);
        }

        $cases = DB::table('hpbrain_cases')->where('tenant_id', $t)->where('signal_id', $signalId)->get();
        $caseIds = $cases->pluck('id')->all();

        $reasoning = DB::table('hpbrain_reasoning_steps')->where('tenant_id', $t)
            ->where('signal_id', $signalId)->orderBy('step_order')->get();

        $recommendations = $reasoning->isEmpty() ? collect() : DB::table('hpbrain_recommendations')
            ->where('tenant_id', $t)->whereIn('reasoning_step_id', $reasoning->pluck('id')->all())->get();

        $decisions = $recommendations->isEmpty() ? collect() : DB::table('hpbrain_decisions')
            ->where('tenant_id', $t)->whereIn('recommendation_id', $recommendations->pluck('id')->all())->get();

        $executions = $decisions->isEmpty() ? collect() : DB::table('hpbrain_eso_executions')
            ->where('tenant_id', $t)->whereIn('decision_id', $decisions->pluck('id')->all())->get();

        $outcomes = $executions->isEmpty() ? collect() : DB::table('hpbrain_outcomes')
            ->where('tenant_id', $t)->whereIn('eso_execution_id', $executions->pluck('id')->all())->get();

        $learnings = $outcomes->isEmpty() ? collect() : DB::table('hpbrain_learnings')
            ->where('tenant_id', $t)->whereIn('outcome_id', $outcomes->pluck('id')->all())->get();

        return response()->json([
            'signal'          => $signal,
            'evidence'        => DB::table('hpbrain_evidence')->where('tenant_id', $t)->where('signal_id', $signalId)->get(),
            'cases'           => $cases,
            'hypotheses'      => $caseIds === [] ? [] : DB::table('hpbrain_hypotheses')->where('tenant_id', $t)->whereIn('case_id', $caseIds)->get(),
            'reasoning'       => $reasoning,
            'recommendations' => $recommendations,
            'decisions'       => $decisions,
            'executions'      => $executions,
            'outcomes'        => $outcomes,
            'learnings'       => $learnings,
            // Explicit: an incomplete chain is a real state, not an error.
            'loopClosed'      => $learnings->isNotEmpty(),
        ]);
    }
}
