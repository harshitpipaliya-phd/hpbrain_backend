<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiGateway;
use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Domain\Graph\GraphQueryPort;
use App\Http\Controllers\Concerns\BuildsGraphRagNarrative;
use App\Http\Controllers\Controller;
use App\Repositories\SignalRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;
use Throwable;

final class SignalController extends Controller
{
    use BuildsGraphRagNarrative;

    /** AiGateway service name — the hpbrain_ai_executions row for this verb is traced under this. */
    private const REASONING_SERVICE = 'signal_reasoning_explanation';

    public function __construct(
        private readonly SignalRepository $repository,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * The response stays a bare JSON array, as web/src/api/signal.ts consumes it
     * (ADR-007). `since` and `limit` narrow it; omitting both returns everything,
     * exactly as before.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:190'],
            'since'  => ['nullable', 'date'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        return response()->json($this->repository->list(
            $this->tenantId($request),
            $data['status'] ?? null,
            isset($data['since']) ? date('Y-m-d H:i:s', strtotime((string) $data['since'])) : null,
            isset($data['limit']) ? (int) $data['limit'] : null,
        ));
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

    /**
     * GET signals/{tenantId}/{id}/reasoning — "why was this signal raised?"
     * (Phase 5 GraphRAG, roadmap doc 5.4).
     *
     * Retrieval is GraphQueryPort::reasoningTrail() — the same bounded
     * Signal→Evidence→Case→Hypothesis→Recommendation→Decision walk
     * GraphController::trail() exposes read-only — resolved through the
     * interface, never the concrete GraphProjection. Generation is
     * AiGateway::completeWithRag(), told to use only what the trail
     * returned. A signal with no onward trail (no evidence, no case, no
     * recommendation yet linked) is `no_evidence`: there is nothing to
     * explain beyond the bare signal, and calling the model on one fact
     * asking "why" would invite it to guess.
     */
    public function explainReasoning(Request $request, string $tenantId, string $id, GraphQueryPort $graph, AiGateway $ai): JsonResponse
    {
        $tenant = $this->tenantId($request);

        try {
            $trail = $graph->reasoningTrail($tenant, 'Signal', $id);
        } catch (Throwable $e) {
            return response()->json(['error' => 'graph_unavailable', 'message' => $e->getMessage()], 503);
        }

        if (($trail['origin']['key'] ?? null) === null) {
            return response()->json(['error' => 'signal_not_found'], 404);
        }

        if (count($trail['trail'] ?? []) <= 1) {
            return response()->json($trail + ['narrative' => null, 'narrativeStatus' => 'no_evidence']);
        }

        $result = $this->narrateFromDocuments(
            $request,
            $ai,
            $tenant,
            self::REASONING_SERVICE,
            'You are explaining why one signal was raised in an organization\'s intelligence graph, and what '
                .'happened to it afterwards, to someone deciding whether to act. Use only the steps and facts '
                .'given in the retrieved context — never invent a cause, a date, or a relationship not present '
                .'in it.',
            sprintf('Explain why Signal "%s" was raised, and summarize the reasoning trail that followed from it.', $id),
            $this->reasoningTrailDocuments($trail),
        );

        return response()->json($trail + $result);
    }

    public function generate(Request $request): JsonResponse
    {
        $tenant = $this->tenantId($request);

        // Resolved from the container: SignalGenerator needs the EntityResolver
        // as well as the publisher, and constructing it by hand here meant every
        // new dependency became a second edit in an unrelated file.
        $result = app(\App\Domain\Signals\RuleEvaluator::class)->evaluate($tenant);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
