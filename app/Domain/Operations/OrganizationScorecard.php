<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Organization\FoundationCounts;
use App\Domain\Universal\EntityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE EXPLAINABLE NUMBER FOR AN ORGANIZATION, BUILT ONLY OUT OF WHAT IT CAN
 * ACTUALLY MEASURE.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE RULE THAT SHAPES EVERYTHING BELOW: AN UNMEASURABLE DIMENSION IS DROPPED,
 * NEVER SCORED ZERO.
 *
 * An organization that has never recorded a capability assessment has NOT failed
 * at capability. It has not measured it. Those are different findings and only one
 * of them belongs in a score. Feeding the first in as a zero manufactures a
 * failing grade out of an absence, and — worse — it is invisible: the reader sees
 * 61% and has no way to learn that a fifth of it was a dimension nobody ever
 * populated.
 *
 * So each dimension declares `supported`. Unsupported dimensions are removed from
 * the weighted mean and their weight is redistributed across the rest, so the
 * headline is always a real average of real measurements. They are then published
 * SEPARATELY, as `unmeasured`, each with the reason it could not be computed and
 * the concrete step that would unlock it. That list is the most useful thing on
 * the screen for an organization still connecting its systems — it is a map of
 * what the product would tell them next.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NO TENANT, INDUSTRY OR DATASET APPEARS ANYWHERE IN THIS CLASS.
 *
 * There is no table of expected scores, no per-customer weighting, no branch on
 * what kind of organization this is. Every dimension is defined over a property
 * any organization's data may or may not have, and the ones that apply are decided
 * by the data. A fibre operator ends up scored on execution, backlog, turnaround
 * and repeat service calls because those are what its imports support; a school
 * connecting fee and attendance data is scored by the identical code on the
 * dimensions ITS data supports. Neither one has a line of code of its own.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EVERY DIMENSION CARRIES ITS OWN ARITHMETIC.
 *
 * `formula` states the expression in words and `inputs` gives the numbers that
 * went into it. A reader who disbelieves 84% can see it is 12,704 ÷ 15,116 and
 * check that against the records screen. A score nobody can audit is a score
 * nobody should act on, and this product's entire claim is that its figures are
 * derived rather than asserted.
 *
 * WEIGHTS ARE STATED, NOT HIDDEN. They encode one judgement — that whether work
 * gets finished matters more to an operating organization than how completely its
 * fields are populated — and they are written where that judgement can be argued
 * with.
 */
final class OrganizationScorecard
{
    public function __construct(
        private readonly OperationalIntelligence $operations,
        private readonly IntelligenceLoopMetrics $loop,
        private readonly FoundationCounts $foundation,
        private readonly EntityResolver $resolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forTenant(string $tenantId, bool $fresh = false): array
    {
        $ops = $this->operations->forTenant($tenantId, $fresh);
        $loop = $this->loop->forTenant($tenantId);
        $foundation = $this->foundation->forTenant($tenantId);
        $capability = $this->capabilityCoverage($tenantId);

        $dimensions = array_values(array_filter([
            $this->dataCoverage($ops),
            $this->executionHealth($ops),
            $this->workloadHealth($ops),
            $this->responsivenessHealth($ops),
            $this->serviceHealth($ops),
            $this->departmentHealth($ops, $foundation),
            $this->signalHealth($loop),
            $this->evidenceStrength($loop),
            $this->deliberationHealth($loop),
            $capability,
        ]));

        $scored = array_values(array_filter($dimensions, fn ($d) => $d['supported']));
        $unmeasured = array_values(array_filter($dimensions, fn ($d) => ! $d['supported']));

        $weightTotal = array_sum(array_column($scored, 'weight'));
        $overall = $weightTotal > 0
            ? (int) round(array_sum(array_map(fn ($d) => $d['score'] * $d['weight'], $scored)) / $weightTotal)
            : null;

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'tenantId' => $tenantId,
            'dataVersion' => $ops['dataVersion'] ?? null,
            'computedAt' => gmdate('c'),
            'overall' => $overall,
            'band' => $this->band($overall),
            'measuredDimensions' => count($scored),
            'unmeasuredDimensions' => count($unmeasured),
            'coverageOfModel' => count($dimensions) > 0
                ? round(count($scored) / count($dimensions), 4)
                : null,
            'dimensions' => $scored,
            'unmeasured' => $unmeasured,
            'strengths' => $this->strengths($scored),
            'risks' => $this->risks($scored),
            'opportunities' => $this->opportunities($unmeasured),
            'recommendedFocus' => $this->recommendedFocus($scored, $unmeasured),
            'method' => [
                'aggregation' => 'Weighted mean of the dimensions this organization\'s data supports. Weights are stated on each dimension and are renormalised over the supported set.',
                'exclusion' => 'A dimension the data cannot support is removed from the mean and listed separately with the reason. It is never entered as zero, because "not measured" and "measured as nothing" are different findings.',
                'bands' => 'Excellent 85+, Healthy 70-84, Watch 55-69, Needs attention below 55. Stated so a reader can disagree with the cut points.',
                'llm' => 'No language model contributed to any score, weight, band or conclusion.',
            ],
        ];
    }

