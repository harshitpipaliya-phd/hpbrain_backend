<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Http\Controllers\Controller;
use App\Repositories\OutcomeRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

/**
 * Response shapes must match the Express originals exactly — web/src/api/*.ts
 * consumes them literally (ADR-007).
 *
 * Golden-path step 9: what actually happened. Two things make an outcome a
 * measurement rather than an assertion, and both are enforced here because the
 * schema cannot enforce either — every column below has a default:
 *
 *   Invariant 1 — an outcome cites evidence. `evidence_ids JSON NOT NULL
 *   DEFAULT '[]'` is satisfied by an empty list, so the requirement has to live
 *   in the write path.
 *   Governance — an outcome exists only for an APPROVED decision. An outcome
 *   against an unapproved one is a record that execution happened outside the
 *   gate, and recording it as normal would launder that.
 */
final class OutcomeController extends Controller
{
    private const ENTITY = 'Outcome';

    public function __construct(
        private readonly OutcomeRepository $repository,
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
            : response()->json(['error' => 'outcome_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenantId'      => ['required', 'string', 'min:1'],
            'decisionId'    => ['required', 'string', 'size:36'],
            'result'        => ['required', Rule::in(['success', 'partial', 'failure', 'inconclusive'])],
            'metrics'       => ['required', 'array'],
            'kpis'          => ['nullable', 'array'],
            // min:1 is the invariant, not a formality: an outcome that cites no
            // evidence is somebody's opinion of what happened.
            'evidenceIds'   => ['required', 'array', 'min:1'],
            'evidenceIds.*' => ['string', 'size:36'],
            'feedback'      => ['nullable', 'string', 'max:5000'],
            'confidence'    => ['required', 'numeric', 'between:0,1'],
        ]);

        $tenant = $this->tenantId($request);

        // The foreign key proves the decision EXISTS. It cannot check ownership
        // and it cannot check status, which are the two things that matter.
        $decisionStatus = DB::table('hpbrain_decisions')
            ->where('tenant_id', $tenant)
            ->where('id', $data['decisionId'])
            ->value('status');

        if ($decisionStatus !== 'approved') {
            return response()->json([
                'error'  => 'decision_not_approved',
                // null distinguishes "no such decision in this tenant" from
                // "found, but still proposed".
                'status' => $decisionStatus,
            ], 422);
        }

        $evidenceIds = array_values(array_unique($data['evidenceIds']));

        $found = DB::table('hpbrain_evidence')
            ->where('tenant_id', $tenant)
            ->whereIn('id', $evidenceIds)
            ->pluck('id')
            ->all();

        $missing = array_values(array_diff($evidenceIds, $found));

        if ($missing !== []) {
            // The offending ids are named. A bare 422 here means the caller has
            // to bisect their own payload to find which citation is bad.
            return response()->json(['error' => 'evidence_not_found', 'ids' => $missing], 422);
        }

        $row = [
            'id'           => Uuid::uuid4()->toString(),
            'tenant_id'    => $tenant,
            'decision_id'  => $data['decisionId'],
            'result'       => $data['result'],
            'metrics'      => json_encode($data['metrics']),
            'kpis'         => json_encode($data['kpis'] ?? []),
            'evidence_ids' => json_encode($evidenceIds),
            'feedback'     => $data['feedback'] ?? null,
            'confidence'   => $data['confidence'],
            // tenantId always comes from the token, never the body — a client
            // must not be able to write into another tenant by changing a
            // payload field.
            'created_by'   => $this->actorId($request),
        ];

        $domain = $this->domainFor($tenant, $data['decisionId']);

        // correlation_id is the DECISION. That is the thread the whole loop
        // hangs off once a decision exists — decision, execution, outcome and
        // learning are one story, and correlating the outcome to itself would
        // break the chain the audit trail reconstructs the case from.
        $this->events->publishInTransaction(
            LoopEvent::OUTCOME_RECORDED,
            $tenant,
            self::ENTITY,
            $this->actorId($request),
            [
                'outcomeId'   => $row['id'],
                'decisionId'  => $row['decision_id'],
                'result'      => $row['result'],
                'confidence'  => $row['confidence'],
                // Module 5 reads this to decide which mental model the learning
                // reinforces.
                'domain'      => $domain,
                'evidenceIds' => $evidenceIds,
            ],
            fn () => ['entityId' => $row['id'], 'result' => $this->repository->insert($row)],
            correlationId: $row['decision_id'],
        );

        // Re-read so metrics/kpis/evidence_ids come back as objects, the way
        // every read surface returns them.
        return response()->json($this->repository->findById($tenant, $row['id']), 201);
    }

    /**
     * The mental model this outcome is evidence about, walked back through the
     * chain that produced it: decision -> recommendation -> reasoning step ->
     * mental model.
     *
     * Module 5's consumer needs a domain to write the learning back against.
     * Deriving it here — where the decision id is in hand — beats making the
     * consumer re-walk four tables, and beats asking the caller for it, which
     * would let a client mislabel which model gets reinforced.
     *
     * Returns null when the chain is broken (no recommendation, no reasoning
     * step, no mental model). The inner joins make that a null rather than a
     * guess, which is the right answer: the domain is genuinely unknown.
     */
    private function domainFor(string $tenant, string $decisionId): ?string
    {
        $domain = DB::table('hpbrain_decisions as d')
            ->join('hpbrain_recommendations as r', 'r.id', '=', 'd.recommendation_id')
            ->join('hpbrain_reasoning_steps as s', 's.id', '=', 'r.reasoning_step_id')
            ->join('hpbrain_mental_models as m', 'm.id', '=', 's.mental_model_id')
            ->where('d.tenant_id', $tenant)
            ->where('m.tenant_id', $tenant)
            ->where('d.id', $decisionId)
            ->value('m.domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

}
