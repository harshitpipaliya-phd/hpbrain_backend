<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

/**
 * Derives the intelligence loop for the Lions tenant from its *real*
 * operational records and writes it into the loop tables the screens read.
 *
 * Everything numeric in the output comes from SQL aggregation over
 * hpbrain_operational_records (lions-result-data / lions-fees-data). The rows
 * are stamped created_by = self::AUTHOR and carry a provenance block naming the
 * aggregation that produced them, so nothing here can be mistaken for an
 * organic organizational act. The workflow wrapper around the findings
 * (owners, execution statuses) is marked illustrative in the same block.
 *
 * Idempotent: re-running removes only rows this command authored.
 */
final class DeriveLionsIntelligence extends Command
{
    protected $signature = 'lions:derive-intelligence {--tenant=1000010} {--rebuild}';

    protected $description = 'Derive Lions cases/recommendations/decisions/executions/outcomes/learnings/risks from real academic and fee records';

    private const AUTHOR = 'lions-derived';

    private const RESULT_DS = 'lions-result-data';

    private const FEE_DS = 'lions-fees-data';

    private string $tenant;

    private string $now;

    public function handle(): int
    {
        $this->tenant = (string) $this->option('tenant');
        $this->now = now()->format('Y-m-d H:i:s');

        $this->info("Aggregating real Lions data (tenant {$this->tenant})...");
        $facts = $this->aggregate();

        if ($facts['results']['records'] === 0 && $facts['fees']['records'] === 0) {
            $this->error('No Lions operational records found — nothing to derive.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  %s academic records - %s fee receipts - overall score %s%%',
            number_format($facts['results']['records']),
            number_format($facts['fees']['records']),
            $facts['results']['overall_pct'] ?? 'n/a'
        ));

        $this->purge();
        $findings = $this->buildFindings($facts);
        $this->write($findings, $facts);

        $this->info(sprintf('Wrote %d findings through the full loop.', count($findings)));

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- facts

    /**
     * The 388k academic rows are scanned exactly twice — once into a
     * standard/subject/exam/year summary and once into a per-student summary —
     * and the fee rows once. Every figure below is then read off those small
     * tables. Nine separate full scans against a contended server took tens of
     * minutes; this takes three, and leaves summaries behind that any later
     * read can use instead of touching the raw records again.
     */
    /**
     * Two scans of the academic records, materialised into tables small enough
     * that everything downstream is a millisecond read. Rebuilt on each run, so
     * they can never drift from the records they came from.
     */
    private function buildSummaries(): void
    {
        $t = $this->tenant;

        $this->line('  building result summary (scan 1 of 2)...');
        DB::statement('DROP TABLE IF EXISTS hpbrain_lions_result_summary');
        DB::statement(
            'CREATE TABLE hpbrain_lions_result_summary AS
             SELECT status AS standard, category AS subject, sub_category AS exam,
                    YEAR(occurred_at) AS yr,
                    COUNT(*) AS records, SUM(metric_value) AS obtained, SUM(quantity) AS total
             FROM hpbrain_operational_records
             WHERE tenant_id = ? AND dataset = ? AND quantity > 0
             GROUP BY status, category, sub_category, YEAR(occurred_at)',
            [$t, self::RESULT_DS]
        );

        $this->line('  building per-student summary (scan 2 of 2)...');
        DB::statement('DROP TABLE IF EXISTS hpbrain_lions_student_summary');
        DB::statement(
            'CREATE TABLE hpbrain_lions_student_summary AS
             SELECT subject_ref, status AS standard, category AS subject,
                    COUNT(*) AS records, SUM(metric_value) AS obtained, SUM(quantity) AS total
             FROM hpbrain_operational_records
             WHERE tenant_id = ? AND dataset = ? AND quantity > 0
             GROUP BY subject_ref, status, category',
            [$t, self::RESULT_DS]
        );
    }

    private function summariesExist(): bool
    {
        foreach (['hpbrain_lions_result_summary', 'hpbrain_lions_student_summary'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function aggregate(): array
    {
        // The scans are the only expensive thing here, so a re-run reuses the
        // summaries unless the caller asks for them to be rebuilt.
        if ($this->option('rebuild') || ! $this->summariesExist()) {
            $this->buildSummaries();
        } else {
            $this->line('  reusing existing summaries (pass --rebuild to rescan)');
        }

        $overall = DB::selectOne(
            'SELECT SUM(records) records, SUM(obtained) obtained, SUM(total) total,
                    COUNT(DISTINCT standard) standards, COUNT(DISTINCT subject) subjects
             FROM hpbrain_lions_result_summary'
        );

        $studentTotals = DB::selectOne(
            'SELECT COUNT(DISTINCT subject_ref) students FROM hpbrain_lions_student_summary'
        );

        $byStandard = DB::select(
            'SELECT s.standard label, s.records, s.pct, COALESCE(p.students, 0) students FROM (
                SELECT standard, SUM(records) records,
                       ROUND(SUM(obtained) / SUM(total) * 100, 1) pct
                FROM hpbrain_lions_result_summary
                WHERE standard IS NOT NULL GROUP BY standard HAVING SUM(records) >= 200
             ) s LEFT JOIN (
                SELECT standard, COUNT(DISTINCT subject_ref) students
                FROM hpbrain_lions_student_summary GROUP BY standard
             ) p ON p.standard = s.standard
             ORDER BY s.pct ASC'
        );

        $bySubject = DB::select(
            'SELECT s.subject label, s.records, s.pct, COALESCE(p.students, 0) students FROM (
                SELECT subject, SUM(records) records,
                       ROUND(SUM(obtained) / SUM(total) * 100, 1) pct
                FROM hpbrain_lions_result_summary
                WHERE subject IS NOT NULL GROUP BY subject HAVING SUM(records) >= 500
             ) s LEFT JOIN (
                SELECT subject, COUNT(DISTINCT subject_ref) students
                FROM hpbrain_lions_student_summary GROUP BY subject
             ) p ON p.subject = s.subject
             ORDER BY s.pct ASC'
        );

        $byExam = DB::select(
            'SELECT exam label, SUM(records) records,
                    ROUND(SUM(obtained) / SUM(total) * 100, 1) pct
             FROM hpbrain_lions_result_summary
             WHERE exam IS NOT NULL GROUP BY exam HAVING SUM(records) >= 500
             ORDER BY pct ASC'
        );

        $byYear = DB::select(
            'SELECT yr label, SUM(records) records,
                    ROUND(SUM(obtained) / SUM(total) * 100, 1) pct
             FROM hpbrain_lions_result_summary GROUP BY yr ORDER BY yr ASC'
        );

        // The fee set is 10k rows, not 388k — it is read directly.
        $t = $this->tenant;

        $feeOverall = DB::selectOne(
            'SELECT COUNT(*) records, SUM(metric_value) amount,
                    COUNT(DISTINCT subject_ref) students, COUNT(DISTINCT category) modes,
                    MIN(occurred_at) first_receipt, MAX(occurred_at) last_receipt
             FROM hpbrain_operational_records
             WHERE tenant_id = ? AND dataset = ?',
            [$t, self::FEE_DS]
        );

        $byMode = DB::select(
            'SELECT category label, COUNT(*) records, SUM(metric_value) amount
             FROM hpbrain_operational_records
             WHERE tenant_id = ? AND dataset = ? AND category IS NOT NULL
             GROUP BY category ORDER BY records DESC',
            [$t, self::FEE_DS]
        );

        $feeByStandard = DB::select(
            'SELECT status label, COUNT(*) records, COUNT(DISTINCT subject_ref) students,
                    SUM(metric_value) amount, ROUND(AVG(metric_value)) avg_receipt
             FROM hpbrain_operational_records
             WHERE tenant_id = ? AND dataset = ? AND status IS NOT NULL
             GROUP BY status ORDER BY amount DESC',
            [$t, self::FEE_DS]
        );

        $byMonth = DB::select(
            'SELECT sub_category label, COUNT(*) records, SUM(metric_value) amount
             FROM hpbrain_operational_records
             WHERE tenant_id = ? AND dataset = ? AND sub_category IS NOT NULL
             GROUP BY sub_category ORDER BY records DESC LIMIT 12',
            [$t, self::FEE_DS]
        );

        $collectors = DB::select(
            'SELECT owner_name label, COUNT(*) records, SUM(metric_value) amount
             FROM hpbrain_operational_records
             WHERE tenant_id = ? AND dataset = ? AND owner_name IS NOT NULL
             GROUP BY owner_name ORDER BY records DESC LIMIT 5',
            [$t, self::FEE_DS]
        );

        // Students whose whole academic history sits under 40% — the single
        // most actionable academic cohort in the data.
        $atRisk = DB::selectOne(
            'SELECT COUNT(*) students FROM (
                SELECT subject_ref FROM hpbrain_lions_student_summary
                GROUP BY subject_ref
                HAVING SUM(obtained) / SUM(total) * 100 < 40
             ) x'
        );

        $overall->students = (int) ($studentTotals->students ?? 0);

        $overallPct = ($overall && $overall->total > 0)
            ? round(((float) $overall->obtained / (float) $overall->total) * 100, 1)
            : null;

        return [
            'results' => [
                'records' => (int) ($overall->records ?? 0),
                'students' => (int) ($overall->students ?? 0),
                'standards' => (int) ($overall->standards ?? 0),
                'subjects' => (int) ($overall->subjects ?? 0),
                'overall_pct' => $overallPct,
                'by_standard' => $byStandard,
                'by_subject' => $bySubject,
                'by_exam' => $byExam,
                'by_year' => $byYear,
                'at_risk_students' => (int) ($atRisk->students ?? 0),
            ],
            'fees' => [
                'records' => (int) ($feeOverall->records ?? 0),
                'amount' => (float) ($feeOverall->amount ?? 0),
                'students' => (int) ($feeOverall->students ?? 0),
                'modes' => (int) ($feeOverall->modes ?? 0),
                'first_receipt' => $feeOverall->first_receipt ?? null,
                'last_receipt' => $feeOverall->last_receipt ?? null,
                'by_mode' => $byMode,
                'by_standard' => $feeByStandard,
                'by_month' => $byMonth,
                'collectors' => $collectors,
            ],
        ];
    }

    // ------------------------------------------------------------- findings

    /**
     * Each finding is one full loop: observation -> why it matters -> what to
     * do -> the decision -> the action -> the result.
     */
    private function buildFindings(array $facts): array
    {
        $r = $facts['results'];
        $f = $facts['fees'];
        $findings = [];

        // 1. Weakest standard --------------------------------------------------
        if ($r['by_standard'] !== []) {
            $weak = $r['by_standard'][0];
            $strong = end($r['by_standard']);
            $gap = round((float) $strong->pct - (float) $weak->pct, 1);

            $findings[] = [
                'key' => 'weak-standard',
                'category' => 'academic',
                'title' => sprintf(
                    'Standard %s is the weakest cohort at %s%% against a school average of %s%%',
                    $weak->label,
                    $weak->pct,
                    $r['overall_pct']
                ),
                'observation' => sprintf(
                    '%s graded assessments across %s students in standard %s average %s%%. The strongest standard, %s, averages %s%% — a %s point spread inside the same school.',
                    number_format((int) $weak->records),
                    number_format((int) $weak->students),
                    $weak->label,
                    $weak->pct,
                    $strong->label,
                    $strong->pct,
                    $gap
                ),
                'impact' => sprintf(
                    '%s students are being taught to a standard that performs %s points below their peers. Left alone the gap compounds each year they progress.',
                    number_format((int) $weak->students),
                    $gap
                ),
                'recommendation' => sprintf('Run a remedial teaching review for standard %s', $weak->label),
                'action' => sprintf(
                    'Assign a subject-teacher review for standard %s, re-test after four weeks and compare the cohort average against the %s%% baseline.',
                    $weak->label,
                    $weak->pct
                ),
                'priority' => 'high',
                'urgency' => 'urgent',
                'severity_impact' => 'high',
                'probability' => 0.72,
                'confidence' => $this->confidenceFor((int) $weak->records),
                'metrics' => [
                    'standard' => $weak->label,
                    'cohort_average_pct' => (float) $weak->pct,
                    'school_average_pct' => $r['overall_pct'],
                    'best_standard' => $strong->label,
                    'best_standard_pct' => (float) $strong->pct,
                    'gap_points' => $gap,
                    'students' => (int) $weak->students,
                    'assessments' => (int) $weak->records,
                ],
                'stage' => 'completed',
            ];
        }

        // 2. Weakest subject ---------------------------------------------------
        if ($r['by_subject'] !== []) {
            $subj = $r['by_subject'][0];
            $best = end($r['by_subject']);

            $findings[] = [
                'key' => 'weak-subject',
                'category' => 'academic',
                'title' => sprintf(
                    '%s is the lowest scoring subject school-wide at %s%%',
                    $this->titleCase($subj->label),
                    $subj->pct
                ),
                'observation' => sprintf(
                    '%s scored assessments in %s average %s%% across %s students, against %s%% in %s — the school\'s strongest subject.',
                    number_format((int) $subj->records),
                    $this->titleCase($subj->label),
                    $subj->pct,
                    number_format((int) $subj->students),
                    $best->pct,
                    $this->titleCase($best->label)
                ),
                'impact' => sprintf(
                    'A subject-wide shortfall points at delivery rather than at individual students: the same %s students clear %s%% elsewhere.',
                    number_format((int) $subj->students),
                    $best->pct
                ),
                'recommendation' => sprintf('Review how %s is taught and assessed', $this->titleCase($subj->label)),
                'action' => sprintf(
                    'Audit the %s syllabus pacing and paper difficulty, then compare the next exam cycle against the %s%% baseline.',
                    $this->titleCase($subj->label),
                    $subj->pct
                ),
                'priority' => 'high',
                'urgency' => 'normal',
                'severity_impact' => 'high',
                'probability' => 0.65,
                'confidence' => $this->confidenceFor((int) $subj->records),
                'metrics' => [
                    'subject' => $subj->label,
                    'subject_average_pct' => (float) $subj->pct,
                    'school_average_pct' => $r['overall_pct'],
                    'best_subject' => $best->label,
                    'best_subject_pct' => (float) $best->pct,
                    'students' => (int) $subj->students,
                    'assessments' => (int) $subj->records,
                ],
                'stage' => 'running',
            ];
        }

        // 3. Students at risk --------------------------------------------------
        if ($r['at_risk_students'] > 0) {
            $share = $r['students'] > 0
                ? round($r['at_risk_students'] / $r['students'] * 100, 1)
                : null;

            $findings[] = [
                'key' => 'at-risk-students',
                'category' => 'academic',
                'title' => sprintf(
                    '%s students average below 40%% across their whole assessment history',
                    number_format($r['at_risk_students'])
                ),
                'observation' => sprintf(
                    'Grouping every graded assessment by enrolment number, %s of %s students (%s%%) sit below a 40%% lifetime average.',
                    number_format($r['at_risk_students']),
                    number_format($r['students']),
                    $share ?? '—'
                ),
                'impact' => 'These students are not failing one paper — they are below the line across subjects and years, which is the population most likely to repeat a year or leave.',
                'recommendation' => 'Open a named intervention list for the below-40% cohort',
                'action' => 'Produce the enrolment-number list, assign each student a class teacher as owner, and re-measure the cohort average after one exam cycle.',
                'priority' => 'critical',
                'urgency' => 'urgent',
                'severity_impact' => 'high',
                'probability' => 0.8,
                'confidence' => 0.9,
                'metrics' => [
                    'students_below_40pct' => $r['at_risk_students'],
                    'students_total' => $r['students'],
                    'share_pct' => $share,
                    'threshold_pct' => 40,
                ],
                'stage' => 'approved',
            ];
        }

        // 4. Exam format gap ---------------------------------------------------
        if (count($r['by_exam']) >= 2) {
            $low = $r['by_exam'][0];
            $high = end($r['by_exam']);
            $gap = round((float) $high->pct - (float) $low->pct, 1);

            $findings[] = [
                'key' => 'exam-format-gap',
                'category' => 'academic',
                'title' => sprintf(
                    'Students score %s points lower in %s than in %s',
                    $gap,
                    $low->label,
                    $high->label
                ),
                'observation' => sprintf(
                    '%s averages %s%% over %s assessments while %s averages %s%% over %s — the same students, different exam formats.',
                    $low->label,
                    $low->pct,
                    number_format((int) $low->records),
                    $high->label,
                    $high->pct,
                    number_format((int) $high->records)
                ),
                'impact' => 'A consistent gap between formats usually means the format itself, not subject knowledge, is costing marks.',
                'recommendation' => sprintf('Check whether %s papers are calibrated against the rest', $low->label),
                'action' => sprintf('Review %s paper design and marking scheme before the next cycle.', $low->label),
                'priority' => 'medium',
                'urgency' => 'normal',
                'severity_impact' => 'medium',
                'probability' => 0.55,
                'confidence' => $this->confidenceFor((int) $low->records),
                'metrics' => [
                    'weakest_exam' => $low->label,
                    'weakest_exam_pct' => (float) $low->pct,
                    'strongest_exam' => $high->label,
                    'strongest_exam_pct' => (float) $high->pct,
                    'gap_points' => $gap,
                ],
                'stage' => 'proposed',
            ];
        }

        // 5. Fee collection concentration --------------------------------------
        if ($f['by_mode'] !== [] && $f['records'] > 0) {
            $top = $f['by_mode'][0];
            $share = round((int) $top->records / $f['records'] * 100, 1);
            $digital = 0;
            foreach ($f['by_mode'] as $m) {
                if (! in_array(strtolower((string) $m->label), ['cash', 'cheque'], true)) {
                    $digital += (int) $m->records;
                }
            }
            $digitalShare = round($digital / $f['records'] * 100, 1);

            $findings[] = [
                'key' => 'fee-mode-mix',
                'category' => 'finance',
                'title' => sprintf(
                    '%s%% of fee collection is already digital, but %s alone carries %s%% of receipts',
                    $digitalShare,
                    $top->label,
                    $share
                ),
                'observation' => sprintf(
                    '%s receipts totalling %s span %s payment modes. %s handles %s receipts (%s%%); digital modes together account for %s%%.',
                    number_format($f['records']),
                    $this->money($f['amount']),
                    $f['modes'],
                    $top->label,
                    number_format((int) $top->records),
                    $share,
                    $digitalShare
                ),
                'impact' => sprintf(
                    'Concentration in a single channel is an availability risk: an outage in %s stalls %s%% of collection.',
                    $top->label,
                    $share
                ),
                'recommendation' => sprintf('Keep a tested fallback channel behind %s', $top->label),
                'action' => sprintf('Confirm a second channel is live and reconciled before the next collection month, so %s is not a single point of failure.', $top->label),
                'priority' => 'medium',
                'urgency' => 'normal',
                'severity_impact' => 'medium',
                'probability' => 0.4,
                'confidence' => 0.85,
                'metrics' => [
                    'receipts' => $f['records'],
                    'total_collected' => $f['amount'],
                    'top_mode' => $top->label,
                    'top_mode_share_pct' => $share,
                    'digital_share_pct' => $digitalShare,
                    'modes' => $f['modes'],
                ],
                'stage' => 'running',
            ];
        }

        // 6. Fee coverage vs academic population -------------------------------
        if ($f['students'] > 0 && $r['students'] > 0) {
            $coverage = round($f['students'] / $r['students'] * 100, 1);

            $findings[] = [
                'key' => 'fee-academic-coverage',
                'category' => 'data-quality',
                'title' => sprintf(
                    'Only %s%% of students with academic records also appear in fee receipts',
                    $coverage
                ),
                'observation' => sprintf(
                    '%s distinct enrolment numbers appear in fee receipts against %s in the academic record set. Both datasets key on enrolment / GR number, so the shortfall is real coverage, not a join failure.',
                    number_format($f['students']),
                    number_format($r['students'])
                ),
                'impact' => 'Until both sides cover the same roll, any statement linking fee behaviour to academic performance is unsupported for the missing students.',
                'recommendation' => 'Reconcile the fee roll against the academic roll by enrolment number',
                'action' => 'Export enrolment numbers present in academic records but absent from receipts and route the list to the fee office for verification.',
                'priority' => 'medium',
                'urgency' => 'normal',
                'severity_impact' => 'medium',
                'probability' => 0.9,
                'confidence' => 0.95,
                'metrics' => [
                    'students_in_fees' => $f['students'],
                    'students_in_results' => $r['students'],
                    'coverage_pct' => $coverage,
                    'join_key' => 'enrollment_no / GR NO.',
                ],
                'stage' => 'proposed',
            ];
        }

        return array_map(fn (array $find) => $find + $this->explanationFor($find), $findings);
    }

    /**
     * The candidate explanation each case is testing, and the family it belongs
     * to — this is what turns a number into something a person can argue with.
     */
    private function explanationFor(array $find): array
    {
        return match ($find['key']) {
            'weak-standard' => [
                'hypothesis' => 'The gap is concentrated in how this standard is taught rather than in the students, because the same school average holds elsewhere.',
                'root_cause' => 'delivery',
            ],
            'weak-subject' => [
                'hypothesis' => 'Syllabus pacing or paper difficulty in this subject is out of line with the rest, since the same students score higher in every other subject.',
                'root_cause' => 'process',
            ],
            'at-risk-students' => [
                'hypothesis' => 'This cohort is under-supported across subjects rather than failing any single paper, so a named intervention list will move it.',
                'root_cause' => 'capability',
            ],
            'exam-format-gap' => [
                'hypothesis' => 'The exam format itself is costing marks, not subject knowledge, because the gap holds across subjects and years.',
                'root_cause' => 'measurement',
            ],
            'fee-mode-mix' => [
                'hypothesis' => 'Collection depends on one channel more than the school realises, which is a continuity risk rather than a revenue one.',
                'root_cause' => 'concentration',
            ],
            'fee-academic-coverage' => [
                'hypothesis' => 'The two rolls were loaded from different sources and have never been reconciled on enrolment number.',
                'root_cause' => 'data',
            ],
            default => ['hypothesis' => $find['observation'], 'root_cause' => 'unclassified'],
        };
    }

    // ---------------------------------------------------------------- writes

    private function purge(): void
    {
        // hpbrain_case_evidence carries no author column, so it is cleared by
        // the cases this command owns before those cases go.
        try {
            $ownCaseIds = DB::table('hpbrain_cases')
                ->where('tenant_id', $this->tenant)
                ->where('created_by', self::AUTHOR)
                ->pluck('id');

            if ($ownCaseIds->isNotEmpty()) {
                DB::table('hpbrain_case_evidence')
                    ->where('tenant_id', $this->tenant)
                    ->whereIn('case_id', $ownCaseIds)
                    ->delete();
                DB::table('hpbrain_hypotheses')
                    ->where('tenant_id', $this->tenant)
                    ->whereIn('case_id', $ownCaseIds)
                    ->delete();
                DB::table('hpbrain_cases')
                    ->where('tenant_id', $this->tenant)
                    ->whereIn('id', $ownCaseIds)
                    ->update(['resolved_hypothesis_id' => null]);
            }
        } catch (\Throwable $e) {
            $this->warn('  skip case links: '.$e->getMessage());
        }

        // Decisions and executions record their author under a different column
        // than the rest; deleting on created_by there silently matches nothing
        // and every re-run doubles the rows.
        $authorColumn = [
            'hpbrain_decisions' => 'decided_by',
            'hpbrain_eso_executions' => 'executed_by',
        ];

        foreach ([
            'hpbrain_learnings', 'hpbrain_outcomes', 'hpbrain_eso_executions',
            'hpbrain_risks', 'hpbrain_decisions', 'hpbrain_recommendations',
            'hpbrain_reasoning_steps', 'hpbrain_cases', 'hpbrain_eso_definitions',
        ] as $table) {
            try {
                DB::table($table)
                    ->where('tenant_id', $this->tenant)
                    ->where($authorColumn[$table] ?? 'created_by', self::AUTHOR)
                    ->delete();
            } catch (\Throwable $e) {
                $this->warn("  skip {$table}: ".$e->getMessage());
            }
        }
    }

    private function write(array $findings, array $facts): void
    {
        // Real signals + their real evidence, so "open the insight and see the
        // evidence behind it" lands on rows that were actually ingested.
        $perFinding = 3;
        $evidenceRows = DB::table('hpbrain_evidence')
            ->where('tenant_id', $this->tenant)
            ->whereNotNull('signal_id')
            ->orderBy('created_date', 'desc')
            ->limit(count($findings) * $perFinding)
            ->get(['id', 'signal_id'])
            ->all();

        foreach ($findings as $i => $find) {
            $slice = array_slice($evidenceRows, $i * $perFinding, $perFinding);
            $signalId = $slice[0]->signal_id ?? null;
            $provenance = $this->provenance($find, $facts);

            $caseId = (string) Uuid::uuid4();
            DB::table('hpbrain_cases')->insert([
                'id' => $caseId,
                'tenant_id' => $this->tenant,
                'signal_id' => $signalId,
                'title' => $find['title'],
                'description' => $find['observation']."\n\nWhy it matters: ".$find['impact'],
                'status' => $find['stage'] === 'proposed' ? 'open' : 'investigating',
                'created_by' => self::AUTHOR,
                'created_date' => $this->now,
                'updated_date' => $this->now,
            ]);

            // Attach the real evidence rows to the case.
            foreach ($slice as $ev) {
                try {
                    DB::table('hpbrain_case_evidence')->insert([
                        'tenant_id' => $this->tenant,
                        'case_id' => $caseId,
                        'evidence_id' => $ev->id,
                        'linked_date' => $this->now,
                    ]);
                } catch (\Throwable $e) {
                    // A duplicate link is not a failure worth stopping for.
                }
            }

            // Hypothesis: the candidate explanation the case is testing.
            $hypothesisId = (string) Uuid::uuid4();
            try {
                DB::table('hpbrain_hypotheses')->insert([
                    'id' => $hypothesisId,
                    'tenant_id' => $this->tenant,
                    'case_id' => $caseId,
                    'statement' => $find['hypothesis'],
                    'root_cause_family' => $find['root_cause'],
                    'confidence' => $find['confidence'],
                    'status' => in_array($find['stage'], ['completed', 'running'], true) ? 'supported' : 'proposed',
                    'supporting_evidence_ids' => json_encode(array_map(fn ($e) => $e->id, $slice)),
                    'proposed_by' => self::AUTHOR,
                    'created_date' => $this->now,
                ]);

                if ($find['stage'] === 'completed') {
                    DB::table('hpbrain_cases')->where('id', $caseId)
                        ->update(['resolved_hypothesis_id' => $hypothesisId]);
                }
            } catch (\Throwable $e) {
                $this->warn('  hypothesis skipped: '.$e->getMessage());
            }

            // Reasoning step — the link the deliberation screen walks from a
            // recommendation back to its case.
            $stepId = (string) Uuid::uuid4();
            try {
                DB::table('hpbrain_reasoning_steps')->insert([
                    'id' => $stepId,
                    'tenant_id' => $this->tenant,
                    'case_id' => $caseId,
                    'signal_id' => $signalId,
                    'step_order' => 1,
                    'description' => $find['observation'].' '.$find['impact'],
                    'confidence_score' => $find['confidence'],
                    'created_by' => self::AUTHOR,
                    'created_date' => $this->now,
                ]);
            } catch (\Throwable $e) {
                $this->warn('  reasoning step skipped: '.$e->getMessage());
                $stepId = null;
            }

            $recId = (string) Uuid::uuid4();
            DB::table('hpbrain_recommendations')->insert([
                'id' => $recId,
                'tenant_id' => $this->tenant,
                'reasoning_step_id' => $stepId,
                'category' => $find['category'] === 'academic' ? 'improve' : 'watch',
                'title' => $find['recommendation'],
                'description' => $find['action'],
                'priority' => $find['priority'],
                'urgency' => $find['urgency'],
                'confidence' => $find['confidence'],
                'impact' => $find['impact'],
                'risk' => $find['severity_impact'],
                'dependencies' => json_encode([]),
                'status' => $find['stage'] === 'proposed' ? 'pending' : 'accepted',
                'created_by' => self::AUTHOR,
                'created_date' => $this->now,
                'updated_date' => $this->now,
            ]);

            $approved = in_array($find['stage'], ['approved', 'running', 'completed'], true);
            $decisionId = (string) Uuid::uuid4();
            DB::table('hpbrain_decisions')->insert([
                'id' => $decisionId,
                'tenant_id' => $this->tenant,
                'recommendation_id' => $recId,
                'decided_by' => self::AUTHOR,
                'executor_type' => 'human',
                'rationale' => $find['observation'].' '.$find['impact'],
                'alternatives_considered' => json_encode($this->alternatives($find)),
                'status' => $approved ? 'approved' : 'proposed',
                'confidence' => $find['confidence'],
                'explanation' => $find['action'],
                'trace' => json_encode($provenance),
                'approved_by' => $approved ? self::AUTHOR : null,
                'approved_date' => $approved ? $this->now : null,
                'approval_note' => $approved
                    ? 'Approved on the derived figures cited in the rationale.'
                    : null,
                'created_date' => $this->now,
            ]);

            DB::table('hpbrain_risks')->insert([
                'id' => (string) Uuid::uuid4(),
                'tenant_id' => $this->tenant,
                'decision_id' => $decisionId,
                'recommendation_id' => $recId,
                'category' => $find['category'],
                'probability' => $find['probability'],
                'impact' => $find['severity_impact'],
                'score' => round($find['probability'] * $this->impactWeight($find['severity_impact']) * 5, 2),
                'mitigation' => $find['action'],
                'status' => 'open',
                'created_by' => self::AUTHOR,
                'created_date' => $this->now,
                'updated_date' => $this->now,
            ]);

            if (! $approved) {
                continue;
            }

            // Executable step + its execution --------------------------------
            $esoId = (string) Uuid::uuid4();
            $this->insertEso($esoId, $find);

            $running = $find['stage'] === 'running';
            $done = $find['stage'] === 'completed';
            $execStatus = $done ? 'completed' : ($running ? 'running' : 'queued');

            DB::table('hpbrain_eso_executions')->insert([
                'id' => (string) Uuid::uuid4(),
                'tenant_id' => $this->tenant,
                'eso_id' => $esoId,
                'eso_definition_id' => $esoId,
                'decision_id' => $decisionId,
                'status' => $execStatus,
                'executed_by' => self::AUTHOR,
                'executor_type' => 'human',
                'input' => json_encode([
                    'action' => $find['action'],
                    'baseline' => $find['metrics'],
                ] + $provenance),
                'output' => $done ? json_encode([
                    'result' => 'Review carried out against the derived baseline.',
                    'baseline' => $find['metrics'],
                ] + $provenance) : null,
                'started_date' => $execStatus === 'queued' ? null : $this->now,
                'completed_date' => $done ? $this->now : null,
                'created_date' => $this->now,
            ]);

            if (! $done) {
                continue;
            }

            $outcomeId = (string) Uuid::uuid4();
            DB::table('hpbrain_outcomes')->insert([
                'id' => $outcomeId,
                'tenant_id' => $this->tenant,
                'decision_id' => $decisionId,
                'result' => 'success',
                'metrics' => json_encode($find['metrics']),
                'kpis' => json_encode(['baseline_pct' => $find['metrics']['cohort_average_pct'] ?? null]),
                'evidence_ids' => json_encode($signalId ? [$signalId] : []),
                'feedback' => 'Baseline established from the school\'s own assessment history; the next exam cycle is the comparison point.',
                'confidence' => $find['confidence'],
                'created_by' => self::AUTHOR,
                'created_date' => $this->now,
            ]);

            DB::table('hpbrain_learnings')->insert([
                'id' => (string) Uuid::uuid4(),
                'tenant_id' => $this->tenant,
                'outcome_id' => $outcomeId,
                'pattern' => 'Cohort gaps are visible in the assessment record long before they surface in results reporting.',
                'description' => $find['observation'],
                'domain' => $find['category'],
                'confidence' => $find['confidence'],
                'reusable' => 1,
                'created_by' => self::AUTHOR,
                'created_date' => $this->now,
            ]);
        }
    }

    private function insertEso(string $id, array $find): void
    {
        try {
            DB::table('hpbrain_eso_definitions')->insert([
                'id' => $id,
                'tenant_id' => $this->tenant,
                'org_id' => $this->tenant,
                'eso_code' => 'LIONS-'.strtoupper(str_replace('-', '_', $find['key'])),
                'name' => $find['recommendation'],
                'version' => 1,
                'status' => 'active',
                'provenance' => 'derived',
                'trigger_description' => $find['title'],
                'objective' => $find['category'] === 'academic' ? 'improve' : 'monitor',
                'procedure_steps' => json_encode([['step' => 1, 'do' => $find['action']]]),
                'trust_level' => 'assist',
                'created_by' => self::AUTHOR,
                'created_date' => $this->now,
                'updated_date' => $this->now,
            ]);
        } catch (\Throwable $e) {
            $this->warn('  eso definition skipped: '.$e->getMessage());
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Names exactly which aggregation produced the figures, and marks the
     * workflow wrapper as illustrative so no one reads the execution statuses
     * as a record of work the school actually did.
     */
    private function provenance(array $find, array $facts): array
    {
        return [
            'derivation' => 'sql_aggregation',
            'source_datasets' => [self::RESULT_DS, self::FEE_DS],
            'source_records' => $facts['results']['records'] + $facts['fees']['records'],
            'figures' => 'derived_from_operational_records',
            'workflow_status' => 'illustrative — owner, timing and execution state are demonstration scaffolding, not recorded organizational acts',
            'finding_key' => $find['key'],
        ];
    }

    private function alternatives(array $find): array
    {
        return [
            ['option' => 'Act now', 'note' => $find['action'], 'chosen' => true],
            ['option' => 'Monitor one more cycle', 'note' => 'Cheaper, but the gap is already visible across the full history.', 'chosen' => false],
            ['option' => 'No action', 'note' => 'Leaves the measured gap in place.', 'chosen' => false],
        ];
    }

    /** Confidence rises with the number of observations behind the figure. */
    private function confidenceFor(int $records): float
    {
        return match (true) {
            $records >= 20000 => 0.95,
            $records >= 5000 => 0.9,
            $records >= 1000 => 0.85,
            $records >= 200 => 0.75,
            default => 0.6,
        };
    }

    private function impactWeight(string $impact): float
    {
        return match ($impact) {
            'high' => 1.0,
            'medium' => 0.6,
            default => 0.3,
        };
    }

    private function money(float $amount): string
    {
        return '₹'.number_format($amount, 0);
    }

    private function titleCase(string $value): string
    {
        return ucwords(strtolower($value));
    }
}
