<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Repositories\ImportJobRepository;
use App\Repositories\ImportLogRepository;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Preview, then commit. The step ImportEngine never had.
 *
 * WHAT WAS WRONG BEFORE. ImportEngine::processImport() looped total_rows times,
 * incremented processed_rows and success_count, and returned status
 * 'completed'. It never read the parsed rows and never inserted anything. An
 * import of 400 people reported 400 successes and wrote nothing — the worst
 * possible failure, because it is indistinguishable from success at every
 * surface that reads the job row.
 *
 * TWO PHASES, AND THE SPLIT IS DELIBERATE.
 *
 *   preview() reads the source, proposes a field map, and records a job in
 *   status 'previewed'. It writes NOTHING to the graph. What it does write is
 *   source_ref — where the batch came from — so that commit re-reads the
 *   source rather than trusting a client to hand the rows back. A client that
 *   can resubmit rows can also alter them, and altered rows committed under a
 *   provenance record naming the original source is a forged citation.
 *
 *   commit() re-reads that source, applies the stored map, and writes one
 *   Signal plus (where the row has evidence text) one Evidence row, each
 *   inside EventPublisher::publishInTransaction. Nothing here bypasses the
 *   event store: a row written without its ObservationMade event is invisible
 *   to replay, which Invariant 8 forbids.
 *
 * ONE TRANSACTION PER ROW, NOT ONE PER BATCH. It is slower, and it is correct.
 * A single transaction over 400 rows means one malformed row discards 399 good
 * ones; per-row means the bad row lands in hpbrain_import_logs with its number
 * and message while the rest commit. That per-row error log is the only thing
 * that makes a partial import diagnosable.
 *
 * ROLLBACK IS POPULATED FOR REAL. Every created id is recorded in the job's
 * rollback_data under the shape ImportEngine::rollbackImport() already expects
 * — ['created_ids' => ['signals' => [...], 'evidence' => [...]]] — so the
 * existing POST /imports/{tenant}/{id}/rollback route undoes an ingestion
 * without a second rollback path being written.
 */
final class IngestionService
{
    /** Marks a job that has been read and mapped but has written nothing. */
    public const PREVIEWED = 'previewed';

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly ImportLogRepository $logs,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * Read the source, propose a mapping, record the intent. No graph writes.
     *
     * @return array{job: array<string, mixed>, preview: array<string, mixed>}
     */
    public function preview(
        IngestionBatch $batch,
        string $actorId,
        ?array $storedMap = null,
        ?string $orgId = null,
    ): array {
        $map = $storedMap !== null
            ? FieldMap::fromConfig($storedMap)
            : FieldMap::suggestFrom($batch->headers());

        $job = $this->jobs->create($batch->tenantId, [
            'org_id'      => $orgId,
            'import_type' => $batch->sourceType,
            'entity_type' => 'signal',
            'status'      => self::PREVIEWED,
            'total_rows'  => $batch->count(),
            'started_by'  => $actorId,
        ]);

        // source_id / sync_type / source_ref are not in ImportJobRepository's
        // column map (it predates them), so they are set directly rather than
        // by widening a repository every other import path also uses.
        DB::table('hpbrain_import_jobs')
            ->where('tenant_id', $batch->tenantId)
            ->where('id', $job['id'])
            ->update([
                'source_id'  => $batch->sourceKey,
                'sync_type'  => $batch->syncType,
                'source_ref' => $batch->sourceRef,
            ]);

        return [
            'job' => $this->jobs->find($batch->tenantId, $job['id']),
            'preview' => [
                'row_count'        => $batch->count(),
                'headers'          => $batch->headers(),
                'suggested_map'    => $map->toArray(),
                'unmapped_fields'  => $map->unmapped(),
                'committable'      => $map->isCommittable(),
                'sample_rows'      => array_slice($batch->rows, 0, 3),
                'sync_type'        => $batch->syncType,
                'next_checkpoint'  => $batch->nextCheckpoint,
                'fetched_at'       => $batch->fetchedAt->format(\DateTimeInterface::ATOM),
                'status'           => 'preview_only_not_committed',
            ],
        ];
    }

