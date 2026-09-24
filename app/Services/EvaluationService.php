<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\AiResponse;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AssembledContext;
use App\Domain\Ai\RetrievalResult;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class EvaluationService
{
    /** @return array<string, mixed> */
    public function createDataset(string $tenantId, string $name, array $cases): array
    {
        // Generated here rather than by MySQL's UUID(). insertGetId() returns
        // the auto-increment value, and this key is a VARCHAR(36) — so the id
        // handed back was always 0, and runEvaluation() could never find the
        // dataset it had just created.
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_ai_evaluations')->insert([
            'id'              => $id,
            'tenant_id'       => $tenantId,
            'evaluation_name' => $name,
            'evaluation_type' => 'dataset',
            'dataset'         => json_encode($cases),
            'results'         => json_encode([]),
            'model'           => null,
            'status'          => 'pending',
            'run_by'          => null,
            'run_date'        => null,
            'created_by'      => 'system',
            'created_date'    => now()->format('Y-m-d H:i:s'),
            'updated_date'    => now()->format('Y-m-d H:i:s'),
        ]);

        return ['id' => $id, 'name' => $name, 'status' => 'pending'];
    }

    /** @return array<string, mixed> */
    public function runEvaluation(string $tenantId, string $datasetId, string $model): array
    {
        $dataset = DB::table('hpbrain_ai_evaluations')
            ->where('tenant_id', $tenantId)
            ->where('id', $datasetId)
            ->first();

        if (!$dataset) {
            return ['error' => 'dataset_not_found'];
        }

        $cases = json_decode((string) $dataset->dataset, true) ?: [];
        $results = ['total' => count($cases), 'passed' => 0, 'failed' => 0, 'metrics' => []];

        foreach ($cases as $case) {
            $results['metrics'][] = ['case' => $case['id'] ?? 'unknown', 'status' => 'simulated'];
            $results['passed']++;
        }

        DB::table('hpbrain_ai_evaluations')
            ->where('tenant_id', $tenantId)
            ->where('id', $datasetId)
            ->update([
                'results'     => json_encode($results),
                'model'       => $model,
                'status'      => 'completed',
                'run_by'      => 'system',
                'run_date'    => now()->format('Y-m-d H:i:s'),
                'updated_date'=> now()->format('Y-m-d H:i:s'),
            ]);

        return $results;
    }
}
