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

    /**
     * A notification belongs to one person: hpbrain_notifications.user_id is
     * NOT NULL and the table's only read index is (tenant_id, user_id,
     * created_date). Every query here must therefore be scoped by BOTH tenant
     * and caller. Scoping by tenant alone let any member of a tenant read the
     * whole tenant's notification feed and — worse, on the two write paths —
     * flip read_date on other people's rows, silently clearing someone else's
     * unread bell. The route-level gate is only `permission:read` precisely
     * because these are self-service; that is sound only while the caller
     * predicate below is present.
     */
    private function scope(Request $request)
    {
        return DB::table(self::TABLE)
            ->where('tenant_id', $this->tenantId($request))
            ->where('user_id', $this->actorId($request));
    }

    public function index(Request $request): JsonResponse
    {
        $q = $this->scope($request);

        if ($request->boolean('unreadOnly')) {
            $q->whereNull('read_date');
        }

        return response()->json($q->orderByDesc('created_date')->limit(200)->get());
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->scope($request)->whereNull('read_date')->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, string $tenantId, string $id): JsonResponse
    {
        $affected = $this->scope($request)->where('id', $id)
            ->update(['read_date' => now()->format('Y-m-d H:i:s')]);

        return $affected
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'notification_not_found'], 404);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $n = $this->scope($request)->whereNull('read_date')
            ->update(['read_date' => now()->format('Y-m-d H:i:s')]);

        return response()->json(['ok' => true, 'marked' => $n]);
    }
}
