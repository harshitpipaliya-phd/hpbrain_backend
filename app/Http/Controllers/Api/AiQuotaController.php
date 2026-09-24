<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiQuotaEnforcer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

final class AiQuotaController extends Controller
{
    public function __construct(private readonly AiQuotaEnforcer $quotaEnforcer)
    {
    }

    public function index(Request $request, string $tenantId): JsonResponse
    {
        $rows = \Illuminate\Support\Facades\DB::table('hpbrain_ai_quotas')
            ->where('tenant_id', $this->tenantId($request))
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return response()->json($rows);
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = \Illuminate\Support\Facades\DB::table('hpbrain_ai_quotas')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->first();

        return $row ? response()->json((array) $row) : response()->json(['error' => 'quota_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'quota_type'  => ['required', 'string', 'max:50'],
            'quota_key'   => ['required', 'string', 'max:255'],
            'limit_value' => ['required', 'integer', 'min:0'],
            'reset_period'=> ['nullable', 'string', 'max:50'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        // Generated here, not by MySQL's UUID(). insertGetId() returns the
        // auto-increment value and this key is a VARCHAR(36), so the id
        // reported to the client was always "0".
        $id = Uuid::uuid4()->toString();

        \Illuminate\Support\Facades\DB::table('hpbrain_ai_quotas')->insert([
            'id'           => $id,
            'tenant_id'    => $this->tenantId($request),
            'quota_type'   => $data['quota_type'],
            'quota_key'    => $data['quota_key'],
            'limit_value'  => $data['limit_value'],
            'current_usage'=> 0,
            'reset_period' => $data['reset_period'] ?? 'monthly',
            'is_active'    => $data['is_active'] ?? true,
            'created_by'   => $this->actorId($request),
            'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'limit_value' => ['sometimes', 'integer', 'min:0'],
            'is_active'   => ['sometimes', 'nullable', 'boolean'],
        ]);

        \Illuminate\Support\Facades\DB::table('hpbrain_ai_quotas')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->update(array_merge($data, ['updated_date' => now()->format('Y-m-d H:i:s')]));

        return response()->json(['ok' => true]);
    }

    public function reset(Request $request, string $tenantId, string $id): JsonResponse
    {
        \Illuminate\Support\Facades\DB::table('hpbrain_ai_quotas')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->update(['current_usage' => 0, 'updated_date' => now()->format('Y-m-d H:i:s')]);

        return response()->json(['ok' => true]);
    }
}
