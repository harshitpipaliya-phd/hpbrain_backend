<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Knowledge\OrganizationalMemoryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organizational Memory — the LEARN half of the loop.
 *
 * The Memory screen previously assembled itself in the browser from three
 * calls, one of which was a raw `fetch('/api/v1/events?type=LearningGrounded')`
 * carrying no tenant at all: every tenant's grounding events were fetched and
 * then filtered client-side by id. This endpoint replaces that. Scope is
 * decided here, server-side, from the request's resolved tenant.
 */
final class OrganizationalMemoryController extends Controller
{
    public function index(Request $request, OrganizationalMemoryService $service): JsonResponse
    {
        return response()->json($service->list($this->tenantId($request), [
            'q' => $request->query('q'),
            'domain' => $request->query('domain'),
            'pattern' => $request->query('pattern'),
            'reusable' => $request->query('reusable'),
            'provenance' => $request->query('provenance'),
            'page' => $request->query('page'),
            'pageSize' => $request->query('pageSize'),
        ]));
    }

    public function summary(Request $request, OrganizationalMemoryService $service): JsonResponse
    {
        return response()->json($service->summary($this->tenantId($request)));
    }

    public function show(Request $request, string $tenantId, string $id, OrganizationalMemoryService $service): JsonResponse
    {
        $payload = $service->detail($this->tenantId($request), $id);

        return $payload
            ? response()->json($payload)
            : response()->json(['error' => 'memory_not_found'], 404);
    }
}
