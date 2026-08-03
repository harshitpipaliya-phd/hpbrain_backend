<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Http\Controllers\Controller;
use App\Repositories\SignalRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

final class SignalController extends Controller
{
    public function __construct(
        private readonly SignalRepository $repository,
        private readonly EventPublisher $events,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->repository->list($this->tenantId($request), $request->query('status')));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->findById($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'signal_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source'         => ['required', 'string', 'max:190'],
            'classification' => ['required', 'string', 'max:190'],
            'priority'       => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'severity'       => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'confidence'     => ['nullable', 'numeric', 'between:0,1'],
            'metadata'       => ['nullable', 'array'],
        ]);

        $tenant = $this->tenantId($request);

        // The id is generated here rather than left to the repository because
        // the event's entity_id, correlation_id and idempotency key all depend
        // on it, and they are decided before the row is written.
        $row = [
            'id'             => Uuid::uuid4()->toString(),
            'tenant_id'      => $tenant,
            'source'         => $data['source'],
            'classification' => $data['classification'],
            'priority'       => $data['priority'] ?? 'medium',
            'severity'       => $data['severity'] ?? 'medium',
            'confidence'     => $data['confidence'] ?? null,
            'metadata'       => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'status'         => 'new',
            'created_by'     => $this->actorId($request),
        ];

        // Golden path stages (2–3): something was noticed. This event STARTS
        // the thread, so correlation_id defaults to the signal's own id —
        // evidence and reasoning inherit it until a decision exists.
        $this->events->publishInTransaction(
            LoopEvent::OBSERVATION_MADE,
            $tenant,
            'Signal',
            $this->actorId($request),
            [
                'signalId'       => $row['id'],
                'source'         => $row['source'],
                'classification' => $row['classification'],
                'priority'       => $row['priority'],
                'severity'       => $row['severity'],
            ],
            fn () => ['entityId' => $row['id'], 'result' => $this->repository->insert($row)],
        );

        return response()->json($this->repository->findById($tenant, $row['id']), 201);
    }

    public function changeStatus(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['new', 'triaged', 'investigating', 'resolved', 'dismissed'])],
        ]);

        $row = $this->repository->updateFields($this->tenantId($request), $id, ['status' => $data['status']]);

        return $row ? response()->json($row) : response()->json(['error' => 'signal_not_found'], 404);
    }

    public function generate(Request $request): JsonResponse
    {
        $tenant = $this->tenantId($request);

        $generator = new \App\Domain\Signals\SignalGenerator($this->events);
        $result = $generator->evaluate($tenant);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
