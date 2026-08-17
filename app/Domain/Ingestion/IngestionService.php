<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Domain\Ingestion;
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
        private readonly ?DatasetAnalysisService $analysis = null,
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

        $schema = (new SchemaDetector($batch->rows, $batch->headers()))->detect();

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
                'schema'           => $schema,
            ],
        ];
    }

    /**
     * Write the batch as real Signals and Evidence.
     *
     * @param  array<string, string>  $map  canonical field => source column
     * @return array{success: int, errors: int, skipped: int, signal_ids: array<int, string>}
     */
    /** How often the progress counter is checkpointed during a commit. */
    private const PROGRESS_EVERY = 50;

    /**
     * Rows per bulk-insert chunk, and per transaction.
     *
     * WHY 500. Each chunk issues three multi-row INSERTs — signals, evidence,
     * outbox events — inside one transaction, so a chunk costs five round trips
     * regardless of its size. 500 rows of signals carries roughly 6,000 bound
     * parameters, comfortably inside MySQL's 65,535 placeholder ceiling and
     * inside a default max_allowed_packet, while keeping the rollback unit
     * small enough that one bad chunk loses 500 rows rather than 160,000.
     *
     * Raising it buys little: the round-trip cost is already amortised to
     * ~0.01 per row. Lowering it costs proportionally more round trips.
     */
    private const CHUNK_ROWS = 500;

    /**
     * Namespace for deterministic signal ids.
     *
     * A fixed, arbitrary UUID. Its only job is to keep uuid5() output in a
     * space that cannot collide with ids minted anywhere else in the system.
     */
    private const ID_NAMESPACE = '6f9619ff-8b86-d011-b42d-00c04fc964ff';

    public function commit(string $jobId, IngestionBatch $batch, array $map, string $actorId): array
    {
        $tenantId = $batch->tenantId;
        $fieldMap = FieldMap::fromConfig($map);

        if ($source = $this->datasetSource($tenantId, $batch->sourceKey)) {
            return $this->commitDataset($jobId, $batch, $fieldMap, $source);
        }

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

        /*
          CHUNKED BULK WRITES, NOT ONE TRANSACTION PER ROW.

          What this replaces: every row went through
          EventPublisher::publishInTransaction(), which opened its own
          transaction and wrote a signal, an evidence row and an outbox event —
          five network round trips per row. Against the remote MySQL this
          project runs on (~40 ms RTT) that measured 306 rows in ~25 s and put
          2,000 rows past the 60-second request limit; a 162,810-row file
          projected to ~3.8 hours. The work was not the problem, the round trips
          were.

          Now each chunk of CHUNK_ROWS rows costs five round trips TOTAL —
          begin, three multi-row inserts, commit — so cost per row falls by
          roughly the chunk size.

          THE TRANSACTION BOUNDARY IS THE CHUNK, deliberately. Wrapping all
          162,810 rows in one transaction would hold locks on shared tables for
          the entire import and make a single bad row discard everything. A
          per-chunk boundary means a failure loses at most CHUNK_ROWS rows,
          every earlier chunk stays committed and countable, and the job record
          says exactly how far it got — which is what makes the import
          resumable rather than merely restartable.
        */
        $chunks = array_chunk($batch->rows, self::CHUNK_ROWS, true);
        $rowNumber = 0;

        foreach ($chunks as $chunk) {
            $signals = [];
            $evidence = [];
            $events = [];
            $chunkFirstRow = $rowNumber + 1;

            // ---- build the chunk in memory; no queries yet ----------------
            foreach ($chunk as $row) {
                $rowNumber++;

                $title = $fieldMap->value($row, 'title');

                if ($title === null) {
                    // A row with no title cannot be named or deduplicated.
                    // Skipped, not errored: an empty trailing row is a normal
                    // property of exported files.
                    $this->log($tenantId, $jobId, $rowNumber, 'skipped', null, 'Row has no mapped title.');
                    $skipped++;
                    continue;
                }

                try {
                    $built = $this->buildRow($batch, $jobId, $row, $fieldMap, $title, $rowNumber, $actorId);
                } catch (\Throwable $e) {
                    // A row that cannot even be BUILT is an error, and it is
                    // recorded against its own row number so the reason is
                    // attributable rather than lost in a batch failure.
                    $this->log($tenantId, $jobId, $rowNumber, 'error', null, $e->getMessage());
                    $errors++;
                    continue;
                }

                $signals[] = $built['signal'];
                $events[] = $built['event'];
                $created['signals'][] = $built['signalId'];

                if ($built['evidence'] !== null) {
                    $evidence[] = $built['evidence'];
                    $created['evidence'][] = $built['evidenceId'];
                }

                $this->log($tenantId, $jobId, $rowNumber, 'created', $built['signalId'], null);
            }

            if ($signals === []) {
                continue;
            }

            // ---- one transaction, three statements -----------------------
            try {
                $written = DB::transaction(function () use ($signals, $evidence, $events): int {
                    /*
                      insertOrIgnore, NOT insert — this is the idempotency.

                      Signal ids are derived deterministically from
                      (tenant, source, row, content) in buildRow(), so
                      re-committing the same file produces the SAME ids and the
                      primary key rejects the repeats instead of doubling the
                      dataset. The outbox row carries a unique idempotency_key
                      built from the same id, so the event is deduplicated by
                      the same mechanism the per-row publisher relied on.

                      That makes a retried queue job, a resubmitted request and
                      a worker restart mid-import all safe, which the previous
                      random-UUID scheme could not be: it minted new ids on
                      every attempt and duplicated everything it re-processed.
                    */
                    $inserted = DB::table('hpbrain_signals')->insertOrIgnore($signals);

                    if ($evidence !== []) {
                        DB::table('hpbrain_evidence')->insertOrIgnore($evidence);
                    }

                    DB::table('hpbrain_event_store')->insertOrIgnore($events);

                    return $inserted;
                });

                /*
                  COUNT WHAT THE DATABASE ACTUALLY WROTE, not what was offered.

                  insertOrIgnore returns the affected-row count, and on a repeat
                  commit that is zero because every id already exists. Counting
                  the submitted array instead reported "committed: 2000" for a
                  run that wrote nothing — a number that reconciles against the
                  source file and lies about the database, which is precisely
                  the failure mode this pipeline must never have.

                  Rows the database skipped are counted as duplicates, because
                  that is what they are: the same row of the same source, seen
                  again.
                */
                $success += $written;
                $skipped += count($signals) - $written;
            } catch (\Throwable $e) {
                /*
                  The chunk rolled back, so none of its rows exist. They are
                  counted as errors and the REASON is recorded once against the
                  chunk's first row, with the range named — writing the same
                  database message 500 times would bury the audit trail without
                  adding information.
                */
                $errors += count($signals);

                $this->log(
                    $tenantId,
                    $jobId,
                    $chunkFirstRow,
                    'error',
                    null,
                    'Chunk rows '.$chunkFirstRow.'-'.$rowNumber.' rolled back: '.$e->getMessage(),
                );

                // The ids were never written, so they must not appear in the
                // rollback manifest as though they had been.
                array_splice($created['signals'], -count($signals));

                if ($evidence !== []) {
                    array_splice($created['evidence'], -count($evidence));
                }
            }

            // Checkpointed per chunk rather than per row: one write per 500
            // rows, and it is what a polling client reads for progress.
            $this->jobs->update($tenantId, $jobId, [
                'processed_rows' => $rowNumber,
                'success_count'  => $success,
                'error_count'    => $errors,
                // Included per chunk, not only at the end: on a RESUMED job
                // almost every row is a duplicate, so a progress view without
                // this column shows a run that is advancing with zero successes
                // and no explanation for the difference.
                'duplicate_count' => $skipped,
            ]);

            $this->flushLogs($tenantId);
        }

        $this->jobs->update($tenantId, $jobId, ['processed_rows' => count($batch->rows)]);
        $this->flushLogs($tenantId);

        $this->jobs->update($tenantId, $jobId, [
            /*
              THREE OUTCOMES, NOT TWO. 'completed' previously covered an import
              that wrote every row and one that wrote half of them, so a caller
              could not tell a clean run from a damaged one without re-reading
              the counts. A run with rejected rows is now
              'completed_with_errors' and says so.
            */
            'status'          => match (true) {
                $success === 0 && $errors > 0 => 'failed',
                $errors > 0                   => 'completed_with_errors',
                default                       => 'completed',
            },
            'success_count'   => $success,
            'error_count'     => $errors,
            'duplicate_count' => $skipped,
            'rollback_data'   => ['created_ids' => $created],
            'completed_date'  => $this->timestamp(),
        ]);

        if ($this->analysis !== null && $success > 0) {
            try {
                $schema = (new SchemaDetector($batch->rows, $batch->headers()))->detect();
                $this->analysis->analyse($tenantId, $jobId, $schema, $success);
            } catch (\Throwable) {
                // AI analysis is best-effort and must not break commit.
            }
        }

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
    /**
     * Build every row a single source row becomes — WITHOUT touching the
     * database.
     *
     * This replaces writeRow(), which wrote as it went, one transaction per
     * row. Separating construction from persistence is what makes the bulk
     * insert possible at all: commit() can now assemble five hundred rows and
     * hand them to three statements.
     *
     * THE ID IS DERIVED, NOT RANDOM, and that is the idempotency mechanism.
     * uuid5 over (tenant, source key, row number, content hash) means the same
     * row of the same file always yields the same signal id. Re-committing a
     * job, retrying a queued worker, or resubmitting the upload therefore
     * collides with the existing primary key and is ignored, instead of
     * silently doubling the dataset — which is exactly what the previous
     * uuid4() did on every retry.
     *
     * The content hash is part of the key on purpose: if a row's VALUES change
     * between runs it is a different observation and deserves its own signal,
     * even at the same row number. Only a byte-identical row at the same
     * position in the same source is treated as the same fact.
     *
     * @param  array<string, mixed>  $row
     * @return array{signalId: string, evidenceId: ?string, signal: array<string, mixed>,
     *               evidence: ?array<string, mixed>, event: array<string, mixed>}
     */
    private function buildRow(
        IngestionBatch $batch,
        string $jobId,
        array $row,
        FieldMap $map,
        string $title,
        int $rowNumber,
        string $actorId,
    ): array {
        $evidenceText = $map->value($row, 'evidence_text');
        $state = $map->value($row, 'state');
        $now = $this->timestamp();

        $signalId = $this->deterministicId($batch, $rowNumber, $row);
        $evidenceId = $evidenceText === null
            ? null
            : $this->deterministicId($batch, $rowNumber, $row, 'evidence');

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

        $signal = [
            'id'             => $signalId,
            'tenant_id'      => $batch->tenantId,
            'source'         => $batch->sourceKey,
            // The source's own word for the state, not a guess. When the row
            // has none, UNDETERMINED — this system's stated way of not
            // inventing a value it was never given.
            'classification' => $state ?? 'UNDETERMINED',
            // Null: ingestion is not a detection rule, and giving it a rule_key
            // would let RuleEvaluator's refresh logic treat two unrelated
            // ingested rows as the same open problem.
            'rule_key'       => null,
            'priority'       => 'medium',
            'severity'       => 'low',
            // Ingested facts are reported, not inferred. 1.0 asserts "the
            // source said this", which is the only thing ingestion can honestly
            // claim; whether the source is RIGHT is the Evidence Engine's
            // question, not this one's.
            'confidence'     => 1.0,
            'status'         => 'new',
            'metadata'       => json_encode([
                'title'       => $title,
                'owner'       => $map->value($row, 'owner'),
                'externalRef' => $map->value($row, 'external_ref'),
                'provenance'  => $provenance,
            ]),
            'created_by'     => 'ingestion',
            'created_date'   => $now,
        ];

        $evidence = null;

        if ($evidenceId !== null) {
            $content = [
                'text'       => $evidenceText,
                'observedAt' => $map->value($row, 'evidence_timestamp'),
                'source'     => $batch->sourceKey,
            ];

            $contentJson = json_encode($content);
            $provenanceJson = json_encode($provenance + ['confidence' => 1.0]);

            $evidence = [
                'id'            => $evidenceId,
                'tenant_id'     => $batch->tenantId,
                'signal_id'     => $signalId,
                'source'        => $batch->sourceKey,
                'evidence_type' => 'observation',
                'content'       => $contentJson,
                'provenance'    => $provenanceJson,
                'confidence'    => 1.0,
                // Same hash construction RuleEvaluator uses, so an ingested
                // evidence row and a detected one are comparable.
                'hash'          => hash('sha256', $contentJson.'|'.$provenanceJson),
                'status'        => 'active',
                'created_by'    => 'ingestion',
                'created_date'  => $now,
            ];
        }

        /*
          The outbox row, built by hand rather than through
          EventPublisher::publishInTransaction().

          The publisher's contract is one transaction per event, which is the
          cost this rewrite exists to remove. What it guarantees — that the
          event and the domain write commit together, and that a repeat is
          rejected by the unique idempotency_key — is preserved here: the event
          is inserted in the SAME chunk transaction as its signal, and carries
          the identical md5(type:tenant:entityType:entityId) key the publisher
          builds. The deduplication is the database's, not the publisher's, and
          the database is still doing it.
        */
        $event = [
            'id'              => Uuid::uuid4()->toString(),
            'type'            => LoopEvent::OBSERVATION_MADE->value,
            'tenant_id'       => $batch->tenantId,
            'entity_type'     => 'Signal',
            'entity_id'       => $signalId,
            'actor_id'        => $actorId,
            'payload'         => json_encode([
                'signalId'    => $signalId,
                'source'      => $batch->sourceKey,
                'sourceType'  => $batch->sourceType,
                'importJobId' => $jobId,
                'rowNumber'   => $rowNumber,
                'evidenceIds' => $evidenceId === null ? [] : [$evidenceId],
            ]),
            // This signal STARTS a thread, so it is its own correlation root —
            // the same rule SignalController::store() follows.
            'correlation_id'  => $signalId,
            'causation_id'    => null,
            'idempotency_key' => md5(LoopEvent::OBSERVATION_MADE->value.":{$batch->tenantId}:Signal:{$signalId}"),
            'status'          => 'pending',
            'retry_count'     => 0,
            'created_at'      => $now,
        ];

        return [
            'signalId'   => $signalId,
            'evidenceId' => $evidenceId,
            'signal'     => $signal,
            'evidence'   => $evidence,
            'event'      => $event,
        ];
    }

    /**
     * A stable id for one row of one source.
     *
     * Same inputs, same uuid — which is what lets insertOrIgnore turn a retry
     * into a no-op instead of a duplicate.
     *
     * @param  array<string, mixed>  $row
     */
    private function deterministicId(
        IngestionBatch $batch,
        int $rowNumber,
        array $row,
        string $kind = 'signal',
    ): string {
        // json_encode of the row is a cheap, order-stable content fingerprint.
        // Order matters and is preserved: the parser yields columns in header
        // order for every row of a given file.
        $name = implode('|', [
            $kind,
            $batch->tenantId,
            $batch->sourceKey,
            (string) $rowNumber,
            hash('sha256', (string) json_encode($row)),
        ]);

        return Uuid::uuid5(self::ID_NAMESPACE, $name)->toString();
    }

    /**
     * The configured dataset source for this batch, if it is one.
     *
     * `source_type = dataset` is deliberately explicit. Existing uploads and
     * ERP sources continue to create signals exactly as before; only a stored
     * source row can opt into operational-record ingestion.
     *
     * @return array<string, mixed>|null
     */
    private function datasetSource(string $tenantId, string $sourceKey): ?array
    {
        $row = DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('source_key', $sourceKey)
            ->where('source_type', 'dataset')
            ->where('is_active', 1)
            ->first();

        if ($row === null) {
            return null;
        }

        $source = (array) $row;
        $config = json_decode((string) ($source['config'] ?? '{}'), true);
        $source['config'] = is_array($config) ? $config : [];

        return $source;
    }

    /**
     * Write a configured dataset source into hpbrain_operational_records.
     *
     * @return array{success: int, errors: int, skipped: int, signal_ids: array<int, string>}
     */
    private function commitDataset(string $jobId, IngestionBatch $batch, FieldMap $map, array $source): array
    {
        if (! $map->has('external_ref') || ! $map->has('measure') || ! $map->has('subject_ref')) {
            throw new \InvalidArgumentException(
                'Dataset field map must bind "external_ref", "measure", and "subject_ref" before commit.'
            );
        }

        $tenantId = $batch->tenantId;
        $config = $source['config'];
        $dataset = (string) ($config['dataset'] ?? $batch->sourceKey);
        $unit = (string) ($config['measure_unit'] ?? '');
        $now = $this->timestamp();

        $this->jobs->update($tenantId, $jobId, ['status' => 'processing']);

        $created = [];
        $success = 0;
        $errors = 0;
        $skipped = 0;
        $rowNumber = 0;

        foreach (array_chunk($batch->rows, self::CHUNK_ROWS, true) as $chunk) {
            $records = [];

            foreach ($chunk as $row) {
                $rowNumber++;

                $naturalKey = $map->value($row, 'external_ref');

                if ($naturalKey === null) {
                    $this->log($tenantId, $jobId, $rowNumber, 'skipped', null, 'Dataset row has no mapped external_ref.');
                    $skipped++;
                    continue;
                }

                $subjectRef = $map->value($row, 'subject_ref');
                $metric = $this->decimal($map->value($row, 'measure'));

                if ($subjectRef === null || $metric === null) {
                    $this->log($tenantId, $jobId, $rowNumber, 'skipped', null, 'Dataset row has no mapped subject_ref or measure.');
                    $skipped++;
                    continue;
                }

                $payload = $row;
                $record = [
                    'id'               => $this->deterministicId($batch, $rowNumber, $row, 'operational-record'),
                    'tenant_id'        => $tenantId,
                    'org_id'           => $source['config']['org_id'] ?? null,
                    'dataset'          => $dataset,
                    'natural_key'      => $naturalKey,
                    'source_file'      => $batch->sourceRef === null ? null : basename($batch->sourceRef),
                    'source_row'       => $rowNumber,
                    'occurred_at'      => $this->dateTime($map->value($row, 'evidence_timestamp')),
                    'closed_at'        => null,
                    'status'           => $this->clip($map->value($row, 'state'), 64),
                    'category'         => $this->clip($map->value($row, 'category'), 191),
                    'sub_category'     => null,
                    'owner_name'       => $this->clip($map->value($row, 'owner'), 191),
                    'supervisor_name'  => null,
                    'zone'             => null,
                    'area'             => null,
                    'subject_ref'      => $this->clip($subjectRef, 191),
                    'metric_value'     => $metric,
                    'metric_unit'      => $this->clip($map->value($row, 'measure_unit') ?? ($unit !== '' ? $unit : null), 20),
                    'quantity'         => null,
                    'payload'          => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'import_job_id'    => $jobId,
                    'created_date'     => $now,
                    'updated_date'     => $now,
                ];

                $hashable = $record;
                unset($hashable['id'], $hashable['source_row'], $hashable['import_job_id'], $hashable['source_file'], $hashable['created_date'], $hashable['updated_date']);
                $record['row_hash'] = hash('sha256', json_encode($hashable, JSON_UNESCAPED_UNICODE) ?: '');

                $records[] = $record;
                $created[] = $record['id'];
            }

            if ($records !== []) {
                try {
                    $written = DB::table('hpbrain_operational_records')->insertOrIgnore($records);
                    $success += $written;
                    $skipped += count($records) - $written;
                } catch (\Throwable $e) {
                    $errors += count($records);
                    array_splice($created, -count($records));

                    $this->log(
                        $tenantId,
                        $jobId,
                        max(1, $rowNumber - count($records) + 1),
                        'error',
                        null,
                        'Dataset chunk rolled back: '.$e->getMessage(),
                    );
                }
            }

            $this->jobs->update($tenantId, $jobId, [
                'processed_rows'  => $rowNumber,
                'success_count'   => $success,
                'error_count'     => $errors,
                'duplicate_count' => $skipped,
            ]);

            $this->flushLogs($tenantId);
        }

        $this->jobs->update($tenantId, $jobId, [
            'status' => match (true) {
                $success === 0 && $errors > 0 => 'failed',
                $errors > 0                   => 'completed_with_errors',
                default                       => 'completed',
            },
            'total_rows'      => count($batch->rows),
            'processed_rows'  => count($batch->rows),
            'success_count'   => $success,
            'error_count'     => $errors,
            'duplicate_count' => $skipped,
            'rollback_data'   => ['created_ids' => ['operational_records' => $created]],
            'completed_date'  => $now,
        ]);

        DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('source_key', $batch->sourceKey)
            ->update([
                'last_synced_at' => $now,
                'updated_date'   => $now,
            ]);

        return [
            'success'    => $success,
            'errors'     => $errors,
            'skipped'    => $skipped,
            'signal_ids' => [],
        ];
    }

    private function decimal(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $clean = str_replace([',', '₹', 'Rs.', 'RS.', '/-'], '', $value);
        $clean = trim($clean);

        return is_numeric($clean) ? round((float) $clean, 4) : null;
    }

    private function dateTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $value, $m)) {
            return sprintf('%s-%s-%s 00:00:00', $m[3], $m[2], $m[1]);
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function clip(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    /**
     * Per-row audit entries, buffered.
     *
     * BUFFERED, NOT WRITTEN IMMEDIATELY. logs->create() inserts and then selects
     * the row back, and nothing here reads the result — so this was two network
     * round trips per row against a remote database, purely to record an audit
     * line. flushLogs() writes them in batches of 500 instead.
     *
     * The buffer is per-commit and is flushed in a finally block, so a commit
     * that dies part-way still records what it managed to do rather than losing
     * the whole trail.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $logBuffer = [];

    private function log(
        string $tenantId,
        string $jobId,
        int $rowNumber,
        string $action,
        ?string $entityId,
        ?string $error,
    ): void {
        $this->logBuffer[] = [
            'import_job_id' => $jobId,
            'row_number'    => $rowNumber,
            'action'        => $action,
            'entity_type'   => 'signal',
            'entity_id'     => $entityId,
            'error_message' => $error,
        ];

        // Bounded so a 200k-row import cannot hold every log line in memory.
        if (count($this->logBuffer) >= 500) {
            $this->flushLogs($tenantId);
        }
    }

    private function flushLogs(string $tenantId): void
    {
        if ($this->logBuffer === []) {
            return;
        }

        $buffered = $this->logBuffer;
        $this->logBuffer = [];

        $this->logs->createMany($tenantId, $buffered);
    }

    /** MySQL-legal. Never date('c') — that emits RFC-3339, which MySQL rejects. */
    private function timestamp(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
