<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * PROJECTS THE THREE EXISTING INTELLIGENCE COMPOSERS' PAYLOADS INTO ONE
 * SHARED, VALIDATED SHAPE.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * A PROJECTOR, NOT A FOURTH ENGINE — SAME PRINCIPLE AS ENTITYINTELLIGENCECOMPOSER
 *
 * DepartmentVerdict, PersonIntelligenceService and OrganizationScorecard each
 * already compute everything a finding needs — the arithmetic, the missing-data
 * reasons, the evidence counts. This class does not recompute or re-query any
 * of it. Each `for*()` method takes that composer's OWN, already-built payload
 * and reshapes it into IntelligenceOutputContract's vocabulary. Nothing here
 * touches a database, calls an LLM, or invents a fact the source composer did
 * not already publish.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHAT "GROUNDED" MEANS HERE
 *
 * A finding's `evidence` array is populated only where the source composer
 * already carries a structured reference — a signal id, an evidence count, a
 * scoring formula with its inputs. Where a composer's underlying figure is a
 * narrative sentence with no id behind it (DepartmentProfile's narrative
 * lines, for instance), `evidence` is published as `[]` rather than invented,
 * because a citation this class did not read is not one it may assert.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * THE THREE COMPOSERS DISAGREE ON WHAT "CONFIDENCE" MEANS, AND THIS DOES NOT
 * PAPER OVER THAT
 *
 * PersonIntelligenceService's recommendation confidence is already a 0-1
 * float; DepartmentVerdict's is a string band (moderate/low/very low) derived
 * from a sufficiency gate; OrganizationScorecard's dimensions carry no
 * confidence at all. Each recommendation below carries whichever of those the
 * source composer actually produced, under its own key
 * (`confidence` vs `confidenceLabel`), rather than forcing a fabricated
 * numeric value onto a composer that never computed one.
 */
final class IntelligenceSummaryComposer
{
    /**
     * @param  array<string, mixed>  $payload  DepartmentVerdict::forDepartment()'s own return value, verbatim
     * @return array{contract: array<string, mixed>, valid: bool, errors: array<int, string>}
     */
    public function forDepartment(array $payload): array
    {
        $department = (array) ($payload['department'] ?? []);
        $findings = [];

        foreach ((array) ($payload['state']['narrative'] ?? []) as $line) {
            $findings[] = $this->finding(
                match ($line['kind'] ?? null) {
                    'trend' => 'trend',
                    'risk' => 'risk',
                    'opportunity' => 'opportunity',
                    default => 'observed_fact',
                },
                (string) ($line['text'] ?? ''),
                [],
                null,
            );
        }

        foreach ((array) ($payload['scoreExplain']['components'] ?? []) as $component) {
            $findings[] = $this->finding(
                'calculated_metric',
                sprintf('%s: %d%% — %s', $component['label'] ?? '', $component['valuePct'] ?? 0, $component['basis'] ?? ''),
                [],
                null,
            );
        }

        foreach ((array) ($payload['signals'] ?? []) as $signal) {
            $isRisk = ($signal['open'] ?? false) === true
                && in_array(strtolower((string) ($signal['severity'] ?? '')), ['high', 'critical'], true);

            $findings[] = $this->finding(
                $isRisk ? 'risk' : 'observed_fact',
                trim(((string) ($signal['title'] ?? '')).(($signal['detail'] ?? null) !== null ? ': '.$signal['detail'] : '')),
                [['type' => 'signal', 'id' => $signal['id'] ?? null, 'evidenceCount' => $signal['evidenceCount'] ?? 0]],
                $signal['confidence'] ?? null,
            );
        }

        $recommendation = (array) ($payload['recommendation'] ?? []);

        $contract = [
            'title' => trim((string) ($department['name'] ?? 'Department')).' — Department Intelligence',
            'executiveSummary' => (string) ($payload['state']['summary'] ?? $payload['health']['reason'] ?? ''),
            'context' => [
                'type' => 'OrganizationUnit',
                'id' => $department['id'] ?? null,
                'name' => $department['name'] ?? null,
            ],
            'timePeriod' => [
                'asOf' => gmdate('c'),
                'previousDate' => $payload['sinceRefresh']['previousDate'] ?? null,
            ],
            'findings' => $findings,
            'metrics' => [
                'healthScore' => $payload['health']['score'] ?? null,
                'healthBand' => $payload['health']['band'] ?? null,
                'confidencePct' => $payload['confidence']['pct'] ?? null,
            ],
            'dataCoverage' => [
                'measuredDimensions' => $payload['confidence']['measurableDimensions'] ?? null,
                'totalDimensions' => $payload['confidence']['totalDimensions'] ?? null,
                'confidencePct' => $payload['confidence']['pct'] ?? null,
                'band' => $payload['confidence']['band'] ?? null,
            ],
            'limitations' => array_map(fn (array $spot): array => [
                'dimension' => $spot['dimension'] ?? null,
                'reason' => $spot['reason'] ?? null,
                'fixLabel' => $spot['fixLabel'] ?? null,
                'fixRoute' => $spot['fixRoute'] ?? null,
            ], (array) ($payload['blindSpots'] ?? [])),
            'recommendations' => $recommendation === [] ? [] : [[
                'title' => $recommendation['title'] ?? '',
                'rationale' => $recommendation['body'] ?? '',
                'confidenceLabel' => $recommendation['confidence'] ?? null,
                'confidenceReason' => $recommendation['confidenceReason'] ?? null,
                'rootCause' => $recommendation['rootCause'] ?? null,
                'rootCauseMissing' => $recommendation['rootCauseMissing'] ?? null,
                'targetRoute' => $recommendation['target'] ?? null,
            ]],
            'decisionStatus' => null,
            'sources' => $payload['sources'] ?? [],
        ];

        return $this->validated($contract);
    }