    /* ─────────────────────────── the dimensions ─────────────────────────── */

    /**
     * How much of what the engine needs the connected sources actually carry.
     *
     * RECORD-WEIGHTED, NOT DATASET-WEIGHTED. A 40-row reference table with every
     * field populated should not offset a 40,000-row workload table with no
     * status column; the denominator is records, so each dataset contributes the
     * data it represents.
     *
     * @param  array<string, mixed>  $ops
     * @return array<string, mixed>|null
     */
    private function dataCoverage(array $ops): ?array
    {
        if (($ops['totals']['records'] ?? 0) <= 0) {
            return $this->unsupported(
                'dataCoverage',
                'Data Coverage',
                1.0,
                'No operational records have been ingested, so there is nothing to assess coverage over.',
                'Connect a source system and run an import.',
            );
        }

        // The seven fields every derived measure in this engine reads.
        $probes = ['timeline', 'status', 'category', 'owner', 'geography', 'subject', 'department'];
        $records = 0;
        $weighted = 0.0;
        $present = [];

        foreach ($ops['datasets'] as $dataset) {
            $n = (int) $dataset['records'];
            $records += $n;
            $carried = 0;

            foreach ($probes as $probe) {
                if (($dataset['fields'][$probe] ?? false) === true) {
                    $carried++;
                    $present[$probe] = ($present[$probe] ?? 0) + $n;
                }
            }

            $weighted += ($carried / count($probes)) * $n;
        }

        $score = (int) round(($weighted / max(1, $records)) * 100);

        return [
            'key' => 'dataCoverage',
            'label' => 'Data Coverage',
            'weight' => 1.0,
            'supported' => true,
            'score' => $score,
            'band' => $this->band($score),
            'statement' => $score.'% of the fields this engine derives from are populated, weighted by how many records each dataset holds.',
            'formula' => 'For each dataset: (populated probe fields ÷ 7) × its record count, summed and divided by total records.',
            'inputs' => [
                'records' => $records,
                'datasets' => count($ops['datasets']),
                'probes' => $probes,
                'recordsCarryingField' => $present,
            ],
        ];
    }

