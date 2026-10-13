<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ai\KnowledgeIngestionService;
use App\Domain\Ai\MemoryIngestionService;
use Illuminate\Console\Command;

/**
 * Phase 6.1 RAG v1 — manual/spot-check trigger for embedding the Knowledge
 * Library. No HTTP route is added for this (routes/api.php is outside this
 * change's lane); a console command is how ingestion gets run today, same
 * as identity:propose-matches is how identity proposals get run.
 *
 * EXTENDED (memory extension) WITH --table= RATHER THAN A SECOND COMMAND.
 * Without --table, behavior is byte-for-byte what it always was: tenantId +
 * optional --asset/--limit against hpbrain_knowledge_assets via
 * KnowledgeIngestionService. --table=<one of MemoryIngestionService::TABLES>
 * switches to MemoryIngestionService for one of the three Organizational
 * Memory & ESO tables instead — same --asset (there, "the one row's id")
 * and --limit semantics, just against a different service and table. One
 * command for "embed something" beat two nearly-identical commands whose
 * only difference is which ingestion service and table they call.
 */
class KnowledgeEmbedCommand extends Command
{
    protected $signature = 'knowledge:embed {tenantId} {--asset=} {--table=} {--limit=50}';

    protected $description = 'Embed rows for a tenant. Default: hpbrain_knowledge_assets. --table=<hpbrain_learnings|hpbrain_mental_models|hpbrain_eso_definitions> embeds one of the memory tables instead. One row with --asset=<id>, or the next --limit unembedded/stale rows.';

    public function handle(KnowledgeIngestionService $ingestion, MemoryIngestionService $memoryIngestion): int
    {
        $tenantId = (string) $this->argument('tenantId');
        $assetId = $this->option('asset');
        $table = $this->option('table');

        if ($table !== null) {
            return $this->handleMemoryTable($memoryIngestion, $tenantId, (string) $table, $assetId);
        }

        if ($assetId !== null) {
            $result = $ingestion->ingestAsset($tenantId, (string) $assetId);
            $this->table(['Field', 'Value'], array_map(
                fn ($k, $v) => [$k, is_scalar($v) ? (string) $v : json_encode($v)],
                array_keys($result),
                array_values($result),
            ));

            return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $result = $ingestion->ingestPending($tenantId, (int) $this->option('limit'));

        $this->table(['Processed', 'Failed'], [[$result['processed'], $result['failed']]]);

        foreach ($result['details'] as $detail) {
            $this->line(json_encode($detail));
        }

        return self::SUCCESS;
    }

    private function handleMemoryTable(MemoryIngestionService $memoryIngestion, string $tenantId, string $table, ?string $rowId): int
    {
        if (! MemoryIngestionService::isSupportedTable($table)) {
            $this->error(sprintf(
                'Unsupported --table=%s. Supported: %s',
                $table,
                implode(', ', MemoryIngestionService::supportedTables()),
            ));

            return self::FAILURE;
        }

        if ($rowId !== null) {
            $result = $memoryIngestion->ingestRow($tenantId, $table, $rowId);
            $this->table(['Field', 'Value'], array_map(
                fn ($k, $v) => [$k, is_scalar($v) ? (string) $v : json_encode($v)],
                array_keys($result),
                array_values($result),
            ));

            return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $result = $memoryIngestion->ingestPending($tenantId, $table, (int) $this->option('limit'));

        $this->table(['Processed', 'Failed'], [[$result['processed'], $result['failed']]]);

        foreach ($result['details'] as $detail) {
            $this->line(json_encode($detail));
        }

        return self::SUCCESS;
    }
}
