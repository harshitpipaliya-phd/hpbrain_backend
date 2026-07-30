<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Http\Controllers\Controller;
use App\Repositories\DecisionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

/**
 * Response shapes must match the Express originals exactly — web/src/api/*.ts
 * consumes them literally (ADR-007).
 *
 * This controller owns golden-path step 8: the human governance gate. A
 * decision is PROPOSED by whoever records it and APPROVED by someone else, and
 * both halves are written down. Two invariants live here:
 *
 *   Invariant 2 — every decision carries a stated rationale, enforced at the
 *   point of creation rather than trusted to the caller.
 *   Invariant 7 — the proposer is not the approver. A gate you can walk
 *   through yourself is not a gate.
 */
final class DecisionController extends Controller
{
    private const ENTITY = 'Decision';

    public function __construct(
        private readonly DecisionRepository $repository,
        private readonly EventPublisher $events,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->repository->list($this->tenantId($request), $request->query('status'))
        );
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->findById($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'decision_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenantId'               => ['required', 'string', 'min:1'],
            'recommendationId'       => ['required', 'string', 'size:36'],
            // rationale is NOT NULL in the schema, and an unstated reason
            // violates Invariant 2 at the moment the decision is created —
            // the one moment at which the reason is still known.
            'rationale'              => ['required', 'string', 'min:10'],
            'executorType'           => ['nullable', Rule::in(['human', 'ai', 'hybrid'])],
            'alternativesConsidered' => ['nullable', 'array'],
        ]);

        $tenant = $this->tenantId($request);

        // The foreign key on recommendation_id proves the recommendation
        // EXISTS; it says nothing about who owns it. Without this check a
        // caller could anchor their decision to another tenant's
        // recommendation and the database would accept it.
        $ownsRecommendation = DB::table('hpbrain_recommendations')
            ->where('tenant_id', $tenant)
            ->where('id', $data['recommendationId'])
            ->exists();

        if (! $ownsRecommendation) {
            return response()->json(['error' => 'recommendation_not_found'], 422);
        }

        // tenantId always comes from the token, never the body — a client must
        // not be able to write into another tenant by changing a payload field.
        $row = $this->repository->insert([
            'tenant_id'               => $tenant,
            'recommendation_id'       => $data['recommendationId'],
            // decided_by records the PROPOSER. It is NOT NULL, and it is the
            // column approve() compares against to refuse self-approval.
            'decided_by'              => $this->actorId($request),
            'executor_type'           => $data['executorType'] ?? 'human',
            'rationale'               => $data['rationale'],
            'alternatives_considered' => json_encode($data['alternativesConsidered'] ?? []),
            // Stated explicitly rather than left to the column default: the
            // default is 'proposed' only on MySQL (see the 2026_07_29 migration
            // — the SQLite suite keeps the old one), and a governance status
            // should never depend on which driver answered.
            'status'                  => 'proposed',
        ]);

        // Re-read so the response is byte-for-byte what a subsequent GET
        // returns, including JSON columns hydrated back into arrays.
        return response()->json($this->repository->findById($tenant, $row['id']), 201);
    }

    /**
     * The governance act. Gated on permission:decision.approve in routes/api.php,
     * so an Analyst never reaches this method.
     *
     * The order of the checks below is the contract:
     *   missing        -> 404
     *   already approved -> 200, unchanged, no second event (idempotent)
     *   any other status -> 409 decision_not_approvable
     *   self-approval    -> 409 self_approval_forbidden, and the denial is audited
     */
    public function approve(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $tenant   = $this->tenantId($request);
        $actor    = $this->actorId($request);
        $decision = $this->repository->findById($tenant, $id);

        if ($decision === null) {
            return response()->json(['error' => 'decision_not_found'], 404);
        }

        $status = (string) ($decision['status'] ?? '');

        // Idempotent. A retried approval — double-clicked button, replayed
        // request — must return the same decision and must NOT append a second
        // DecisionReached event, because downstream consumers treat that event
        // as the trigger to execute.
        if ($status === 'approved') {
            return response()->json($decision);
        }

        if ($status !== 'proposed') {
            return response()->json([
                'error'  => 'decision_not_approvable',
                'status' => $status,
            ], 409);
        }

        // Invariant 7. The denial is audited rather than silently refused: an
        // attempted self-approval is precisely the event a governance log
        // exists to record.
        if (($decision['decided_by'] ?? null) === $actor) {
            $this->writeAudit($request, $tenant, $id, 'decision.approve.denied', [
                'reason'     => 'self_approval_forbidden',
                'decided_by' => $decision['decided_by'],
            ]);

            return response()->json(['error' => 'self_approval_forbidden'], 409);
        }

        $now  = now()->format('Y-m-d H:i:s');
        $note = $data['note'] ?? null;

        // The approval, its audit row and DecisionReached are one commit. An
        // approved decision whose event never landed is a governance act no
        // consumer can see — the ESO downstream would never start.
        //
        // correlation_id is the decision id: from here on the decision IS the
        // thread, and execution and outcome inherit it.
        $this->events->publishInTransaction(
            LoopEvent::DECISION_REACHED,
            $tenant,
            self::ENTITY,
            $actor,
            [
                'decisionId'   => $id,
                'approvedBy'   => $actor,
                'approvedDate' => $now,
                'note'         => $note,
            ],
            function () use ($request, $tenant, $id, $actor, $now, $note, $status) {
                DB::table('hpbrain_decisions')
                    ->where('tenant_id', $tenant)
                    ->where('id', $id)
                    ->update([
                        'status'        => 'approved',
                        'approved_by'   => $actor,
                        'approved_date' => $now,
                        'approval_note' => $note,
                    ]);

                $this->writeAudit($request, $tenant, $id, 'decision.approve', [
                    'status'        => ['from' => $status, 'to' => 'approved'],
                    'approval_note' => $note,
                ]);

                return ['entityId' => $id, 'result' => true];
            },
            correlationId: $id,
        );

        return response()->json($this->repository->findById($tenant, $id));
    }

    /** @param array<string, mixed> $changes */
    private function writeAudit(
        Request $request,
        string $tenant,
        string $decisionId,
        string $action,
        array $changes
    ): void {
        DB::table('hpbrain_audit_logs')->insert([
            'id'          => Uuid::uuid4()->toString(),
            'tenant_id'   => $tenant,
            'entity_type' => self::ENTITY,
            'entity_id'   => $decisionId,
            'action'      => $action,
            'actor_id'    => $this->actorId($request),
            'actor_name'  => $this->actorName($this->actorId($request)),
            'changes'     => json_encode($changes),
            'ip_address'  => $request->ip(),
            'user_agent'  => (string) $request->userAgent(),
            'created_at'  => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * actor_name is NOT NULL and the JWT carries no name claim, so it is
     * resolved from the Brain's own user table. An identity with no row there
     * (the dev token, an ERP-only user) falls back to the id: an audit entry
     * that names the actor by id is worth far more than a governance write that
     * fails on a display field.
     */
    private function actorName(string $actorId): string
    {
        $name = DB::table('hpbrain_auth_users')->where('id', $actorId)->value('name');

        return is_string($name) && $name !== '' ? $name : $actorId;
    }
}