    /**
     * Whether work that starts gets finished.
     *
     * @param  array<string, mixed>  $ops
     * @return array<string, mixed>|null
     */
    private function executionHealth(array $ops): ?array
    {
        $execution = $ops['execution'] ?? [];

        if (($execution['supported'] ?? false) !== true) {
            return $this->unsupported(
                'executionHealth',
                'Execution Health',
                1.4,
                (string) ($execution['reason'] ?? 'No dataset carries a resolvable workflow status.'),
                'Map a status field on at least one operational dataset so completion can be derived.',
            );
        }

        $score = (int) round(((float) $execution['completionRate']) * 100);

        return [
            'key' => 'executionHealth',
            'label' => 'Execution Health',
            'weight' => 1.4,
            'supported' => true,
            'score' => $score,
            'band' => $this->band($score),
            'statement' => number_format((int) $execution['completed']).' of '.number_format((int) $execution['classified'])
                .' records with a resolvable status have reached a completed state.',
            'formula' => 'completed ÷ (completed + in progress + open + cancelled), over every dataset whose status vocabulary resolves.',
            'inputs' => [
                'completed' => (int) $execution['completed'],
                'inProgress' => (int) $execution['inProgress'],
                'open' => (int) $execution['open'],
                'cancelled' => (int) $execution['cancelled'],
                'classified' => (int) $execution['classified'],
                'contributingDatasets' => $execution['contributingDatasets'],
            ],
        ];
    }

    /**
     * How much of the organization's recorded work is still in flight.
     *
     * SEPARATE FROM EXECUTION ON PURPOSE. Completion and backlog are not two views
     * of one number once cancellations exist: an organization can complete 70% and
     * cancel 25%, leaving a 5% backlog, and calling that "30% incomplete" would
     * describe abandoned work as pending work.
     *
     * @param  array<string, mixed>  $ops
     * @return array<string, mixed>|null
     */
    private function workloadHealth(array $ops): ?array
    {
        $execution = $ops['execution'] ?? [];

        if (($execution['supported'] ?? false) !== true) {
            return null; // Already reported as unmeasurable by executionHealth.
        }

        $score = (int) round((1 - (float) $execution['backlogRate']) * 100);

        return [
            'key' => 'workloadHealth',
            'label' => 'Workload Health',
            'weight' => 1.2,
            'supported' => true,
            'score' => $score,
            'band' => $this->band($score),
            'statement' => number_format((int) $execution['backlog']).' records are still open or in progress — '
                .$this->pct((float) $execution['backlogRate']).' of classified work.',
            'formula' => '1 − ((open + in progress) ÷ classified records).',
            'inputs' => [
                'backlog' => (int) $execution['backlog'],
                'classified' => (int) $execution['classified'],
                'backlogRate' => $execution['backlogRate'],
            ],
        ];
    }

    /**
     * How quickly closed work closed.
     *
     * SCORED ON THE SHARE CLOSED WITHIN A DAY, NOT ON THE MEAN. A mean elapsed
     * time is dominated by a handful of records left open for months, and it has
     * no natural scale to map onto 0-100 without inventing a target this engine
     * has no basis for. "What proportion closed inside 24 hours" is already a
     * proportion, needs no threshold of its own beyond the day, and is the figure
     * an operator actually watches.
     *
     * @param  array<string, mixed>  $ops
     * @return array<string, mixed>|null
     */
    private function responsivenessHealth(array $ops): ?array
    {
        $responsiveness = $ops['responsiveness'] ?? [];

        if (($responsiveness['supported'] ?? false) !== true) {
            return $this->unsupported(
                'responsiveness',
                'Responsiveness',
                1.0,
                (string) ($responsiveness['reason'] ?? 'No record carries both an opening and a later closing timestamp.'),
                'Map a closing or resolution timestamp on an operational dataset to unlock turnaround and SLA analysis.',
            );
        }

        $score = (int) round(((float) $responsiveness['withinDayRate']) * 100);

        return [
            'key' => 'responsiveness',
            'label' => 'Responsiveness',
            'weight' => 1.0,
            'supported' => true,
            'score' => $score,
            'band' => $this->band($score),
            'statement' => $this->pct((float) $responsiveness['withinDayRate']).' of the '
                .number_format((int) $responsiveness['measured']).' records with a measurable elapsed time closed within a day; '
                .'the average was '.$this->hours((float) $responsiveness['averageHours']).'.',
            'formula' => 'records closed within 24 hours ÷ records where the closing timestamp is later than the opening one.',
            'inputs' => [
                'measured' => (int) $responsiveness['measured'],
                'averageHours' => $responsiveness['averageHours'],
                'withinDayRate' => $responsiveness['withinDayRate'],
                'byDataset' => $responsiveness['byDataset'],
            ],
        ];
    }

