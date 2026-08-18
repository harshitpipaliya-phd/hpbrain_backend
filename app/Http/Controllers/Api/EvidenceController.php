<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Http\Controllers\Controller;
use App\Repositories\EvidenceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

/**
 * Response shapes must match the Express originals exactly — web/src/api/*.ts
 * consumes them literally (ADR-007).
 *
 * This is the only door evidence enters the system through, which makes it the
 * only place Invariant 1 can be enforced: no evidence without provenance. The
 * schema alone cannot do it — provenance is NOT NULL but defaults to '{}', so
 * an empty write satisfies the constraint and proves nothing. The rules below
 * are what make the invariant structural rather than encouraged.
 */
final class EvidenceController extends Controller
{
    private const ENTITY = 'Evidence';

    public function __construct(
        private readonly EvidenceRepository $repository,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * The response stays a bare JSON array, as web/src/api/evidence.ts consumes
     * it. `since` and `limit` narrow it; omitting both returns everything,
     * exactly as before. Mirrors SignalController::index deliberately — the two
     * screens are siblings and a reviewer should not have to learn two
     * different query vocabularies.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:190'],
            'since'  => ['nullable', 'date'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        return response()->json(
            $this->repository->list(
                $this->tenantId($request),
                $data['status'] ?? null,
                null,
                isset($data['since']) ? date('Y-m-d H:i:s', strtotime((string) $data['since'])) : null,
                isset($data['limit']) ? (int) $data['limit'] : null,
            )
        );
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->findById($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'evidence_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        // provenance is validated field by field, not as a blob. A blob rule
        // ('provenance' => ['required','array']) is satisfied by {} — the same
        // empty object the column default already writes — so it would assert
        // nothing. Foundation Build Reference §3.2 step 6 names these three:
        // where it came from, when it was observed, how strongly it is held.
        $data = $request->validate([
            'tenantId'              => ['required', 'string', 'min:1'],
            'signalId'              => ['required', 'string', 'size:36'],
            'source'                => ['required', 'string', 'min:1', 'max:500'],
            'evidenceType'          => ['nullable', Rule::in(['observation', 'assessment', 'document', 'system', 'testimony'])],
            'content'               => ['required', 'array'],
            'provenance'            => ['required', 'array'],
            'provenance.source'     => ['required', 'string', 'min:1', 'max:500'],
            'provenance.ts'         => ['required', 'date'],
            'provenance.confidence' => ['required', 'numeric', 'between:0,1'],
            'confidence'            => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $tenant = $this->tenantId($request);

        // The foreign key on signal_id proves the signal EXISTS; it says
        // nothing about who owns it. Without this check, evidence could be
        // attached to another tenant's signal and would then surface in that
        // tenant's reasoning.
        $ownsSignal = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenant)
            ->where('id', $data['signalId'])
            ->exists();

        if (! $ownsSignal) {
            return response()->json(['error' => 'signal_not_found'], 422);
        }

        // Encoded once, here, and both hashed and stored from these exact
        // strings — see hashOf().
        $content    = json_encode($data['content']);
        $provenance = json_encode($data['provenance']);

        // Confidence INHERITS from provenance when unstated. It is deliberately
        // not left to the column default of 0.5: a confidence nobody asserted
        // is a fabricated confidence, and it would flow straight into
        // ReasoningService's weighted average as though someone had judged it.
        // The provenance's own confidence is a real claim by a real source.
        $confidence = $data['confidence'] ?? $data['provenance']['confidence'];

        $row = [
            'id'            => Uuid::uuid4()->toString(),
            'tenant_id'     => $tenant,
            'signal_id'     => $data['signalId'],
            'source'        => $data['source'],
            'evidence_type' => $data['evidenceType'] ?? 'observation',
            'content'       => $content,
            'provenance'    => $provenance,
            'confidence'    => $confidence,
            // Never client-supplied: a hash the caller chose detects nothing.
            // Any `hash` in the payload is unvalidated and therefore discarded
            // by validate() before it reaches here.
            'hash'          => $this->hashOf($content, $provenance),
            'status'        => 'active',
            // tenantId always comes from the token, never the body — a client
            // must not be able to write into another tenant by changing a
            // payload field.
            'created_by'    => $this->actorId($request),
        ];

        // correlation_id is the SIGNAL, not the evidence. The signal is the
        // thread everything before the decision hangs off — several pieces of
        // evidence corroborate one signal, and correlating each to itself would
        // say nothing.
        $this->events->publishInTransaction(
            LoopEvent::EVIDENCE_RECORDED,
            $tenant,
            self::ENTITY,
            $this->actorId($request),
            [
                'evidenceId'   => $row['id'],
                'signalId'     => $row['signal_id'],
                'source'       => $row['source'],
                'evidenceType' => $row['evidence_type'],
                'confidence'   => $row['confidence'],
                'hash'         => $row['hash'],
            ],
            fn () => ['entityId' => $row['id'], 'result' => $this->repository->insert($row)],
            correlationId: $row['signal_id'],
        );

        // Re-read rather than returning $row: the insert holds content and
        // provenance as encoded strings, and every read surface hydrates them
        // back into objects. A create that answers differently from a read is
        // a trap for the SPA.
        return response()->json($this->repository->findById($tenant, $row['id']), 201);
    }

    public function forSignal(Request $request, string $tenantId, string $signalId): JsonResponse
    {
        // Filtered in SQL against idx_evidence_signal. This used to list the
        // whole tenant and filter in PHP.
        return response()->json(
            $this->repository->list($this->tenantId($request), null, $signalId)
        );
    }

    /**
     * SHA-256 over the stored representation, not over the request arrays.
     *
     * The hash exists so that tampering with a row is detectable, which means
     * it must be reproducible from what the database holds: re-encoding the
     * hydrated arrays could reorder keys or renormalise numbers and produce a
     * different digest for an untouched row. The separator keeps a value that
     * ends where the next begins from colliding with one that does not.
     */
    private function hashOf(string $content, string $provenance): string
    {
        return hash('sha256', $content.'|'.$provenance);
    }

}
