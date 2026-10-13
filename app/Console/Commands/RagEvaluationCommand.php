<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\EvaluationService;
use Illuminate\Console\Command;

/**
 * Phase 6.1 RAG v1 — seeds and runs EvaluationService's hand-authored RAG
 * smoke test for a tenant, and prints the REAL pass/fail numbers. This is
 * the command used to produce the actual numbers quoted for task 3, as
 * opposed to the mocked, reproducible PHPUnit test
 * (tests/Feature/RagEvaluationHarnessTest.php) which validates the
 * harness's mechanics against faked provider responses.
 */
class RagEvaluationCommand extends Command
{
    protected $signature = 'ai:eval-rag {tenantId}';

    protected $description = 'Seed and run the hand-authored RAG evaluation dataset for a tenant, reporting real pass/fail counts';

    public function handle(EvaluationService $evaluation): int
    {
        $tenantId = (string) $this->argument('tenantId');

        $seed = $evaluation->seedDefaultRagDataset($tenantId);
        $this->info('Seeded dataset ' . $seed['datasetId'] . ' (' . count($seed['seededAssetIds']) . ' fixture documents).');

        $results = $evaluation->runEvaluation($tenantId, $seed['datasetId'], 'rag-eval-v1');

        $this->table(['Total', 'Passed', 'Failed', 'AI configured'], [[
            $results['total'], $results['passed'], $results['failed'], $results['aiConfigured'] ? 'yes' : 'no',
        ]]);

        foreach ($results['metrics'] as $metric) {
            $this->line(json_encode($metric));
        }

        return self::SUCCESS;
    }
}
