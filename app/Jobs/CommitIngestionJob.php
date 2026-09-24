<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Ingestion\IngestionService;
use App\Domain\Ingestion\Sources\CsvUploadSource;
use App\Repositories\ImportJobRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Commit an ingestion job off the request thread.
 *
 * WHY THIS EXISTS. Commit is now ~200 rows/second against the remote database
 * — sixteen times faster than the per-row version it replaced — but 162,810
 * rows is still about fourteen minutes of work, and no HTTP request should be
 * held open for that. Small imports stay synchronous (see
 * IngestionController::commit) because a queue round trip is worse than the
 * work for a few hundred rows; large ones come here.
 *
 * NOTHING ABOUT THE INGESTION LOGIC MOVES INTO THIS CLASS. It re-reads the
 * source exactly as the synchronous path does and calls the same
 * IngestionService::commit(). The job is transport, not behaviour — which is
 * what keeps one implementation of the rules rather than two that drift.
 *
 * TENANT ISOLATION SURVIVES THE HOP. There is no request and therefore no
 * EnsureTenantScope out here, so the tenant is carried explicitly as a
 * constructor argument, captured from the VERIFIED token claim at dispatch
 * time. Every write the service performs is scoped by that value, exactly as
 * it is in the synchronous path. Nothing reads a tenant from the queue payload
 * that a client could have influenced.
 *
 * RETRY SAFETY IS THE DETERMINISTIC ID. A queue retry re-runs commit() over the
 * same rows; because signal ids are derived from
 * (tenant, source, row, content), the second attempt collides with the first
 * on the primary key and inserts nothing. Without that, `tries` above 1 would
 * have duplicated the dataset on every retry.
 */
final class CommitIngestionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Three attempts, then the row lands in failed_jobs with its exception.
     *
     * Safe only because the commit is idempotent — see the class docblock.
     */
    public int $tries = 3;

    /**
     * Twenty minutes. A 162,810-row commit measures around fourteen at the
     * observed throughput; this leaves headroom without letting a genuinely
     * stuck job occupy a worker indefinitely.
     */
    public int $timeout = 1200;

    /**
     * @param  array<string, string>  $fieldMap
     */
    public function __construct(
        private readonly string $tenantId,
        private readonly string $jobId,
        private readonly string $sourceRef,
        private readonly string $sourceKey,
        private readonly array $fieldMap,
        private readonly string $actorId,
    ) {
    }

    public function handle(IngestionService $ingestion, ImportJobRepository $jobs): void
    {
        $jobs->update($this->tenantId, $this->jobId, ['status' => 'processing']);

        try {
            $batch = (new CsvUploadSource(
                $this->tenantId,
                $this->sourceRef,
                $this->sourceKey,
            ))->fetch();

            $ingestion->commit($this->jobId, $batch, $this->fieldMap, $this->actorId);
        } catch (\Throwable $e) {
            // Recorded on the JOB ROW, not only in the worker log, because the
            // job row is what the UI polls. A failed import that still reads
            // "processing" forever is indistinguishable from a slow one.
            $jobs->update($this->tenantId, $this->jobId, [
                'status'      => 'failed',
                'error_report' => ['message' => $e->getMessage(), 'class' => $e::class],
            ]);

            Log::error('Queued ingestion commit failed', [
                'tenant_id' => $this->tenantId,
                'job_id'    => $this->jobId,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Called once all retries are exhausted.
     *
     * handle() already marks the row failed on each attempt; this guarantees it
     * even for the failure modes that never reach the catch — a timeout kill or
     * a worker that dies mid-run.
     */
    public function failed(\Throwable $e): void
    {
        app(ImportJobRepository::class)->update($this->tenantId, $this->jobId, [
            'status'       => 'failed',
            'error_report' => ['message' => $e->getMessage(), 'class' => $e::class],
        ]);
    }
}
