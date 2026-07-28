<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NotificationController extends Controller
{
    private const TABLE = 'hpbrain_notifications';

    public function index(Request $request): JsonResponse
    {
        $q = DB::table(self::TABLE)->where('tenant_id', $this->tenantId($request));

        if ($request->boolean('unreadOnly')) {
            $q->whereNull('read_date');
        }

        return response()->json($q->orderByDesc('created_date')->limit(200)->get());
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = DB::table(self::TABLE)
            ->where('tenant_id', $this->tenantId($request))
            ->whereNull('read_date')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, string $tenantId, string $id): JsonResponse
    {
        $affected = DB::table(self::TABLE)
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)
            ->update(['read_date' => now()->format('Y-m-d H:i:s')]);

        return $affected
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'notification_not_found'], 404);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $n = DB::table(self::TABLE)
            ->where('tenant_id', $this->tenantId($request))->whereNull('read_date')
            ->update(['read_date' => now()->format('Y-m-d H:i:s')]);

        return response()->json(['ok' => true, 'marked' => $n]);
    }
}
