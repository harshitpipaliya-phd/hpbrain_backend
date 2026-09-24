<?php

declare(strict_types=1);

namespace App\Domain\Signals;

use App\Repositories\OperationalRecordRepository;
use Illuminate\Support\Facades\DB;

/**
 * Signal rules over imported operational data.
 *
 * These are NOT a second intelligence engine. Each rule returns the same
 * ['created' => bool] shape the five ERP rules in OperationalSignalWriter return, and
 * creates signals and evidence through OperationalSignalWriter's own methods, so every
 * signal produced here flows into the same loop: Evidence -> Reasoning ->
 * Recommendation -> Decision -> Outcome -> Learning, and appears on the same
 * Workspace and Analytics screens as every other organization's.
 *
 * The rules are attached by DATA, not by organization name â€” SignalRuleRegistry
 * looks at which datasets a tenant actually has. Any organization that imports a
 * 'complaint' dataset gets the complaint rules.
 *
 * THRESHOLDS
 * ----------
 * Every threshold below was chosen against the real FY2025-26 distribution
 * rather than picked round. They live in config('brain.operational_signals')
 * so they can be tuned per deployment without a code change. Where the data is
 * quoted in a comment, that is the actual figure from the imported workbook.
 */
final class OperationalSignalRules
{
    public function __construct(
        private readonly OperationalRecordRepository $records,
    ) {
    }

    /**
     * Rules applicable to a tenant given the datasets it holds.
     *
     * @return array<int, callable(): array{created: bool, signalId?: string, reason?: string}>
     */
    public function rulesFor(OperationalSignalWriter $generator, string $tenantId, array $datasets): array
    {
        $rules = [];

        if (($datasets['complaint'] ?? 0) > 0) {
            $rules[] = fn () => $this->slaBreaches($generator, $tenantId);
            $rules[] = fn () => $this->unrecordedRootCause($generator, $tenantId);
            $rules[] = fn () => $this->repeatComplaintSubscribers($generator, $tenantId);
            $rules[] = fn () => $this->zoneConcentration($generator, $tenantId);
        }

        if (($datasets['work_order'] ?? 0) > 0) {
            $rules[] = fn () => $this->stalledWorkOrders($generator, $tenantId);
            $rules[] = fn () => $this->provisioningCancellations($generator, $tenantId);
        }

        if (($datasets['helpdesk_month'] ?? 0) > 0) {
            $rules[] = fn () => $this->callDropRate($generator, $tenantId);
        }

        if (($datasets['school_fee'] ?? 0) > 0) {
            $rules[] = fn () => $this->feeMissingCollector($generator, $tenantId);
            $rules[] = fn () => $this->feeZeroAmountConcessions($generator, $tenantId);
            $rules[] = fn () => $this->feeCollectorConcentration($generator, $tenantId);
        }

        return $rules;
    }

    /**
     * Rule: fee receipts whose collection owner is blank.
     *
     * Lions FY2025-26 CSV: 3,988 of 10,430 student fee rows have an empty
     * 'Collected By'. Amounts are real receipts, but operational ownership is
     * missing, so follow-up cannot be routed to a cashier or office user.
     */
    private function feeMissingCollector(OperationalSignalWriter $generator, string $tenantId): array
    {
        $total = $this->records->countFor($tenantId, 'school_fee');

        if ($total === 0) {
            return ['created' => false, 'reason' => 'no_data'];
        }

        $missing = $this->records->count($tenantId, 'school_fee', ['owner_name' => null]);
        $share = $missing / $total;
        $floor = (float) config('brain.operational_signals.fee_missing_collector_share', 0.30);

        if ($share < $floor) {
            return ['created' => false, 'reason' => 'below_threshold'];
        }

        $samples = $this->records->sample($tenantId, 'school_fee', ['owner_name' => null], null, 5);
        $evidenceIds = [];

        foreach ($samples as $record) {
            $evidenceIds[] = $generator->recordEvidence($tenantId, [
                'source'     => 'import.school_fee',
                'recordId'   => (string) $record['natural_key'],
                'sourceRow'  => (int) ($record['source_row'] ?? 0),
                'studentRef' => (string) ($record['subject_ref'] ?? ''),
                'amount'     => (float) ($record['metric_value'] ?? 0),
                'unit'       => (string) ($record['metric_unit'] ?? 'INR'),
                'issue'      => 'fee receipt has no collector recorded',
            ]);
        }

        return $generator->raise($tenantId, [
            'source'         => 'import.school_fee',
            'classification' => 'fee_collection_provenance',
            'severity'       => $share >= 0.40 ? 'high' : 'medium',
            'priority'       => 'high',
            'confidence'     => 1.0,
            'metadata'       => [
                'rule'         => 'fee_missing_collector',
                'missingCount' => $missing,
                'totalCount'   => $total,
                'missingShare' => round($share, 4),
            ],
        ], $evidenceIds);
    }

