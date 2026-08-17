<?php

declare(strict_types=1);

namespace App\Domain\Cases;

use Illuminate\Support\Facades\DB;

/**
 * What else the case knew, beside what a recommendation actually stood on.
 *
 * THE PATH FROM A RECOMMENDATION TO ITS CASE, AND WHY IT IS NOT THE OBVIOUS ONE.
 * hpbrain_recommendations.reasoning_step_id looks like the answer and is not:
 * RecommendVerb::persist writes it as a hardcoded null, deliberately, because a
 * model-authored recommendation has no human reasoning step behind it and
 * attaching one would be a fabricated provenance. hpbrain_recommendation_evidence
 * looks like the second answer and is also not: only the retired SignalReasoner
 * ever wrote it, and the verb pipeline writes nothing there at all. Measured on
 * the real installation — every one of that table's rows belongs to a
 * signal-reasoner recommendation, and every RecommendVerb recommendation has a
 * null reasoning step.
 *
 * So the only path that works for the pipeline actually in use is through the
 * evidence the recommendation itself cites:
 *
 *     hpbrain_recommendations.dependencies (the evidence ids GroundedClaims let
 *     through) -> hpbrain_evidence.signal_id -> hpbrain_case_signals -> the case
 *
 * That is not a workaround. It is the strongest available link, because it is
 * built from what the recommendation actually stood on rather than from a
 * bookkeeping column something forgot to fill in.
 *
 * groundedOn IS RETURNED UNTOUCHED, AND THAT IS THE WHOLE POINT OF SEPARATING
 * IT. It is the decoded `dependencies` array exactly as stored — not filtered
 * against existence, not reordered, not deduplicated, not intersected with
 * anything. A reader has to be able to see precisely what the recommendation
 * cited, because that is the claim GroundedClaims already validated and nothing
 * downstream is entitled to quietly improve it. Everything this class derives
 * lives under caseContext, where it cannot be mistaken for part of the citation.
 *
 * CASE CONTEXT IS NOT EVIDENCE FOR THE RECOMMENDATION. The related signals were
 * never shown to the model — RecommendVerb grounds on one signal — so this is
 * strictly "here is what else the case holds", offered to a human deciding
 * whether the recommendation is still right. Nothing here feeds a verb.
 */
final class RecommendationCaseContext
{
    public function __construct(private readonly CaseSignalEvidence $caseEvidence)
    {
    }

    /**
     * A recommendation, what it stood on, and what its case knows besides.
     *
     * Null when the recommendation does not exist for this tenant.
     *
     * @return array<string, mixed>|null
     */
    public function forRecommendation(string $tenantId, string $recommendationId): ?array
    {
        $recommendation = DB::table('hpbrain_recommendations')
            ->where('tenant_id', $tenantId)->where('id', $recommendationId)->first();

        if ($recommendation === null) {
            return null;
        }

        // EXACTLY as stored. json_decode and nothing else.
        $decoded = json_decode((string) ($recommendation->dependencies ?? '[]'), true);
        $groundedOn = is_array($decoded) ? $decoded : [];

        $resolution = $this->resolveCase($tenantId, $groundedOn);

        return [
            'tenantId'         => $tenantId,
            'recommendationId' => $recommendationId,
            'caseId'           => $resolution['caseId'],
            'resolution'       => $resolution['detail'],
            'groundedOn'       => $groundedOn,
            'caseContext'      => $resolution['caseId'] === null
                ? $this->emptyContext()
                : $this->contextFor($tenantId, $resolution['caseId'], $groundedOn),
        ];
    }

    /**
     * The case, reached through the cited evidence.
     *
     * AMBIGUITY IS REPORTED, NOT RESOLVED BY PREFERENCE. If cited evidence spans
     * two cases there is no honest single answer to "which case did this come
     * from", so caseId stays null and the candidates are named. Picking the
     * first would produce a confident wrong answer, which is the failure this
     * build refuses everywhere else.
     *
     * @param  array<int, mixed>  $groundedOn
     * @return array{caseId: string|null, detail: array<string, mixed>}
     */
    private function resolveCase(string $tenantId, array $groundedOn): array
    {
        $evidenceIds = array_values(array_filter($groundedOn, 'is_string'));

        $signalIds = $evidenceIds === [] ? [] : DB::table('hpbrain_evidence')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $evidenceIds)
            ->whereNotNull('signal_id')
            ->distinct()->pluck('signal_id')->map(fn ($v) => (string) $v)->all();

        $caseIds = $signalIds === [] ? [] : DB::table('hpbrain_case_signals')
            ->where('tenant_id', $tenantId)
            ->whereIn('signal_id', $signalIds)
            ->distinct()->pluck('case_id')->map(fn ($v) => (string) $v)->all();

        sort($caseIds);

        $detail = [
            'via'               => 'hpbrain_recommendations.dependencies -> hpbrain_evidence.signal_id'
                                   .' -> hpbrain_case_signals -> hpbrain_cases',
            'citedEvidence'     => count($evidenceIds),
            'signalIds'         => $signalIds,
            'candidateCaseIds'  => $caseIds,
            'note'              => null,
        ];

        if (count($caseIds) === 1) {
            return ['caseId' => $caseIds[0], 'detail' => $detail];
        }

        $detail['note'] = $caseIds === []
            ? 'No case links any signal behind this recommendation\'s cited evidence.'
            : 'The cited evidence spans more than one case, so the originating case is undetermined.';

        return ['caseId' => null, 'detail' => $detail];
    }

    /**
     * What the case holds that this recommendation did NOT stand on.
     *
     * Built by calling CaseSignalEvidence::forCase unmodified and keeping only
     * the related side. The id check beside the role check is not redundant: it
     * is what makes "not used in this recommendation" literally true rather than
     * true by an argument about how RecommendVerb happens to ground. If a cited
     * id ever does turn up under a related signal, it is counted and named
     * rather than silently dropped out of both halves of the answer.
     *
     * @param  array<int, mixed>  $groundedOn
     * @return array<string, mixed>
     */
    private function contextFor(string $tenantId, string $caseId, array $groundedOn): array
    {
        $cluster = $this->caseEvidence->forCase($tenantId, $caseId);

        if ($cluster === null) {
            return $this->emptyContext();
        }

        $cited = array_flip(array_values(array_filter($groundedOn, 'is_string')));

        $related = array_values(array_filter(
            $cluster['signals'],
            fn (array $s): bool => $s['role'] !== CaseSignalLinker::PRIMARY
        ));

        $evidence = [];
        $citedFromRelated = 0;

        foreach ($cluster['evidence'] as $row) {
            if ($row['role'] === CaseSignalLinker::PRIMARY) {
                continue;
            }

            if (isset($cited[(string) $row['id']])) {
                $citedFromRelated++;

                continue;
            }

            $evidence[] = $row;
        }

        return [
            'primarySignalId'  => $cluster['primarySignalId'],
            'relatedSignals'   => $related,
            'evidence'         => $evidence,
            'evidenceCount'    => count($evidence),
            // Zero for every recommendation the verb pipeline produces, because
            // it grounds on one signal. Published anyway: a non-zero value would
            // mean a recommendation cited something outside the signal it was
            // asked about, and that is worth seeing rather than inferring.
            'citedFromRelated' => $citedFromRelated,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyContext(): array
    {
        return [
            'primarySignalId' => null,
            'relatedSignals'  => [],
            'evidence'        => [],
            'evidenceCount'   => 0,
            'citedFromRelated' => 0,
        ];
    }
}
