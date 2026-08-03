<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\AiResponse;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AssembledContext;
use App\Domain\Ai\RetrievalResult;
use App\Domain\Ai\QuotaResult;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class TokenCostAccountingService
{
    public function record(string $tenantId, string $userId, string $model, int $inputTokens, int $outputTokens, ?string $entityType = null, ?string $entityId = null): void
    {
        $pricing = config("brain.ai.pricing.{$model}", []);
        $cost = 0.0;

        if ($pricing !== []) {
            $cost = (($inputTokens ?? 0) / 1_000_000) * (float) ($pricing['input'] ?? 0)
                  + (($outputTokens ?? 0) / 1_000_000) * (float) ($pricing['output'] ?? 0);
        }

        DB::table('hpbrain_ai_executions')->insert([
            'id'                 => Uuid::uuid4()->toString(),
            'tenant_id'          => $tenantId,
            'user_id'            => $userId,
            'service_name'       => 'cost_accounting',
            'provider'           => (string) config('brain.ai.provider', 'none'),
            'model'              => $model,
            'status'             => 'completed',
            'input_tokens'       => $inputTokens,
            'output_tokens'      => $outputTokens,
            'latency_ms'         => null,
            'estimated_cost_usd' => round($cost, 4),
            'error'              => null,
            'entity_type'        => $entityType,
            'entity_id'          => $entityId,
            'created_date'       => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{totalCost: float, byModel: array<string, float>, byFeature: array<string, float>} */
    public function getMonthlyCost(string $tenantId, string $orgId): array
    {
        $rows = DB::table('hpbrain_ai_executions')
            ->where('tenant_id', $tenantId)
            ->where('created_date', '>=', now()->subMonth()->format('Y-m-d H:i:s'))
            ->get();

        $totalCost = 0.0;
        $byModel = [];
        $byFeature = [];

        foreach ($rows as $row) {
            $cost = (float) ($row->estimated_cost_usd ?? 0);
            $model = (string) ($row->model ?? 'unknown');
            $feature = (string) ($row->service_name ?? 'unknown');

            $totalCost += $cost;
            $byModel[$model] = ($byModel[$model] ?? 0) + $cost;
            $byFeature[$feature] = ($byFeature[$feature] ?? 0) + $cost;
        }

        return ['totalCost' => $totalCost, 'byModel' => $byModel, 'byFeature' => $byFeature];
    }
}
