<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Cases\CaseSignalEvidence;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The evidence behind a case, across every signal linked to it.
 *
 * A SEPARATE CONTROLLER, DELIBERATELY. This could have been a method on
 * CaseController or on ReasoningEngineController, and both would have been
 * wrong. ReasoningEngineController is the HTTP edge of the verb pipeline, and
 * putting a case-wide evidence view there would imply the verbs read it — they
 * do not. CaseController already owns `cases/{tenantId}/{id}/evidence`, which
 * returns hpbrain_case_evidence: the rows a PERSON attached to the case. Serving
 * a different body of fact from a neighbouring method on the same class is how
 * two endpoints come to be confused for one another.
 *
 * READ ONLY. It answers a question and writes nothing — not even the
 * LearningGrounded traceability events the explain endpoint produces, because
 * nothing here consults organizational memory.
 *
 * TENANT SCOPE IS NOT THIS CONTROLLER'S DECISION. tenantId() returns the value
 * EnsureTenantScope resolved from the authenticated token; the route parameter
 * can narrow to that same tenant but can never switch to another one. The
 * service filters every query on the resolved value.
 */
final class CaseSignalEvidenceController extends Controller
{
    public function __construct(private readonly CaseSignalEvidence $evidence)
    {
    }

    /**
     * GET cases/{tenantId}/{id}/signal-evidence
     *
     * 404 for a case this tenant does not have, which is the same answer a case
     * that does not exist gets — a caller must not be able to tell another
     * organization's case id apart from a fictional one.
     */
    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $result = $this->evidence->forCase($this->tenantId($request), $id);

        return $result === null
            ? response()->json(['error' => 'case_not_found'], 404)
            : response()->json($result);
    }
}
