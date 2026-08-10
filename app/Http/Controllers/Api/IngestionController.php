<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\IngestionService;
use App\Domain\Ingestion\Sources\CsvUploadSource;
use App\Domain\Ingestion\Sources\ErpDataSource;
use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\UnsupportedEntityException;
use App\Http\Controllers\Controller;
use App\Repositories\DataSourceRepository;
use App\Repositories\ImportJobRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

/**
 * Ingestion — external (uploaded file) and internal (the shared ERP).
 *
 * ROUTES BELONG INSIDE THE v1 GROUP. Every route in this application sits
 * inside Route::prefix('v1') with ['jwt', 'tenant', 'permission:read'], and
 * these must too. Registered outside it, the endpoint would answer on
 * /api/ingestion/... with no token required — an unauthenticated 20 MB file
 * upload open to the internet — and $this->tenantId() would return '' because
 * EnsureTenantScope would never have run. See ROUTES.patch for the exact
 * insertion point.
 *
 * UPLOADS ARE READ THROUGH Storage, NEVER THROUGH storage_path('app/'…).
 * This project is on Laravel 11 and ships no config/filesystems.php, so the
 * framework default applies and the local disk root is storage/app/private.
 * Building the path by hand points one directory too high and every upload
 * fails with "Cannot read upload at …". Storage::path() asks the disk where it
 * actually put the file.
 *
 * PREVIEW AND COMMIT ARE SEPARATE CALLS. Nothing reaches the graph until a
 * human has seen the proposed field map and posted it back. That is the same
 * "extraction preview before provenance commit" discipline the rest of the
 * system follows, and it is what stops a mis-mapped column being written under
 * a real provenance record.
 */
final class IngestionController extends Controller
{
    /** Matches the 20 MB cap the validation rule enforces. */
    private const UPLOAD_DISK = 'local';

    public function __construct(
        private readonly IngestionService $ingestion,
        private readonly DataSourceRepository $sources,
        private readonly ImportJobRepository $jobs,
    ) {
    }

    /** GET  ingestion/sources/{tenantId} */
    public function sources(Request $request): JsonResponse
    {
        return response()->json($this->sources->list($this->tenantId($request)));
    }

