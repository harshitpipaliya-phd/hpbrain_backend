<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiGateway;
use App\Domain\Graph\GraphQueryPort;
use App\Http\Controllers\Concerns\BuildsGraphRagNarrative;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

final class RiskController extends Controller
{
    use BuildsGraphRagNarrative;

    private const TABLE = 'hpbrain_risks';

    /** AiGateway service name — the hpbrain_ai_executions row for this verb is traced under this. */
    private const RISK_GRAPH_NARRATIVE_SERVICE = 'risk_graph_narrative';

    public function index(Request $request): JsonResponse
    {
        $q = DB::table(self::TABLE)->where('tenant_id', $this->tenantId($request));

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return response()->json($q->orderByDesc('created_date')->limit(500)->get());
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = DB::table(self::TABLE)
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)->first();

        return $row ? response()->json($row) : response()->json(['error' => 'risk_not_found'], 404);
    }

    public function assess(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'min:1'],
            'category'    => ['required', 'string'],
            'impact'      => ['required', 'numeric', 'between:0,1'],
            'probability' => ['required', 'numeric', 'between:0,1'],
            'description' => ['nullable', 'string'],
        ]);

        // Score is DERIVED, never supplied. Two risks with the same impact and
        // probability must never carry different scores because a caller
        // asserted one — the same discipline as computed confidence.
        $score = round(((float) $data['impact']) * ((float) $data['probability']), 4);

        return response()->json($this->insertRow($request, [
            'title'       => $data['title'],
            'category'    => $data['category'],
            'impact'      => $data['impact'],
            'probability' => $data['probability'],
            'score'       => $score,
            'description' => $data['description'] ?? null,
            'status'      => 'open',
        ]), 201);
    }

    public function mitigate(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate(['mitigation' => ['required', 'string', 'min:1']]);

        $n = DB::table(self::TABLE)
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)
            ->update(['status' => 'mitigated', 'mitigation' => $data['mitigation']]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'risk_not_found'], 404);
    }

    /**
     * GET risks/{tenantId}/{id}/graph-narrative — "risk factors traversed
     * from the org graph" (Phase 5 GraphRAG, same family as
     * SignalController::explainReasoning() / RecommendationController::
     * reasoningTrail()).
     *
     * RISK IS NOT A GRAPH LABEL. GraphVocabulary::LABEL_FAMILY has no entry
     * for "Risk" — hpbrain_risks is never projected into the graph — so
     * there is no reasoningTrail($tenant, 'Risk', $id) to call. What a
     * registered risk DOES carry is an optional decision_id or
     * recommendation_id (2026_01_01_001200_decision_intelligence.php), and
     * both Decision and Recommendation ARE intelligence-family graph
     * labels. The anchor for "risk factors traversed from the graph" is
     * therefore that linked Decision (preferred, since it is the later,
     * more specific link) or Recommendation — walked with the exact same
     * reasoningTrail()+reasoningTrailDocuments() pair every other
     * narrative endpoint in this family uses, never a bespoke query.
     *
     * A risk with neither link (every DERIVED risk RiskAnalyzer produces,
     * and any registered risk assessed without citing one) has nothing in
     * the graph to traverse at all — `no_evidence` before any
     * GraphQueryPort call is made, not a 404: the risk itself was found.
     */
    public function graphNarrative(Request $request, string $tenantId, string $id, GraphQueryPort $graph, AiGateway $ai): JsonResponse
    {
        $tenant = $this->tenantId($request);

        $risk = DB::table(self::TABLE)->where('tenant_id', $tenant)->where('id', $id)->first();

        if ($risk === null) {
            return response()->json(['error' => 'risk_not_found'], 404);
        }

        if (! empty($risk->decision_id)) {
            $label = 'Decision';
            $anchorId = (string) $risk->decision_id;
        } elseif (! empty($risk->recommendation_id)) {
            $label = 'Recommendation';
            $anchorId = (string) $risk->recommendation_id;
        } else {
            return response()->json([
                'risk' => $risk,
                'anchor' => null,
                'narrative' => null,
                'narrativeStatus' => 'no_evidence',
            ]);
        }

        try {
            $trail = $graph->reasoningTrail($tenant, $label, $anchorId);
        } catch (Throwable $e) {
            return response()->json(['error' => 'graph_unavailable', 'message' => $e->getMessage()], 503);
        }

        $anchor = ['label' => $label, 'id' => $anchorId];

        // The anchor id is stale (its Decision/Recommendation row no longer
        // exists), or it exists but has no onward links of its own. Either
        // way the RISK was found — this is `no_evidence`, not a 404.
        if (($trail['origin']['key'] ?? null) === null || count($trail['trail'] ?? []) <= 1) {
            return response()->json($trail + [
                'risk' => $risk,
                'anchor' => $anchor,
                'narrative' => null,
                'narrativeStatus' => 'no_evidence',
            ]);
        }

        $result = $this->narrateFromDocuments(
            $request,
            $ai,
            $tenant,
            self::RISK_GRAPH_NARRATIVE_SERVICE,
            'You are explaining the risk factors behind one entry in an organization\'s risk register, traced '
                .'through the decision or recommendation it is linked to in the organization\'s intelligence '
                .'graph. Use only the steps and facts given in the retrieved context — never invent a cause, a '
                .'date, or a relationship not present in it.',
            sprintf(
                'Explain the risk factors behind Risk "%s" (category "%s"), by walking the reasoning trail '
                    .'behind the %s it is linked to.',
                $id,
                (string) $risk->category,
                strtolower($label),
            ),
            $this->reasoningTrailDocuments($trail),
        );

        return response()->json($trail + ['risk' => $risk, 'anchor' => $anchor] + $result);
    }

    protected function insertRow(Request $request, array $fields): array
    {
        $row = array_merge($fields, [
            'id'           => Uuid::uuid4()->toString(),
            'tenant_id'    => $this->tenantId($request),
            'created_by'   => $this->actorId($request),
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        DB::table(self::TABLE)->insert($row);

        return $row;
    }
}
