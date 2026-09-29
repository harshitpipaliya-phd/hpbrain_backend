<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Signals\OperationalSignalWriter;
use App\Domain\Signals\SignalRuleRegistry;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The two real, data-driven academic and attendance signal rules added
 * alongside the school fee rules in OperationalSignalRules.php.
 *
 * Same convention as LionsFeesIngestionTest's rule tests: insert real-shaped
 * operational records, tune the rule's own config threshold up and down
 * around the fixture, and assert the rule fires exactly when the real
 * computed value crosses that threshold — never on an arbitrary count.
 */
final class SchoolAcademicAttendanceSignalRulesTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = '9001';
    private const ACADEMIC_DATASET = 'demo-academic-results';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    private function runOperationalRules(): void
    {
        $writer = app(OperationalSignalWriter::class);

        foreach (app(SignalRuleRegistry::class)->extraRulesFor($writer, self::TENANT) as $rule) {
            $rule();
        }
    }

    private function signalCount(string $rule): int
    {
        return DB::table('hpbrain_signals')
            ->where('tenant_id', self::TENANT)
            ->where('rule_key', $rule)
            ->count();
    }

    private function clearSignals(): void
    {
        DB::table('hpbrain_evidence')->where('tenant_id', self::TENANT)->delete();
        DB::table('hpbrain_event_store')->where('tenant_id', self::TENANT)->delete();
        DB::table('hpbrain_signals')->where('tenant_id', self::TENANT)->delete();
    }

    private function declareAcademicDataset(): void
    {
        DB::table('hpbrain_data_sources')->insert([
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => self::TENANT,
            'source_key' => self::ACADEMIC_DATASET,
            'display_name' => 'Demo Academic Results',
            'source_type' => 'dataset',
            'config' => json_encode(['dataset_role' => 'academic']),
            'is_active' => 1,
            'created_by' => 'test',
            'created_date' => '2026-01-01 00:00:00',
            'updated_date' => '2026-01-01 00:00:00',
        ]);
    }

    /**
     * @param array<int, array{standard: string, subject: string, ref: string, pct: float}> $rows
     */
    private function insertAcademicRow(string $standard, string $subject, string $studentRef, float $pct, string $date = '2026-03-01'): void
    {
        $naturalKey = "{$standard}-{$subject}-{$studentRef}";

        DB::table('hpbrain_operational_records')->insert([
            'id' => Uuid::uuid5(Uuid::fromString('6a6e0b1a-e4c9-4d6b-9f8a-7f7a2f5b9d10'), self::TENANT.':'.self::ACADEMIC_DATASET.':'.$naturalKey)->toString(),
            'tenant_id' => self::TENANT,
            'dataset' => self::ACADEMIC_DATASET,
            'natural_key' => $naturalKey,
            'source_row' => 1,
            'occurred_at' => "{$date} 09:00:00",
            'status' => $standard,
            'category' => $subject,
            'subject_ref' => $studentRef,
            'metric_value' => $pct,
            'quantity' => 100,
            'payload' => json_encode(['student_name' => "Student {$studentRef}"]),
            'row_hash' => hash('sha256', $naturalKey),
            'created_date' => '2026-03-01 00:00:00',
            'updated_date' => '2026-03-01 00:00:00',
        ]);
    }

    private function insertAttendanceRow(string $studentRef, string $month, int $present, int $working): void
    {
        $naturalKey = "{$studentRef}-{$month}";

        DB::table('hpbrain_operational_records')->insert([
            'id' => Uuid::uuid5(Uuid::fromString('6a6e0b1a-e4c9-4d6b-9f8a-7f7a2f5b9d10'), self::TENANT.':attendance:'.$naturalKey)->toString(),
            'tenant_id' => self::TENANT,
            'dataset' => 'attendance',
            'natural_key' => $naturalKey,
            'source_row' => 1,
            'occurred_at' => "{$month}-15 00:00:00",
            'subject_ref' => $studentRef,
            'metric_value' => $present,
            'quantity' => $working,
            'payload' => json_encode([]),
            'row_hash' => hash('sha256', $naturalKey),
            'created_date' => '2026-01-01 00:00:00',
            'updated_date' => '2026-01-01 00:00:00',
        ]);
    }

    // ---- academic_cohort_gap -------------------------------------------

    public function test_academic_cohort_gap_fires_above_and_is_silent_below_real_threshold(): void
    {
        $this->declareAcademicDataset();

        // A healthy cohort of 5 (all scoring ~80%) and a struggling cohort of
        // 5 (all scoring ~40%). The rule's "gap" is schoolAvg (mean of cohort
        // averages) minus the worst cohort: (80+40)/2 - 40 = 20 points — kept
        // well clear of the configured floors below (10 / 25) so a harmless
        // floating-point AVG() residue can never flip the comparison at the
        // boundary the way an exact-equality gap would.
        for ($i = 1; $i <= 5; $i++) {
            $this->insertAcademicRow('CBSE-9', 'Mathematics', "healthy-{$i}", 80.0);
            $this->insertAcademicRow('CBSE-9', 'Science', "weak-{$i}", 40.0);
        }

        config(['brain.operational_signals.academic_cohort_minimum' => 5]);
        config(['brain.operational_signals.academic_cohort_gap_points' => 10.0]);

        $this->runOperationalRules();

        self::assertSame(1, $this->signalCount('academic_cohort_gap'));

        $signal = DB::table('hpbrain_signals')->where('tenant_id', self::TENANT)->where('rule_key', 'academic_cohort_gap')->first();
        $meta = json_decode((string) $signal->metadata, true);
        self::assertSame('CBSE-9', $meta['standard']);
        self::assertSame('Science', $meta['subject']);
        self::assertEqualsWithDelta(40.0, $meta['cohortAvgPct'], 0.01);

        $this->clearSignals();

        // Raise the floor comfortably above the real 20-point gap: must go
        // silent, not fire on a smaller, fabricated version of the same finding.
        config(['brain.operational_signals.academic_cohort_gap_points' => 25.0]);
        $this->runOperationalRules();

        self::assertSame(0, $this->signalCount('academic_cohort_gap'));
    }

    public function test_academic_cohort_gap_does_not_fire_from_a_single_cohort(): void
    {
        // Only one (standard, subject) group exists on this date — there is
        // nothing to compare it to, so the rule must not invent a "school
        // average" from a single data point.
        $this->declareAcademicDataset();

        for ($i = 1; $i <= 5; $i++) {
            $this->insertAcademicRow('CBSE-9', 'Mathematics', "solo-{$i}", 40.0);
        }

        config(['brain.operational_signals.academic_cohort_minimum' => 5]);
        config(['brain.operational_signals.academic_cohort_gap_points' => 1.0]);

        $this->runOperationalRules();

        self::assertSame(0, $this->signalCount('academic_cohort_gap'));
    }

    public function test_academic_cohort_gap_ignores_a_cohort_smaller_than_the_minimum(): void
    {
        // A 2-student "cohort" scoring 0% must not be reported as a cohort
        // finding merely because the gap is large — the minimum-sample guard
        // exists precisely so one or two students cannot stand in for a class.
        $this->declareAcademicDataset();

        for ($i = 1; $i <= 5; $i++) {
            $this->insertAcademicRow('CBSE-9', 'Mathematics', "healthy-{$i}", 80.0);
        }
        $this->insertAcademicRow('CBSE-9', 'Art', 'tiny-1', 10.0);
        $this->insertAcademicRow('CBSE-9', 'Art', 'tiny-2', 10.0);

        config(['brain.operational_signals.academic_cohort_minimum' => 5]);
        config(['brain.operational_signals.academic_cohort_gap_points' => 15.0]);

        $this->runOperationalRules();

        self::assertSame(0, $this->signalCount('academic_cohort_gap'));
    }

    // ---- attendance_chronic_absence ------------------------------------

    public function test_attendance_chronic_absence_fires_above_and_is_silent_below_real_threshold(): void
    {
        // 3 students chronically absent (60% over 3 months), 7 students fine
        // (95%) — a real, minority pattern, not an arbitrary count.
        foreach (['low-1', 'low-2', 'low-3'] as $ref) {
            foreach (['2026-01', '2026-02', '2026-03'] as $month) {
                $this->insertAttendanceRow($ref, $month, 12, 20);
            }
        }
        foreach (['ok-1', 'ok-2', 'ok-3', 'ok-4', 'ok-5', 'ok-6', 'ok-7'] as $ref) {
            foreach (['2026-01', '2026-02', '2026-03'] as $month) {
                $this->insertAttendanceRow($ref, $month, 19, 20);
            }
        }

        config(['brain.operational_signals.attendance_minimum_months' => 3]);
        config(['brain.operational_signals.attendance_chronic_pct' => 75.0]);
        config(['brain.operational_signals.attendance_chronic_minimum' => 3]);

        $this->runOperationalRules();

        self::assertSame(1, $this->signalCount('attendance_chronic_absence'));

        $signal = DB::table('hpbrain_signals')->where('tenant_id', self::TENANT)->where('rule_key', 'attendance_chronic_absence')->first();
        $meta = json_decode((string) $signal->metadata, true);
        self::assertSame(3, $meta['affectedCount']);

        $this->clearSignals();

        // Requiring 4 affected students when only 3 are chronic: must go silent.
        config(['brain.operational_signals.attendance_chronic_minimum' => 4]);
        $this->runOperationalRules();

        self::assertSame(0, $this->signalCount('attendance_chronic_absence'));
    }

    public function test_attendance_chronic_absence_excludes_students_with_too_few_recorded_months(): void
    {
        // One student with a single terrible month (0%) must not be reported
        // as "chronically" absent — one data point is not a pattern, and a
        // missing record is not the same as a recorded absence.
        $this->insertAttendanceRow('new-student', '2026-03', 0, 20);

        foreach (['ok-1', 'ok-2', 'ok-3'] as $ref) {
            foreach (['2026-01', '2026-02', '2026-03'] as $month) {
                $this->insertAttendanceRow($ref, $month, 19, 20);
            }
        }

        config(['brain.operational_signals.attendance_minimum_months' => 3]);
        config(['brain.operational_signals.attendance_chronic_pct' => 75.0]);
        config(['brain.operational_signals.attendance_chronic_minimum' => 1]);

        $this->runOperationalRules();

        self::assertSame(0, $this->signalCount('attendance_chronic_absence'));
    }

    public function test_reprocessing_refreshes_rather_than_duplicates(): void
    {
        foreach (['low-1', 'low-2', 'low-3'] as $ref) {
            foreach (['2026-01', '2026-02', '2026-03'] as $month) {
                $this->insertAttendanceRow($ref, $month, 12, 20);
            }
        }

        config(['brain.operational_signals.attendance_chronic_minimum' => 3]);

        $this->runOperationalRules();
        $this->runOperationalRules();
        $this->runOperationalRules();

        self::assertSame(1, $this->signalCount('attendance_chronic_absence'));
    }
}
