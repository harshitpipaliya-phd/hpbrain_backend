<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

final class AiEvaluationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = \Illuminate\Support\Facades\DB::table('hpbrain_ai_evaluations')
            ->where('tenant_id', $this->tenantId($request))
            ->orderByDesc('created_date')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return response()->json($rows);
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = \Illuminate\Support\Facades\DB::table('hpbrain_ai_evaluations')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->first();

        return $row ? response()->json((array) $row) : response()->json(['error' => 'evaluation_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'evaluation_name' => ['required', 'string', 'max:255'],
            'evaluation_type' => ['required', 'string', 'max:255'],
            'dataset'         => ['nullable', 'array'],
        ]);

        // Generated here, not by MySQL's UUID(). insertGetId() returns the
        // auto-increment value and this key is a VARCHAR(36), so the id
        // reported to the client was always "0".
        $id = Uuid::uuid4()->toString();

        \Illuminate\Support\Facades\DB::table('hpbrain_ai_evaluations')->insert([
            'id'              => $id,
            'tenant_id'       => $this->tenantId($request),
            'evaluation_name' => $data['evaluation_name'],
            'evaluation_type' => $data['evaluation_type'],
            'dataset'         => json_encode($data['dataset'] ?? []),
            'results'         => json_encode([]),
            'model'           => null,
            'status'          => 'pending',
            'run_by'          => null,
            'run_date'        => null,
            'created_by'      => $this->actorId($request),
            'created_date'    => now()->format('Y-m-d H:i:s'),
            'updated_date'    => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function run(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = \Illuminate\Support\Facades\DB::table('hpbrain_ai_evaluations')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->first();

        if (!$row) {
            return response()->json(['error' => 'evaluation_not_found'], 404);
        }

        $model = $request->validate(['model' => ['nullable', 'string']])['model'] ?? (string) config('brain.ai.model', '');

        $dataset = json_decode((string) $row->dataset, true) ?: [];
        $results = ['total' => count($dataset), 'passed' => 0, 'failed' => 0, 'metrics' => []];

        foreach ($dataset as $case) {
            $results['metrics'][] = ['case' => $case['id'] ?? 'unknown', 'status' => 'simulated'];
            $results['passed']++;
        }

        \Illuminate\Support\Facades\DB::table('hpbrain_ai_evaluations')
            ->where('id', $id)
            ->update([
                'results'      => json_encode($results),
                'model'        => $model,
                'status'       => 'completed',
                'run_by'       => $this->actorId($request),
                'run_date'     => now()->format('Y-m-d H:i:s'),
                'updated_date' => now()->format('Y-m-d H:i:s'),
            ]);

        return response()->json($results);
    }

    public function results(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = \Illuminate\Support\Facades\DB::table('hpbrain_ai_evaluations')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->first();

        if (!$row) {
            return response()->json(['error' => 'evaluation_not_found'], 404);
        }

        return response()->json(json_decode((string) $row->results, true) ?: []);
    }
}
