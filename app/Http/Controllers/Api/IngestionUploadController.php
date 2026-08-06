<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\Sources\CsvUploadSource;
use App\Http\Controllers\Controller;
use App\Repositories\ImportJobRepository;
use App\Repositories\SignalRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * CSV ingestion: list sources, upload a preview, commit the mapping.
 *
 * TWO CALLS, NEVER ONE. upload() writes nothing to the graph; it parses, infers
 * a mapping, persists a job, and returns a preview. commit() re-reads the file
 * server-side from the job and writes Signals. The rows are deliberately NOT
 * posted back — rows a client could resubmit are rows a client could alter, and
 * altered rows stored under a provenance record naming the original file is a
 * forged citation. web/src/api/ingestion.ts states the same contract.
 *
 * WHY THE JOB LIVES IN hpbrain_import_jobs RATHER THAN ingestion_runs.
 * `ingestion_runs` is unprefixed in a database shared with the institute ERP
 * and carries no tenant_id — both release-blockers recorded in
 * docs/API-FUNCTIONAL-AUDIT.md. hpbrain_import_jobs is prefixed, tenant-scoped
 * through BaseRepository, and already has the counters this flow reports. It
 * also means rollback works for free: ImportEngine::rollbackImport() reads
 * rollback_data.created_ids, which commit() populates.
 *
 * SIGNALS ARE WRITTEN THROUGH THE EVENT PUBLISHER, exactly as
 * SignalController::store does. A raw insert would produce rows the loop never
 * sees — no ObservationMade event, so no evidence, no reasoning, no
 * recommendation. An ingested signal that cannot enter the loop is a row in a
 * table pretending to be intelligence.
 */
final class IngestionUploadController extends Controller
{
    /** Mirrors the client's cap so the response cannot grow without bound. */
    private const SIGNAL_ID_SAMPLE = 20;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly SignalRepository $signals,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * GET /v1/ingestion/sources/{tenantId}
     *
     * Answers with source_key/display_name/source_type, which is the shape
     * DataSourceRow declares client-side. An empty list is a legitimate answer
     * for a tenant that has configured none — and is now distinguishable from a
     * failure, because a missing route no longer 404s here.
     */
    public function sources(Request $request, string $tenantId): JsonResponse
    {
        $rows = DB::table('hpbrain_data_sources')
            ->where('tenant_id', $this->tenantId($request))
            ->where('is_active', 1)
            ->orderBy('display_name')
            ->get(['source_key', 'display_name', 'source_type']);

        return response()->json($rows->map(static fn ($r) => [
            'source_key'   => (string) $r->source_key,
            'display_name' => (string) $r->display_name,
            'source_type'  => (string) $r->source_type,
        ])->all());
    }

