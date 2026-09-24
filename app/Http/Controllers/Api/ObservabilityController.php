<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ObservabilityController extends Controller
{
    public function health(): JsonResponse
    {
        $db = $this->databaseCheck();
        $events = $this->eventsCheck();
        $ok = $db['status'] === 'healthy' && $events['status'] === 'healthy';

        return response()->json([
            'status' => $ok ? 'healthy' : 'unhealthy',
            'checks' => [
                'database' => $db,
                'events'   => $events,
                'system'   => $this->systemCheck(),
                // ADR-008: Neo4j is deferred out of v1. Reported explicitly as
                // 'not_configured' rather than omitted, so an operator can tell
                // "deliberately absent" from "silently broken".
                'neo4j'    => ['status' => 'not_configured', 'details' => ['reason' => 'deferred by ADR-008']],
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function database(): JsonResponse { return response()->json($this->databaseCheck()); }
    public function events(): JsonResponse   { return response()->json($this->eventsCheck()); }
    public function system(): JsonResponse   { return response()->json($this->systemCheck()); }

    public function neo4j(): JsonResponse
    {
        return response()->json(['status' => 'not_configured', 'details' => ['reason' => 'deferred by ADR-008']]);
    }

    public function systemMetrics(): JsonResponse
    {
        return response()->json($this->systemCheck());
    }

    public function metrics(Request $request): JsonResponse
    {
        $q = DB::table('hpbrain_metrics')->where('tenant_id', $this->tenantId($request));

        if ($name = $request->query('metricName')) {
            $q->where('metric_name', $name);
        }

        return response()->json($q->orderByDesc('recorded_at')->limit(500)->get());
    }

    private function databaseCheck(): array
    {
        $start = microtime(true);

        try {
            DB::select('SELECT 1');

            return ['status' => 'healthy', 'responseTime' => (int) ((microtime(true) - $start) * 1000)];
        } catch (Throwable $e) {
            return ['status' => 'unhealthy', 'details' => ['error' => $e->getMessage()]];
        }
    }

    private function eventsCheck(): array
    {
        try {
            return ['status' => 'healthy', 'details' => [
                'eventCount'  => DB::table('hpbrain_event_store')->count(),
                'deadLetters' => DB::table('hpbrain_dead_letter_queue')->count(),
            ]];
        } catch (Throwable $e) {
            return ['status' => 'unhealthy', 'details' => ['error' => $e->getMessage()]];
        }
    }

    private function systemCheck(): array
    {
        return ['status' => 'healthy', 'details' => [
            'memory' => [
                'heapUsed'  => (int) (memory_get_usage(true) / 1048576),
                'heapTotal' => (int) (memory_get_peak_usage(true) / 1048576),
                'unit'      => 'MB',
            ],
            'php' => PHP_VERSION,
        ]];
    }
}