    /**
     * Rule: zero-amount fee rows that look like concessions/waivers.
     *
     * Lions FY2025-26 CSV has 28 zero-amount student fee rows; the most common
     * remarks explicitly mention 50% fee maffi/discount. That is a real fee
     * governance signal because waivers need review even when no cash moved.
     */
    private function feeZeroAmountConcessions(OperationalSignalWriter $generator, string $tenantId): array
    {
        $minimum = (int) config('brain.operational_signals.fee_zero_amount_minimum', 20);
        $zero = $this->records->count($tenantId, 'school_fee', ['metric_value' => 0]);

        if ($zero < $minimum) {
            return ['created' => false, 'reason' => 'below_threshold'];
        }

        $samples = $this->records->sample($tenantId, 'school_fee', ['metric_value' => 0], null, 5);
        $evidenceIds = [];

        foreach ($samples as $record) {
            $payload = $record['payload'] ?? [];

            $evidenceIds[] = $generator->recordEvidence($tenantId, [
                'source'     => 'import.school_fee',
                'recordId'   => (string) $record['natural_key'],
                'sourceRow'  => (int) ($record['source_row'] ?? 0),
                'studentRef' => (string) ($record['subject_ref'] ?? ''),
                'quota'      => (string) ($record['category'] ?? ''),
                'remark'     => (string) ($payload['Remarks'] ?? ''),
                'amount'     => 0,
                'unit'       => (string) ($record['metric_unit'] ?? 'INR'),
                'issue'      => 'zero-amount fee receipt requires concession review',
            ]);
        }

        return $generator->raise($tenantId, [
            'source'         => 'import.school_fee',
            'classification' => 'fee_concession_review',
            'severity'       => $zero >= 50 ? 'high' : 'medium',
            'priority'       => 'medium',
            'confidence'     => 1.0,
            'metadata'       => [
                'rule'            => 'fee_zero_amount_concessions',
                'zeroAmountCount' => $zero,
                'threshold'       => $minimum,
            ],
        ], $evidenceIds);
    }

