<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Recommendations\RecommendationRepository;
use App\Domain\AiIntelligence\Support\AiAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Recommendation Engine — the approval gate over HP Brain's own recommendations.
 *
 * Ported from G2G's RecommendationController. `show` returns the whole chain —
 * recommendation, reasoning step, evidence — in one call, because an approve
 * button beside an explanation that never loaded is not a decision. Nothing is
 * generated here: the rows were produced by the intelligence loop.
 *
 * HP Brain has no separate recommendation-decision workflow to delegate to (its
 * DecisionController governs hpbrain_decisions, a different record), so a
 * decision here is the status transition plus an audit row, exactly as in G2G —
 * scoped to the caller's tenant only.
 */
final class AiIntelligenceRecommendationController extends AiIntelligenceController
{
    public function __construct(
        private readonly RecommendationRepository $recommendations,
        private readonly AiAuditLogger $audit,
    ) {
    }

    public function pending(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            if (! $this->recommendations->available()) {
                return $this->failure('The recommendation store is not installed on this deployment.', 422);
            }

            return $this->success('Pending recommendations resolved.', [
                'tenant_id' => $tenantId,
                'counts' => $this->recommendations->counts($tenantId),
                'recommendations' => $this->recommendations->pending($tenantId, $this->limit($request, 50, 200)),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            $validated = $request->validate(['status' => 'nullable|string|max:40']);

            return $this->success('Recommendations resolved.', [
                'tenant_id' => $tenantId,
                'status' => $validated['status'] ?? null,
                'counts' => $this->recommendations->counts($tenantId),
                'recommendations' => $this->recommendations->all(
                    $tenantId,
                    $validated['status'] ?? null,
                    $this->limit($request, 100, 200)
                ),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function show(Request $request, string $recommendation): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            $chain = $this->recommendations->explain($recommendation, $tenantId);

            if ($chain['recommendation'] === null) {
                return $this->failure('That recommendation was not found.', 404);
            }

            return $this->success('Recommendation resolved.', $chain + ['tenant_id' => $tenantId]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function approve(Request $request, string $recommendation): JsonResponse
    {
        return $this->decide($request, $recommendation, 'approve');
    }

    public function reject(Request $request, string $recommendation): JsonResponse
    {
        return $this->decide($request, $recommendation, 'reject');
    }

    public function defer(Request $request, string $recommendation): JsonResponse
    {
        return $this->decide($request, $recommendation, 'defer');
    }

    private function decide(Request $request, string $recommendation, string $decision): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            $validated = $request->validate(['note' => 'nullable|string|max:2000']);

            $before = $this->recommendations->find($recommendation, $tenantId);

            if ($before === null) {
                return $this->failure('That recommendation was not found.', 404);
            }

            $outcome = $this->recommendations->decide($recommendation, $tenantId, $decision);

            if (! $outcome['ok']) {
                // 409: the request was well-formed; the recommendation's state is
                // what refuses it (already decided, possibly by someone else).
                return $this->failure($outcome['message'], 409);
            }

            $past = ['approve' => 'approved', 'reject' => 'rejected', 'defer' => 'deferred'][$decision];

            $this->audit->record("ai.recommendation.{$past}", $scope, [
                'related_type' => 'hpbrain_recommendations',
                'related_id' => $recommendation,
                'subject_entity_key' => 'recommendation',
                'subject_id' => $recommendation,
                'message' => sprintf(
                    '%s "%s" (was %s, now %s).',
                    ucfirst($past),
                    $before['title'],
                    $before['status'],
                    $outcome['status']
                ),
                'payload' => [
                    'recommendation_id' => $recommendation,
                    'from' => $before['status'],
                    'to' => $outcome['status'],
                    'confidence' => $before['confidence'],
                    'note' => $validated['note'] ?? null,
                ],
            ]);

            return $this->success($outcome['message'], [
                'recommendation' => $this->recommendations->find($recommendation, $tenantId),
                'counts' => $this->recommendations->counts($tenantId),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }
}
