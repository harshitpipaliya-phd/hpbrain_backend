<?php

declare(strict_types=1);

namespace App\Domain\Cases;

use Illuminate\Support\Facades\DB;

/**
 * All the evidence behind a case — across every signal linked to it.
 *
 * WHY IT IS NOT CALLED CaseEvidenceAggregator. `hpbrain_case_evidence` already
 * exists and means something else entirely: evidence a person deliberately
 * attached to a case through CaseController::attachEvidence. A class named for
 * "case evidence" would be read as aggregating that table, and the two are
 * different bodies of fact — one curated by a human onto the case, one produced
 * by the detectors under the case's signals. This walks hpbrain_case_signals,
 * so it is named for what it walks.
 *
 * READ ONLY, AND NOT WIRED INTO REASONING. Nothing here is called by ExplainVerb
 * or RecommendVerb. Both still ground on the single signal they were asked
 * about, exactly as they do today. Whether a verb should reason over a case's
 * whole signal set is a real decision with real consequences for what a
 * recommendation cites, and it is not made here.
 *
 * THE PER-SIGNAL QUERY IS A DELIBERATE, LITERAL COPY of the one both verbs use
 * to gather grounding (ExplainVerb::ground, RecommendVerb::ground):
 *
 *     DB::table('hpbrain_evidence')
 *         ->where('tenant_id', $tenantId)
 *         ->where('signal_id', $signalId)
 *         ->orderByDesc('confidence')
 *         ->get()
 *         ->map(fn ($r) => ['id' => $r->id, 'kind' => 'evidence', 'row' => (array) $r])
 *         ->all();
 *
 * Copied rather than generalised, and copied INCLUDING its quirks — no status
 * filter, so retracted or superseded rows are present here exactly as they are
 * there; and no tiebreak after confidence. Diverging in either direction would
 * mean this view showed a case standing on evidence the verbs never saw, or
 * hid evidence they did. If that query is ever wrong, it is wrong in three
 * places consistently rather than in two places differently.
 *
 * A UNION NEEDS NO DEDUPLICATION. hpbrain_evidence.signal_id is a single
 * column, so a row belongs to exactly one signal and cannot appear twice.
 *
 * ORDERING IS PER SIGNAL, NOT ACROSS THE UNION. Each signal's evidence keeps the
 * confidence order the verbs would see, and the signals themselves come back
 * primary first. Re-sorting the whole union by confidence would interleave the
 * groups and destroy the one property that makes the single-signal case provably
 * identical to what EXPLAIN sees today.
 */
final class CaseSignalEvidence
{
    public function __construct(private readonly CaseSignalLinker $links)
    {
    }

    /**
     * Every signal linked to a case, and the evidence under each.
     *
     * Null when the case does not exist for this tenant — a read, so a missing
     * subject is an absence to report rather than a fault to raise.
     *
     * @return array{
     *     tenantId: string, caseId: string, primarySignalId: string|null,
     *     signals: array<int, array{signalId: string, role: string, evidenceCount: int}>,
     *     evidence: array<int, array<string, mixed>>, evidenceCount: int
     * }|null
     */
    public function forCase(string $tenantId, string $caseId): ?array
    {
        // Tenant scope is established here, once, before anything is read. Every
        // query below is filtered on the same $tenantId.
        $case = DB::table('hpbrain_cases')
            ->where('tenant_id', $tenantId)->where('id', $caseId)->first();

        if ($case === null) {
            return null;
        }

        // Through the linker, not by querying the junction directly: what
        // 'primary' means and which order the roles come back in are that
        // class's to define, and a second implementation of either would be a
        // second thing to keep in step.
        $links = $this->links->signalsFor($tenantId, $caseId);

        $signals = [];
        $evidence = [];

        foreach ($links as $link) {
            $rows = $this->evidenceForSignal($tenantId, $link['signalId']);

            foreach ($rows as $row) {
                // The tag is ADDITIVE. Strip signalId and role and what remains
                // is byte-for-byte the shape the verbs build.
                $evidence[] = $row + ['signalId' => $link['signalId'], 'role' => $link['role']];
            }

            $signals[] = [
                'signalId'      => $link['signalId'],
                'role'          => $link['role'],
                'evidenceCount' => count($rows),
            ];
        }

        return [
            'tenantId' => $tenantId,
            'caseId'   => $caseId,
            // The AUTHORITATIVE primary, read from the column rather than the
            // junction. Published so a reader can see for themselves that the
            // two agree — a case whose column names a signal absent from
            // `signals` below has drifted, and that is worth being visible
            // rather than silently reconciled here.
            'primarySignalId' => ($case->signal_id === null || $case->signal_id === '')
                ? null : (string) $case->signal_id,
            'signals'       => $signals,
            'evidence'      => $evidence,
            'evidenceCount' => count($evidence),
        ];
    }

    /**
     * One signal's evidence, by the verbs' own query.
     *
     * @return array<int, array{id: mixed, kind: string, row: array<string, mixed>}>
     */
    private function evidenceForSignal(string $tenantId, string $signalId): array
    {
        return DB::table('hpbrain_evidence')
            ->where('tenant_id', $tenantId)
            ->where('signal_id', $signalId)
            ->orderByDesc('confidence')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'kind' => 'evidence', 'row' => (array) $r])
            ->all();
    }
}
