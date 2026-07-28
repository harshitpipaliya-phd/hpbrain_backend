<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\HypothesisRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Hypothesis Ledger. Every hypothesis is classified against one of the
 * eight root-cause families — the enum is validated against config/brain.php,
 * which quotes contracts/taxonomy/root-cause.schema.yaml, so the contract stays
 * the single source of truth rather than being re-declared as a DB constraint.
 */
final class HypothesisController extends Controller
{
    public function __construct(private readonly HypothesisRepository $repository)
    {
    }

    public function forCase(Request $request, string $tenantId, string $caseId): JsonResponse
    {
        $all = $this->repository->list($this->tenantId($request));

        return response()->json(array_values(array_filter($all, fn ($h) => ($h['case_id'] ?? null) === $caseId)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'caseId'          => ['required', 'string'],
            'statement'       => ['required', 'string', 'min:1'],
            'rootCauseFamily' => ['required', Rule::in(config('brain.root_cause_families'))],
            'confidence'      => ['nullable', 'numeric', 'between:0,1'],
        ]);

        return response()->json($this->repository->insert([
            'tenant_id'         => $this->tenantId($request),
            'case_id'           => $data['caseId'],
            'statement'         => $data['statement'],
            'root_cause_family' => $data['rootCauseFamily'],
            'confidence'        => $data['confidence'] ?? 0.5,
            'status'            => 'proposed',
            'proposed_by'       => $this->actorId($request),
        ]), 201);
    }

    /**
     * The frontend addresses these as verbs nested under the case
     * (/hypotheses/{tenant}/case/{caseId}/{id}/reject). Preserved exactly —
     * renaming to a generic PATCH would break web/src/api/case.ts.
     */
    public function reject(Request $request, string $tenantId, string $caseId, string $id): JsonResponse
    {
        $reason = trim((string) $request->input('rejectedReason', $request->input('reason', '')));

        // A rejection without a reason is an opinion, not a finding. The
        // Hypothesis Ledger is only useful if rejected branches stay readable.
        if ($reason === '') {
            return response()->json(['error' => 'rejection_requires_reason'], 422);
        }

        return $this->applyStatus($request, $caseId, $id, 'rejected', $reason);
    }

    public function support(Request $request, string $tenantId, string $caseId, string $id): JsonResponse
    {
        return $this->applyStatus($request, $caseId, $id, 'supported', null);
    }

    public function confirm(Request $request, string $tenantId, string $caseId, string $id): JsonResponse
    {
        return $this->applyStatus($request, $caseId, $id, 'confirmed', null);
    }

    private function applyStatus(Request $request, string $caseId, string $id, string $status, ?string $reason): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $existing = $this->repository->findById($tenant, $id);

        if (! $existing || ($existing['case_id'] ?? null) !== $caseId) {
            return response()->json(['error' => 'hypothesis_not_found'], 404);
        }

        return response()->json($this->repository->updateFields($tenant, $id, [
            'status'          => $status,
            'rejected_reason' => $reason,
        ]));
    }

    public function setStatus(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'status'         => ['required', Rule::in(['proposed', 'supported', 'rejected', 'confirmed'])],
            'rejectedReason' => ['nullable', 'string'],
        ]);

        // A rejection without a reason is not a rejection, it is an opinion.
        if ($data['status'] === 'rejected' && empty($data['rejectedReason'])) {
            return response()->json(['error' => 'rejection_requires_reason'], 422);
        }

        return response()->json($this->repository->updateFields($this->tenantId($request), $id, [
            'status'          => $data['status'],
            'rejected_reason' => $data['rejectedReason'] ?? null,
        ]));
    }
}
