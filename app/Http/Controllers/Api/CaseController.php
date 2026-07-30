<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\CaseFile\CaseService;
use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Http\Controllers\Controller;
use App\Repositories\CaseRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CaseController extends Controller
{
    public function __construct(
        private readonly CaseRepository $repository,
        private readonly CaseService $cases,
        private readonly EventPublisher $events,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->repository->list($this->tenantId($request), $request->query('status')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'    => ['required', 'string', 'min:1'],
            'signalId' => ['nullable', 'string'],
        ]);

        $tenant = $this->tenantId($request);

        $row = $this->repository->insert([
            'tenant_id'  => $tenant,
            'title'      => $data['title'],
            'signal_id'  => $data['signalId'] ?? null,
            'status'     => 'open',
            'created_by' => $this->actorId($request),
        ]);

        // Golden path stage (3): the moment a principal names what they are
        // reasoning about.
        //
        // emit() rather than publishInTransaction() is a deliberate exception
        // to the rule, and the reason is narrow: a case is not a loop STAGE, it
        // is the container the stages happen inside. Nothing downstream
        // consumes SubjectSelected to do work — it exists so the audit trail
        // can say when the subject was chosen. If that ever changes, this must
        // move inside the transaction with the insert.
        $this->events->emit(
            LoopEvent::SUBJECT_SELECTED,
            $tenant,
            'Case',
            (string) $row['id'],
            $this->actorId($request),
            [
                'caseId'   => $row['id'],
                'title'    => $row['title'],
                'signalId' => $row['signal_id'],
            ],
        );

        return response()->json($row, 201);
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->findById($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'case_not_found'], 404);
    }

    public function evidence(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->tenantId($request);

        $ids = DB::table('hpbrain_case_evidence')
            ->where('tenant_id', $tenant)->where('case_id', $id)->pluck('evidence_id')->all();

        return response()->json(
            $ids === [] ? [] : DB::table('hpbrain_evidence')
                ->where('tenant_id', $tenant)->whereIn('id', $ids)->get()
        );
    }

    public function transition(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'status'               => ['required', 'string'],
            'resolvedHypothesisId' => ['nullable', 'string'],
        ]);

        $case = $this->repository->findById($this->tenantId($request), $id);

        if (! $case) {
            return response()->json(['error' => 'case_not_found'], 404);
        }

        try {
            $this->cases->assertTransition($case['status'], $data['status'], $data['resolvedHypothesisId'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->repository->updateFields($this->tenantId($request), $id, [
            'status'                 => $data['status'],
            'resolved_hypothesis_id' => $data['resolvedHypothesisId'] ?? null,
        ]));
    }

    public function attachEvidence(Request $request, string $tenantId, string $id): JsonResponse
    {
        $evidenceId = (string) $request->input('evidenceId');
        $tenant = $this->tenantId($request);

        // hpbrain_case_evidence is a join table with composite PK
        // (case_id, evidence_id) and NO id column. Probing for `id` here threw
        // ER_BAD_FIELD_ERROR in the Node build and broke the loop at stage 4.
        $exists = DB::table('hpbrain_case_evidence')
            ->where('tenant_id', $tenant)->where('case_id', $id)->where('evidence_id', $evidenceId)
            ->exists();

        if (! $exists) {
            DB::table('hpbrain_case_evidence')->insert([
                'tenant_id'   => $tenant,
                'case_id'     => $id,
                'evidence_id' => $evidenceId,
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
