<?php

declare(strict_types=1);

namespace App\Domain\People;

use Illuminate\Support\Facades\DB;

/**
 * The "records" pane of the Person Profile.
 *
 * Reads ONLY the operational records the person-attachment engine has already
 * linked to this person (via subject_ref / owner_name / supervisor_name exact
 * match) and renders them paginated. The dataset shape is the universal
 * hpbrain_operational_records row, so the same code works for schools, fibre
 * operators, hospitals, etc. — no per-industry branch.
 *
 * MISMATCH DETECTION (D3) is computed here because the records are the source
 * of truth: a "mismatch day" is a date where a present-style record from one
 * dataset coexists with an absent-style record from another dataset under the
 * same owner_name. The cross-source contradiction is reported as a data-quality
 * issue, never as misconduct (R5).
 */
final class PersonRecordsService
{
    private const PAGE_SIZE = 25;
    private const MISMATCH_LIMIT = 6;

    public function __construct(private readonly PersonAttachmentService $attachment)
    {
    }

    /**
     * @return array{
     *   summary: array<int, array<string, mixed>>,
     *   page: array{page:int, pageSize:int, total:int, items:array<int, array<string, mixed>>}
     * }
     */
    public function build(string $tenantId, string $personId, int $page = 1, int $pageSize = self::PAGE_SIZE): array
    {
        $attachment = $this->attachment->rules($tenantId, $personId);

        if (! ($attachment['available'] ?? false) || ($attachment['records'] ?? 0) === 0) {
            return [
                'summary' => [],
                'page' => ['page' => 1, 'pageSize' => $pageSize, 'total' => 0, 'items' => []],
            ];
        }

        $total = (int) $attachment['records'];

        $rows = $this->recordQuery($tenantId, $attachment['rules'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_date')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get([
                'id', 'dataset', 'natural_key', 'source_file', 'source_row', 'occurred_at', 'closed_at',
                'status', 'category', 'sub_category', 'owner_name', 'supervisor_name', 'subject_ref',
                'area', 'zone', 'metric_value', 'metric_unit', 'quantity', 'payload', 'import_job_id', 'created_date',
            ]);

        $mismatchDates = $this->mismatchDates($tenantId, $attachment['rules']);

        $items = $rows->map(function ($r) use ($attachment, $mismatchDates) {
            $payload = $this->payload($r->payload ?? null);
            $matched = [];
            foreach ($attachment['rules'] as $rule) {
                if ((string) ($r->{$rule['column']} ?? '') === $rule['value']) {
                    $matched[] = $rule['label'];
                }
            }

            $dateKey = $r->occurred_at ? substr((string) $r->occurred_at, 0, 10) : null;
            $isMismatch = $dateKey !== null && isset($mismatchDates[$dateKey]);

            return [
                'id' => (string) $r->id,
                'date' => $r->occurred_at,
                'type' => (string) $r->dataset,
                'recordKey' => (string) $r->natural_key,
                'status' => $r->status,
                'amount' => $r->metric_value === null ? null : round((float) $r->metric_value, 2),
                'currency' => $r->metric_unit,
                'category' => $r->category,
                'subCategory' => $r->sub_category,
                'matchedBy' => $matched,
                'sourceFile' => $r->source_file,
                'mismatch' => $isMismatch,
            ];
        })->values()->all();

        return [
            'summary' => $this->summaryByDataset($tenantId, $attachment['rules']),
            'page' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'items' => $items,
            ],
        ];
    }

    /**
     * D3: mismatches between present/absent across datasets for the same
     * owner_name on the same date. Returns count + sample dates + likely cause.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array{count:int, windowDays:int, sampleDates:array<int, string>, likelyCause:string, dates:array<string, true>}
     */
    public function mismatches(string $tenantId, array $rules): array
    {
        if ($rules === []) {
            return ['count' => 0, 'windowDays' => 0, 'sampleDates' => [], 'likelyCause' => '', 'dates' => []];
        }

        $present = $this->presentStatuses();
        $absent = $this->absentStatuses();

        $byDate = $this->recordQuery($tenantId, $rules)
            ->whereNotNull('occurred_at')
            ->select('occurred_at', 'dataset', 'status', 'owner_name')
            ->orderBy('occurred_at')
            ->get()
            ->groupBy(fn ($r) => substr((string) $r->occurred_at, 0, 10));

        $dates = [];
        $samples = [];

        foreach ($byDate as $date => $rows) {
            $hasPresent = false;
            $hasAbsent = false;
            foreach ($rows as $r) {
                $s = strtolower(trim((string) ($r->status ?? '')));
                if (in_array($s, $present, true)) {
                    $hasPresent = true;
                }
                if (in_array($s, $absent, true)) {
                    $hasAbsent = true;
                }
                if ($hasPresent && $hasAbsent) {
                    break;
                }
            }
            if ($hasPresent && $hasAbsent) {
                $dates[$date] = true;
                if (count($samples) < self::MISMATCH_LIMIT) {
                    $samples[] = $date;
                }
            }
        }

        $count = count($dates);
        // The span the contradictions were found across, so "12 days" can be
        // read against how long a period they came from. It was previously the
        // count again, which told the reader nothing they did not already have.
        $allDates = $byDate->keys()->all();
        $windowDays = 0;
        if ($count > 0 && $allDates !== []) {
            $first = min($allDates);
            $last = max($allDates);
            $windowDays = (int) \Carbon\CarbonImmutable::parse($first)->diffInDays(\Carbon\CarbonImmutable::parse($last)) + 1;
        }

        return [
            'count' => $count,
            'windowDays' => $windowDays,
            'sampleDates' => $samples,
            'likelyCause' => $count > 0 ? 'likely device sync or import mapping' : '',
            'dates' => $dates,
        ];
    }

    /**
     * Rules that have been verified as "cleared" — i.e. resolved contradictions
     * in the data. Surfaced as a positive signal on the Intelligence tab.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array{rule:string, detail:string}>
     */
    public function cleared(string $tenantId, array $rules): array
    {
        $cleared = [];

        if ($rules === []) {
            return $cleared;
        }

        $checkinCount = (int) $this->recordQuery($tenantId, $rules)
            ->whereIn('dataset', ['EmployeeCheckin', 'Checkin', 'field_attendance'])
            ->count();

        if ($checkinCount > 0) {
            $null = (int) $this->recordQuery($tenantId, $rules)
                ->whereIn('dataset', ['EmployeeCheckin', 'Checkin', 'field_attendance'])
                ->whereNull('status')
                ->count();

            if ($null === 0) {
                $cleared[] = [
                    'rule' => 'Check-in statuses complete',
                    'detail' => "All {$checkinCount} check-in records have a status — no rows missing the present/absent flag.",
                ];
            }
        }

        $openComplaints = (int) $this->recordQuery($tenantId, $rules)
            ->where('dataset', 'complaint')
            ->whereIn('status', ['open', 'in_progress', 'pending'])
            ->count();

        $totalComplaints = (int) $this->recordQuery($tenantId, $rules)
            ->where('dataset', 'complaint')
            ->count();

        if ($totalComplaints > 0 && $openComplaints === 0) {
            $cleared[] = [
                'rule' => 'No open complaints',
                'detail' => "All {$totalComplaints} complaints are closed for this person.",
            ];
        }

        return $cleared;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return \Illuminate\Database\Query\Builder
     */
    private function recordQuery(string $tenantId, array $rules)
    {
        $q = DB::table('hpbrain_operational_records')->where('tenant_id', $tenantId);
        if ($rules === []) {
            return $q->whereRaw('1 = 0');
        }
        return $q->where(function ($w) use ($rules) {
            foreach ($rules as $rule) {
                $w->orWhere($rule['column'], $rule['value']);
            }
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function summaryByDataset(string $tenantId, array $rules): array
    {
        $rows = $this->recordQuery($tenantId, $rules)
            ->select('dataset',
                DB::raw('COUNT(*) as total'),
                DB::raw('MIN(occurred_at) as first_at'),
                DB::raw('MAX(occurred_at) as last_at'))
            ->groupBy('dataset')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($r) => [
            'type' => (string) $r->dataset,
            'count' => (int) $r->total,
            'from' => $r->first_at,
            'to' => $r->last_at,
        ])->values()->all();
    }

    /** @return array<string, true> */
    private function mismatchDates(string $tenantId, array $rules): array
    {
        return $this->mismatches($tenantId, $rules)['dates'];
    }

    /** @return array<int, string> */
    /*
        WORDS ONLY. A BARE NUMBER IS NOT AN ATTENDANCE STATE.

        "0" and "1" used to sit in these lists. In this ERP's export every one
        of the 392 employee_checkin rows carries status "0" — a source-system
        flag the importer mapped into the status column, not a verdict on the
        day. Read as "absent" it contradicted the attendance dataset on every
        day the person worked: 163 manufactured contradictions for someone
        present on 97% of their recorded days, enough to cap the standing
        penalty and move them a whole band, and to put "163 days where check-in
        and attendance disagree" in front of their manager.

        A numeric flag column in an ERP means docstatus, enabled, is_active or
        a dozen other things far more often than it means absence, so it is not
        evidence either way. A dataset that records attendance in words still
        testifies exactly as before.
    */
    private function presentStatuses(): array
    {
        return ['present', 'p', 'checked_in', 'checked-in', 'in', 'attended', 'half day', 'half-day'];
    }

    /** @return array<int, string> */
    private function absentStatuses(): array
    {
        return ['absent', 'a', 'missing', 'not_checked_in', 'no_show', 'no-show', 'leave'];
    }

    /** @return array<string, mixed> */
    private function payload(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