    /**
     * POST /v1/ingestion/upload  (multipart)
     *
     * Returns {job_id, preview}. The preview shape is the client's
     * IngestionPreview interface verbatim; before this, the method returned six
     * different keys and none of the ones the screen reads, so
     * `data.preview.suggested_map` threw on every upload even when the route
     * was reachable.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file'      => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
            'source_id' => ['required', 'string', 'max:190'],
            'org_id'    => ['nullable', 'string', 'max:36'],
        ]);

        $tenant = $this->tenantId($request);

        // Stored under a per-tenant path. A flat directory would let one
        // tenant's job id, if guessed, name another tenant's file.
        $path = $request->file('file')->store("ingestion-uploads/{$tenant}");

        $batch = (new CsvUploadSource(
            filePath: storage_path('app/'.$path),
            sourceId: (string) $request->input('source_id'),
        ))->fetch();

        // League\Csv keys every row by the header row, so the first row's keys
        // ARE the headers. An empty file yields no rows and therefore no
        // headers, which is reported rather than treated as a mapping failure.
        $headers = $batch->rows === [] ? [] : array_map('strval', array_keys($batch->rows[0]));

        $suggested = FieldMap::suggest($headers);

        $job = $this->jobs->create($tenant, [
            'org_id'         => $request->input('org_id'),
            'import_type'    => 'csv_ingestion',
            'entity_type'    => 'signal',
            'status'         => 'previewed',
            'total_rows'     => count($batch->rows),
            'processed_rows' => 0,
            'success_count'  => 0,
            'error_count'    => 0,
            'error_report'   => [],
            // Everything commit() needs to re-read the file without trusting
            // the client for any of it.
            'rollback_data'  => [
                'source_id'   => $batch->sourceId,
                'file_path'   => $path,
                'headers'     => $headers,
                'created_ids' => [],
            ],
            'started_by'     => $this->actorId($request),
        ]);

        return response()->json([
            'job_id'  => $job['id'],
            'preview' => [
                'row_count'       => count($batch->rows),
                'headers'         => $headers,
                'suggested_map'   => (object) $suggested,
                'unmapped_fields' => FieldMap::unmapped($headers, $suggested),
                'committable'     => FieldMap::isCommittable($suggested),
                'sample_rows'     => array_slice($batch->rows, 0, 3),
                'sync_type'       => $batch->syncType,
                'fetched_at'      => $batch->fetchedAt->format(\DateTimeInterface::ATOM),
            ],
        ], 201);
    }

    /**
     * POST /v1/ingestion/{tenantId}/{jobId}/commit
     *
     * Body: {field_map: {canonical => header}, save_map: bool}
     */
    public function commit(Request $request, string $tenantId, string $jobId): JsonResponse
    {
        $data = $request->validate([
            'field_map' => ['required', 'array'],
            'save_map'  => ['nullable', 'boolean'],
        ]);

        $tenant = $this->tenantId($request);
        $job = $this->jobs->find($tenant, $jobId);

        if ($job === null) {
            return response()->json(['error' => 'import_job_not_found'], 404);
        }

        // Committing twice would double every Signal. The job's own status is
        // the guard, not a client flag.
        if (($job['status'] ?? '') === 'completed') {
            return response()->json(['error' => 'already_committed', 'job_id' => $jobId], 409);
        }

        $map = FieldMap::sanitise($data['field_map']);

        if (! FieldMap::isCommittable($map)) {
            return response()->json([
                'error'    => 'incomplete_mapping',
                'required' => FieldMap::REQUIRED,
            ], 422);
        }

        $context = $job['rollback_data'] ?? [];
        $filePath = $context['file_path'] ?? null;

        if (! is_string($filePath) || ! is_readable(storage_path('app/'.$filePath))) {
            // The upload is gone — a cleared storage directory, or a job from a
            // previous deployment. Reported as its own state rather than as an
            // empty commit, which would read as "the file had no rows".
            return response()->json(['error' => 'upload_no_longer_available', 'job_id' => $jobId], 410);
        }

        $batch = (new CsvUploadSource(
            filePath: storage_path('app/'.$filePath),
            sourceId: (string) ($context['source_id'] ?? 'csv_upload'),
        ))->fetch();

        $actor = $this->actorId($request);
        $committed = 0;
        $skipped = 0;
        $errors = [];
        $signalIds = [];

        foreach ($batch->rows as $index => $row) {
            $title = trim((string) ($row[$map['title']] ?? ''));
            $state = trim((string) ($row[$map['state']] ?? ''));

            // A row missing either required value is SKIPPED and reported, not
            // defaulted. A Signal titled '' classified '' is not a lesser
            // observation; it is a fabricated one.
            if ($title === '' || $state === '') {
                $skipped++;
                continue;
            }

            $signalId = Uuid::uuid4()->toString();

            $metadata = ['ingestion_job_id' => $jobId, 'source_row' => $index + 1];

            foreach (['owner', 'evidence_text', 'evidence_timestamp', 'external_ref'] as $optional) {
                if (isset($map[$optional]) && ($value = trim((string) ($row[$map[$optional]] ?? ''))) !== '') {
                    $metadata[$optional] = $value;
                }
            }

            try {
                $this->events->publishInTransaction(
                    LoopEvent::OBSERVATION_MADE,
                    $tenant,
                    'Signal',
                    $actor,
                    [
                        'signalId'       => $signalId,
                        'source'         => $batch->sourceId,
                        'classification' => $state,
                    ],
                    fn () => ['entityId' => $signalId, 'result' => $this->signals->insert([
                        'id'             => $signalId,
                        'tenant_id'      => $tenant,
                        'org_id'         => $job['org_id'] ?? null,
                        'source'         => $batch->sourceId,
                        'classification' => $state,
                        'priority'       => 'medium',
                        'severity'       => 'medium',
                        // Deliberately null. An imported row carries no
                        // assessed confidence, and inventing one would let an
                        // unreviewed CSV outrank a measured signal.
                        'confidence'     => null,
                        'metadata'       => json_encode($metadata),
                        'status'         => 'new',
                        'created_by'     => $actor,
                    ])],
                );

                $committed++;
                $signalIds[] = $signalId;
            } catch (\Throwable $e) {
                // One bad row must never lose the batch. Recorded with its row
                // number so the reviewer can fix that line and re-upload.
                $errors[] = ['row' => $index + 1, 'message' => substr($e->getMessage(), 0, 300)];
            }
        }

        if (($data['save_map'] ?? false) && ($context['source_id'] ?? null)) {
            $this->rememberMap($tenant, (string) $context['source_id'], $map);
        }

        $context['created_ids'] = ['signals' => $signalIds];

        $this->jobs->update($tenant, $jobId, [
            'status'         => 'completed',
            'processed_rows' => count($batch->rows),
            'success_count'  => $committed,
            'error_count'    => count($errors),
            'error_report'   => $errors,
            'rollback_data'  => $context,
            'completed_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'job_id'     => $jobId,
            'committed'  => $committed,
            'errors'     => count($errors),
            'skipped'    => $skipped,
            // Capped, as the client's CommitResponse documents. The full set is
            // on the job's rollback_data.
            'signal_ids' => array_slice($signalIds, 0, self::SIGNAL_ID_SAMPLE),
            'status'     => 'committed',
        ]);
    }

    /**
     * Persist a confirmed mapping so the next upload from the same source is
     * pre-filled with what a human already approved.
     *
     * Upsert against (tenant_id, source_key): re-confirming a mapping must
     * update it, never add a second row that the next lookup picks between.
     *
     * @param  array<string, string>  $map
     */
    private function rememberMap(string $tenant, string $sourceKey, array $map): void
    {
        $existing = DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenant)
            ->where('source_key', $sourceKey)
            ->value('id');

        $config = json_encode(['field_map' => $map]);

        if ($existing !== null) {
            DB::table('hpbrain_data_sources')->where('id', $existing)->update([
                'config'       => $config,
                'updated_date' => now()->format('Y-m-d H:i:s'),
            ]);

            return;
        }

        DB::table('hpbrain_data_sources')->insert([
            'id'           => Uuid::uuid4()->toString(),
            'tenant_id'    => $tenant,
            'source_key'   => $sourceKey,
            'display_name' => $sourceKey,
            'source_type'  => 'csv_upload',
            'config'       => $config,
            'is_active'    => 1,
            'created_by'   => 'system',
        ]);
    }
}
