<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeasurementPlanRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

/**
 * Measurement plans are first-class because Invariant 4 requires a plan to
 * pre-date every ESO execution. A caller creates one in a separate request,
 * then starts the execution. The execution's ordering check reads
 * hpbrain_measurement_plans.created_date to prove the plan existed before the
 * run — an inline string created in the same request cannot satisfy that.
 */
final class MeasurementPlanController extends Controller
{
    public function __construct(private readonly MeasurementPlanRepository $repository)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'decisionId'             => ['required', 'string'],
            'baselineMetric'         => ['required', 'string', 'min:1'],
            'baselineValue'          => ['nullable', 'numeric'],
            'targetValue'            => ['nullable', 'numeric'],
            'metricUnit'             => ['nullable', 'string', 'max:50'],
            'measurementWindowDays'  => ['nullable', 'integer', 'min:1'],
            'ownerId'                => ['nullable', 'string'],
        ]);

        $tenant = $this->tenantId($request);
        $actor  = $this->actorId($request);
        $now    = now()->format('Y-m-d H:i:s');

        $id = Uuid::uuid4()->toString();

        $plan = [
            'id'                      => $id,
            'tenant_id'               => $tenant,
            'decision_id'             => $data['decisionId'],
            'baseline_metric'         => $data['baselineMetric'],
            'baseline_value'          => $data['baselineValue'] ?? null,
            'target_value'            => $data['targetValue'] ?? null,
            'metric_unit'             => $data['metricUnit'] ?? null,
            'measurement_window_days' => $data['measurementWindowDays'] ?? 14,
            'owner_id'                => $data['ownerId'] ?? null,
            'created_by'              => $actor,
            'created_date'            => $now,
        ];

        $this->repository->insert($plan);

        return response()->json($plan, 201);
    }
}
