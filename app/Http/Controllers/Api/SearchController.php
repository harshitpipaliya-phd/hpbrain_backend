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
    /**
     * `headline` names the column that makes a row recognisable to a human.
     * Without it a result carries only an id, and the Global Search list
     * rendered a column of blank rows — technically correct answers nobody
     * could act on.
     */
    private const SEARCHABLE = [
        'signals'         => ['table' => 'hpbrain_signals',         'fields' => ['classification', 'source'],       'headline' => 'classification'],
        'evidence'        => ['table' => 'hpbrain_evidence',        'fields' => ['content', 'source'],              'headline' => 'source'],
        'cases'           => ['table' => 'hpbrain_cases',           'fields' => ['title'],                          'headline' => 'title'],
        'recommendations' => ['table' => 'hpbrain_recommendations', 'fields' => ['title'],                          'headline' => 'title'],
        'learnings'       => ['table' => 'hpbrain_learnings',       'fields' => ['pattern'],                        'headline' => 'pattern'],
        'capabilities'    => ['table' => 'hpbrain_capabilities',    'fields' => ['name', 'capability_code'],        'headline' => 'name'],
    ];

    /**
     * Each result is flattened to (entityType, id, headline) alongside the full
     * record. GlobalSearch.tsx reads exactly those three fields; the previous
     * {type, record} shape left every one of them undefined, so the screen
     * listed the right number of results with nothing written on them.
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return response()->json(['query' => '', 'count' => 0, 'results' => []]);
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
                $headline = $row->{$spec['headline']} ?? null;

                $results[] = [
                    'type'       => $type,
                    'entityType' => $type,
                    'id'         => (string) $row->id,
                    'headline'   => (string) ($headline !== null && $headline !== '' ? $headline : $row->id),
                    'record'     => $row,
                ];
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

        // hpbrain_outcomes links to the DECISION, not to the execution — there
        // is no eso_execution_id column, and asking for one raised
        // "Unknown column 'eso_execution_id'" the moment a chain reached its
        // first execution. An outcome is the result of the decision; which
        // execution carried it out is recorded on the execution.
        $outcomes = $decisions->isEmpty() ? collect() : DB::table('hpbrain_outcomes')
            ->where('tenant_id', $t)->whereIn('decision_id', $decisions->pluck('id')->all())->get();

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