    /**
     * @param  array<string, mixed>  $payload  PersonIntelligenceService::buildWithPage()'s own return value, verbatim
     * @return array{contract: array<string, mixed>, valid: bool, errors: array<int, string>}
     */
    public function forPerson(array $payload): array
    {
        $person = (array) ($payload['person'] ?? []);
        $findings = [];

        foreach ((array) ($payload['scoreExplain']['components'] ?? []) as $component) {
            $findings[] = $this->finding(
                'calculated_metric',
                sprintf('%s: %d%% — %s', $component['label'] ?? '', (int) round((float) ($component['valuePct'] ?? 0)), $component['basis'] ?? ''),
                [],
                null,
            );
        }

        $mismatchCount = (int) ($payload['consistency']['mismatches']['count'] ?? 0);
        if ($mismatchCount > 0) {
            $findings[] = $this->finding(
                'anomaly',
                sprintf(
                    '%d day(s) in the last %d where check-in and attendance records disagree.',
                    $mismatchCount,
                    (int) ($payload['consistency']['mismatches']['windowDays'] ?? 0),
                ),
                [['type' => 'mismatch_sample', 'dates' => $payload['consistency']['mismatches']['sampleDates'] ?? []]],
                null,
            );
        }

        if (($payload['presence']['longHoursFlag'] ?? false) === true) {
            $findings[] = $this->finding(
                'trend',
                sprintf(
                    'Working hours have been above the threshold for %d consecutive week(s).',
                    (int) ($payload['presence']['longHoursWeeks'] ?? 0),
                ),
                [],
                null,
            );
        }

        $loop = (array) ($payload['loop'] ?? []);
        if (($loop['signals'] ?? 0) > 0) {
            $findings[] = $this->finding(
                'observed_fact',
                sprintf(
                    '%d signal(s) reference this person; %d investigation(s) opened.',
                    (int) $loop['signals'],
                    (int) ($loop['cases'] ?? 0),
                ),
                [['type' => 'loop_counts', 'counts' => $loop]],
                null,
            );
        }

        $recommendation = (array) ($payload['recommendation'] ?? []);

        $contract = [
            'title' => trim((string) ($person['name'] ?? 'Person')).' — Person Intelligence',
            'executiveSummary' => (string) ($payload['standing']['reason'] ?? ''),
            'context' => [
                'type' => 'Person',
                'id' => $person['id'] ?? null,
                'name' => $person['name'] ?? null,
            ],
            'timePeriod' => [
                'asOf' => gmdate('c'),
                'previousDate' => null,
            ],
            'findings' => $findings,
            'metrics' => [
                'standingScore' => $payload['standing']['score'] ?? null,
                'standingBand' => $payload['standing']['band'] ?? null,
                'confidencePct' => $payload['confidence']['pct'] ?? null,
            ],
            'dataCoverage' => [
                'measuredDimensions' => $payload['confidence']['measurableDimensions'] ?? null,
                'totalDimensions' => $payload['confidence']['totalDimensions'] ?? null,
                'confidencePct' => $payload['confidence']['pct'] ?? null,
                'undetermined' => $payload['confidence']['undetermined'] ?? [],
            ],
            'limitations' => array_map(fn (array $spot): array => [
                'dimension' => $spot['dimension'] ?? null,
                'reason' => $spot['reason'] ?? null,
                'fixLabel' => $spot['fixLabel'] ?? null,
                'fixRoute' => $spot['fixRoute'] ?? null,
            ], (array) ($payload['blindSpots'] ?? [])),
            'recommendations' => $recommendation === [] ? [] : [[
                'title' => $recommendation['title'] ?? '',
                'rationale' => $recommendation['body'] ?? '',
                'confidence' => $recommendation['confidence'] ?? null,
                'rootCause' => $recommendation['rootCause'] ?? null,
                'targetRoute' => $recommendation['createPlanRoute'] ?? null,
            ]],
            'decisionStatus' => null,
            'loopActivity' => $loop,
            'sources' => [],
        ];

        return $this->validated($contract);
    }