    /**
     * How often the same subject comes back.
     *
     * @param  array<string, mixed>  $ops
     * @return array<string, mixed>|null
     */
    private function serviceHealth(array $ops): ?array
    {
        $service = $ops['service'] ?? [];

        if (($service['supported'] ?? false) !== true) {
            return $this->unsupported(
                'serviceHealth',
                'Service Health',
                1.2,
                (string) ($service['reason'] ?? 'No dataset carries a subject reference.'),
                'Map the customer, asset or subject identifier on an operational dataset to unlock repeat-issue analysis.',
            );
        }

        $score = (int) round((1 - (float) $service['repeatRate']) * 100);

        return [
            'key' => 'serviceHealth',
            'label' => 'Service Health',
            'weight' => 1.2,
            'supported' => true,
            'score' => $score,
            'band' => $this->band($score),
            'statement' => number_format((int) $service['repeatedSubjects']).' of '
                .number_format((int) $service['subjects']).' distinct subjects appear more than once — '
                .$this->pct((float) $service['repeatRate']).' recurrence.',
            'formula' => '1 − (subjects appearing more than once ÷ distinct subjects), across every dataset carrying a subject reference.',
            'inputs' => [
                'subjects' => (int) $service['subjects'],
                'repeatedSubjects' => (int) $service['repeatedSubjects'],
                'repeatRate' => $service['repeatRate'],
                'highestRepeatDataset' => $service['highestRepeatDataset'],
            ],
        ];
    }

    /**
     * Whether the organization's registered units are visible in its data.
     *
     * TWO FAILURES, ONE SCORE, AND BOTH ARE REAL. A unit on the register with no
     * recorded activity is invisible to every operational figure the product
     * produces; a concentration where one unit carries almost everything means the
     * same thing for the rest. The score is the share of registered units that
     * carry any recorded work, which captures the first directly and the second by
     * construction.
     *
     * @param  array<string, mixed>  $ops
     * @param  array<string, mixed>  $foundation
     * @return array<string, mixed>|null
     */
    private function departmentHealth(array $ops, array $foundation): ?array
    {
        $registered = (int) ($foundation['departments']['active'] ?? 0);
        $withActivity = (int) ($ops['totals']['departmentsWithActivity'] ?? 0);

        if ($registered <= 0) {
            return $this->unsupported(
                'departmentHealth',
                'Department Coverage',
                0.9,
                'This organization has no active units on its register, so unit coverage cannot be assessed.',
                'Register the organization\'s departments or units in the connected HR system.',
            );
        }

        if (($ops['support']['department'] ?? false) !== true) {
            return $this->unsupported(
                'departmentHealth',
                'Department Coverage',
                0.9,
                (string) ($ops['support']['reasons']['department'] ?? 'Imported records do not name an owning unit.'),
                'Include the owning department on operational exports so work can be attributed to a unit.',
            );
        }

        // The register carries units the imports do not name, and imports can name
        // units the register does not carry. Capped at 100 so the second case
        // cannot produce a score above full marks.
        $score = (int) round(min(1.0, $withActivity / $registered) * 100);

        return [
            'key' => 'departmentHealth',
            'label' => 'Department Coverage',
            'weight' => 0.9,
            'supported' => true,
            'score' => $score,
            'band' => $this->band($score),
            'statement' => $withActivity.' of '.$registered.' active units appear in the operational data; the rest record no work this engine can see.',
            'formula' => 'units named by imported records ÷ active units on the register, capped at 1.',
            'inputs' => [
                'registeredUnits' => $registered,
                'unitsWithActivity' => $withActivity,
                'concentration' => $ops['rankings']['concentration']['departments'] ?? null,
            ],
        ];
    }

