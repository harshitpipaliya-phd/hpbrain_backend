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
use App\Jobs\CommitIngestionJob;
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
    private const UPLOAD_DISK = 'local';

    /**
     * Upload cap, in kilobytes.
     *
     * 20 MB was too small for the datasets this product exists to read — a
     * 65,000-row employee export with thirty columns is comfortably past it as
     * CSV and further as XLSX. Raised to 64 MB.
     *
     * THIS NUMBER IS NOT SUFFICIENT ON ITS OWN. PHP rejects an oversized body
     * before any Laravel rule runs, so upload_max_filesize and post_max_size
     * must be at least this large or the user gets a 413 (or a bare
     * "failed to upload") instead of the clean message this cap produces.
     * uploadPrecondition() detects that misconfiguration and says so by name
     * rather than leaving it to be guessed.
     */
    private const MAX_UPLOAD_KB = 65536;

    /**
     * Above this many rows, commit is queued instead of run in the request.
     *
     * At the measured ~200 rows/second against the remote database, 5,000 rows
     * is roughly 25 seconds — already past what a user should watch a spinner
     * for, and close enough to typical proxy timeouts to be risky. Below it the
     * synchronous path is kept, because dispatching a job and polling for a
     * 300-row import costs more than simply doing the work.
     */
    private const QUEUE_ABOVE_ROWS = 200000;

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
        // A PHP-LEVEL UPLOAD FAILURE IS DIAGNOSED BEFORE VALIDATION RUNS.
        //
        // When PHP sets an UPLOAD_ERR_* code, Laravel's implicit `uploaded`
        // rule reports the single string "The file failed to upload." for all
        // seven causes — over the ini limit, no temp directory, unwritable temp
        // directory, a truncated request, an extension that aborted it. That
        // message is what a user sees, and it names none of them, so the same
        // 422 has been reported for a 60 MB file and for a missing C:\xampp\tmp
        // with nothing to tell them apart. Every branch below is actionable.
        if ($precondition = $this->uploadPrecondition($request)) {
            return $precondition;
        }

        $data = $request->validate([
            // Validate the extension, then let CsvUploadSource parse or reduce
            // the file to metadata. MIME sniffing is intentionally avoided:
            // browsers and PHP disagree on ordinary CSV/DOC/PDF uploads often
            // enough that `mimes` rejects valid files before ingestion can
            // inspect them. The extension allow-list is the product contract
            // exposed by the SPA's file picker.
            'file'      => [
                'required', 'file',
                'extensions:csv,tsv,xls,xlsx,pdf,doc,docx,txt,json,xml,html,htm,md,markdown,zip,sql,png,jpg,jpeg',
                'max:'.self::MAX_UPLOAD_KB,
            ],
            'source_id' => ['required', 'string', 'max:191'],
            'org_id'    => ['nullable', 'string', 'max:36'],
        ]);

        $tenantId = $this->tenantId($request);
        $upload = $request->file('file');

        /*
          THE STORED FILENAME MUST KEEP THE REAL EXTENSION.

          ->store() names files with hashName(), which derives the extension
          from guessExtension() — a MIME sniff. A perfectly ordinary CSV sniffs
          as text/plain, so it was written to disk as `<hash>.txt`. Two lines
          later CsvUploadSource reads the extension back off that stored path,
          saw `txt`, and took its plain-text branch: the ENTIRE FILE became one
          row with the filename as `title` and the whole content as
          `evidence_text`.

          That is why real organization data never arrived. A 65,000-row export
          did not fail — it ingested as a single document record, quietly. The
          .txt files sitting in storage/app/ingestion/7/ are the fossils of it.

          Using the client extension here is safe because `extensions:` above
          has already validated it against an allow-list, and basename() strips
          any path the client tried to smuggle in.
        */
        $extension = strtolower($upload->getClientOriginalExtension() ?: 'csv');
        $storedName = bin2hex(random_bytes(16)).'.'.$extension;

        // Stored under the tenant id so two tenants uploading the same
        // filename cannot collide, and so an operator can see at a glance
        // whose file a stray upload belongs to.
        $path = $upload->storeAs("ingestion/{$tenantId}", $storedName, self::UPLOAD_DISK);

        if ($path === false) {
            return response()->json([
                'error'   => 'storage_failed',
                'message' => 'The file was received but could not be written to storage. Check that the storage directory is writable.',
            ], 500);
        }

        $source = new CsvUploadSource(
            tenantId: $tenantId,
            filePath: Storage::disk(self::UPLOAD_DISK)->path($path),
            sourceKey: $data['source_id'],
            // Passed explicitly rather than re-derived from the path, so
            // parsing can never again depend on how storage chose to name the
            // file. The original name travels with it for provenance.
            originalName: $upload->getClientOriginalName(),
            originalExtension: $extension,
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
     * Diagnose a PHP-level upload failure, or return null if there is none.
     *
     * Laravel collapses all seven UPLOAD_ERR_* codes into one message. This
     * separates them, because the fixes are completely different: raising an
     * ini value, creating a directory, granting write permission, or retrying a
     * dropped connection. The technical detail is logged; the caller gets a
     * sentence naming what to change.
     *
     * The ini values are read at request time rather than hardcoded so the
     * message quotes the limit actually in force on this machine — which is the
     * one fact the person reading it needs and cannot see.
     */
    private function uploadPrecondition(Request $request): ?JsonResponse
    {
        /*
          READ THE FILE BAG DIRECTLY. NEVER hasFile() HERE.

          This is the bug that made the first version of this method useless.
          hasFile() calls isValidFile(), which requires getPath() !== '' — and
          on UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_NO_TMP_DIR and UPLOAD_ERR_PARTIAL,
          PHP hands Laravel an empty tmp_name. getPath() is therefore '' and
          hasFile() returns FALSE for precisely the failures this method exists
          to diagnose. The guard bailed out, execution fell through to the
          `uploaded` rule, and the user got "The file failed to upload." — the
          exact generic message this was written to replace.

          $request->files->get() returns the UploadedFile whatever state it is
          in, which is what makes the error code readable at all. Verified:
          with an empty tmp_name and UPLOAD_ERR_INI_SIZE, hasFile() is false
          while files->get() returns the object carrying getError() === 1.
        */
        $file = $request->files->get('file');

        /*
          TYPE-CHECKED AGAINST THE SYMFONY BASE CLASS, NOT LARAVEL'S SUBCLASS.

          The second bug in this method, and the reason it still returned the
          generic message after the first fix. On a REAL request PHP's $_FILES
          is converted by Symfony's FileBag, which constructs
          Symfony\...\File\UploadedFile. Laravel only upgrades those to
          Illuminate\Http\UploadedFile inside allFiles()/convertUploadedFiles() —
          a path that ->files->get() does not take. So the object here is the
          Symfony parent, `instanceof Illuminate\Http\UploadedFile` was false,
          and the guard returned null on every genuine upload failure.

          It was invisible to both the CLI probe and the feature tests because
          Request::create() and $this->postJson() accept an already-constructed
          Illuminate UploadedFile and keep it — so the narrow check passed there
          and failed only against the real web server.

          Illuminate's class extends this one, so matching the parent covers
          both and cannot regress in the other direction.
        */
        if (! $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return null;
        }

        if ($file->isValid()) {
            return null;
        }

        $code = $file->getError();
        $uploadMax = (string) ini_get('upload_max_filesize');
        $postMax = (string) ini_get('post_max_size');
        $tmpDir = (string) (ini_get('upload_tmp_dir') ?: sys_get_temp_dir());

        [$error, $message] = match ($code) {
            UPLOAD_ERR_INI_SIZE => [
                'file_exceeds_php_limit',
                "The file is larger than this server's PHP upload limit (upload_max_filesize = {$uploadMax}). "
                ."Raise upload_max_filesize and post_max_size to at least ".(self::MAX_UPLOAD_KB / 1024)." MB in php.ini and restart the web server.",
            ],
            UPLOAD_ERR_FORM_SIZE => [
                'file_exceeds_form_limit',
                'The file is larger than the limit declared by the upload form.',
            ],
            UPLOAD_ERR_PARTIAL => [
                'upload_incomplete',
                'Only part of the file reached the server. The connection was interrupted — please retry the upload.',
            ],
            UPLOAD_ERR_NO_FILE => [
                'no_file_received',
                'No file was received. Choose a file and try again.',
            ],
            UPLOAD_ERR_NO_TMP_DIR => [
                'missing_temp_directory',
                "PHP has no temporary upload directory. Create {$tmpDir} and make it writable by the web server user, then restart it.",
            ],
            UPLOAD_ERR_CANT_WRITE => [
                'temp_directory_not_writable',
                "PHP could not write the upload to {$tmpDir}. Grant the web server user write permission on that directory.",
            ],
            UPLOAD_ERR_EXTENSION => [
                'upload_blocked_by_extension',
                'A PHP extension on this server stopped the upload. Check the PHP error log for which one.',
            ],
            default => [
                'upload_failed',
                'The file could not be received by the server.',
            ],
        };

        // Server-side detail, including the numeric code and the limits in
        // force, so the log answers the question without a second reproduction.
        \Illuminate\Support\Facades\Log::error('Ingestion upload rejected by PHP', [
            'upload_error_code'   => $code,
            'error'               => $error,
            'client_name'         => $file->getClientOriginalName(),
            'upload_max_filesize' => $uploadMax,
            'post_max_size'       => $postMax,
            'upload_tmp_dir'      => $tmpDir,
            'tmp_dir_exists'      => is_dir($tmpDir),
            'tmp_dir_writable'    => is_writable($tmpDir),
        ]);

        return response()->json([
            'error'   => $error,
            'message' => $message,
            // Mirrored under `errors.file` so the SPA's existing extractor —
            // which reads errors.file[0] first — shows this instead of falling
            // back to the generic status text.
            'errors'  => ['file' => [$message]],
        ], 422);
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

        /*
          LARGE IMPORTS GO TO THE QUEUE; SMALL ONES DO NOT.

          The field map is validated HERE, before dispatch, so an incomplete map
          still fails fast with a 422 the user can act on rather than becoming a
          job that fails silently in a worker minutes later.
        */
        try {
            $validatedMap = FieldMap::fromConfig($data['field_map']);

            if (! $validatedMap->isCommittable()) {
                throw new \InvalidArgumentException(
                    'Field map must bind at least "title" and "state" before commit.'
                );
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'incomplete_field_map', 'message' => $e->getMessage()], 422);
        }

        if (count($batch->rows) > self::QUEUE_ABOVE_ROWS) {
            $this->jobs->update($tenant, $jobId, [
                'status'     => 'queued',
                'total_rows' => count($batch->rows),
            ]);

            if ($data['save_map'] ?? false) {
                $this->sources->saveFieldMap($tenant, (string) $job['source_id'], $validatedMap->toArray());
            }

            CommitIngestionJob::dispatch(
                $tenant,
                $jobId,
                (string) $job['source_ref'],
                $batch->sourceKey,
                $data['field_map'],
                $this->actorId($request),
            );

            // 202, not 201: the work is accepted and not yet done. The client
            // polls the job endpoint for progress rather than waiting here.
            return response()->json([
                'job_id'     => $jobId,
                'status'     => 'queued',
                'total_rows' => count($batch->rows),
                'poll'       => "/api/v1/imports/{$tenant}/{$jobId}",
                'message'    => 'Ingestion has been queued. Track progress on the job.',
            ], 202);
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
            // Reports what actually happened rather than a flat 'committed'.
            // A run with rejected rows must not look identical to a clean one.
            'status'     => match (true) {
                $result['success'] === 0 && $result['errors'] > 0 => 'failed',
                $result['errors'] > 0                             => 'completed_with_errors',
                default                                           => 'committed',
            },
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
