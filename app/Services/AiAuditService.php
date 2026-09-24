<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AiAuditService
{
    public function log(string $tenantId, string $userId, string $action, array $context = []): void
    {
        DB::table('hpbrain_ai_executions')->insert([
            // Generated here, not by MySQL's UUID(): that function does not
            // exist on SQLite, so the suite could never reach this write.
            'id'           => Uuid::uuid4()->toString(),
            'tenant_id'    => $tenantId,
            'user_id'      => $userId,
            'service_name' => 'audit',
            'provider'     => 'system',
            'model'        => 'none',
            'status'       => 'completed',
            'input_tokens' => 0,
            'output_tokens'=> 0,
            'latency_ms'   => 0,
            'error'        => null,
            'entity_type'  => 'Audit',
            'entity_id'    => $action,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getAuditLog(string $tenantId, array $filters = []): array
    {
        $q = DB::table('hpbrain_ai_executions')
            ->where('tenant_id', $tenantId)
            ->where('service_name', 'audit');

        if (!empty($filters['userId'])) {
            $q->where('user_id', $filters['userId']);
        }

        if (!empty($filters['action'])) {
            $q->where('entity_id', $filters['action']);
        }

        return $q->orderByDesc('created_date')->limit(200)->get()->map(fn ($r) => (array) $r)->all();
    }
}
