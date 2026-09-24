<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * Query-builder repository for hpbrain_measurement_plans. No Eloquent.
 */
final class MeasurementPlanRepository
{
    public function insert(array $plan): void
    {
        DB::table('hpbrain_measurement_plans')->insert($plan);
    }

    public function findForDecision(string $tenant, string $decisionId): ?array
    {
        $row = DB::table('hpbrain_measurement_plans')
            ->where('tenant_id', $tenant)
            ->where('decision_id', $decisionId)
            ->orderBy('created_date')
            ->first();

        return $row ? (array) $row : null;
    }
}