    /**
     * Write the batch as real Signals and Evidence.
     *
     * @param  array<string, string>  $map  canonical field => source column
     * @return array{success: int, errors: int, skipped: int, signal_ids: array<int, string>}
     */
    public function commit(string $jobId, IngestionBatch $batch, array $map, string $actorId): array
    {
        $tenantId = $batch->tenantId;
        $fieldMap = FieldMap::fromConfig($map);

        if (! $fieldMap->isCommittable()) {
            // Refused rather than defaulted. A Signal whose title and
            // classification were invented by this class would be indexed,
            // reasoned over, and cited as though somebody had observed it.
            throw new \InvalidArgumentException(
                'Field map must bind at least "title" and "state" before commit.'
            );
        }

        $this->jobs->update($tenantId, $jobId, ['status' => 'processing']);

        $created = ['signals' => [], 'evidence' => []];
        $success = 0;
        $errors = 0;
        $skipped = 0;

        foreach ($batch->rows as $index => $row) {
            $rowNumber = $index + 1;

            try {
                $title = $fieldMap->value($row, 'title');

                if ($title === null) {
                    // A row with no title cannot be named or deduplicated.
                    // Logged as skipped, not counted as an error: an empty
                    // trailing row is a normal property of exported files.
                    $this->log($tenantId, $jobId, $rowNumber, 'skipped', null, 'Row has no mapped title.');
                    $skipped++;
                    continue;
                }

                $ids = $this->writeRow($batch, $jobId, $row, $fieldMap, $title, $rowNumber, $actorId);

                $created['signals'][] = $ids['signalId'];

                if ($ids['evidenceId'] !== null) {
                    $created['evidence'][] = $ids['evidenceId'];
                }

                $this->log($tenantId, $jobId, $rowNumber, 'created', $ids['signalId'], null);
                $success++;
            } catch (\Throwable $e) {
                // One bad row must not discard the batch. The message is kept
                // verbatim because a truncated SQLSTATE is undiagnosable.
                $this->log($tenantId, $jobId, $rowNumber, 'error', null, $e->getMessage());
                $errors++;
            }

            // Written every row rather than at the end, so a run that dies
            // halfway leaves an accurate count instead of zero.
            $this->jobs->update($tenantId, $jobId, ['processed_rows' => $rowNumber]);
        }

        $this->jobs->update($tenantId, $jobId, [
            'status'          => $errors > 0 && $success === 0 ? 'failed' : 'completed',
            'success_count'   => $success,
            'error_count'     => $errors,
            'duplicate_count' => $skipped,
            'rollback_data'   => ['created_ids' => $created],
            'completed_date'  => $this->timestamp(),
        ]);

        return [
            'success'    => $success,
            'errors'     => $errors,
            'skipped'    => $skipped,
            'signal_ids' => $created['signals'],
        ];
    }

