<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\OnboardingSessionRepository;
use App\Repositories\ReadinessCheckRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingSessionRepository $sessionRepository,
        private readonly ReadinessCheckRepository $readinessRepository,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $orgId = $request->query('orgId');

        return response()->json($this->sessionRepository->list($tenantId, $orgId));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->sessionRepository->find($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'onboarding_session_not_found'], 404);
    }

    public function start(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate([
            'org_id'      => ['nullable', 'string'],
            'initial_data'=> ['nullable', 'array'],
        ]);

        $data['started_by'] = $this->actorId($request);
        $data['tenant_id'] = $tenantId;

        $session = app(\App\Services\OnboardingEngine::class)->startOnboarding($tenantId, $data);

        return response()->json($session, 201);
    }

    public function completeStep(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'step' => ['required', 'integer'],
            'data' => ['nullable', 'array'],
        ]);

        $session = app(\App\Services\OnboardingEngine::class)->completeStep($id, (string) $data['step'], $data['data'] ?? []);

        return $session ? response()->json($session) : response()->json(['error' => 'onboarding_session_not_found'], 404);
    }

    public function getNextStep(Request $request, string $tenantId, string $id): JsonResponse
    {
        $step = app(\App\Services\OnboardingEngine::class)->getNextStep($id);

        return response()->json($step);
    }

    public function validateStep(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'step' => ['required', 'integer'],
        ]);

        $result = app(\App\Services\OnboardingEngine::class)->validateStep($id, (string) $data['step']);

        return response()->json($result);
    }

    public function activate(Request $request, string $tenantId, string $id): JsonResponse
    {
        $session = app(\App\Services\OnboardingEngine::class)->activateOrganization($id);

        return $session ? response()->json($session) : response()->json(['error' => 'onboarding_session_not_found'], 404);
    }

    public function readiness(Request $request, string $tenantId, string $id): JsonResponse
    {
        $status = app(\App\Services\OnboardingEngine::class)->getReadinessStatus($id);

        return response()->json($status);
    }

    public function runReadinessChecks(Request $request, string $tenantId, string $id): JsonResponse
    {
        $results = app(\App\Services\OnboardingEngine::class)->runReadinessChecks($id);

        return response()->json($results);
    }

    public function abandon(Request $request, string $tenantId, string $id): JsonResponse
    {
        $session = app(\App\Services\OnboardingEngine::class)->abandonOnboarding($id);

        return $session ? response()->json($session) : response()->json(['error' => 'onboarding_session_not_found'], 404);
    }
}
