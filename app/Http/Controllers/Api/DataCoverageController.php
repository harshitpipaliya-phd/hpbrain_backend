<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ingestion\DataCoverageService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET operations/{tenantId}/coverage — how much of this tenant's data the
 * Brain actually sees, along five separate axes. See DataCoverageService for
 * why they are kept separate rather than blended into one percentage.
 *
 * TENANT SCOPE IS NOT THIS CONTROLLER'S DECISION, same as
 * OperationalIntelligenceController beside it: `tenantId()` reads the value
 * EnsureTenantScope already resolved from the authenticated token.
 */
final class DataCoverageController extends Controller
{
    public function __construct(private readonly DataCoverageService $coverage)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->coverage->forTenant($this->tenantId($request), $request->boolean('fresh')));
    }
}
