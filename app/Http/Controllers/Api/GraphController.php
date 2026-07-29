<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Graph queries served from MySQL (ADR-008 — Neo4j deferred for v1).
 *
 * This is the GraphQueryPort seam the ADR describes: entity and relationship
 * reads go through here, so reintroducing Neo4j later means adding an adapter
 * rather than unpicking call sites. Deep traversal is intentionally NOT offered
 * — a shallow relational approximation dressed as graph traversal would mislead
 * callers about what the system can actually answer.
 */
final class GraphController extends Controller
{
    private const LABELS = [
        'Signal'         => 'hpbrain_signals',
        'Evidence'       => 'hpbrain_evidence',
        'Case'           => 'hpbrain_cases',
        'Hypothesis'     => 'hpbrain_hypotheses',
        'Recommendation' => 'hpbrain_recommendations',
        'Decision'       => 'hpbrain_decisions',
        'Outcome'        => 'hpbrain_outcomes',
        'Learning'       => 'hpbrain_learnings',
        'Capability'     => 'hpbrain_capabilities',
    ];

    public function entity(Request $request, string $tenantId, string $label, string $id): JsonResponse
    {
        $table = self::LABELS[$label] ?? null;

        if ($table === null) {
            return response()->json(['error' => 'unknown_label', 'supported' => array_keys(self::LABELS)], 422);
        }

        $row = DB::table($table)->where('tenant_id', $this->tenantId($request))->where('id', $id)->first();

        // `labels` is an array because the graph contract models a node as
        // carrying a set of labels, and GraphExplorer reads node.labels[0].
        // MySQL gives each row exactly one, but the shape has to hold either way.
        return $row
            ? response()->json(['label' => $label, 'labels' => [$label], 'properties' => $row])
            : response()->json(['error' => 'entity_not_found'], 404);
    }

    /** One hop only, along the loop's real foreign keys. */
    public function related(Request $request, string $tenantId, string $label, string $id): JsonResponse
    {
        $table = self::LABELS[$label] ?? null;

        if ($table === null) {
            return response()->json(['error' => 'unknown_label', 'supported' => array_keys(self::LABELS)], 422);
        }

        $t = $this->tenantId($request);
        $edges = [
            'Signal'         => [['Evidence', 'hpbrain_evidence', 'signal_id'], ['Case', 'hpbrain_cases', 'signal_id']],
            'Case'           => [['Hypothesis', 'hpbrain_hypotheses', 'case_id']],
            'Recommendation' => [['Decision', 'hpbrain_decisions', 'recommendation_id']],
            'Decision'       => [['Execution', 'hpbrain_eso_executions', 'decision_id']],
            'Outcome'        => [['Learning', 'hpbrain_learnings', 'outcome_id']],
        ];

        $out = [];

        foreach ($edges[$label] ?? [] as [$relLabel, $relTable, $fk]) {
            foreach (DB::table($relTable)->where('tenant_id', $t)->where($fk, $id)->limit(100)->get() as $row) {
                // A relationship is (type, direction, otherNode) — the shape
                // GraphExplorer navigates by. The flat {label, via, properties}
                // it used to return left r.otherNode undefined, and clicking
                // through to a related entity threw on r.otherNode.labels[0].
                $out[] = [
                    'type'      => $fk,
                    'via'       => $fk,
                    // Every edge here is followed from this node outward: the
                    // related row holds the foreign key pointing back at it.
                    'direction' => 'outgoing',
                    'otherNode' => ['label' => $relLabel, 'labels' => [$relLabel], 'properties' => $row],
                ];
            }
        }

        return response()->json(['label' => $label, 'id' => $id, 'related' => $out, 'depth' => 1]);
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        // Envelope, not a bare array: both callers (GraphExplorer and
        // GlobalSearch) read `.results`, and a bare array made that undefined —
        // the explorer then called .map on it and blanked the screen.
        if ($term === '') {
            return response()->json(['query' => '', 'count' => 0, 'results' => []]);
        }

        $t = $this->tenantId($request);
        $wanted = array_filter(explode(',', (string) $request->query('labels', '')));
        $out = [];

        foreach (self::LABELS as $label => $table) {
            if ($wanted !== [] && ! in_array($label, $wanted, true)) {
                continue;
            }

            $col = match ($label) {
                'Signal' => 'classification', 'Evidence' => 'content',
                'Case', 'Recommendation' => 'title', 'Hypothesis' => 'statement',
                'Learning' => 'pattern', 'Capability' => 'name', default => 'id',
            };

            foreach (DB::table($table)->where('tenant_id', $t)->where($col, 'like', "%{$term}%")->limit(20)->get() as $row) {
                $out[] = ['label' => $label, 'labels' => [$label], 'properties' => $row];
            }
        }

        return response()->json(['query' => $term, 'count' => count($out), 'results' => $out]);
    }
}
