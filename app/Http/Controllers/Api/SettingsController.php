<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings.
 *
 * hpbrain_settings is (tenant_id, user_id, key, value, updated_date) — five
 * columns, no surrogate id. This controller previously addressed it as
 * (id, scope, setting_key, setting_value, created_by, created_date), none of
 * which exist, so every read and every write raised a 500. The table is the
 * source of truth here; the queries are what were wrong.
 *
 * `scope` is expressed through user_id rather than a column of its own:
 *   personal -> the caller's own row  (user_id = actor)
 *   org      -> the tenant-wide row   (user_id IS NULL)
 * which is the distinction the UI actually needs, and it keeps one person's
 * preferences from overwriting everyone else's.
 */
final class SettingsController extends Controller
{
    private const TABLE = 'hpbrain_settings';

    public function index(Request $request): JsonResponse
    {
        $q = DB::table(self::TABLE)->where('tenant_id', $this->tenantId($request));

        $scope = (string) $request->query('scope', '');

        if ($scope === 'personal') {
            $q->where('user_id', $this->actorId($request));
        } elseif ($scope === 'org') {
            $q->whereNull('user_id');
        }

        return response()->json($q->orderBy('key')->get());
    }

    /** Upsert, keyed on (tenant_id, user_id, key). */
    public function set(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', 'string', 'max:100'],
            'key'   => ['required', 'string', 'max:190'],
            'value' => ['present'],
        ]);

        $tenant = $this->tenantId($request);
        $userId = ($data['scope'] ?? 'personal') === 'org' ? null : $this->actorId($request);
        $value  = is_scalar($data['value']) ? (string) $data['value'] : json_encode($data['value']);
        $now    = now()->format('Y-m-d H:i:s');

        $q = DB::table(self::TABLE)->where('tenant_id', $tenant)->where('key', $data['key']);
        $userId === null ? $q->whereNull('user_id') : $q->where('user_id', $userId);

        if ($q->exists()) {
            $q->update(['value' => $value, 'updated_date' => $now]);
        } else {
            DB::table(self::TABLE)->insert([
                'tenant_id'    => $tenant,
                'user_id'      => $userId,
                'key'          => $data['key'],
                'value'        => $value,
                'updated_date' => $now,
            ]);
        }

        return response()->json(['ok' => true, 'key' => $data['key'], 'scope' => $data['scope'] ?? 'personal']);
    }
}
