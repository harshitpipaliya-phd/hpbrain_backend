<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class RiskController extends Controller
{
    private const TABLE = 'hpbrain_risks';

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

        return $row ? response()->json($row) : response()->json(['error' => 'risk_not_found'], 404);
    }

    public function assess(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'min:1'],
            'category'    => ['required', 'string'],
            'impact'      => ['required', 'numeric', 'between:0,1'],
            'probability' => ['required', 'numeric', 'between:0,1'],
            'description' => ['nullable', 'string'],
        ]);

        // Score is DERIVED, never supplied. Two risks with the same impact and
        // probability must never carry different scores because a caller
        // asserted one — the same discipline as computed confidence.
        $score = round(((float) $data['impact']) * ((float) $data['probability']), 4);

        return response()->json($this->insertRow($request, [
            'title'       => $data['title'],
            'category'    => $data['category'],
            'impact'      => $data['impact'],
            'probability' => $data['probability'],
            'score'       => $score,
            'description' => $data['description'] ?? null,
            'status'      => 'open',
        ]), 201);
    }

    public function mitigate(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate(['mitigation' => ['required', 'string', 'min:1']]);

        $n = DB::table(self::TABLE)
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)
            ->update(['status' => 'mitigated', 'mitigation' => $data['mitigation']]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'risk_not_found'], 404);
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