    /**
     * Whether detected signals are being closed out, and how many severe ones sit open.
     *
     * @param  array<string, mixed>  $loop
     * @return array<string, mixed>|null
     */
    private function signalHealth(array $loop): ?array
    {
        $signals = $loop['signals'] ?? [];

        if (($signals['supported'] ?? false) !== true) {
            return $this->unsupported(
                'signalHealth',
                'Signal Health',
                1.0,
                (string) ($signals['reason'] ?? 'No detector has fired for this organization.'),
                'Ingest operational data so the detector set has something to read.',
            );
        }

        $total = (int) $signals['total'];
        $resolutionRate = (float) ($signals['resolutionRate'] ?? 0.0);
        $highOpen = (int) $signals['highSeverityOpen'];

        /*
          TWO TERMS, WEIGHTED THREE TO TWO.

          Resolution rate alone would rate an organization with one unresolved
          critical signal identically to one with twelve, and severity is the
          whole reason a triage queue exists. The severity term is the share of
          signals that are NOT open at high or critical severity, which is bounded
          in the same 0-1 range as the first and needs no scaling constant.
        */
        $severityTerm = $total > 0 ? 1 - ($highOpen / $total) : 1.0;
        $score = (int) round((($resolutionRate * 0.6) + ($severityTerm * 0.4)) * 100);

        return [
            'key' => 'signalHealth',
            'label' => 'Signal Health',
            'weight' => 1.0,
            'supported' => true,
            'score' => $score,
            'band' => $this->band($score),
            'statement' => $total.' signals detected, '.(int) $signals['resolved'].' resolved, '
                .$highOpen.' still open at high or critical severity.',
            'formula' => '(resolution rate × 0.6) + (share of signals not open at high severity × 0.4).',
            'inputs' => [
                'total' => $total,
                'open' => (int) $signals['open'],
                'resolved' => (int) $signals['resolved'],
                'highSeverityOpen' => $highOpen,
                'resolutionRate' => $signals['resolutionRate'],
                'distinctRules' => (int) $signals['distinctRules'],
            ],
        ];
    }

    /**
     * Whether the signals that exist are grounded in observed source records.
     *
     * @param  array<string, mixed>  $loop
     * @return array<string, mixed>|null
     */
    private function evidenceStrength(array $loop): ?array
    {
        $signals = $loop['signals'] ?? [];
        $evidence = $loop['evidence'] ?? [];

        if (($evidence['supported'] ?? false) !== true || ($signals['supported'] ?? false) !== true) {
            return $this->unsupported(
                'evidenceStrength',
                'Evidence Strength',
                1.0,
                (string) ($evidence['reason'] ?? 'No evidence has been recorded.'),
                'Evidence is written by the detectors alongside signals; it appears with the first grounded detection.',
            );
        }

        $score = (int) round(((float) ($signals['groundedRate'] ?? 0.0)) * 100);

        return [
            'key' => 'evidenceStrength',
            'label' => 'Evidence Strength',
            'weight' => 1.0,
            'supported' => true,
            'score' => $score,
            'band' => $this->band($score),
            'statement' => (int) $signals['grounded'].' of '.(int) $signals['total'].' signals cite at least one observed source record; '
                .(int) $evidence['total'].' observations are held in total.',
            'formula' => 'signals citing at least one evidence row ÷ total signals.',
            'inputs' => [
                'evidenceRows' => (int) $evidence['total'],
                'signalsGrounded' => (int) $signals['grounded'],
                'signalsUngrounded' => (int) $signals['ungrounded'],
                'evidencePerSignal' => $evidence['perSignalAverage'],
                'freshnessDays' => $evidence['freshnessDays'],
            ],
        ];
    }