    /**
     * @param  array<string, mixed>  $payload  OrganizationScorecard::forTenant()'s own return value, verbatim
     * @return array{contract: array<string, mixed>, valid: bool, errors: array<int, string>}
     */
    public function forOrganization(array $payload, ?string $organizationName = null): array
    {
        $overall = $payload['overall'] ?? null;
        $findings = [];

        foreach ((array) ($payload['dimensions'] ?? []) as $dimension) {
            $findings[] = $this->finding(
                'calculated_metric',
                (string) ($dimension['statement'] ?? ''),
                [['type' => 'formula', 'formula' => $dimension['formula'] ?? null, 'inputs' => $dimension['inputs'] ?? []]],
                null,
            );
        }

        foreach ((array) ($payload['risks'] ?? []) as $risk) {
            $findings[] = $this->finding(
                'risk',
                (string) ($risk['statement'] ?? ''),
                [],
                null,
            );
        }

        $recommendedFocus = $payload['recommendedFocus'] ?? null;

        $contract = [
            'title' => ($organizationName !== null ? $organizationName : 'Organization').' — Organization Intelligence',
            'executiveSummary' => $overall === null
                ? 'Not enough connected data exists yet to compute an overall organization score. See limitations below for what would unlock it.'
                : sprintf(
                    'Overall score %d%% (%s), from %d of %d measurable dimensions.',
                    $overall,
                    $payload['band'] ?? 'undetermined',
                    $payload['measuredDimensions'] ?? 0,
                    ($payload['measuredDimensions'] ?? 0) + ($payload['unmeasuredDimensions'] ?? 0),
                ),
            'context' => [
                'type' => 'Organization',
                'id' => $payload['tenantId'] ?? null,
                'name' => $organizationName,
            ],
            'timePeriod' => [
                'asOf' => $payload['computedAt'] ?? gmdate('c'),
                'previousDate' => null,
            ],
            'findings' => $findings,
            'metrics' => [
                'overall' => $overall,
                'band' => $payload['band'] ?? null,
                'coverageOfModel' => $payload['coverageOfModel'] ?? null,
            ],
            'dataCoverage' => [
                'measuredDimensions' => $payload['measuredDimensions'] ?? null,
                'unmeasuredDimensions' => $payload['unmeasuredDimensions'] ?? null,
                'coverageOfModel' => $payload['coverageOfModel'] ?? null,
                'band' => $payload['band'] ?? null,
            ],
            'limitations' => array_map(fn (array $unmeasured): array => [
                'dimension' => $unmeasured['label'] ?? null,
                'reason' => $unmeasured['reason'] ?? null,
                'fixLabel' => $unmeasured['nextStep'] ?? null,
                'fixRoute' => null,
            ], (array) ($payload['unmeasured'] ?? [])),
            'recommendations' => $recommendedFocus === null ? [] : [[
                'title' => (string) ($recommendedFocus['dimension'] ?? ''),
                'rationale' => (string) ($recommendedFocus['why'] ?? ''),
                'kind' => $recommendedFocus['type'] ?? null,
            ]],
            'decisionStatus' => null,
            'sources' => [],
        ];

        return $this->validated($contract);
    }

    /**
     * @param  array<int, array{type: string, id: mixed}>  $evidence
     */
    private function finding(string $type, string $statement, array $evidence, ?float $confidence): array
    {
        return [
            'type' => $type,
            'statement' => $statement,
            'evidence' => $evidence,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array{contract: array<string, mixed>, valid: bool, errors: array<int, string>}
     */
    private function validated(array $contract): array
    {
        $errors = IntelligenceOutputContract::validate($contract);

        return ['contract' => $contract, 'valid' => $errors === [], 'errors' => $errors];
    }
}