    /**
     * One row → one Signal, and its Evidence when the row carries any.
     *
     * @param  array<string, mixed>  $row
     * @return array{signalId: string, evidenceId: ?string}
     */
    private function writeRow(
        IngestionBatch $batch,
        string $jobId,
        array $row,
        FieldMap $map,
        string $title,
        int $rowNumber,
        string $actorId,
    ): array {
        $signalId = Uuid::uuid4()->toString();
        $evidenceText = $map->value($row, 'evidence_text');
        $evidenceId = $evidenceText === null ? null : Uuid::uuid4()->toString();
        $state = $map->value($row, 'state');

        // The provenance every downstream consumer needs, and the reason this
        // is ingestion rather than an import: source, job, row, and the exact
        // fetch time, so any claim built on this signal can be traced back to
        // the file or the read that produced it.
        $provenance = [
            'sourceKey'    => $batch->sourceKey,
            'sourceType'   => $batch->sourceType,
            'syncType'     => $batch->syncType,
            'sourceRef'    => $batch->sourceRef,
            'importJobId'  => $jobId,
            'rowNumber'    => $rowNumber,
            'fetchedAt'    => $batch->fetchedAt->format('Y-m-d\TH:i:s\Z'),
        ];

        $this->events->publishInTransaction(
            LoopEvent::OBSERVATION_MADE,
            $batch->tenantId,
            'Signal',
            $actorId,
            [
                'signalId'    => $signalId,
                'source'      => $batch->sourceKey,
                'sourceType'  => $batch->sourceType,
                'importJobId' => $jobId,
                'rowNumber'   => $rowNumber,
                'evidenceIds' => $evidenceId === null ? [] : [$evidenceId],
            ],
            function () use ($batch, $signalId, $evidenceId, $evidenceText, $title, $state, $map, $row, $provenance) {
                DB::table('hpbrain_signals')->insert([
                    'id'             => $signalId,
                    'tenant_id'      => $batch->tenantId,
                    'source'         => $batch->sourceKey,
                    // The source's own word for the state, not a guess. When
                    // the row has none, UNDETERMINED — this system's stated
                    // way of not inventing a value it was never given.
                    'classification' => $state ?? 'UNDETERMINED',
                    // Null: ingestion is not a detection rule, and giving it a
                    // rule_key would let RuleEvaluator's refresh logic treat
                    // two unrelated ingested rows as the same open problem.
                    'rule_key'       => null,
                    'priority'       => 'medium',
                    'severity'       => 'low',
                    // Ingested facts are reported, not inferred. 1.0 asserts
                    // "the source said this", which is the only thing ingestion
                    // can honestly claim; whether the source is RIGHT is the
                    // Evidence Engine's question, not this one's.
                    'confidence'     => 1.0,
                    'status'         => 'new',
                    'metadata'       => json_encode([
                        'title'       => $title,
                        'owner'       => $map->value($row, 'owner'),
                        'externalRef' => $map->value($row, 'external_ref'),
                        'provenance'  => $provenance,
                    ]),
                    'created_by'     => 'ingestion',
                    'created_date'   => $this->timestamp(),
                ]);

                if ($evidenceId !== null) {
                    $this->writeEvidence(
                        $batch->tenantId,
                        $signalId,
                        $evidenceId,
                        [
                            'text'       => $evidenceText,
                            'observedAt' => $map->value($row, 'evidence_timestamp'),
                            'source'     => $batch->sourceKey,
                        ],
                        $provenance,
                    );
                }

                return ['entityId' => $signalId, 'result' => true];
            },
            // This signal STARTS a thread, so it is its own correlation root —
            // the same rule SignalController::store() follows.
            correlationId: $signalId,
        );

        return ['signalId' => $signalId, 'evidenceId' => $evidenceId];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $provenance
     */
    private function writeEvidence(
        string $tenantId,
        string $signalId,
        string $evidenceId,
        array $content,
        array $provenance,
    ): void {
        $contentJson = json_encode($content);
        $provenanceJson = json_encode($provenance + ['confidence' => 1.0]);

        DB::table('hpbrain_evidence')->insert([
            'id'            => $evidenceId,
            'tenant_id'     => $tenantId,
            'signal_id'     => $signalId,
            'source'        => $content['source'],
            'evidence_type' => 'observation',
            'content'       => $contentJson,
            'provenance'    => $provenanceJson,
            'confidence'    => 1.0,
            // Same hash construction RuleEvaluator uses, so an ingested
            // evidence row and a detected one are comparable.
            'hash'          => hash('sha256', $contentJson.'|'.$provenanceJson),
            'status'        => 'active',
            'created_by'    => 'ingestion',
            'created_date'  => $this->timestamp(),
        ]);
    }

    private function log(
        string $tenantId,
        string $jobId,
        int $rowNumber,
        string $action,
        ?string $entityId,
        ?string $error,
    ): void {
        $this->logs->create($tenantId, [
            'import_job_id' => $jobId,
            'row_number'    => $rowNumber,
            'action'        => $action,
            'entity_type'   => 'signal',
            'entity_id'     => $entityId,
            'error_message' => $error,
        ]);
    }

    /** MySQL-legal. Never date('c') — that emits RFC-3339, which MySQL rejects. */
    private function timestamp(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