    /**
     * Whether open investigations are moving.
     *
     * @param  array<string, mixed>  $loop
     * @return array<string, mixed>|null
     */
    private function deliberationHealth(array $loop): ?array
    {
        $cases = $loop['cases'] ?? [];

        if (($cases['supported'] ?? false) !== true) {
            return $this->unsupported(
                'deliberationHealth',
                'Deliberation Health',
                0.8,
                (string) ($cases['reason'] ?? 'No investigation has been opened.'),
                'Triage a detected signal into an investigation to start the deliberation loop.',
            );
        }

        $total = (int) $cases['total'];
        $withHypothesis = (int) $cases['withHypothesis'];

        $score = $total > 0 ? (int) round(($withHypothesis / $total) * 100) : 0;

        return [
            'key' => 'deliberationHealth',
            'label' => 'Deliberation Health',
            'weight' => 0.8,
            'supported' => true,
            'score' => $score,
            'band' => $this->band($score),
            'statement' => $withHypothesis.' of '.$total.' open investigations have reached a proposed cause; '
                .(int) $cases['awaitingHypothesis'].' are still awaiting one.',
            'formula' => 'investigations carrying at least one hypothesis ÷ total investigations.',
            'inputs' => [
                'total' => $total,
                'open' => (int) $cases['open'],
                'withHypothesis' => $withHypothesis,
                'awaitingHypothesis' => (int) $cases['awaitingHypothesis'],
                'averageAgeDays' => $cases['averageAgeDays'],
                'oldestOpenDays' => $cases['oldestOpenDays'],
            ],
        ];
    }

    /**
     * Whether anyone's skills have been assessed at all.
     *
     * THIS IS THE DIMENSION THE WHOLE `supported` MECHANISM EXISTS FOR. Most
     * organizations connecting this product have never recorded a capability
     * assignment, and the screen used to answer that with a large "0" — which
     * reads as "this organization has no capabilities". It says nothing of the
     * sort. It says nobody has been assessed. The dimension leaves the score
     * entirely and appears under `unmeasured` with the step that would populate it.
     *
     * @return array<string, mixed>
     */
    private function capabilityCoverage(string $tenantId): array
    {
        $unsupported = fn (string $reason) => $this->unsupported(
            'capabilityCoverage',
            'Capability Coverage',
            1.0,
            $reason,
            'Assign capabilities to departments and people to unlock skill-gap and readiness intelligence.',
        );

        if (! Schema::hasTable('hpbrain_capability_assignments') || ! Schema::hasTable('hpbrain_capabilities')) {
            return $unsupported('This installation has no capability store.');
        }

        $defined = DB::table('hpbrain_capabilities')->where('tenant_id', $tenantId)->count();
        $assigned = DB::table('hpbrain_capability_assignments')->where('tenant_id', $tenantId)->count();

        if ($assigned === 0) {
            return $unsupported(
                $defined === 0
                    ? 'No capability has been defined for this organization, and none is assigned, so skill coverage is not measured.'
                    : $defined.' capabilities are defined but none is assigned to a person or unit, so coverage is not measured.',
            );
        }

        if (! $this->resolver->has($tenantId, 'Person')) {
            return $unsupported('This organization has no person register mapped, so assignment coverage has no denominator.');
        }

        $people = (int) ($this->foundation->forTenant($tenantId)['people']['total'] ?? 0);

        if ($people <= 0) {
            return $unsupported('This organization has no active people on its register, so assignment coverage has no denominator.');
        }

        $assessed = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $tenantId)
            ->distinct()
            ->count('person_id');

        $score = (int) round(min(1.0, $assessed / $people) * 100);

