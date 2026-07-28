<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SettingsController extends Controller
{
    private const TABLE = 'hpbrain_settings';

    public function index(Request $request): JsonResponse
    {
        $q = DB::table(self::TABLE)->where('tenant_id', $this->tenantId($request));

        if ($scope = $request->query('scope')) {
            $q->where('scope', $scope);
        }

        return response()->json($q->orderBy('setting_key')->get());
    }

    /** Upsert. Settings are keyed (tenant, scope, key) — never duplicated. */
    public function set(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'max:100'],
            'key'   => ['required', 'string', 'max:190'],
            'value' => ['present'],
        ]);

        $tenant = $this->tenantId($request);
        $now = now()->format('Y-m-d H:i:s');
        $value = is_scalar($data['value']) ? (string) $data['value'] : json_encode($data['value']);

        $existing = DB::table(self::TABLE)
            ->where('tenant_id', $tenant)->where('scope', $data['scope'])->where('setting_key', $data['key'])
            ->first();

        if ($existing) {
            DB::table(self::TABLE)->where('id', $existing->id)
                ->update(['setting_value' => $value, 'updated_date' => $now]);
        } else {
            DB::table(self::TABLE)->insert([
                'id' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
                'tenant_id' => $tenant, 'scope' => $data['scope'], 'setting_key' => $data['key'],
                'setting_value' => $value, 'created_by' => $this->actorId($request),
                'created_date' => $now, 'updated_date' => $now,
            ]);
        }

        return response()->json(['ok' => true, 'scope' => $data['scope'], 'key' => $data['key']]);
    }
}
