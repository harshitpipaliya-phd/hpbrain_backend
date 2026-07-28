<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reasoning\ReasoningService;
use App\Http\Controllers\Controller;
use App\Repositories\EvidenceRepository;
use App\Repositories\ReasoningStepRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Confidence is computed here from corroborating evidence. The caller supplies
 * a description and a signal; it does NOT supply a confidence score, and any
 * attempt to send one is ignored.
 */
final class ReasoningController extends Controller
{
    public function __construct(
        private readonly ReasoningStepRepository $repository,
        private readonly EvidenceRepository $evidence,
        private readonly ReasoningService $reasoning,
    ) {
    }

    public function forSignal(Request $request, string $tenantId, string $signalId): JsonResponse
    {
        $all = $this->repository->list($this->tenantId($request));

        return response()->json(array_values(array_filter($all, fn ($r) => ($r['signal_id'] ?? null) === $signalId)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'signalId'    => ['required', 'string'],
            'description' => ['required', 'string', 'min:1'],
            'caseId'      => ['nullable', 'string'],
        ]);

        $tenant = $this->tenantId($request);

        $evidence = array_values(array_filter(
            $this->evidence->list($tenant),
            fn ($e) => ($e['signal_id'] ?? null) === $data['signalId']
        ));

        $confidence = $this->reasoning->computeConfidence(array_map(fn ($e) => [
            'confidence'   => (float) ($e['confidence'] ?? 0),
            'observedDate' => (string) ($e['observed_date'] ?? $e['created_date']),
        ], $evidence));

        $existing = array_filter($this->repository->list($tenant), fn ($r) => ($r['signal_id'] ?? null) === $data['signalId']);

        return response()->json($this->repository->insert([
            'tenant_id'        => $tenant,
            'signal_id'        => $data['signalId'],
            'case_id'          => $data['caseId'] ?? null,
            'step_order'       => count($existing) + 1,
            'description'      => $data['description'],
            'confidence_score' => $confidence,
            'created_by'       => $this->actorId($request),
        ]), 201);
    }
}