    /**
     * POST ingestion/upload — external ingestion, phase 1 of 2.
     *
     * Returns a preview and a job id. Writes nothing to the graph.
     */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file'      => ['required', 'file', 'mimes:csv,xls,xlsx,txt,json,xml,html,htm,md,markdown,sql,pdf,doc,docx,zip,png,jpg,jpeg', 'max:20480'],
            'source_id' => ['required', 'string', 'max:191'],
            'org_id'    => ['nullable', 'string', 'max:36'],
        ]);

        $tenantId = $this->tenantId($request);

        // Stored under the tenant id so two tenants uploading the same
        // filename cannot collide, and so an operator can see at a glance
        // whose file a stray upload belongs to.
        $path = $request->file('file')->store("ingestion/{$tenantId}", self::UPLOAD_DISK);

        $source = new CsvUploadSource(
            tenantId: $tenantId,
            filePath: Storage::disk(self::UPLOAD_DISK)->path($path),
            sourceKey: $data['source_id'],
        );

        try {
            $batch = $source->fetch();
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'unreadable_upload', 'message' => $e->getMessage()], 422);
        }

        try {
            $stored = $this->sources->findByKey($tenantId, $data['source_id']);

            $result = $this->ingestion->preview(
                $batch,
                $this->actorId($request),
                $stored['field_map'] ?? null,
                $data['org_id'] ?? null,
            );
        } catch (QueryException $e) {
            report($e);

            return response()->json([
                'error' => 'database_unavailable',
                'message' => 'Upload was received, but ingestion preview could not start because the database is unavailable.',
            ], 503);
        }

        return response()->json([
            'job_id'  => $result['job']['id'],
            'preview' => $result['preview'],
        ], 201);
    }

    /**
     * POST ingestion/internal — internal ingestion, phase 1 of 2.
     *
     * No file. Reads the ERP through EntityResolver, resuming from the stored
     * checkpoint when the source has one.
     */
    public function internal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_id'        => ['required', 'string', 'max:191'],
            'universal_entity' => ['required', 'string', 'max:100'],
            'full_resync'      => ['nullable', 'boolean'],
            'org_id'           => ['nullable', 'string', 'max:36'],
        ]);

        $tenantId = $this->tenantId($request);
        $stored = $this->sources->findByKey($tenantId, $data['source_id']);

        // A full resync is an explicit, logged choice — never the fallback for
        // a missing checkpoint, which would re-ingest the entire ERP every time
        // a source row was recreated.
        $since = ($data['full_resync'] ?? false) ? null : ($stored['checkpoint'] ?? null);

        $source = new ErpDataSource(
            resolver: app(EntityResolver::class),
            tenantId: $tenantId,
            universalEntity: $data['universal_entity'],
            sourceKey: $data['source_id'],
        );

        try {
            $batch = $source->fetch($since);
        } catch (UnsupportedEntityException $e) {
            // Fails closed, and says which entity is unmapped rather than
            // returning an empty batch that reads as "nothing new".
            return response()->json(['error' => 'entity_not_mapped', 'message' => $e->getMessage()], 422);
        }

        $result = $this->ingestion->preview(
            $batch,
            $this->actorId($request),
            $stored['field_map'] ?? null,
            $data['org_id'] ?? null,
        );

        return response()->json([
            'job_id'  => $result['job']['id'],
            'preview' => $result['preview'],
        ], 201);
    }

    /**
     * POST ingestion/{tenantId}/{jobId}/commit — phase 2 of 2.
     *
     * Re-reads the source recorded on the job and writes real Signals and
     * Evidence. The rows are NOT accepted from the request body: a client that
     * can resubmit rows can alter them, and altered rows written under a
     * provenance record naming the original file is a forged citation.
     */
    public function commit(Request $request, string $tenantId, string $jobId): JsonResponse
    {
        $data = $request->validate([
            'field_map'   => ['required', 'array'],
            'save_map'    => ['nullable', 'boolean'],
        ]);

        $tenant = $this->tenantId($request);
        $job = $this->jobs->find($tenant, $jobId);

        if ($job === null) {
            return response()->json(['error' => 'import_job_not_found'], 404);
        }

        if ($job['status'] !== IngestionService::PREVIEWED) {
            // Guards the double-commit that would otherwise duplicate every
            // signal in the batch under a second set of ids.
            return response()->json([
                'error'   => 'job_not_previewed',
                'message' => "Job is in status '{$job['status']}'; only a previewed job may be committed.",
            ], 409);
        }

        $batch = $this->rebuild($tenant, $job);

        if ($batch === null) {
            return response()->json([
                'error'   => 'source_unavailable',
                'message' => 'The source recorded on this job can no longer be read. Re-run the preview.',
            ], 410);
        }

        try {
            $result = $this->ingestion->commit($jobId, $batch, $data['field_map'], $this->actorId($request));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'incomplete_field_map', 'message' => $e->getMessage()], 422);
        }

        if ($data['save_map'] ?? false) {
            $clean = FieldMap::fromConfig($data['field_map'])->toArray();
            $this->sources->saveFieldMap($tenant, (string) $job['source_id'], $clean);
        }

        // Only advanced after a successful write. Advancing on preview would
        // mean a preview that was never committed silently skipped its rows
        // for good.
        if ($batch->nextCheckpoint !== null) {
            $this->sources->advanceCheckpoint($tenant, $batch->sourceKey, $batch->nextCheckpoint);
        }

        return response()->json([
            'job_id'     => $jobId,
            'committed'  => $result['success'],
            'errors'     => $result['errors'],
            'skipped'    => $result['skipped'],
            'signal_ids' => array_slice($result['signal_ids'], 0, 20),
            'status'     => 'committed',
        ]);
    }

    /**
     * Rebuild the batch from what the job recorded.
     *
     * @param  array<string, mixed>  $job
     */
    private function rebuild(string $tenantId, array $job): ?\App\Domain\Ingestion\IngestionBatch
    {
        $ref = (string) ($job['source_ref'] ?? '');
        $key = (string) ($job['source_id'] ?? '');

        if ($ref === '' || $key === '') {
            return null;
        }

        try {
            if (str_ends_with((string) $job['import_type'], '_upload')) {
                return (new CsvUploadSource($tenantId, $ref, $key))->fetch();
            }

            // internal_erp: source_ref is 'Entity@checkpoint'. Re-reading from
            // the SAME checkpoint the preview used is what makes commit show
            // the rows the reviewer actually approved.
            [$entity, $checkpoint] = array_pad(explode('@', $ref, 2), 2, 'start');

            return (new ErpDataSource(app(EntityResolver::class), $tenantId, $entity, $key))
                ->fetch($checkpoint === 'start' ? null : $checkpoint);
        } catch (\Throwable) {
            return null;
        }
    }
}
