<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Domain\Reasoning\ReasoningService;
use App\Http\Controllers\Controller;
use App\Repositories\EvidenceRepository;
use App\Repositories\ReasoningStepRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

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
        private readonly EventPublisher $events,
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

        $row = [
            'id'               => Uuid::uuid4()->toString(),
            'tenant_id'        => $tenant,
            'signal_id'        => $data['signalId'],
            'case_id'          => $data['caseId'] ?? null,
            'step_order'       => count($existing) + 1,
            'description'      => $data['description'],
            'confidence_score' => $confidence,
            'created_by'       => $this->actorId($request),
        ];

        // Golden path stages (6–7). The payload carries the evidence this step
        // was grounded on, because the confidence above is a function of those
        // rows: without them the number is unexplainable after the fact, and
        // "why did it believe that?" is the question the event log exists to
        // answer.
        //
        // correlation_id is the SIGNAL — no decision exists yet, so the signal
        // is still the thread.
        $this->events->publishInTransaction(
            LoopEvent::DELIBERATED,
            $tenant,
            'ReasoningStep',
            $this->actorId($request),
            [
                'reasoningStepId' => $row['id'],
                'signalId'        => $row['signal_id'],
                'caseId'          => $row['case_id'],
                'stepOrder'       => $row['step_order'],
                'confidenceScore' => $confidence,
                'evidenceIds'     => array_values(array_map(fn ($e) => (string) $e['id'], $evidence)),
            ],
            fn () => ['entityId' => $row['id'], 'result' => $this->repository->insert($row)],
            correlationId: $row['signal_id'],
        );

        return response()->json($this->repository->findById($tenant, $row['id']), 201);
    }
}
