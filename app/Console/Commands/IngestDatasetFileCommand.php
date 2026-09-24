<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\IngestionService;
use App\Domain\Ingestion\Sources\CsvUploadSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ingest an already-uploaded file from the command line.
 *
 * WHY THIS EXISTS RATHER THAN "JUST USE THE UI". Two reasons, and both are about
 * the demo being reproducible.
 *
 * A 388,401-row import takes minutes. The HTTP path correctly refuses to do that
 * in a request and dispatches CommitIngestionJob instead, which needs a worker
 * running. That is the right production design and it is a poor way to get a
 * database into a known state before a demonstration, because a stalled worker
 * leaves a job stuck in 'processing' with no operator signal — which is exactly
 * the state this installation was found in, at 308,000 of 388,401 rows.
 *
 * This command runs the SAME IngestionService::commit() the worker would, in the
 * foreground, with progress on stdout. Nothing is duplicated: identical
 * chunking, identical deterministic ids, identical insertOrIgnore idempotency.
 * Re-running over rows already present writes nothing and reports them as
 * duplicates, so an interrupted import is resumed by running it again.
 *
 * IT DOES NOT DELETE ANYTHING. There is no truncate, no reset and no rollback
 * path here. The only write is an idempotent insert.
 */
final class IngestDatasetFileCommand extends Command
{
    protected $signature = 'dataset:ingest
        {tenant   : Tenant id}
        {source   : source_key registered in hpbrain_data_sources}
        {file     : Absolute path, or a path under storage/app}
        {--actor=console : Value recorded as the importing actor}';

    protected $description = 'Commit an uploaded dataset file synchronously, resuming what is already imported.';

    public function handle(IngestionService $ingestion): int
    {
        $tenantId = (string) $this->argument('tenant');
        $sourceKey = (string) $this->argument('source');
        $path = $this->resolvePath((string) $this->argument('file'));

        if ($path === null) {
            $this->error('File not found: '.$this->argument('file'));

            return self::FAILURE;
        }

        $source = DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('source_key', $sourceKey)
            ->first();

        if ($source === null) {
            $this->error("No source '{$sourceKey}' for tenant {$tenantId}. Register it first.");

            return self::FAILURE;
        }

        $map = json_decode((string) $source->field_map, true);

        if (! is_array($map) || $map === []) {
            $this->error("Source '{$sourceKey}' has no field map. Run dataset:configure --map=... first.");

            return self::FAILURE;
        }

        $batch = (new CsvUploadSource($tenantId, $path, $sourceKey))->fetch();

        $this->info("Ingesting {$batch->count()} rows from ".basename($path));
        $this->line('  tenant   '.$tenantId);
        $this->line('  source   '.$sourceKey);
        $this->line('  type     '.$source->source_type);
        $this->newLine();

        // A job row so the Ingestion screen shows this run alongside the ones
        // started from the UI, rather than data appearing with no provenance.
        $preview = $ingestion->preview($batch, (string) $this->option('actor'), $map);
        $jobId = (string) $preview['job']['id'];

        $startedAt = microtime(true);
        $result = $ingestion->commit($jobId, $batch, $map, (string) $this->option('actor'));
        $elapsed = microtime(true) - $startedAt;

        $this->info(sprintf(
            'Done in %ds — %d written, %d already present, %d rejected.',
            (int) round($elapsed),
            $result['success'],
            $result['skipped'],
            $result['errors'],
        ));
        $this->line('  job '.$jobId);

        if ($result['errors'] > 0) {
            $this->warn('Rejected rows are recorded in hpbrain_import_logs against this job id.');
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $given): ?string
    {
        foreach ([$given, storage_path('app/'.ltrim($given, '/\\')), base_path($given)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
