<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class MentalModelController extends Controller
{
    private const TABLE = 'hpbrain_mental_models';

    public function index(Request $request): JsonResponse
    {
        $q = DB::table(self::TABLE)->where('tenant_id', $this->tenantId($request));

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return response()->json($q->orderByDesc('created_date')->limit(500)->get());
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = DB::table(self::TABLE)
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)->first();

        return $row ? response()->json($row) : response()->json(['error' => 'mental_model_not_found'], 404);
    }

    public function byDomain(Request $request, string $tenantId, string $domain): JsonResponse
    {
        $row = DB::table(self::TABLE)
            ->where('tenant_id', $this->tenantId($request))->where('domain', $domain)
            ->orderByDesc('created_date')->first();

        return $row ? response()->json($row) : response()->json(['error' => 'mental_model_not_found'], 404);
    }

    protected function insertRow(Request $request, array $fields): array
    {
        $row = array_merge($fields, [
            'id'           => Uuid::uuid4()->toString(),
            'tenant_id'    => $this->tenantId($request),
            'created_by'   => $this->actorId($request),
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        DB::table(self::TABLE)->insert($row);

        return $row;
    }
}