        return [
            'key' => 'capabilityCoverage',
            'label' => 'Capability Coverage',
            'weight' => 1.0,
            'supported' => true,
            'score' => $score,
            'band' => $this->band($score),
            'statement' => $assessed.' of '.$people.' people carry at least one capability assignment, across '.$defined.' defined capabilities.',
            'formula' => 'people with at least one capability assignment ÷ active people on the register.',
            'inputs' => [
                'capabilitiesDefined' => $defined,
                'assignments' => $assigned,
                'peopleAssessed' => $assessed,
                'people' => $people,
            ],
        ];
    }

    /* ─────────────────────────── interpretation ─────────────────────────── */

    /**
     * @param  array<int, array<string, mixed>>  $scored
     * @return array<int, array<string, mixed>>
     */
    private function strengths(array $scored): array
    {
        $out = [];

        foreach ($scored as $d) {
            if ($d['score'] < 80) {
                continue;
            }

            $out[] = [
                'dimension' => $d['label'],
                'score' => $d['score'],
                'statement' => $d['statement'],
            ];
        }

        return array_slice($out, 0, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $scored
     * @return array<int, array<string, mixed>>
     */
    private function risks(array $scored): array
    {
        $out = [];

        foreach ($scored as $d) {
            if ($d['score'] >= 70) {
                continue;
            }

            $out[] = [
                'dimension' => $d['label'],
                'score' => $d['score'],
                'severity' => $d['score'] < 55 ? 'high' : 'medium',
                'statement' => $d['statement'],
                'weight' => $d['weight'],
            ];
        }

        usort($out, fn ($a, $b) => ($a['score'] * $b['weight']) <=> ($b['score'] * $a['weight']));

        return array_slice($out, 0, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $unmeasured
     * @return array<int, array<string, mixed>>
     */
    private function opportunities(array $unmeasured): array
    {
        $out = [];

        foreach ($unmeasured as $d) {
            $out[] = [
                'dimension' => $d['label'],
                'reason' => $d['reason'],
                'unlocks' => $d['nextStep'],
                'weightIfMeasured' => $d['weight'],
            ];
        }

        usort($out, fn ($a, $b) => $b['weightIfMeasured'] <=> $a['weightIfMeasured']);

        return $out;
    }

    /**
     * The single thing worth doing next.
     *
     * The lowest-scoring supported dimension weighted by how much it matters,
     * unless nothing is failing — in which case the highest-weighted unmeasured
     * dimension, because the next largest gain is then in what is not yet visible
     * rather than in what already is.
     *
     * @param  array<int, array<string, mixed>>  $scored
     * @param  array<int, array<string, mixed>>  $unmeasured
     * @return array<string, mixed>|null
     */
    private function recommendedFocus(array $scored, array $unmeasured): ?array
    {
        $risks = $this->risks($scored);

        if ($risks !== []) {
            return [
                'type' => 'improve',
                'dimension' => $risks[0]['dimension'],
                'score' => $risks[0]['score'],
                'why' => $risks[0]['statement'],
            ];
        }

        $opportunities = $this->opportunities($unmeasured);

        if ($opportunities !== []) {
            return [
                'type' => 'measure',
                'dimension' => $opportunities[0]['dimension'],
                'score' => null,
                'why' => $opportunities[0]['reason'].' '.$opportunities[0]['unlocks'],
            ];
        }

        return null;
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function unsupported(string $key, string $label, float $weight, string $reason, string $nextStep): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'weight' => $weight,
            'supported' => false,
            'score' => null,
            'band' => null,
            'statement' => 'Not measurable from connected sources.',
            'reason' => $reason,
            'nextStep' => $nextStep,
        ];
    }

    private function band(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return match (true) {
            $score >= 85 => 'excellent',
            $score >= 70 => 'healthy',
            $score >= 55 => 'watch',
            default => 'needs attention',
        };
    }

    private function pct(float $ratio): string
    {
        return round($ratio * 100, 1).'%';
    }

    private function hours(float $hours): string
    {
        if ($hours < 48) {
            return round($hours, 1).' hours';
        }

        return round($hours / 24, 1).' days';
    }
}
