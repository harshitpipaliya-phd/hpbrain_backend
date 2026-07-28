<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\LearningRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\SignalRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Intelligence Workspace summary — the composite the SPA's landing screen
 * reads. Mirrors api/src/workspace/workspace.routes.ts.
 */
final class WorkspaceController extends Controller
{
    public function __construct(
        private readonly SignalRepository $signals,
        private readonly RecommendationRepository $recommendations,
        private readonly LearningRepository $learnings,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $learnings = $this->learnings->list($tenantId);

        return response()->json([
            'signals'           => array_slice($this->signals->list($tenantId), 0, 10),
            'recommendations'   => array_slice($this->recommendations->list($tenantId), 0, 10),
            'reusableLearnings' => array_slice(
                array_values(array_filter($learnings, fn ($l) => (bool) ($l['reusable'] ?? false))),
                0, 10
            ),
        ]);
    }
}
