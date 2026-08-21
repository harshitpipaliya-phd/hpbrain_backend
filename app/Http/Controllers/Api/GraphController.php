<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Graph\GraphProjection;
use App\Domain\Graph\GraphVocabulary;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Graph queries served from MySQL (ADR-008 — Neo4j deferred for v1).
 *
 * This is the GraphQueryPort seam the ADR describes: entity and relationship
 * reads go through here, so reintroducing Neo4j later means adding an adapter
 * rather than unpicking call sites.
 *
 * TWO GENERATIONS OF ENDPOINT LIVE HERE AND BOTH ARE LOAD-BEARING.
 *
 * `entity` and `related`, with the LABELS map below, are the original loop-table
 * reads. They are untouched: their shapes are what existing clients were written
 * against, and widening them would have broken those callers to no purpose.
 *
 * `overview`, `expand`, `node`, `summary` and `vocabulary` are Graph Explorer's.
 * They go through GraphProjection, which composes the services that already own
 * each answer — OrganizationStructureService for what a department is,
 * FoundationCounts for how many people there are, EntityResolver for which table
 * a tenant keeps them in — rather than querying around them. Nothing here
 * computes intelligence and nothing here writes.
 *
 * `search` now answers from the projection as well, because a search that could
 * only find the nine loop tables could not find the organization, its
 * departments, its staff or its students — every entity a user actually wants to
 * start from. The response envelope is unchanged, and each result still carries
 * `labels` and `properties.id`, so the older consumers keep working.
 *
 * DEEP TRAVERSAL IS STILL NOT ON OFFER, and the original note on that stands.
 * `overview` accepts a depth of at most 3 and every level is bounded by a node
 * budget; `expand` is one hop. Neither walks the graph looking for a path,
 * because a relational approximation of path-finding dressed as graph traversal
 * would mislead callers about what this system can answer.
 *
 * THE TENANT COMES FROM THE TOKEN, NEVER FROM THE PATH. Every method resolves it
 * with tenantId(), which reads what EnsureTenantScope took from the authenticated
 * token. The {tenantId} segment stays in the URL because it is the shape every
 * other route uses, but a caller who edits it is refused by the middleware
 * before anything here runs.
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

    /**
     * GET graph/{tenantId}/search?q=&labels= — entity search across everything
     * the graph can draw.
     *
     * Envelope, not a bare array: both callers read `.results`, and a bare array
     * made that undefined — the explorer then called .map on it and blanked the
     * screen.
     */
    public function search(Request $request, GraphProjection $projection): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return response()->json(['query' => '', 'count' => 0, 'results' => []]);
        }

        $labels = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('labels', '')))));

        return $this->guard(fn (): array => $projection->search($this->tenantId($request), $term, $labels));
    }

    /* ══════════════════════════════════════════════ Graph Explorer reads ══ */

    /**
     * GET graph/{tenantId}/overview — the graph the screen opens on.
     *
     * The caller's own organization, and the branches it genuinely has. An
     * organization with staff and no students has no student branch at all,
     * because a zero would invite the reader to wonder what went wrong with an
     * import that never happened.
     */
    public function overview(Request $request, GraphProjection $projection): JsonResponse
    {
        return $this->guard(fn (): array => $projection->overview(
            $this->tenantId($request),
            (int) $request->query('depth', '1'),
            $this->include($request),
        ));
    }

    /**
     * GET graph/{tenantId}/expand?label=&id=&offset= — one hop from one node.
     *
     * Label and id travel as query parameters rather than path segments because
     * a subject is called "Business Studies" and a standard "CBSE-12": ids here
     * are real values out of the data, not opaque keys, and plenty of them
     * contain characters a path segment mangles.
     */
    public function expand(Request $request, GraphProjection $projection): JsonResponse
    {
        $label = (string) $request->query('label', '');
        $id = (string) $request->query('id', '');

        if ($label === '' || $id === '') {
            return response()->json(['error' => 'label_and_id_required'], 422);
        }

        return $this->guard(fn (): array => $projection->expand(
            $this->tenantId($request),
            $label,
            $id,
            $this->include($request),
            max(0, (int) $request->query('offset', '0')),
        ));
    }

    /** GET graph/{tenantId}/node?label=&id= — what the detail panel renders. */
    public function node(Request $request, GraphProjection $projection): JsonResponse
    {
        $label = (string) $request->query('label', '');
        $id = (string) $request->query('id', '');

        if ($label === '' || $id === '') {
            return response()->json(['error' => 'label_and_id_required'], 422);
        }

        if (! GraphVocabulary::isKnownLabel($label)) {
            return response()->json(['error' => 'unknown_label', 'supported' => array_keys(GraphVocabulary::LABEL_FAMILY)], 422);
        }

        try {
            $detail = $projection->detail($this->tenantId($request), $label, $id);
        } catch (Throwable $e) {
            return $this->failed($e);
        }

        // A miss is a 404 rather than an empty panel, and it is also what a node
        // id belonging to another organization produces: the projection looks it
        // up under THIS tenant and finds nothing.
        return $detail === null
            ? response()->json(['error' => 'entity_not_found', 'label' => $label, 'id' => $id], 404)
            : response()->json($detail);
    }

    /** GET graph/{tenantId}/summary — the metric strip, without the graph. */
    public function summary(Request $request, GraphProjection $projection): JsonResponse
    {
        return $this->guard(fn (): array => ['summary' => $projection->summary($this->tenantId($request))]);
    }

    /**
     * GET graph/{tenantId}/vocabulary — every label and relationship this graph
     * can draw, with the column behind each one.
     *
     * Static, and published so the legend and the relationship filters are
     * generated from the same list the query layer is bound by rather than
     * restated in the client, where the two would drift.
     */
    public function vocabulary(): JsonResponse
    {
        $relationships = [];

        foreach (GraphVocabulary::RELATIONSHIPS as $type => [$label, $family, $provenance]) {
            $relationships[] = ['type' => $type, 'label' => $label, 'family' => $family, 'provenance' => $provenance];
        }

        return response()->json([
            'labels'               => GraphVocabulary::LABEL_FAMILY,
            'relationships'        => $relationships,
            'relationshipFamilies' => GraphVocabulary::RELATIONSHIP_FAMILIES,
        ]);
    }

    /* ─────────────────────────────── plumbing ─────────────────────────── */

    /**
     * Which intelligence branches the caller wants, or all of them.
     *
     * @return array<int, string>
     */
    private function include(Request $request): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $request->query('include', '')))));
    }

    /**
     * Run a read and turn any failure into a graph-shaped error.
     *
     * WHY THIS EXISTS. A tenant with an incomplete entity mapping makes
     * EntityResolver throw, which is correct — it fails closed rather than
     * reading another organization's tables. But an uncaught throw returns a 500
     * with an HTML body, and the screen receiving it shows a blank canvas and no
     * explanation. The failure is reported as data instead, so Graph Explorer
     * renders an honest error state and the rest of the application is
     * unaffected by it.
     *
     * @param  Closure(): array<string, mixed>  $read
     */
    private function guard(Closure $read): JsonResponse
    {
        try {
            return response()->json($read());
        } catch (Throwable $e) {
            return $this->failed($e);
        }
    }

    private function failed(Throwable $e): JsonResponse
    {
        report($e);

        return response()->json([
            'error'     => 'graph_unavailable',
            'message'   => $e->getMessage(),
            'nodes'     => [],
            'edges'     => [],
            'truncated' => [],
            'results'   => [],
        ], 503);
    }
}
