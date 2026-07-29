<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\DecisionRepository;
use App\Repositories\LearningRepository;
use App\Repositories\OutcomeRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\SignalRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
