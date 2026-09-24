<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Cases\RecommendationCaseContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a recommendation stood on, and what its case knew besides.
 *
 * A SEPARATE CONTROLLER, following CaseSignalEvidenceController exactly. It
 * could have been a method on RecommendationController, and that would put a
 * derived, case-wide view next to the CRUD that creates recommendations —
 * inviting a reader to assume the extra evidence was part of the recommendation.
 * The separation in the response (groundedOn vs caseContext) is the point of
 * this endpoint, and a separate surface makes it harder to lose.
 *
 * READ ONLY. It answers a question and writes nothing. Nothing here is consulted
 * by RECOMMEND or EXPLAIN; both still ground exactly as they do today.
 *
 * TENANT SCOPE IS NOT THIS CONTROLLER'S DECISION. tenantId() returns the value
 * EnsureTenantScope resolved from the authenticated token; the route parameter
 * can narrow to that same tenant but never switch to another one.
 */
final class RecommendationCaseContextController extends Controller
{
    public function __construct(private readonly RecommendationCaseContext $context)
    {
    }

    /**
     * GET recommendations/{tenantId}/{id}/case-context
     *
     * 404 for a recommendation this tenant does not have, which is the same
     * answer one that does not exist gets — a caller must not be able to tell
     * another organization's recommendation id apart from a fictional one.
     */
    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $result = $this->context->forRecommendation($this->tenantId($request), $id);

        return $result === null
            ? response()->json(['error' => 'recommendation_not_found'], 404)
            : response()->json($result);
    }
}
