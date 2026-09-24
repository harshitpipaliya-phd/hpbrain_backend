<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Sources;

use App\Domain\Ingestion\IngestionBatch;
use App\Domain\Universal\EntityResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads a school ERP's result marks as the academic dataset shape the Brain
 * already understands.
 *
 * This is a source adapter only. It does not write operational records and it
 * does not build student projections; IngestionService::commitDataset() and
 * StudentProjectionBuilder keep owning those contracts.
 */
final class SchoolAcademicErpDataSource
{
    private const CHUNK_ROWS = 1000;

    public const SOURCE_KEY = 'erp-academic-results';

    public const DATASET = 'erp-academic-results';

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly string $tenantId,
        private readonly string $sourceKey = self::SOURCE_KEY,
        private readonly string $dataset = self::DATASET,
        private readonly ?int $limit = null,
    ) {
    }

    public function fetch(): IngestionBatch
    {
        $count = $this->countRows();
        $sample = $this->query()->limit(10)->get()->map(fn ($row): array => $this->row((array) $row))->all();

        return new IngestionBatch(
            tenantId: $this->tenantId,
            sourceKey: $this->sourceKey,
            sourceType: 'internal_erp',
            syncType: 'one_time_historical_import',
            rows: [],
            fetchedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            nextCheckpoint: null,
            sourceRef: 'SchoolAcademicErp@'.$this->dataset,
            rowStream: fn (): iterable => $this->rows(),
            streamCount: $count,
            streamHeaders: array_keys($sample[0] ?? $this->emptyRow()),
            streamSample: $sample,
        );
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function rows(): \Generator
    {
        $lastId = 0;
        $emitted = 0;

        while (true) {
            $remaining = $this->limit === null ? self::CHUNK_ROWS : min(self::CHUNK_ROWS, $this->limit - $emitted);

            if ($remaining <= 0) {
                return;
            }

            $rows = $this->query()
                ->where('rm.id', '>', $lastId)
                ->orderBy('rm.id')
                ->limit($remaining)
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $record) {
                $row = (array) $record;
                $lastId = max($lastId, (int) $row['result_mark_id']);
                $emitted++;

                yield $this->row($row);
            }
        }
    }

    private function countRows(): int
    {
        $query = $this->query();

        if ($this->limit !== null) {
            return min((int) $query->count(), $this->limit);
        }

        return (int) $query->count();
    }

    private function query(): Builder
    {
        $prefix = $this->sourcePrefix();
        $studentName = DB::connection()->getDriverName() === 'sqlite'
            ? "TRIM(COALESCE(student.first_name, '') || ' ' || COALESCE(student.middle_name, '') || ' ' || COALESCE(student.last_name, ''))"
            : "TRIM(CONCAT_WS(' ', NULLIF(student.first_name, ''), NULLIF(student.middle_name, ''), NULLIF(student.last_name, '')))";
        $year = DB::connection()->getDriverName() === 'sqlite'
            ? "COALESCE(exam.syear, strftime('%Y', rm.created_at), strftime('%Y', rm.updated_at))"
            : 'COALESCE(exam.syear, YEAR(rm.created_at), YEAR(rm.updated_at))';

        return DB::table($prefix.'result_marks as rm')
            ->join($prefix.'tblstudent as student', function ($join): void {
                $join->on('student.id', '=', 'rm.student_id')
                    ->on('student.sub_institute_id', '=', 'rm.sub_institute_id');
            })
            ->leftJoin($prefix.'result_create_exam as exam', function ($join): void {
                $join->on('exam.id', '=', 'rm.exam_id')
                    ->on('exam.sub_institute_id', '=', 'rm.sub_institute_id');
            })
            ->leftJoin($prefix.'standard as standard', function ($join): void {
                $join->on('standard.id', '=', 'exam.standard_id')
                    ->on('standard.sub_institute_id', '=', 'rm.sub_institute_id');
            })
            ->leftJoin($prefix.'subject as subject', function ($join): void {
                $join->on('subject.id', '=', 'exam.subject_id')
                    ->on('subject.sub_institute_id', '=', 'rm.sub_institute_id');
            })
            ->where('rm.sub_institute_id', $this->tenantId)
            ->where(function ($query): void {
                $query->whereNull('student.status')->orWhere('student.status', 1);
            })
            ->selectRaw(implode(', ', [
                'rm.id as result_mark_id',
                'rm.student_id',
                'student.enrollment_no',
                "{$studentName} as student_name",
                'COALESCE(rm.standard_name, standard.name) as standard',
                'COALESCE(rm.subject_name, subject.subject_name) as subject',
                'COALESCE(rm.exam_title, exam.title) as exam',
                'rm.points as obtain',
                'COALESCE(exam.points, exam.con_point) as total',
                "{$year} as syear",
                'rm.grade',
                'rm.per',
                'rm.comment',
                'rm.is_absent',
                'rm.created_at',
                'rm.updated_at',
            ]));
    }

    private function sourcePrefix(): string
    {
        $organization = $this->resolver->resolve($this->tenantId, 'Organization');
        $table = $organization->table;
        $dot = strrpos($table, '.');

        return $dot === false ? '' : substr($table, 0, $dot + 1);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function row(array $record): array
    {
        $studentRef = trim((string) ($record['enrollment_no'] ?? ''));

        if ($studentRef === '') {
            $studentRef = (string) ($record['student_id'] ?? '');
        }

        $standard = trim((string) ($record['standard'] ?? ''));
        $subject = trim((string) ($record['subject'] ?? ''));
        $exam = trim((string) ($record['exam'] ?? ''));
        $studentName = trim(preg_replace('/\s+/', ' ', (string) ($record['student_name'] ?? '')) ?? '');

        return [
            'external_ref' => (string) ($record['result_mark_id'] ?? ''),
            'subject_ref' => $studentRef,
            'measure' => (string) ($record['obtain'] ?? ''),
            'quantity' => (string) ($record['total'] ?? ''),
            'category' => $subject,
            'sub_category' => $exam,
            'state' => $standard,
            'evidence_timestamp' => (string) ($record['syear'] ?? ''),
            'title' => $studentName !== '' ? $studentName : $studentRef,
            'student_name' => $studentName !== '' ? $studentName : $studentRef,
            'measure_unit' => 'marks',
            'student_id' => (string) ($record['student_id'] ?? ''),
            'enrollment_no' => $studentRef,
            'standard' => $standard,
            'subject' => $subject,
            'exam' => $exam,
            'grade' => (string) ($record['grade'] ?? ''),
            'percentage' => (string) ($record['per'] ?? ''),
            'comment' => (string) ($record['comment'] ?? ''),
            'is_absent' => (string) ($record['is_absent'] ?? ''),
            'source_dataset' => $this->dataset,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function emptyRow(): array
    {
        return [
            'external_ref' => '',
            'subject_ref' => '',
            'measure' => '',
            'quantity' => '',
            'category' => '',
            'sub_category' => '',
            'state' => '',
            'evidence_timestamp' => '',
            'title' => '',
            'student_name' => '',
            'measure_unit' => 'marks',
        ];
    }
}
