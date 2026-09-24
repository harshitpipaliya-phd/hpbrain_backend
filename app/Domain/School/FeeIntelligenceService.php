<?php

declare(strict_types=1);

namespace App\Domain\School;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FeeIntelligenceService
{
    /**
     * @return array<string, mixed>|null
     */
    public function forTenant(string $tenantId): ?array
    {
        if (! Schema::hasTable('hpbrain_operational_records')) {
            return null;
        }

        $columns = Schema::getColumnListing('hpbrain_operational_records');
        $select = array_values(array_intersect([
            'id', 'natural_key', 'source_file', 'source_row', 'subject_ref', 'status',
            'category', 'sub_category', 'owner_name', 'metric_value', 'metric_unit', 'occurred_at',
            'closed_at', 'zone', 'area',
            'payload', 'created_date',
        ], $columns));

        $records = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('dataset', 'school_fee')
            ->get($select);

        if ($records->isEmpty()) {
            return null;
        }

        $invoiceBacked = $records->filter(function ($record): bool {
            $payload = $this->payload($record->payload);

            return ($payload['invoice_id'] ?? '') !== '' && ($payload['fee_due_date'] ?? '') !== '';
        });

        if ($invoiceBacked->isNotEmpty()) {
            $records = $invoiceBacked->values();
        }

        $students = [];
        $departments = [];
        $classes = [];
        $sections = [];
        $feeTypes = [];
        $methods = [];
        $months = [];
        $departmentBuckets = [];
        $scholarshipBuckets = [];
        $sourceRiskRows = [];
        $studentProfiles = [];
        $latestCreated = null;

        $totals = [
            'records' => $records->count(),
            'billed' => 0.0,
            'concession' => 0.0,
            'net' => 0.0,
            'collected' => 0.0,
            'outstanding' => 0.0,
            'overdue' => 0.0,
            'partial' => 0.0,
            'expectedCollectable' => 0.0,
        ];

        $quality = [
            'missingStudentRef' => 0,
            'negativeOutstanding' => 0,
            'paidWithOutstandingMismatch' => 0,
            'duplicateReceipts' => 0,
            'outstandingMismatch' => 0,
            'negativeNet' => 0,
            'missingDueDate' => 0,
            'failedTransactions' => 0,
        ];

        $receiptCounts = [];
        $availability = [
            'dueDate' => false,
            'reminderHistory' => false,
            'attendance' => false,
            'academicPerformance' => false,
            'paymentMethod' => false,
        ];

        foreach ($records as $record) {
            $payload = $this->payload($record->payload);
            $studentRef = trim((string) ($record->subject_ref ?: ($payload['student_ref'] ?? $payload['student_id'] ?? $payload['admission_no'] ?? $payload['gr_no'] ?? '')));
            $studentName = trim((string) ($payload['student_name'] ?? $studentRef));
            $department = trim((string) ($payload['department'] ?? $record->area ?? ''));
            $class = trim((string) ($payload['class_name'] ?? $payload['class'] ?? ''));
            $section = trim((string) ($payload['section'] ?? ''));
            if ($department === '' && ($class !== '' || $section !== '')) {
                $department = trim('Grade '.$class.' '.$section);
            }
            $feeType = trim((string) ($payload['fee_type'] ?? $payload['fee_component'] ?? $record->category ?? 'Unclassified'));
            $feePlan = trim((string) ($payload['fee_plan'] ?? $record->sub_category ?? 'Unclassified')) ?: 'Unclassified';
            $method = trim((string) ($payload['payment_method'] ?? ''));
            $status = trim((string) ($payload['payment_status'] ?? $record->status ?? ''));
            $receipt = trim((string) ($payload['receipt_no'] ?? $payload['invoice_id'] ?? $record->natural_key ?? ''));
            $scholarship = trim((string) ($payload['scholarship_type'] ?? ''));
            if ($scholarship === '' && ($payload['scholarship_pct'] ?? '') !== '') {
                $scholarship = 'Scholarship '.$payload['scholarship_pct'].'%';
            }
            $scholarship = $scholarship !== '' ? $scholarship : 'Unclassified';
            $sourceRisk = trim((string) ($payload['risk_band'] ?? $payload['risk_level'] ?? 'Unclassified')) ?: 'Unclassified';

            $amountDue = $this->money($payload['amount_due'] ?? $payload['gross_fee_amount'] ?? null);
            $concession = $this->money($payload['concession_amount'] ?? $payload['discount_amount'] ?? null);
            $net = $this->money($payload['net_amount'] ?? $payload['net_fee_amount'] ?? $record->metric_value ?? null);
            $paid = $this->money($payload['amount_paid'] ?? null);
            $outstanding = $this->money($payload['balance_amount'] ?? $payload['outstanding_amount'] ?? null);
            $daysOverdue = (int) $this->money($payload['days_overdue'] ?? 0);
            $previousLate = (int) $this->money($payload['previous_late_payment_count'] ?? 0);
            $previousBounce = (int) $this->money($payload['previous_bounce_count'] ?? 0);
            $reminderCount = (int) $this->money($payload['reminder_count'] ?? 0);
            $expectedCollectable = $this->money($payload['expected_collectable_amount'] ?? 0);
            $transactionStatus = trim((string) ($payload['transaction_status'] ?? ''));
            $paymentAfterReminder = $this->truthy($payload['payment_after_reminder_flag'] ?? null);

            $totals['billed'] += $amountDue;
            $totals['concession'] += $concession;
            $totals['net'] += $net;
            $totals['collected'] += $paid;
            $totals['outstanding'] += $outstanding;
            $totals['expectedCollectable'] += $expectedCollectable;
            if ($daysOverdue > 0) {
                $totals['overdue'] += $outstanding;
            }
            if (strcasecmp($status, 'partial') === 0 || ($paid > 0 && $outstanding > 0)) {
                $totals['partial'] += $outstanding;
            }

            if ($studentRef === '') {
                $quality['missingStudentRef']++;
            } else {
                $students[$studentRef] = true;
            }
            if ($department !== '') {
                $departments[$department] = true;
            }
            if ($class !== '') {
                $classes[$class] = true;
            }
            if ($class !== '' || $section !== '') {
                $sections[$class.'|'.$section] = true;
            }
            if ($method !== '') {
                $methods[$method] = ($methods[$method] ?? $this->emptyBucket($method)) + ['name' => $method];
                $this->addToBucket($methods[$method], $net, $paid, $outstanding);
                $availability['paymentMethod'] = true;
            }
            if ($feeType !== '') {
                $feeTypes[$feeType] = ($feeTypes[$feeType] ?? $this->emptyBucket($feeType)) + ['name' => $feeType];
                $this->addToBucket($feeTypes[$feeType], $net, $paid, $outstanding);
            }
            $feePlanBuckets[$feePlan] = $feePlanBuckets[$feePlan] ?? $this->emptyBucket($feePlan);
            $this->addToBucket($feePlanBuckets[$feePlan], $net, $paid, $outstanding);
            if ($department !== '') {
                $departmentBuckets[$department] = $departmentBuckets[$department] ?? $this->emptyBucket($department);
                $this->addToBucket($departmentBuckets[$department], $net, $paid, $outstanding);
            }

            $scholarshipBuckets[$scholarship] = $scholarshipBuckets[$scholarship] ?? $this->emptyBucket($scholarship);
            $this->addToBucket($scholarshipBuckets[$scholarship], $net, $paid, $outstanding);

            $sourceRiskRows[$sourceRisk] = ($sourceRiskRows[$sourceRisk] ?? 0) + 1;

            $classKey = $class !== '' ? $class : 'Unclassified';
            $classes[$classKey] = $classes[$classKey] ?? true;
            $classBuckets[$classKey] = $classBuckets[$classKey] ?? $this->emptyBucket($classKey);
            $this->addToBucket($classBuckets[$classKey], $net, $paid, $outstanding);

            $sectionKey = trim($class.' '.$section) ?: 'Unclassified';
            $sectionBuckets[$sectionKey] = $sectionBuckets[$sectionKey] ?? $this->emptyBucket($sectionKey);
            $this->addToBucket($sectionBuckets[$sectionKey], $net, $paid, $outstanding);

            $month = $this->month((string) ($payload['fee_period'] ?? $payload['payment_date'] ?? $record->occurred_at ?? ''));
            if ($month !== null) {
                $months[$month] = $months[$month] ?? $this->emptyBucket($month);
                $this->addToBucket($months[$month], $net, $paid, $outstanding);
            }

            if ($receipt !== '') {
                $receiptCounts[$receipt] = ($receiptCounts[$receipt] ?? 0) + 1;
            }
            if ($outstanding < 0) {
                $quality['negativeOutstanding']++;
            }
            if ($net < 0) {
                $quality['negativeNet']++;
            }
            if (abs(($net - $paid) - $outstanding) > 1.0) {
                $quality['outstandingMismatch']++;
            }
            if (strcasecmp($status, 'paid') === 0 && abs($outstanding) > 0.01) {
                $quality['paidWithOutstandingMismatch']++;
            }
            if (($payload['fee_due_date'] ?? $payload['due_date'] ?? '') === '') {
                $quality['missingDueDate']++;
            }
            if ($transactionStatus !== '' && ! in_array(strtolower($transactionStatus), ['success', 'successful'], true)) {
                $quality['failedTransactions']++;
            }

            $attendance = $this->nullableFloat($payload['attendance_pct'] ?? null);
            $exam = $this->nullableFloat($payload['exam_average_pct'] ?? null);
            if ($attendance !== null) {
                $availability['attendance'] = true;
            }
            if ($exam !== null) {
                $availability['academicPerformance'] = true;
            }
            if (array_key_exists('due_date', $payload) || array_key_exists('fee_due_date', $payload)) {
                $availability['dueDate'] = true;
            }
            if (array_key_exists('reminder_count', $payload) || array_key_exists('reminder_history', $payload) || array_key_exists('reminder_channels', $payload)) {
                $availability['reminderHistory'] = true;
            }

            if ($studentRef !== '') {
                $profile = $studentProfiles[$studentRef] ?? [
                    'studentRef' => $studentRef,
                    'studentName' => $studentName,
                    'admissionNo' => $payload['admission_no'] ?? null,
                    'className' => $class,
                    'section' => $section,
                    'department' => $department,
                    'outstanding' => 0.0,
                    'net' => 0.0,
                    'paid' => 0.0,
                    'expectedCollectable' => 0.0,
                    'overdueRecords' => 0,
                    'partialRecords' => 0,
                    'maxDaysOverdue' => 0,
                    'previousLatePaymentCount' => 0,
                    'previousBounceCount' => 0,
                    'reminderCount' => 0,
                    'paymentsAfterReminder' => 0,
                    'failedTransactions' => 0,
                    'latestRecommendedAction' => null,
                    'attendanceValues' => [],
                    'examValues' => [],
                    'lmsValues' => [],
                    'sourceRiskLevel' => null,
                    'sourceRiskRank' => -1,
                    'sourceRows' => [],
                ];
                $profile['outstanding'] += $outstanding;
                $profile['net'] += $net;
                $profile['paid'] += $paid;
                $profile['expectedCollectable'] += $expectedCollectable;
                $profile['maxDaysOverdue'] = max($profile['maxDaysOverdue'], $daysOverdue);
                $profile['previousLatePaymentCount'] = max($profile['previousLatePaymentCount'], $previousLate);
                $profile['previousBounceCount'] = max($profile['previousBounceCount'], $previousBounce);
                $profile['reminderCount'] += $reminderCount;
                $profile['paymentsAfterReminder'] += $paymentAfterReminder ? 1 : 0;
                $profile['latestRecommendedAction'] = $payload['recommended_action'] ?? $profile['latestRecommendedAction'];
                if ($transactionStatus !== '' && ! in_array(strtolower($transactionStatus), ['success', 'successful'], true)) {
                    $profile['failedTransactions']++;
                }
                $riskRank = $this->sourceRiskRank($sourceRisk);
                if ($riskRank > $profile['sourceRiskRank']) {
                    $profile['sourceRiskLevel'] = $sourceRisk;
                    $profile['sourceRiskRank'] = $riskRank;
                }
                if (strcasecmp($status, 'overdue') === 0 || $daysOverdue > 0 || $this->truthy($payload['is_overdue'] ?? null)) {
                    $profile['overdueRecords']++;
                }
                if (strcasecmp($status, 'partial') === 0 || ($paid > 0 && $outstanding > 0)) {
                    $profile['partialRecords']++;
                }
                if ($attendance !== null) {
                    $profile['attendanceValues'][] = $attendance;
                }
                if ($exam !== null) {
                    $profile['examValues'][] = $exam;
                }
                $lms = $this->nullableFloat($payload['lms_engagement_pct'] ?? null);
                if ($lms !== null) {
                    $profile['lmsValues'][] = $lms;
                }
                if (count($profile['sourceRows']) < 5) {
                    $profile['sourceRows'][] = [
                        'id' => (string) $record->id,
                        'receipt' => $receipt,
                        'period' => $payload['fee_period'] ?? null,
                        'status' => $status,
                        'sourceFile' => $record->source_file ?? null,
                        'sourceRow' => $record->source_row ?? null,
                    ];
                }
                $studentProfiles[$studentRef] = $profile;
            }

            $latestCreated = max($latestCreated ?? (string) $record->created_date, (string) $record->created_date);
        }

        foreach ($receiptCounts as $count) {
            if ($count > 1) {
                $quality['duplicateReceipts']++;
            }
        }

        $defaulters = [];
        foreach ($studentProfiles as $profile) {
            if ($profile['outstanding'] <= 0 && $profile['overdueRecords'] === 0 && $profile['partialRecords'] === 0) {
                continue;
            }

            $defaulters[] = $this->riskProfile($profile);
        }

        usort($defaulters, fn (array $a, array $b): int => $b['riskScore'] <=> $a['riskScore']);

        return [
            'dataset' => 'school_fee',
            'lastUpdated' => $latestCreated,
            'availability' => $availability,
            'overview' => [
                'records' => $totals['records'],
                'students' => count($students),
                'departments' => count($departments),
                'classes' => count($classes),
                'sections' => count($sections),
                'totalBilled' => round($totals['billed'], 2),
                'totalConcession' => round($totals['concession'], 2),
                'totalNet' => round($totals['net'], 2),
                'totalCollected' => round($totals['collected'], 2),
                'totalOutstanding' => round($totals['outstanding'], 2),
                'totalOverdue' => round($totals['overdue'], 2),
                'totalPartial' => round($totals['partial'], 2),
                'expectedCollectable' => round($totals['expectedCollectable'], 2),
                'atRiskAmount' => round(max(0, $totals['outstanding'] - $totals['expectedCollectable']), 2),
                'collectionRate' => $totals['net'] > 0 ? round($totals['collected'] / $totals['net'], 4) : null,
                'defaulters' => count($defaulters),
                'criticalRiskStudents' => count(array_filter($defaulters, fn (array $row): bool => $row['riskBand'] === 'Critical')),
                'averagePaymentDelayDays' => $this->average(array_filter(array_map(
                    fn (array $profile): int => (int) $profile['maxDaysOverdue'],
                    $studentProfiles,
                ))),
            ],
            'analytics' => [
                'byMonth' => $this->sortedBuckets($months),
                'byDepartment' => $this->topBuckets($departmentBuckets),
                'byClass' => $this->topBuckets($classBuckets ?? []),
                'bySection' => $this->topBuckets($sectionBuckets ?? []),
                'byFeeType' => $this->topBuckets($feeTypes),
                'byFeePlan' => $this->topBuckets($feePlanBuckets ?? []),
                'byPaymentMethod' => $this->topBuckets($methods),
                'byScholarship' => $this->topBuckets($scholarshipBuckets),
                'riskLevelRows' => $this->distribution($sourceRiskRows),
                'riskLevelStudents' => $this->studentRiskDistribution($studentProfiles),
            ],
            'priorityRecovery' => array_slice($this->priorityRecovery($defaulters), 0, 20),
            'defaulters' => array_slice($defaulters, 0, 20),
            'dataQuality' => $quality,
            'trace' => [
                'table' => 'hpbrain_operational_records',
                'tenantId' => $tenantId,
                'dataset' => 'school_fee',
                'recordCount' => $records->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function money(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'y'], true);
    }

    private function month(string $value): ?string
    {
        $time = strtotime($value);

        return $time === false ? null : date('Y-m', $time);
    }

    /** @return array{name: string, records: int, net: float, collected: float, outstanding: float, collectionRate: float|null} */
    private function emptyBucket(string $name): array
    {
        return ['name' => $name, 'records' => 0, 'net' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'collectionRate' => null];
    }

    /** @param array<string, mixed> $bucket */
    private function addToBucket(array &$bucket, float $net, float $paid, float $outstanding): void
    {
        $bucket['records']++;
        $bucket['net'] += $net;
        $bucket['collected'] += $paid;
        $bucket['outstanding'] += $outstanding;
        $bucket['collectionRate'] = $bucket['net'] > 0 ? round($bucket['collected'] / $bucket['net'], 4) : null;
    }

    /** @param array<string, array<string, mixed>> $buckets */
    private function topBuckets(array $buckets): array
    {
        usort($buckets, fn (array $a, array $b): int => $b['outstanding'] <=> $a['outstanding']);

        return array_map(fn (array $row): array => $this->roundBucket($row), array_slice($buckets, 0, 10));
    }

    /** @param array<string, array<string, mixed>> $buckets */
    private function sortedBuckets(array $buckets): array
    {
        ksort($buckets);

        return array_map(fn (array $row): array => $this->roundBucket($row), array_values($buckets));
    }

    /** @param array<string, mixed> $row */
    private function roundBucket(array $row): array
    {
        foreach (['net', 'collected', 'outstanding'] as $key) {
            $row[$key] = round((float) $row[$key], 2);
        }

        return $row;
    }

    /** @param array<string, int> $counts */
    private function distribution(array $counts): array
    {
        $total = array_sum($counts);
        $rows = [];

        foreach ($counts as $name => $count) {
            $rows[] = [
                'name' => $name,
                'count' => $count,
                'share' => $total > 0 ? round($count / $total, 4) : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $this->sourceRiskRank((string) $b['name']) <=> $this->sourceRiskRank((string) $a['name']));

        return $rows;
    }

    /** @param array<string, array<string, mixed>> $studentProfiles */
    private function studentRiskDistribution(array $studentProfiles): array
    {
        $counts = [];

        foreach ($studentProfiles as $profile) {
            $risk = (string) ($profile['sourceRiskLevel'] ?? 'Unclassified');
            $counts[$risk] = ($counts[$risk] ?? 0) + 1;
        }

        return $this->distribution($counts);
    }

    /** @param array<int, array<string, mixed>> $defaulters */
    private function priorityRecovery(array $defaulters): array
    {
        usort($defaulters, function (array $a, array $b): int {
            $outstanding = $b['outstanding'] <=> $a['outstanding'];
            if ($outstanding !== 0) {
                return $outstanding;
            }

            return $a['collectionRate'] <=> $b['collectionRate'];
        });

        return $defaulters;
    }

    private function sourceRiskRank(string $risk): int
    {
        return match (strtolower($risk)) {
            'high', 'critical' => 3,
            'medium', 'orange' => 2,
            'low', 'green' => 1,
            default => 0,
        };
    }

    /** @param array<string, mixed> $profile */
    private function riskProfile(array $profile): array
    {
        $score = 0;
        $factors = [];

        if ($profile['outstanding'] >= 20000) {
            $score += 25;
            $factors[] = 'Outstanding amount is at least INR 20,000.';
        } elseif ($profile['outstanding'] >= 10000) {
            $score += 18;
            $factors[] = 'Outstanding amount is at least INR 10,000.';
        } elseif ($profile['outstanding'] > 0) {
            $score += 10;
            $factors[] = 'Outstanding balance remains open.';
        }

        if ($profile['maxDaysOverdue'] >= 30) {
            $score += 20;
            $factors[] = $profile['maxDaysOverdue'].' maximum days overdue.';
        } elseif ($profile['maxDaysOverdue'] >= 15) {
            $score += 14;
            $factors[] = $profile['maxDaysOverdue'].' maximum days overdue.';
        } elseif ($profile['maxDaysOverdue'] > 0) {
            $score += 8;
            $factors[] = $profile['maxDaysOverdue'].' maximum days overdue.';
        }

        if ($profile['overdueRecords'] > 0) {
            $score += min(12, $profile['overdueRecords'] * 3);
            $factors[] = $profile['overdueRecords'].' overdue fee record(s).';
        }

        if ($profile['partialRecords'] > 0) {
            $score += min(10, $profile['partialRecords'] * 2);
            $factors[] = $profile['partialRecords'].' partial-payment record(s).';
        }

        if ($profile['previousLatePaymentCount'] > 0) {
            $score += min(10, $profile['previousLatePaymentCount'] * 2);
            $factors[] = $profile['previousLatePaymentCount'].' previous late payment(s).';
        }

        if ($profile['previousBounceCount'] > 0) {
            $score += min(10, $profile['previousBounceCount'] * 5);
            $factors[] = $profile['previousBounceCount'].' previous bounced payment(s).';
        }

        if ($profile['reminderCount'] > 0 && $profile['paymentsAfterReminder'] === 0) {
            $score += min(8, $profile['reminderCount']);
            $factors[] = $profile['reminderCount'].' reminder(s) with no payment-after-reminder flag.';
        }

        if ($profile['failedTransactions'] > 0) {
            $score += min(8, $profile['failedTransactions'] * 4);
            $factors[] = $profile['failedTransactions'].' failed or returned transaction(s).';
        }

        $attendance = $this->average($profile['attendanceValues']);
        if ($attendance !== null && $attendance < 75) {
            $score += 10;
            $factors[] = 'Attendance is below 75%.';
        }

        $exam = $this->average($profile['examValues']);
        if ($exam !== null && $exam < 60) {
            $score += 10;
            $factors[] = 'Exam average is below 60%.';
        }

        $lms = $this->average($profile['lmsValues']);
        if ($lms !== null && $lms < 50) {
            $score += 5;
            $factors[] = 'LMS engagement is below 50%.';
        }

        $score = min(100, $score);
        $band = match (true) {
            $score >= 70 => 'Critical',
            $score >= 50 => 'High',
            $score >= 30 => 'Medium',
            default => 'Low',
        };

        return [
            'studentRef' => $profile['studentRef'],
            'studentName' => $profile['studentName'],
            'admissionNo' => $profile['admissionNo'],
            'className' => $profile['className'],
            'section' => $profile['section'],
            'department' => $profile['department'],
            'outstanding' => round($profile['outstanding'], 2),
            'paid' => round($profile['paid'], 2),
            'net' => round($profile['net'], 2),
            'expectedCollectable' => round($profile['expectedCollectable'], 2),
            'collectionRate' => $profile['net'] > 0 ? round($profile['paid'] / $profile['net'], 4) : null,
            'overdueRecords' => $profile['overdueRecords'],
            'partialRecords' => $profile['partialRecords'],
            'previousLatePaymentCount' => $profile['previousLatePaymentCount'],
            'previousBounceCount' => $profile['previousBounceCount'],
            'reminderCount' => $profile['reminderCount'],
            'paymentsAfterReminder' => $profile['paymentsAfterReminder'],
            'failedTransactions' => $profile['failedTransactions'],
            'averageAttendancePct' => $attendance,
            'averageExamPct' => $exam,
            'averageLmsEngagementPct' => $lms,
            'sourceRiskLevel' => $profile['sourceRiskLevel'],
            'daysOverdue' => $profile['maxDaysOverdue'],
            'riskScore' => $score,
            'riskBand' => $band,
            'riskFactors' => $factors,
            'recommendedAction' => $profile['latestRecommendedAction'] ?? match ($band) {
                'Critical' => 'Escalate to Accounts Team with source receipts attached.',
                'High' => 'Schedule targeted follow-up before escalation.',
                'Medium' => 'Send payment reminder and monitor response.',
                default => 'Monitor in the next fee review.',
            },
            'sourceRows' => $profile['sourceRows'],
        ];
    }

    /** @param array<int, float> $values */
    private function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }
}
