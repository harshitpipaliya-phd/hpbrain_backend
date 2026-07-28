<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Audit is append-only by contract. There is deliberately no update or delete
 * endpoint — an audit trail that can be edited is not an audit trail.
 */
final class AuditController extends Controller
{
    private const TABLE = 'hpbrain_audit_logs';

    public function index(Request $request): JsonResponse
    {
        $q = DB::table(self::TABLE)->where('tenant_id', $this->tenantId($request));

        if ($entityType = $request->query('entityType')) { $q->where('entity_type', $entityType); }
        if ($entityId   = $request->query('entityId'))   { $q->where('entity_id', $entityId); }
        if ($action     = $request->query('action'))     { $q->where('action', $action); }
        if ($term       = $request->query('q')) {
            $q->where(fn ($w) => $w->where('entity_type', 'like', "%{$term}%")
                                   ->orWhere('action', 'like', "%{$term}%"));
        }

        $limit = min((int) $request->query('limit', 100), 500);

        return response()->json($q->orderByDesc('created_date')->limit($limit)->get());
    }

    public function activity(Request $request): JsonResponse
    {
        return response()->json(
            DB::table(self::TABLE)->where('tenant_id', $this->tenantId($request))
                ->orderByDesc('created_date')->limit(50)->get()
        );
    }

    public function stats(Request $request): JsonResponse
    {
        $tenant = $this->tenantId($request);

        return response()->json([
            'total'      => DB::table(self::TABLE)->where('tenant_id', $tenant)->count(),
            'byAction'   => DB::table(self::TABLE)->where('tenant_id', $tenant)
                              ->select('action', DB::raw('COUNT(*) as count'))
                              ->groupBy('action')->orderByDesc('count')->get(),
            'byEntity'   => DB::table(self::TABLE)->where('tenant_id', $tenant)
                              ->select('entity_type', DB::raw('COUNT(*) as count'))
                              ->groupBy('entity_type')->orderByDesc('count')->get(),
        ]);
    }
}
