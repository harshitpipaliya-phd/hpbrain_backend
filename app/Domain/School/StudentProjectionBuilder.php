<?php

declare(strict_types=1);

namespace App\Domain\School;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapse operational records into one row per student.
 *
 * THE WHOLE POINT: NOTHING CROSSES THE WIRE PER STUDENT. Every statement here is
 * `INSERT ... SELECT ... GROUP BY ... ON DUPLICATE KEY UPDATE`, so 388,401
 * academic rows and 10,430 fee rows are collapsed inside MySQL and only the
 * write happens. The obvious alternative — read the records into PHP, group them
 * in a Collection, upsert one student at a time — is roughly 7,400 round trips
 * against a shared database, which is how the original import produced
 * `Lock wait timeout exceeded` in the first place.
 *
 * IDENTITY. `academic.enrollment_no` and `fees."GR NO."` are both written to
 * `subject_ref` at ingest, and they are the same number for the same child. That
 * equality is the ONLY join between the two files; names are not used, because
 * two children share a name far more often than a school expects and a
 * name-matched fee record attached to the wrong student is worse than no fee
 * record at all.
 *
 * THE STUDENT ID IS DERIVED, NOT RANDOM. uuid-shaped SHA-256 over
 * (tenant_id, student_ref), so a rebuild produces the SAME id for the same
 * student and a link to a student's page keeps working across rebuilds. A
 * random id would silently break every stored reference each time this ran.
 *
 * WHY avg_percentage IS SUM(obtain)/SUM(total), NOT AVG(obtain/total). A student
 * sitting a 30-mark activity and a 120-mark written paper has not scored the
 * average of two percentages — the written paper is four times the assessment.
 * Averaging the per-paper percentages would weight a 30-mark drawing exercise
 * equally with a final, which is a different (and wrong) number wearing the same
 * label. The totals it is built from are stored beside it so the figure can be
 * checked rather than trusted.
 *
 * MYSQL ONLY, BY DESIGN. This is a maintenance command against the production
 * store, not request-path code. The suite runs on SQLite where these statements
 * have no equivalent and nothing calls them; rebuild() reports that it did
 * nothing rather than pretending to have run.
 */
final class StudentProjectionBuilder
{
    public function __construct(private readonly DatasetRegistry $datasets)
    {
    }

    /**
     * Rebuild one tenant's student projection from its datasets.
     *
     * @return array{academic: int, fees: int, students: int, skipped: ?string}
     */
    public function rebuild(string $tenantId, ?string $academicDataset = null, ?string $feeDataset = null): array
    {
        $academicDataset ??= $this->datasets->academic($tenantId);
        $feeDataset ??= $this->datasets->fees($tenantId);

        if (DB::connection()->getDriverName() !== 'mysql') {
            return ['academic' => 0, 'fees' => 0, 'students' => 0, 'skipped' => 'not_mysql'];
        }

        if (! Schema::hasTable('hpbrain_students') || ! Schema::hasTable('hpbrain_operational_records')) {
            return ['academic' => 0, 'fees' => 0, 'students' => 0, 'skipped' => 'missing_tables'];
        }

        if ($academicDataset === null && $feeDataset === null) {
            return ['academic' => 0, 'fees' => 0, 'students' => 0, 'skipped' => 'no_datasets'];
        }

        $now = gmdate('Y-m-d H:i:s');

        $academic = $academicDataset === null ? 0 : $this->projectAcademic($tenantId, $academicDataset, $now);
        $fees = $feeDataset === null ? 0 : $this->projectFees($tenantId, $feeDataset, $now);

        $this->labelCohorts($tenantId);

        $students = (int) DB::table('hpbrain_students')->where('tenant_id', $tenantId)->count();

        return ['academic' => $academic, 'fees' => $fees, 'students' => $students, 'skipped' => null];
    }