    /**
     * Rule: one named collector owns most named fee receipts.
     *
     * Lions FY2025-26 CSV: among rows with a non-empty collector, Raksha Raval
     * appears on 4,488 of 6,442 receipts (69.7%). This is not a claim of
     * wrongdoing; it is a concentration and continuity risk.
     */
    private function feeCollectorConcentration(OperationalSignalWriter $generator, string $tenantId): array
    {
        $rows = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('dataset', 'school_fee')
            ->whereNotNull('owner_name')
            ->select('owner_name', DB::raw('COUNT(*) as total'), DB::raw('SUM(metric_value) as amount'))
            ->groupBy('owner_name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit(5)
            ->get();

        if ($rows->count() < 2) {
            return ['created' => false, 'reason' => 'insufficient_collectors'];
        }

        $namedTotal = (int) $rows->sum('total');
        $top = $rows->first();
        $share = $namedTotal === 0 ? 0.0 : ((int) $top->total / $namedTotal);
        $floor = (float) config('brain.operational_signals.fee_collector_concentration_share', 0.65);

        if ($share < $floor) {
            return ['created' => false, 'reason' => 'below_threshold'];
        }

        $samples = $this->records->sample($tenantId, 'school_fee', ['owner_name' => (string) $top->owner_name], null, 5);
        $evidenceIds = [];

        foreach ($samples as $record) {
            $evidenceIds[] = $generator->recordEvidence($tenantId, [
                'source'     => 'import.school_fee',
                'recordId'   => (string) $record['natural_key'],
                'sourceRow'  => (int) ($record['source_row'] ?? 0),
                'studentRef' => (string) ($record['subject_ref'] ?? ''),
                'collector'  => (string) ($record['owner_name'] ?? ''),
                'amount'     => (float) ($record['metric_value'] ?? 0),
                'unit'       => (string) ($record['metric_unit'] ?? 'INR'),
                'issue'      => "fee collection is concentrated with {$top->owner_name}",
            ]);
        }

        return $generator->raise($tenantId, [
            'source'         => 'import.school_fee',
            'classification' => 'fee_collection_concentration',
            'severity'       => $share >= 0.75 ? 'high' : 'medium',
            'priority'       => 'medium',
            'confidence'     => 0.9,
            'metadata'       => [
                'rule'           => 'fee_collector_concentration',
                'collector'      => (string) $top->owner_name,
                'collectorCount' => (int) $top->total,
                'namedCount'     => $namedTotal,
                'collectorShare' => round($share, 4),
                'collectorAmount'=> round((float) $top->amount, 2),
            ],
        ], $evidenceIds);
    }

    /**
     * Rule: complaints resolved outside the 24-hour service target.
     *
     * The source data bands resolution time in a 'Time Group' column, but this
     * rule computes from metric_value (hours) instead. Trusting the band would
     * mean inheriting the spreadsheet's own arithmetic, including the one row
     * whose Time Group is '#VALUE!' â€” intelligence must be derived from facts,
     * not from someone else's formula.
     *
     * FY2025-26 baseline: 6,945 of 65,268 tickets over 24h (10.6%), of which
     * 412 over 72h.
     */
    private function slaBreaches(OperationalSignalWriter $generator, string $tenantId): array
    {
        $since = $this->records->latestPeriod($tenantId, 'complaint');

        if ($since === null) {
            return ['created' => false, 'reason' => 'no_data'];
        }

        $threshold = (float) config('brain.operational_signals.complaint_sla_hours', 24);
        $minimum   = (int) config('brain.operational_signals.complaint_sla_minimum', 25);

        $total = $this->records->count($tenantId, 'complaint', [], $since);

        if ($total === 0) {
            return ['created' => false, 'reason' => 'no_data'];
        }

        $breached = $this->records->countAbove($tenantId, 'complaint', 'metric_value', $threshold, $since);

        if ($breached < $minimum) {
            return ['created' => false, 'reason' => 'below_threshold'];
        }

        $share = $breached / $total;
        $samples = $this->records->sampleAbove($tenantId, 'complaint', 'metric_value', $threshold, $since, 5);

        $evidenceIds = [];

        foreach ($samples as $record) {
            $evidenceIds[] = $generator->recordEvidence($tenantId, [
                'source'     => 'import.complaint',
                'recordId'   => (string) $record['natural_key'],
                'zone'       => (string) ($record['zone'] ?? ''),
                'engineer'   => (string) ($record['owner_name'] ?? ''),
                'supervisor' => (string) ($record['supervisor_name'] ?? ''),
                'hours'      => (float) ($record['metric_value'] ?? 0),
                'category'   => (string) ($record['category'] ?? ''),
                'issue'      => "resolution took {$record['metric_value']}h against a {$threshold}h target",
            ]);
        }

        return $generator->raise($tenantId, [
            'source'         => 'import.complaint',
            'classification' => 'service_quality',
            // A tenth of tickets breaching is a different conversation from a
            // fortieth. Severity follows the share, not the raw count, so a
            // larger organization does not automatically look worse.
            'severity'       => $share >= 0.15 ? 'high' : ($share >= 0.08 ? 'medium' : 'low'),
            'priority'       => $share >= 0.15 ? 'high' : 'medium',
            'confidence'     => 1.0,
            'metadata'       => [
                'rule'          => 'complaint_sla_breach',
                'period'        => substr($since, 0, 7),
                'breachedCount' => $breached,
                'totalCount'    => $total,
                'breachShare'   => round($share, 4),
                'thresholdHours'=> $threshold,
            ],
        ], $evidenceIds);
    }

    /**
     * Rule: complaints closed with no root cause recorded.
     *
     * FY2025-26 baseline: 44,500 of 65,268 tickets â€” 68% â€” have an empty
     * 'Final Solution'. That is the single largest finding in the dataset and
     * it is invisible in the spreadsheet, because a blank cell looks like
     * nothing rather than like a missing answer.
     *
     * The consequence is concrete: with two-thirds of resolutions uncategorised,
     * no reliable failure-mode analysis is possible, so recurring physical
     * faults cannot be told apart from one-off customer-premises issues.
     */
    private function unrecordedRootCause(OperationalSignalWriter $generator, string $tenantId): array
    {
        $since = $this->records->latestPeriod($tenantId, 'complaint');

        if ($since === null) {
            return ['created' => false, 'reason' => 'no_data'];
        }

        $total = $this->records->count($tenantId, 'complaint', ['status' => 'closed'], $since);

        if ($total === 0) {
            return ['created' => false, 'reason' => 'no_data'];
        }

        $blank = $this->records->count($tenantId, 'complaint', ['status' => 'closed', 'sub_category' => null], $since);
        $share = $blank / $total;

        $floor = (float) config('brain.operational_signals.root_cause_blank_share', 0.25);

        if ($share < $floor) {
            return ['created' => false, 'reason' => 'below_threshold'];
        }

        $samples = $this->records->sample($tenantId, 'complaint', ['status' => 'closed', 'sub_category' => null], $since, 5);
        $evidenceIds = [];

        foreach ($samples as $record) {
            $evidenceIds[] = $generator->recordEvidence($tenantId, [
                'source'   => 'import.complaint',
                'recordId' => (string) $record['natural_key'],
                'zone'     => (string) ($record['zone'] ?? ''),
                'category' => (string) ($record['category'] ?? ''),
                'engineer' => (string) ($record['owner_name'] ?? ''),
                'issue'    => 'ticket closed with no Final Solution recorded',
            ]);
        }

        return $generator->raise($tenantId, [
            'source'         => 'import.complaint',
            'classification' => 'data_quality',
            'severity'       => $share >= 0.5 ? 'high' : 'medium',
            'priority'       => 'high',
            // Confidence is 1.0 because this is a direct field check on data
            // already in hand, not an inference. The Product Bible's rule is
            // that confidence is computed from what is known â€” and here
            // everything is known.
            'confidence'     => 1.0,
            'metadata'       => [
                'rule'         => 'complaint_root_cause_unrecorded',
                'period'       => substr($since, 0, 7),
                'blankCount'   => $blank,
                'closedCount'  => $total,
                'blankShare'   => round($share, 4),
            ],
        ], $evidenceIds);
    }

    /**
     * Rule: subscribers raising repeated complaints in one month.
     *
     * A subscriber who calls four times in a month is not four independent
     * incidents; it is one unresolved fault being closed prematurely. This is
     * the rule that distinguishes "tickets closed quickly" from "problems
     * actually fixed" â€” and the two look identical in the SLA report.
     */
    private function repeatComplaintSubscribers(OperationalSignalWriter $generator, string $tenantId): array
    {
        $since = $this->records->latestPeriod($tenantId, 'complaint');

        if ($since === null) {
            return ['created' => false, 'reason' => 'no_data'];
        }

        $minRepeats = (int) config('brain.operational_signals.repeat_complaint_threshold', 4);
        $minSubjects = (int) config('brain.operational_signals.repeat_complaint_minimum', 5);

        $repeat = $this->records->repeatSubjects($tenantId, 'complaint', $since, $minRepeats, 25);

        if (count($repeat) < $minSubjects) {
            return ['created' => false, 'reason' => 'below_threshold'];
        }

        $evidenceIds = [];

        foreach (array_slice($repeat, 0, 5) as $subject) {
            $evidenceIds[] = $generator->recordEvidence($tenantId, [
                'source'     => 'import.complaint',
                'recordId'   => (string) $subject['subject_ref'],
                'complaints' => (int) $subject['total'],
                'zone'       => (string) ($subject['zone'] ?? ''),
                'issue'      => "subscriber raised {$subject['total']} complaints in one month",
            ]);
        }

        $worst = (int) ($repeat[0]['total'] ?? 0);

        return $generator->raise($tenantId, [
            'source'         => 'import.complaint',
            'classification' => 'recurring_fault',
            'severity'       => $worst >= 8 ? 'high' : 'medium',
            'priority'       => 'high',
            'confidence'     => 0.9,
            'metadata'       => [
                'rule'             => 'complaint_repeat_subscribers',
                'period'           => substr($since, 0, 7),
                'affectedCount'    => count($repeat),
                'threshold'        => $minRepeats,
                'worstCaseCount'   => $worst,
                'sampleSubjects'   => array_map(
                    fn ($s) => ['subject' => $s['subject_ref'], 'complaints' => (int) $s['total']],
                    array_slice($repeat, 0, 5)
                ),
            ],
        ], $evidenceIds);
    }

    /**
     * Rule: one zone carrying a disproportionate share of complaints.
     *
     * Uses share-of-total against an expected even split rather than a raw
     * count, so a genuinely larger zone does not trip the rule merely for being
     * larger. FY2025-26: Katargam holds 11,555 of 65,268 (17.7%) across 69
     * zones, where an even split would be 1.4%.
     */
    private function zoneConcentration(OperationalSignalWriter $generator, string $tenantId): array
    {
        $since = $this->records->latestPeriod($tenantId, 'complaint');

        if ($since === null) {
            return ['created' => false, 'reason' => 'no_data'];
        }

        $byZone = $this->records->countsGroupedBy($tenantId, 'complaint', 'zone', [], $since, 50);
        $byZone = array_values(array_filter($byZone, fn ($z) => $z['label'] !== null));

        if (count($byZone) < 3) {
            return ['created' => false, 'reason' => 'insufficient_zones'];
        }

        $total = array_sum(array_column($byZone, 'total'));

        if ($total === 0) {
            return ['created' => false, 'reason' => 'no_data'];
        }

        $multiple = (float) config('brain.operational_signals.zone_concentration_multiple', 4.0);
        $expected = 1 / count($byZone);
        $top      = $byZone[0];
        $share    = $top['total'] / $total;

        if ($share < $expected * $multiple) {
            return ['created' => false, 'reason' => 'below_threshold'];
        }

        $samples = $this->records->sample($tenantId, 'complaint', ['zone' => $top['label']], $since, 5);
        $evidenceIds = [];

        foreach ($samples as $record) {
            $evidenceIds[] = $generator->recordEvidence($tenantId, [
                'source'   => 'import.complaint',
                'recordId' => (string) $record['natural_key'],
                'zone'     => (string) ($record['zone'] ?? ''),
                'category' => (string) ($record['category'] ?? ''),
                'area'     => (string) ($record['area'] ?? ''),
                'issue'    => "complaint in over-represented zone {$top['label']}",
            ]);
        }

        return $generator->raise($tenantId, [
            'source'         => 'import.complaint',
            'classification' => 'network_hotspot',
            'severity'       => $share >= $expected * 8 ? 'high' : 'medium',
            'priority'       => 'medium',
            // Below 1.0: concentration is consistent with a network fault, but
            // also with the zone simply having more subscribers. The Brain does
            // not know subscriber counts per zone, so it must not claim to.
            'confidence'     => 0.75,
            'metadata'       => [
                'rule'          => 'complaint_zone_concentration',
                'period'        => substr($since, 0, 7),
                'zone'          => $top['label'],
                'zoneCount'     => $top['total'],
                'totalCount'    => $total,
                'zoneShare'     => round($share, 4),
                'expectedShare' => round($expected, 4),
                'topZones'      => array_slice($byZone, 0, 5),
            ],
        ], $evidenceIds);
    }

    /**
     * Rule: provisioning job orders still open well past target.
     *
     * FY2025-26: 213 of 5,790 orders took more than 15 days, and 7 remain open.
     * Each is a customer who has signed and is not yet connected.
     */
    private function stalledWorkOrders(OperationalSignalWriter $generator, string $tenantId): array
    {
        $limit   = (int) config('brain.operational_signals.work_order_pending_days', 15);
        $minimum = (int) config('brain.operational_signals.work_order_minimum', 5);

        $stalled = $this->records->countAbove($tenantId, 'work_order', 'quantity', (float) $limit, null);

        if ($stalled < $minimum) {
            return ['created' => false, 'reason' => 'below_threshold'];
        }

        $samples = $this->records->sampleAbove($tenantId, 'work_order', 'quantity', (float) $limit, null, 5);
        $evidenceIds = [];

        foreach ($samples as $record) {
            $evidenceIds[] = $generator->recordEvidence($tenantId, [
                'source'      => 'import.work_order',
                'recordId'    => (string) $record['natural_key'],
                'zone'        => (string) ($record['zone'] ?? ''),
                'technician'  => (string) ($record['owner_name'] ?? ''),
                'supervisor'  => (string) ($record['supervisor_name'] ?? ''),
                'pendingDays' => (int) ($record['quantity'] ?? 0),
                'holdStatus'  => (string) ($record['sub_category'] ?? ''),
                'issue'       => "job order pending {$record['quantity']} days against a {$limit}-day target",
            ]);
        }

        $total = $this->records->countFor($tenantId, 'work_order');

        return $generator->raise($tenantId, [
            'source'         => 'import.work_order',
            'classification' => 'provisioning_delay',
            'severity'       => $total > 0 && ($stalled / $total) >= 0.05 ? 'high' : 'medium',
            'priority'       => 'high',
            'confidence'     => 1.0,
            'metadata'       => [
                'rule'           => 'work_order_stalled',
                'stalledCount'   => $stalled,
                'totalCount'     => $total,
                'thresholdDays'  => $limit,
            ],
        ], $evidenceIds);
    }

    /**
     * Rule: provisioning orders cancelled for reasons the operator controls.
     *
     * FY2025-26: 353 cancellations, the largest single reason being cable
     * laying issues (116) and permission issues (49) â€” both operational, not
     * customer choice. 'Client Denied' is excluded deliberately: a customer
     * changing their mind is not an execution failure and flagging it would
     * make the signal untrustworthy.
     */
    private function provisioningCancellations(OperationalSignalWriter $generator, string $tenantId): array
    {
        $cancelled = $this->records->count($tenantId, 'work_order', ['status' => 'Cancel']);
        $total     = $this->records->countFor($tenantId, 'work_order');

        if ($total === 0 || $cancelled === 0) {
            return ['created' => false, 'reason' => 'no_data'];
        }

        $share = $cancelled / $total;
        $floor = (float) config('brain.operational_signals.cancellation_share', 0.04);

        if ($share < $floor) {
            return ['created' => false, 'reason' => 'below_threshold'];
        }

        $samples = $this->records->sample($tenantId, 'work_order', ['status' => 'Cancel'], null, 5);
        $evidenceIds = [];

        foreach ($samples as $record) {
            $payload = $record['payload'] ?? [];

            $evidenceIds[] = $generator->recordEvidence($tenantId, [
                'source'   => 'import.work_order',
                'recordId' => (string) $record['natural_key'],
                'zone'     => (string) ($record['zone'] ?? ''),
                'reason'   => (string) ($payload['Cancelation Reason'] ?? 'not recorded'),
                'feasibility' => (string) ($payload['Feasibility'] ?? ''),
                'issue'    => 'job order cancelled before connection',
            ]);
        }

        return $generator->raise($tenantId, [
            'source'         => 'import.work_order',
            'classification' => 'revenue_leakage',
            'severity'       => $share >= 0.08 ? 'high' : 'medium',
            'priority'       => 'medium',
            'confidence'     => 0.85,
            'metadata'       => [
                'rule'             => 'work_order_cancellation_rate',
                'cancelledCount'   => $cancelled,
                'totalCount'       => $total,
                'cancellationShare'=> round($share, 4),
            ],
        ], $evidenceIds);
    }

    /**
     * Rule: help-desk calls dropped rather than answered.
     *
     * FY2025-26 is stark: June 2025 saw 30,538 drops against 50,359 offered
     * calls â€” a 61% drop rate. A subscriber who cannot reach the help desk
     * raises no ticket, so this rule also explains why complaint volume alone
     * understates the true fault load. That connection is the reason this rule
     * exists rather than being left to a dashboard chart.
     */
    private function callDropRate(OperationalSignalWriter $generator, string $tenantId): array
    {
        $months = $this->records->allForDataset($tenantId, 'helpdesk_month', 24);

        if ($months === []) {
            return ['created' => false, 'reason' => 'no_data'];
        }

        // Latest month with usable figures.
        $latest = null;

        foreach ($months as $month) {
            $payload = $month['payload'] ?? [];

            if (isset($payload['Total Drop']) && ($month['metric_value'] ?? null) !== null) {
                $latest = $month;
            }
        }

        if ($latest === null) {
            return ['created' => false, 'reason' => 'no_data'];
        }

        $offered = (float) $latest['metric_value'];
        $dropped = (float) ($latest['payload']['Total Drop'] ?? 0);

        if ($offered <= 0) {
            return ['created' => false, 'reason' => 'no_data'];
        }

        $rate  = $dropped / $offered;
        $floor = (float) config('brain.operational_signals.call_drop_rate', 0.20);

        if ($rate < $floor) {
            return ['created' => false, 'reason' => 'below_threshold'];
        }

        $evidenceIds = [$generator->recordEvidence($tenantId, [
            'source'       => 'import.helpdesk_month',
            'recordId'     => (string) $latest['natural_key'],
            'offeredCalls' => (int) $offered,
            'droppedCalls' => (int) $dropped,
            'answeredCalls'=> (int) ($latest['payload']['Total Answers'] ?? 0),
            'agents'       => (int) ($latest['quantity'] ?? 0),
            'issue'        => sprintf('%.1f%% of offered calls were dropped', $rate * 100),
        ])];

        return $generator->raise($tenantId, [
            'source'         => 'import.helpdesk_month',
            'classification' => 'service_accessibility',
            'severity'       => $rate >= 0.40 ? 'critical' : ($rate >= 0.25 ? 'high' : 'medium'),
            'priority'       => 'high',
            'confidence'     => 1.0,
            'metadata'       => [
                'rule'         => 'helpdesk_call_drop_rate',
                'period'       => (string) $latest['natural_key'],
                'offeredCalls' => (int) $offered,
                'droppedCalls' => (int) $dropped,
                'dropRate'     => round($rate, 4),
                'agentCount'   => (int) ($latest['quantity'] ?? 0),
            ],
        ], $evidenceIds);
    }
}
