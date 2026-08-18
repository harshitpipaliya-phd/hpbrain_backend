<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\IngestionBatch;
use App\Domain\Ingestion\IngestionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Academic results as an operational dataset, alongside the fee dataset.
 *
 * THE IDENTITY PROBLEM IS THE WHOLE POINT. A result row is unique per student
 * per year per subject per exam. enrollment_no alone repeats roughly forty
 * times per student across a transcript, so binding it as the natural key would
 * collapse every subject and every exam a student sat into ONE record and
 * report the other thirty-nine as duplicates — a 388,401-row file arriving as a
 * few thousand rows, with the loss reported as successful deduplication. Every
 * assertion about composite identity below exists to stop that.
 *
 * The second theme is that numbers must stay numbers. `obtain` and `total` are
 * the two figures every academic question is built from, and a design that
 * stored them as evidence prose would make percentage, subject strength and
 * year-on-year movement uncomputable without re-parsing text.
 */
final class AcademicResultIngestionTest extends TestCase
{
    use \Tests\Support\BuildsBrainSchema;

    private const TENANT = '1000010';

    private const OTHER_TENANT = '2000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();

        if (! Schema::hasTable('hpbrain_operational_records')) {
            $this->markTestSkipped('operational records table is not part of this fixture');
        }
    }

    /* ─────────────────────────── the map itself ─────────────────────────── */

    public function test_external_ref_accepts_a_composite_of_several_columns(): void
    {
        $map = $this->resultMap();

        $this->assertTrue($map->isComposite('external_ref'));
        $this->assertSame(
            ['enrollment_no', 'syear', 'standard', 'subject', 'exam'],
            $map->columnsFor('external_ref'),
        );
    }

    public function test_a_composite_identity_is_built_from_every_bound_column(): void
    {
        $value = $this->resultMap()->value($this->row(), 'external_ref');

        $this->assertSame('10818|2018|CBSE-1|ENVIRONMENTAL STUDIES|Written', $value);
    }

    public function test_a_composite_with_any_blank_component_is_null_not_a_short_join(): void
    {
        $map = $this->resultMap();

        // A shortened join would be a DIFFERENT string for the SAME result, so
        // the natural key would stop deduplicating. Null makes the row skip.
        $this->assertNull($map->value($this->row(['exam' => '']), 'external_ref'));
        $this->assertNull($map->value($this->row(['subject' => '   ']), 'external_ref'));
        $this->assertNull($map->value($this->row(['enrollment_no' => null]), 'external_ref'));
    }

    public function test_a_single_element_list_collapses_to_a_plain_column(): void
    {
        $map = FieldMap::fromConfig(['external_ref' => ['enrollment_no']]);

        $this->assertFalse($map->isComposite('external_ref'));
        $this->assertSame('10818', $map->value($this->row(), 'external_ref'));
    }

    public function test_only_external_ref_may_be_composite(): void
    {
        // A composite measure would be a concatenation pretending to be a
        // number, and every aggregation downstream would have nothing to work
        // with. The binding is dropped rather than half-honoured.
        $map = FieldMap::fromConfig(['measure' => ['obtain', 'total'], 'title' => 'student_name']);

        $this->assertFalse($map->has('measure'));
        $this->assertTrue($map->has('title'));
    }

    /* ─────────────────────────── commit ─────────────────────────── */

    public function test_multiple_subjects_for_one_student_are_separate_records(): void
    {
        $this->commit([
            $this->row(['subject' => 'MATHS']),
            $this->row(['subject' => 'SCIENCE']),
            $this->row(['subject' => 'ENGLISH']),
        ]);

        $this->assertSame(3, $this->records()->count());
        $this->assertSame(3, $this->records()->distinct()->count('natural_key'));
    }

    public function test_multiple_exams_for_one_student_and_subject_are_separate_records(): void
    {
        $this->commit([
            $this->row(['exam' => 'Written']),
            $this->row(['exam' => 'Project']),
            $this->row(['exam' => 'Activity']),
        ]);

        $this->assertSame(3, $this->records()->count());
        $this->assertSame(3, $this->records()->distinct()->count('natural_key'));
    }

    public function test_multiple_years_for_one_student_subject_and_exam_are_separate_records(): void
    {
        $this->commit([
            $this->row(['syear' => '2018']),
            $this->row(['syear' => '2019']),
        ]);

        $this->assertSame(2, $this->records()->count());
    }

    public function test_enrollment_no_alone_would_have_collapsed_the_transcript(): void
    {
        // The counterfactual, asserted rather than described: the same three
        // rows under an enrollment-only key produce one usable identity.
        $map = FieldMap::fromConfig([
            'external_ref' => 'enrollment_no',
            'subject_ref'  => 'enrollment_no',
            'measure'      => 'obtain',
        ]);

        $rows = [
            $this->row(['subject' => 'MATHS']),
            $this->row(['subject' => 'SCIENCE']),
            $this->row(['subject' => 'ENGLISH']),
        ];

        $keys = [];
        foreach ($rows as $row) {
            $keys[] = $map->value($row, 'external_ref');
        }

        $this->assertSame(['10818', '10818', '10818'], $keys);
        $this->assertCount(1, array_unique($keys), 'this is the collapse the composite key prevents');
    }

    /* ─────────────────────────── numbers ─────────────────────────── */

    public function test_obtained_and_total_marks_are_stored_as_numbers(): void
    {
        $this->commit([$this->row(['obtain' => '112.00', 'total' => '120'])]);

        $record = $this->records()->first();

        $this->assertEqualsWithDelta(112.0, (float) $record->metric_value, 0.0001);
        $this->assertSame(120, (int) $record->quantity);
    }

    public function test_a_decimal_total_becomes_a_whole_number(): void
    {
        // The export writes paper totals as "30.00"; quantity is an INT column.
        $this->commit([$this->row(['total' => '30.00', 'obtain' => '29.00'])]);

        $this->assertSame(30, (int) $this->records()->first()->quantity);
    }

    public function test_percentage_is_computable_from_the_stored_numbers(): void
    {
        $this->commit([
            $this->row(['subject' => 'MATHS', 'obtain' => '90', 'total' => '120']),
            $this->row(['subject' => 'SCIENCE', 'obtain' => '45', 'total' => '50']),
        ]);

        $rows = $this->records()->orderBy('category')->get();

        $percent = static fn ($r): float => round(((float) $r->metric_value) / ((int) $r->quantity) * 100, 2);

        $this->assertEqualsWithDelta(90.0, $percent($rows[1]), 0.01);  // MATHS 90/120
        $this->assertEqualsWithDelta(75.0, $percent($rows[0]), 0.01);  // SCIENCE 45/50
    }

    public function test_a_zero_or_unparseable_total_is_null_never_zero(): void
    {
        $this->commit([
            $this->row(['exam' => 'A', 'total' => 'N/A']),
            $this->row(['exam' => 'B', 'total' => '']),
        ]);

        // Null rather than 0, so a percentage built on it divides by nothing
        // and is skipped rather than silently reporting infinity.
        foreach ($this->records()->get() as $record) {
            $this->assertNull($record->quantity);
        }
    }

    /* ─────────────────────────── preservation ─────────────────────────── */

    public function test_every_original_column_survives_in_the_payload(): void
    {
        $this->commit([$this->row()]);

        $payload = json_decode((string) $this->records()->first()->payload, true);

        foreach (['sub_institute_id', 'syear', 'enrollment_no', 'student_name', 'standard', 'subject', 'exam', 'total', 'obtain'] as $column) {
            $this->assertArrayHasKey($column, $payload, "{$column} was lost");
        }

        $this->assertSame('AYUSH KUMAR MANDAL', $payload['student_name']);
        $this->assertSame('CBSE-1', $payload['standard']);
    }

    public function test_the_structured_columns_carry_the_academic_dimensions(): void
    {
        $this->commit([$this->row()]);

        $record = $this->records()->first();

        $this->assertSame('10818', $record->subject_ref, 'the cross-dataset join key');
        $this->assertSame('ENVIRONMENTAL STUDIES', $record->category);
        $this->assertSame('Written', $record->sub_category);
        $this->assertSame('CBSE-1', $record->status);
    }

    /* ─────────────── the join to the fee dataset ─────────────── */

    public function test_results_and_fees_share_the_student_identifier(): void
    {
        $this->commit([$this->row(['enrollment_no' => '10818'])]);

        // A fee record for the same student, keyed the way the fee map binds it.
        DB::table('hpbrain_operational_records')->insert([
            'id' => 'fee-1', 'tenant_id' => self::TENANT, 'dataset' => 'lions-fees-data',
            'natural_key' => 'RC-1', 'subject_ref' => '10818', 'metric_value' => 48550,
            'row_hash' => str_repeat('b', 64), 'created_date' => now(), 'updated_date' => now(),
        ]);

        $shared = DB::table('hpbrain_operational_records as r')
            ->join('hpbrain_operational_records as f', function ($j) {
                $j->on('f.subject_ref', '=', 'r.subject_ref')
                    ->where('f.dataset', '=', 'lions-fees-data');
            })
            ->where('r.tenant_id', self::TENANT)
            ->where('r.dataset', 'lions-result-data')
            ->count();

        $this->assertSame(1, $shared, 'subject_ref must join results to fees');
    }

    /* ─────────────────────────── re-import ─────────────────────────── */

    public function test_reimporting_the_same_rows_creates_no_duplicates(): void
    {
        $rows = [$this->row(['subject' => 'MATHS']), $this->row(['subject' => 'SCIENCE'])];

        $this->commit($rows);
        $this->assertSame(2, $this->records()->count());

        $this->commit($rows, 'job-2');
        $this->assertSame(2, $this->records()->count(), 're-importing must not duplicate');
    }

    /**
     * A re-import carrying a CORRECTED mark keeps the first value.
     *
     * `(tenant_id, dataset, natural_key)` is UNIQUE — confirmed on both the
     * fixture and the live table — and the dataset writer uses insertOrIgnore.
     * So one result identity holds exactly one row for ever, which is the right
     * guarantee for a transcript: two conflicting marks for the same student,
     * year, subject and exam would make every average ambiguous.
     *
     * The consequence is worth asserting rather than discovering later: a
     * corrected mark in a re-imported file is NOT applied. It is counted as a
     * duplicate, not silently dropped — the import job reports it — but the
     * stored value stays as first imported. Applying corrections would need
     * upsert semantics and is a deliberate design decision, not something to
     * fall into by accident.
     */
    public function test_a_corrected_mark_on_re_import_keeps_the_first_value_and_is_reported(): void
    {
        $this->commit([$this->row(['obtain' => '90'])]);
        $this->commit([$this->row(['obtain' => '95'])], 'job-2');

        $this->assertSame(1, $this->records()->count(), 'one identity, one row');
        $this->assertEqualsWithDelta(90.0, (float) $this->records()->first()->metric_value, 0.0001);

        // Reported, not hidden.
        $this->assertSame(
            1,
            (int) DB::table('hpbrain_import_jobs')->where('id', 'job-2')->value('duplicate_count'),
        );
    }

    /* ─────────────────────────── isolation ─────────────────────────── */

    public function test_results_are_written_only_to_the_committing_tenant(): void
    {
        $this->commit([$this->row(), $this->row(['subject' => 'MATHS'])]);

        $this->assertSame(2, $this->records()->count());
        $this->assertSame(
            0,
            DB::table('hpbrain_operational_records')->where('tenant_id', '!=', self::TENANT)->count(),
            'no record may land outside the committing tenant',
        );
    }

    public function test_the_file_column_naming_a_foreign_tenant_is_never_used_as_the_tenant(): void
    {
        // Every row carries sub_institute_id = 61. The tenant comes from the
        // batch, never from the file.
        $this->commit([$this->row(['sub_institute_id' => '61'])]);

        $this->assertSame(self::TENANT, $this->records()->first()->tenant_id);
        $this->assertSame(0, DB::table('hpbrain_operational_records')->where('tenant_id', '61')->count());
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    /**
     * The identity is FIVE columns, and `standard` is in it for a measured
     * reason rather than a theoretical one.
     *
     * Counted over the real 388,401-row export:
     *
     *   enrollment+year+subject+exam            384,972 distinct — 2,819 CONFLICTS
     *   enrollment+year+standard+subject+exam   388,401 distinct —     0 conflicts
     *
     * A student appears in one academic year under more than one standard, and
     * the same subject and exam label is then reused for a different paper —
     * 10818/2018/ENVIRONMENTAL STUDIES/Written exists once out of 120 marks and
     * once out of 180. Without `standard` those are one identity, and because
     * (tenant_id, dataset, natural_key) is UNIQUE and the writer uses
     * insertOrIgnore, 2,819 real marks would have been dropped and counted as
     * successful deduplication.
     *
     * `total` is deliberately NOT in the key even though it would also
     * disambiguate. Putting a measured value in an identity means a corrected
     * mark becomes a new row instead of a recognised duplicate, and the
     * transcript then holds both the wrong and the right figure with nothing to
     * choose between them.
     */
    private function resultMap(): FieldMap
    {
        return FieldMap::fromConfig([
            'external_ref' => ['enrollment_no', 'syear', 'standard', 'subject', 'exam'],
            'subject_ref'  => 'enrollment_no',
            'measure'      => 'obtain',
            'quantity'     => 'total',
            'category'     => 'subject',
            'sub_category' => 'exam',
            'state'        => 'standard',
            'title'        => 'student_name',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'sub_institute_id' => '61',
            'syear'            => '2018',
            'enrollment_no'    => '10818',
            'student_name'     => 'AYUSH KUMAR MANDAL',
            'standard'         => 'CBSE-1',
            'subject'          => 'ENVIRONMENTAL STUDIES',
            'exam'             => 'Written',
            'total'            => '120',
            'obtain'           => '112.00',
        ], $overrides);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function commit(array $rows, string $jobId = 'job-1'): void
    {
        DB::table('hpbrain_data_sources')->updateOrInsert(
            ['tenant_id' => self::TENANT, 'source_key' => 'lions-result-data'],
            [
                'id' => 'src-result', 'display_name' => 'results', 'source_type' => 'dataset',
                'config' => json_encode(['dataset' => 'lions-result-data']),
                'field_map' => json_encode($this->resultMap()->toArray()),
                'is_active' => 1, 'created_by' => 'test',
                'created_date' => now(), 'updated_date' => now(),
            ],
        );

        DB::table('hpbrain_import_jobs')->updateOrInsert(
            ['id' => $jobId],
            [
                'tenant_id' => self::TENANT, 'import_type' => 'csv_upload', 'status' => 'queued',
                'total_rows' => count($rows), 'processed_rows' => 0, 'success_count' => 0,
                'error_count' => 0, 'duplicate_count' => 0, 'started_by' => 'test',
                'source_id' => 'lions-result-data', 'created_date' => now(), 'updated_date' => now(),
            ],
        );

        $batch = new IngestionBatch(
            tenantId: self::TENANT,
            sourceKey: 'lions-result-data',
            sourceType: 'csv_upload',
            syncType: 'one_time_historical_import',
            rows: $rows,
            fetchedAt: new \DateTimeImmutable('2026-08-17T00:00:00Z'),
            sourceRef: 'results.csv',
        );

        app(IngestionService::class)->commit($jobId, $batch, $this->resultMap()->toArray(), 'test-actor');
    }

    private function records(): \Illuminate\Database\Query\Builder
    {
        return DB::table('hpbrain_operational_records')
            ->where('tenant_id', self::TENANT)
            ->where('dataset', 'lions-result-data');
    }
}