    /**
     * One row per enrollment_no, carrying that student's whole academic record.
     */
    private function projectAcademic(string $tenantId, string $dataset, string $now): int
    {
        $id = $this->derivedId('a.tenant_id', 'a.student_ref');

        $sql = <<<SQL
        INSERT INTO hpbrain_students (
            id, tenant_id, student_ref, student_name, academic_standard,
            in_academic, academic_records, subjects_count,
            total_obtained, total_marks, avg_percentage,
            first_academic_year, last_academic_year, academic_year,
            projected_at, created_date, updated_date
        )
        SELECT
            {$id}, a.tenant_id, a.student_ref, a.student_name, a.latest_standard,
            1, a.records, a.subjects,
            a.obtained, a.marks, a.avg_pct,
            a.first_year, a.last_year, a.last_year,
            ?, ?, ?
        FROM (
            SELECT
                tenant_id,
                subject_ref AS student_ref,
                /*
                  EXACTLY ONE JSON PARSE PER ROW, and that is deliberate.

                  The first version of this query read syear out of `payload`
                  three times — MIN, MAX, and as the sort key of the
                  GROUP_CONCAT — which is four JSON parses per row plus a
                  per-group filesort over a parsed expression. Across one
                  tenant's 388,401 rows that measured over fifteen minutes and
                  was still running.

                  The year is now taken from occurred_at, which is an indexed
                  DATETIME holding exactly the same fact (see
                  dataset:repair-occurred-at, which must run first on data
                  imported before that column was written correctly). Only the
                  student's name has no promoted column, so it is the only
                  field still read out of JSON.
                */
                MAX(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.student_name')))            AS student_name,
                COUNT(*)                                                              AS records,
                COUNT(DISTINCT category)                                              AS subjects,
                ROUND(SUM(metric_value), 2)                                           AS obtained,
                ROUND(SUM(quantity), 2)                                               AS marks,
                ROUND(SUM(metric_value) / NULLIF(SUM(quantity), 0) * 100, 2)          AS avg_pct,
                CAST(YEAR(MIN(occurred_at)) AS CHAR)                                  AS first_year,
                CAST(YEAR(MAX(occurred_at)) AS CHAR)                                  AS last_year,
                /*
                  The standard the student was in during their most recent year.

                  MAX over a sortable concatenation, NOT
                  GROUP_CONCAT(status ORDER BY occurred_at DESC). Both answer the
                  question; only one is cheap. GROUP_CONCAT with an ORDER BY
                  builds and sorts a buffer for every one of the ~5,300 groups
                  and can spill to a temporary table, which dominated the runtime
                  of this statement. MAX() is a running comparison with no buffer
                  at all.

                  It works because the date prefix is FIXED WIDTH: '20210101'
                  always sorts after '20180101', so the largest string is the one
                  from the latest date and SUBSTRING past the prefix recovers the
                  standard. It is also deterministic where the GROUP_CONCAT form
                  broke ties arbitrarily.
                */
                SUBSTRING(
                    MAX(CONCAT(DATE_FORMAT(occurred_at, '%Y%m%d'), status)),
                    9
                )                                                                     AS latest_standard
            FROM hpbrain_operational_records
            WHERE tenant_id = ?
              AND dataset = ?
              AND subject_ref IS NOT NULL
              AND subject_ref <> ''
            GROUP BY tenant_id, subject_ref
        ) a
        ON DUPLICATE KEY UPDATE
            student_name        = COALESCE(NULLIF(VALUES(student_name), ''), hpbrain_students.student_name),
            academic_standard   = VALUES(academic_standard),
            in_academic         = 1,
            academic_records    = VALUES(academic_records),
            subjects_count      = VALUES(subjects_count),
            total_obtained      = VALUES(total_obtained),
            total_marks         = VALUES(total_marks),
            avg_percentage      = VALUES(avg_percentage),
            first_academic_year = VALUES(first_academic_year),
            last_academic_year  = VALUES(last_academic_year),
            academic_year       = VALUES(academic_year),
            projected_at        = VALUES(projected_at),
            updated_date        = VALUES(updated_date)
        SQL;

        return DB::affectingStatement($sql, [$now, $now, $now, $tenantId, $dataset]);
    }

    /**
     * One row per GR number, carrying that student's receipts.
     *
     * ONLY WHAT THE RECEIPTS SAY. The source has an Amount per receipt and no
     * billed, demand or due column anywhere in it, so this stores money received
     * and nothing else. Outstanding, overdue and collection-rate are not
     * derivable from this file and are not invented here.
     */
    private function projectFees(string $tenantId, string $dataset, string $now): int
    {
        $id = $this->derivedId('f.tenant_id', 'f.student_ref');

        /*
          "The value from this student's most recent receipt."

          Still GROUP_CONCAT here, unlike the academic pass above, and that is a
          size judgement rather than an oversight: the fee register is 10,430
          rows against 4,052 groups, where the sort buffers cost nothing
          measurable (the whole pass runs in about a minute). The academic pass
          faces 388,401 rows and had to drop it. This form also reads more
          plainly and, unlike MAX(CONCAT(...)), does not depend on the date
          prefix being fixed width — worth keeping wherever the size allows.
        */
        $latest = fn (string $expr): string => "SUBSTRING_INDEX(GROUP_CONCAT({$expr} ORDER BY occurred_at DESC SEPARATOR '||'), '||', 1)";

        $sql = <<<SQL
        INSERT INTO hpbrain_students (
            id, tenant_id, student_ref, student_name,
            standard, division, batch, student_quota, unique_id,
            in_fees, fee_records, total_paid, first_receipt_date, last_receipt_date,
            projected_at, created_date, updated_date
        )
        SELECT
            {$id}, f.tenant_id, f.student_ref, f.student_name,
            f.standard, f.division, f.batch, f.quota, f.unique_id,
            1, f.records, f.paid, f.first_receipt, f.last_receipt,
            ?, ?, ?
        FROM (
            SELECT
                tenant_id,
                subject_ref AS student_ref,
                MAX(JSON_UNQUOTE(JSON_EXTRACT(payload, '$."Student Name"')))  AS student_name,
                COUNT(*)                                                      AS records,
                ROUND(SUM(metric_value), 2)                                   AS paid,
                MIN(DATE(occurred_at))                                        AS first_receipt,
                MAX(DATE(occurred_at))                                        AS last_receipt,
                {$latest('status')}                                           AS standard,
                {$latest("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.\"Division\"'))")}       AS division,
                {$latest("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.\"Batch\"'))")}          AS batch,
                {$latest("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.\"Student Quota\"'))")}  AS quota,
                {$latest("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.\"Unique ID\"'))")}      AS unique_id
            FROM hpbrain_operational_records
            WHERE tenant_id = ?
              AND dataset = ?
              AND subject_ref IS NOT NULL
              AND subject_ref <> ''
            GROUP BY tenant_id, subject_ref
        ) f
        ON DUPLICATE KEY UPDATE
            -- The academic pass runs first and its name wins; this only fills a
            -- student the academic file never mentioned.
            student_name       = COALESCE(NULLIF(hpbrain_students.student_name, ''), VALUES(student_name)),
            standard           = VALUES(standard),
            division           = VALUES(division),
            batch              = VALUES(batch),
            student_quota      = VALUES(student_quota),
            unique_id          = VALUES(unique_id),
            in_fees            = 1,
            fee_records        = VALUES(fee_records),
            total_paid         = VALUES(total_paid),
            first_receipt_date = VALUES(first_receipt_date),
            last_receipt_date  = VALUES(last_receipt_date),
            projected_at       = VALUES(projected_at),
            updated_date       = VALUES(updated_date)
        SQL;

        return DB::affectingStatement($sql, [$now, $now, $now, $tenantId, $dataset]);
    }

    /**
     * Name each student's cohort from the flags the two passes set.
     *
     * Stored rather than computed per query so "matched", "fee-only" and
     * "result-only" are an indexed count. A student whose records were removed
     * from a dataset is demoted here rather than keeping a stale label.
     */
    private function labelCohorts(string $tenantId): void
    {
        DB::update(
            "UPDATE hpbrain_students
                SET source_dataset = CASE
                        WHEN in_academic = 1 AND in_fees = 1 THEN 'academic+fees'
                        WHEN in_academic = 1                 THEN 'academic'
                        WHEN in_fees = 1                     THEN 'fees'
                        ELSE NULL
                    END
              WHERE tenant_id = ?",
            [$tenantId],
        );
    }

    /**
     * A uuid-shaped, deterministic id for (tenant, student_ref).
     *
     * Not a real v5 uuid — it is a SHA-256 laid out in uuid punctuation. It only
     * has to be stable, unique and the right shape for a VARCHAR(36) column that
     * every other id in this schema also fills, and it is all three.
     */
    private function derivedId(string $tenantColumn, string $refColumn): string
    {
        $hash = "SHA2(CONCAT({$tenantColumn}, '|', {$refColumn}), 256)";

        return "LOWER(CONCAT_WS('-',
            SUBSTR({$hash}, 1, 8), SUBSTR({$hash}, 9, 4), SUBSTR({$hash}, 13, 4),
            SUBSTR({$hash}, 17, 4), SUBSTR({$hash}, 21, 12)))";
    }
}
