<?php

declare(strict_types=1);

namespace App\Domain\People;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * D1: weekly contribution trend, team share, high-load flag.
 * D2: presence (attendance %), streak, absence pattern, working hours.
 *
 * The two derivations live together because the underlying record set is the
 * same — operational records under the person's attachment rules. Splitting
 * the file further would just hide which queries share an index.
 */
final class PersonContributionPresenceService
{
    private const WEEKS = 8;
    private const ATTENDANCE_DAYS = 60;

    /** @var array<int, string> */
    private const ATTENDANCE_DATASETS = [
        'field_attendance', 'EmployeeCheckin', 'Checkin', 'Attendance',
        'helpdesk_attendance', 'attendance', 'checkin',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array{
     *   weeklyTrend: array<int, int>,
     *   handledTotal: int,
     *   handled30d: int,
     *   teamSharePct: float|null,
     *   highLoad: bool,
     *   supervisedCount: int,
     *   presence: array<string, mixed>,
     * }
     */
    public function build(string $tenantId, string $personId, ?int $deptId, array $rules): array
    {
        $weeklyTrend = $this->weeklyTrend($tenantId, $rules);
        $total = $this->handledTotal($tenantId, $rules);
        $handled30d = $this->handledInLastDays($tenantId, $rules, 30);

        $teamShare = $this->teamShare($tenantId, $deptId, $rules);
        $highLoad = $this->isHighLoad($tenantId, $deptId, $rules);

        $supervised = (int) DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('supervisor_name', $this->displayName($tenantId, $personId))
            ->count();

        $presence = $this->presence($tenantId, $rules);

        return [
            'weeklyTrend' => $weeklyTrend,
            'handledTotal' => $total,
            'handled30d' => $handled30d,
            'teamSharePct' => $teamShare,
            'highLoad' => $highLoad,
            'supervisedCount' => $supervised,
            'presence' => $presence,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, int>
     */
    private function weeklyTrend(string $tenantId, array $rules): array
    {
        $weeks = [];
        $now = CarbonImmutable::now();
        for ($i = self::WEEKS - 1; $i >= 0; $i--) {
            $start = $now->subWeeks($i)->startOfWeek();
            $weeks[$start->format('Y-m-d')] = 0;
        }

        if ($rules === []) {
            return array_values($weeks);
        }

        $earliest = array_key_first($weeks);
        $rows = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('owner_name', $this->ownerValue($rules))
            ->where('occurred_at', '>=', $earliest . ' 00:00:00')
            ->select('occurred_at')
            ->get(['occurred_at']);

        foreach ($rows as $r) {
            $weekStart = CarbonImmutable::parse($r->occurred_at)->startOfWeek()->format('Y-m-d');
            if (isset($weeks[$weekStart])) {
                $weeks[$weekStart]++;
            }
        }

        return array_values($weeks);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    /**
     * EVERY RECORD THIS PERSON HANDLED, NOT THE ONES IN THE TREND WINDOW.
     *
     * This used to be `array_sum($weeklyTrend)` — the total of the last eight
     * weeks. On a tenant whose import covers a finished financial year that is
     * zero, and the Contribution card read "0 handled" on the same screen whose
     * Records tab listed 387 rows for the same person. Two numbers describing
     * one set, disagreeing, is the exact failure this profile exists to catch.
     *
     * The trend keeps its 8-week window: it answers "how steady is the load",
     * which is a question about recent weeks. This answers "how much has this
     * person handled", which is not.
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function handledTotal(string $tenantId, array $rules): int
    {
        if ($rules === []) {
            return 0;
        }

        return (int) DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('owner_name', $this->ownerValue($rules))
            ->count();
    }

    private function handledInLastDays(string $tenantId, array $rules, int $days): int
    {
        if ($rules === []) {
            return 0;
        }
        $from = CarbonImmutable::now()->subDays($days)->format('Y-m-d');
        return (int) DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('owner_name', $this->ownerValue($rules))
            ->where('occurred_at', '>=', $from)
            ->count();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function teamShare(string $tenantId, ?int $deptId, array $rules): ?float
    {
        if ($deptId === null || $rules === []) {
            return null;
        }
        $owner = $this->ownerValue($rules);
        $deptMemberNames = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('department_id', $deptId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->get(['first_name', 'last_name'])
            ->map(fn ($u) => trim(((string) $u->first_name) . ' ' . ((string) $u->last_name)))
            ->filter(fn ($n) => $n !== '')
            ->all();

        if ($deptMemberNames === []) {
            return null;
        }

        $deptTotal = (int) DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->whereIn('owner_name', $deptMemberNames)
            ->count();

        $personTotal = (int) DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('owner_name', $owner)
            ->count();

        if ($deptTotal <= 0) {
            return null;
        }

        return round(($personTotal / $deptTotal) * 100, 1);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function isHighLoad(string $tenantId, ?int $deptId, array $rules): bool
    {
        if ($deptId === null || $rules === []) {
            return false;
        }
        $topDecile = (float) config('scoring.person.top_decile', 0.10);
        $deptMemberNames = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('department_id', $deptId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->get(['first_name', 'last_name'])
            ->map(fn ($u) => trim(((string) $u->first_name) . ' ' . ((string) $u->last_name)))
            ->filter(fn ($n) => $n !== '')
            ->all();

        if (count($deptMemberNames) < 5) {
            return false;
        }

        $counts = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->whereIn('owner_name', $deptMemberNames)
            ->select('owner_name', DB::raw('COUNT(*) as n'))
            ->groupBy('owner_name')
            ->orderByDesc('n')
            ->pluck('n', 'owner_name');

        $totalPeople = max(count($deptMemberNames), 1);
        $cutoff = max(1, (int) ceil($totalPeople * $topDecile));

        $owner = $this->ownerValue($rules);
        $ownerCount = (int) ($counts[$owner] ?? 0);
        $topN = array_slice($counts->all(), 0, $cutoff, true);

        return $ownerCount > 0 && in_array($ownerCount, $topN, true) && $ownerCount === (int) max($topN);
    }

    /**
     * D2: presence (attendance %), streak, absence pattern, working hours.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function presence(string $tenantId, array $rules): array
    {
        $empty = [
            'available' => false,
            'attendancePct' => null,
            'streakDays' => 0,
            'absencePattern' => 'none',
            'recurringDay' => null,
            'avgHours' => null,
            'weeklyHours' => array_fill(0, self::WEEKS, 0),
            'longHoursFlag' => false,
            'longHoursWeeks' => 0,
        ];

        if ($rules === []) {
            return $empty;
        }

        $since = CarbonImmutable::now()->subDays(self::ATTENDANCE_DAYS)->format('Y-m-d');
        $rows = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('owner_name', $this->ownerValue($rules))
            ->whereIn('dataset', self::ATTENDANCE_DATASETS)
            ->where('occurred_at', '>=', $since)
            ->get(['occurred_at', 'status', 'metric_value', 'payload']);

        if ($rows->isEmpty()) {
            return $empty + ['reason' => 'No attendance records in the last 60 days.'];
        }

        $byDate = $rows->groupBy(fn ($r) => substr((string) $r->occurred_at, 0, 10));
        $present = 0;
        $total = 0;
        $weekdayAbsences = [];
        $weeklyHours = array_fill(0, self::WEEKS, 0.0);
        $now = CarbonImmutable::now();
        $threshold = (float) config('scoring.person.long_hours.threshold', 9.5);

        $hoursDays = 0;
        $hoursSum = 0.0;
        $weeklyHoursDays = array_fill(0, self::WEEKS, 0);
        $presentWords = ['present', 'p', 'checked_in', 'attended', 'in', 'half day', 'half-day'];
        $absentWords = ['absent', 'a', 'missing', 'no_show', 'no-show', 'leave'];

        foreach ($byDate as $date => $dayRows) {
            $total++;

            /*
                THE DAY'S STATUS COMES FROM A ROW THAT ACTUALLY STATES ONE.

                A single date carries rows from several datasets — an attendance
                row saying "Present" with its hours, and a check-in row whose
                status column holds a source-system flag like "0". Reading
                `$dayRows->first()` took whichever the database returned first,
                so the day's presence was decided by row order.

                Only words this vocabulary recognises count. A numeric flag is
                deliberately not one of them: see the mismatch detector, where
                reading "0" as "absent" invented 163 contradictions for a person
                who was present on 97% of their recorded days.
            */
            $isPresent = false;
            $isAbsent = false;
            foreach ($dayRows as $r) {
                $status = strtolower(trim((string) ($r->status ?? '')));
                if (in_array($status, $presentWords, true)) {
                    $isPresent = true;
                } elseif (in_array($status, $absentWords, true)) {
                    $isAbsent = true;
                }
            }

            if ($isPresent) {
                $present++;

                // Hours live on whichever row for the day carries them — the
                // attendance row, not the check-in beside it.
                $hours = 0.0;
                foreach ($dayRows as $r) {
                    $h = $this->extractHours($r);
                    if ($h > 0.0) {
                        $hours = $h;
                        break;
                    }
                }
                if ($hours > 0.0) {
                    $hoursDays++;
                    $hoursSum += $hours;
                }

                /*
                    ABSOLUTE WEEKS BACK, NOT A SIGNED DIFFERENCE. `diffInWeeks`
                    answers with a sign, and for a past date that made the index
                    larger than the window, so every hours figure was silently
                    dropped and the card read "0h" for someone averaging 9.8.
                */
                $weeksAgo = (int) floor(abs((float) $now->diffInWeeks(CarbonImmutable::parse($date))));
                $weekIdx = self::WEEKS - 1 - $weeksAgo;
                if ($weekIdx >= 0 && $weekIdx < self::WEEKS && $hours > 0.0) {
                    $weeklyHours[$weekIdx] += $hours;
                    $weeklyHoursDays[$weekIdx]++;
                }
            } elseif ($isAbsent) {
                $weekday = strtolower(CarbonImmutable::parse($date)->format('l'));
                $weekdayAbsences[$weekday] = ($weekdayAbsences[$weekday] ?? 0) + 1;
            }
        }

        $attendancePct = $total > 0 ? round(($present / $total) * 100, 1) : null;
        $streak = $this->currentStreak($byDate->keys()->all());

        arsort($weekdayAbsences);
        $recurringDay = null;
        $absencePattern = 'none';
        if (($weekdayAbsences[array_key_first($weekdayAbsences)] ?? 0) >= 3) {
            $absencePattern = 'recurring_weekday';
            $recurringDay = (string) array_key_first($weekdayAbsences);
        } else {
            $blocks = $this->consecutiveBlocks($byDate);
            $clustered = array_filter($blocks, fn ($b) => count($b) >= 2);
            if (count($clustered) >= 2) {
                $absencePattern = 'clustered';
            }
        }

        /*
            NO HOURS ON FILE IS NULL, NOT ZERO (R2).

            This divided the 8-week hours total by the count of present days
            over 60 — two different windows — and answered 0.0 whenever the
            attendance dataset carried no hours column at all. "0h worked" and
            "hours were never recorded" are opposite findings, and only one of
            them is about the person. The average is now over exactly the days
            that carried an hours figure, and refuses to answer when none did.
        */
        $avgHours = $hoursDays > 0 ? round($hoursSum / $hoursDays, 2) : null;
        /*
            THE THRESHOLD IS A DAY'S HOURS, SO THE COMPARISON MUST BE TOO.

            This compared each week's TOTAL against 9.5 — a figure that is a
            per-day length. Any ordinary week clears 9.5 hours in total, so the
            flag fired for everyone with an hours column, and "worth a workload
            check" would have appeared on every profile in the tenant. A run is
            counted only from weeks that actually recorded hours; a quiet week
            with nothing on file does not silently break a run.
        */
        $longHoursWeeks = 0;
        foreach ($weeklyHours as $i => $h) {
            $days = $weeklyHoursDays[$i];
            if ($days === 0) {
                continue;
            }
            if (($h / $days) > $threshold) {
                $longHoursWeeks++;
            } else {
                $longHoursWeeks = 0;
            }
        }
        $weeksMin = (int) config('scoring.person.long_hours.weeks_min', 3);

        return [
            'available' => true,
            'attendancePct' => $attendancePct,
            'streakDays' => $streak,
            'absencePattern' => $absencePattern,
            'recurringDay' => $recurringDay,
            'avgHours' => $avgHours,
            'weeklyHours' => $weeklyHours,
            'longHoursFlag' => $longHoursWeeks >= $weeksMin,
            'longHoursWeeks' => $longHoursWeeks,
        ];
    }

    /** @return array<int, string> */
    private function ownerValue(array $rules): ?string
    {
        foreach ($rules as $r) {
            if (($r['column'] ?? null) === 'owner_name') {
                return (string) ($r['value'] ?? '');
            }
        }
        return null;
    }

    private function displayName(string $tenantId, string $personId): string
    {
        $row = DB::table('tbluser')->where('sub_institute_id', $tenantId)->where('id', $personId)->first();
        if (! $row) {
            return '';
        }
        return trim(((string) $row->first_name) . ' ' . ((string) $row->last_name));
    }

    private function extractHours(object $row): float
    {
        if ($row->metric_value !== null) {
            return (float) $row->metric_value;
        }
        $payload = json_decode((string) ($row->payload ?? ''), true);
        if (is_array($payload)) {
            foreach (['hours', 'Hours', 'total_hours', 'work_hours', 'duration_hours'] as $k) {
                if (isset($payload[$k]) && is_numeric($payload[$k])) {
                    return (float) $payload[$k];
                }
            }
        }
        return 0.0;
    }

    /** @param array<int, string> $dates unsorted */
    private function currentStreak(array $dates): int
    {
        $today = CarbonImmutable::now();
        $streak = 0;
        for ($i = 0; $i < self::ATTENDANCE_DAYS; $i++) {
            $d = $today->subDays($i)->format('Y-m-d');
            if (in_array($d, $dates, true)) {
                $streak++;
            } else {
                break;
            }
        }
        return $streak;
    }

    private function consecutiveBlocks(\Illuminate\Support\Collection $byDate): array
    {
        $absentDates = [];
        foreach ($byDate as $date => $rows) {
            $status = strtolower(trim((string) ($rows->first()->status ?? '')));
            if (in_array($status, ['absent', 'a', '0', 'missing', 'leave'], true)) {
                $absentDates[] = $date;
            }
        }
        sort($absentDates);
        $blocks = [];
        $current = [];
        foreach ($absentDates as $d) {
            if ($current === []) {
                $current[] = $d;
                continue;
            }
            $prev = end($current);
            if (CarbonImmutable::parse($d)->diffInDays(CarbonImmutable::parse($prev)) === 1) {
                $current[] = $d;
            } else {
                $blocks[] = $current;
                $current = [$d];
            }
        }
        if ($current !== []) {
            $blocks[] = $current;
        }
        return $blocks;
    }
}
