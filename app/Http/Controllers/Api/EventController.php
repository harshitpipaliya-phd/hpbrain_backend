<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Event backbone (ADR-002).
 *
 * Events are past-tense facts, never commands. Every one carries tenantId,
 * correlationId, causationId, ts and an idempotency key, so the loop can be
 * replayed and audited. A poison message is dead-lettered, never allowed to
 * stall a stage.
 *
 * Replay is deliberately a NEW event carrying the original payload and a fresh
 * idempotency key, rather than a re-dispatch of the original row. Re-running
 * the original would either be swallowed by idempotency (achieving nothing) or
 * bypass it (duplicating business actions). A replay is a new fact that
 * references an old one.
 */
final class EventController extends Controller
{
    /**
     * hpbrain_event_store names the column `type`, and timestamps it with
     * `created_at`. Every query here previously said `event_type` and
     * `created_date`, so the whole events surface 500'd.
     *
     * hpbrain_dead_letter_queue has no tenant_id at all — it is
     * (id, event_id, consumer_name, error_message, error_stack, retry_count,
     * max_retries, created_at). Tenant scoping therefore has to travel through
     * the event the entry refers to, which is what dlqForTenant() does.
     */
    private function dlqForTenant(string $tenant)
    {
        return DB::table('hpbrain_dead_letter_queue as d')
            ->join('hpbrain_event_store as e', 'e.id', '=', 'd.event_id')
            ->where('e.tenant_id', $tenant);
    }

    /**
     * GET /events — the collection read the SPA's Event Store screen calls.
     * There was no such route, so that screen could only ever 404.
     */
    public function index(Request $request): JsonResponse
    {
        $q = DB::table('hpbrain_event_store')->where('tenant_id', $this->tenantId($request));

        if ($type   = $request->query('type'))   { $q->where('type', $type); }
        if ($status = $request->query('status')) { $q->where('status', $status); }
        if ($entity = $request->query('entityType')) { $q->where('entity_type', $entity); }

        $limit = min((int) $request->query('limit', 100), 500);

        return response()->json($q->orderByDesc('created_at')->limit($limit)->get());
    }

    public function stats(Request $request): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $store  = fn () => DB::table('hpbrain_event_store')->where('tenant_id', $tenant);

        $byStatus = $store()->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')->pluck('count', 'status');

        return response()->json([
            'total'           => $store()->count(),
            // The dashboard reads these four by name, so emit them explicitly
            // rather than making the client dig through a grouped array.
            'pending'         => (int) ($byStatus['pending'] ?? 0),
            'processing'      => (int) ($byStatus['processing'] ?? 0),
            'completed'       => (int) ($byStatus['completed'] ?? 0),
            'failed'          => (int) ($byStatus['failed'] ?? 0),
            'byType'          => $store()->select('type', DB::raw('COUNT(*) as count'))
                                   ->groupBy('type')->orderByDesc('count')->limit(50)->get(),
            'deadLetterCount' => $this->dlqForTenant($tenant)->count(),
            'consumers'       => DB::table('hpbrain_consumer_state')->pluck('consumer_name'),
            'consumerStates'  => DB::table('hpbrain_consumer_state')
                                   ->select('consumer_name', 'status', 'last_processed_at')
                                   ->orderBy('consumer_name')->get(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $row = DB::table('hpbrain_event_store')
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)->first();

        return $row ? response()->json($row) : response()->json(['error' => 'event_not_found'], 404);
    }

    public function replay(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $original = DB::table('hpbrain_event_store')->where('tenant_id', $tenant)->where('id', $id)->first();

        if (! $original) {
            return response()->json(['error' => 'event_not_found'], 404);
        }

        $newId = Uuid::uuid4()->toString();

        DB::table('hpbrain_event_store')->insert([
            'id'              => $newId,
            'tenant_id'       => $tenant,
            'type'            => $original->type,
            'entity_type'     => $original->entity_type ?? null,
            'entity_id'       => $original->entity_id ?? null,
            'payload'         => $original->payload,
            'actor_id'        => $this->actorId($request),
            'correlation_id'  => $original->correlation_id ?? $original->id,
            'causation_id'    => $original->id,
            'idempotency_key' => "replay-{$original->id}-{$newId}",
            'status'          => 'pending',
            'created_at'      => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json(['ok' => true, 'replayEventId' => $newId, 'causationId' => $original->id], 202);
    }

    public function retryFailed(Request $request): JsonResponse
    {
        $n = DB::table('hpbrain_event_store')
            ->where('tenant_id', $this->tenantId($request))->where('status', 'failed')
            ->update(['status' => 'pending', 'retry_count' => DB::raw('COALESCE(retry_count, 0) + 1')]);

        return response()->json(['ok' => true, 'requeued' => $n]);
    }

    public function dlq(Request $request): JsonResponse
    {
        return response()->json(
            $this->dlqForTenant($this->tenantId($request))
                ->select('d.*', 'e.type as event_type', 'e.payload')
                ->orderByDesc('d.created_at')->limit(200)->get()
        );
    }

    public function retryDlq(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $row = $this->dlqForTenant($tenant)->select('d.*', 'e.type as event_type', 'e.payload')
            ->where('d.id', $id)->first();

        if (! $row) {
            return response()->json(['error' => 'dlq_entry_not_found'], 404);
        }

        return DB::transaction(function () use ($row, $tenant, $id) {
            $newId = Uuid::uuid4()->toString();

            DB::table('hpbrain_event_store')->insert([
                'id'              => $newId,
                'tenant_id'       => $tenant,
                'type'            => $row->event_type,
                'payload'         => $row->payload,
                'correlation_id'  => $row->id,
                'idempotency_key' => "dlq-retry-{$row->id}-{$newId}",
                'status'          => 'pending',
                'created_at'      => now()->format('Y-m-d H:i:s'),
            ]);

            DB::table('hpbrain_dead_letter_queue')->where('id', $id)->delete();

            return response()->json(['ok' => true, 'eventId' => $newId], 202);
        });
    }

    public function deleteDlq(Request $request, string $id): JsonResponse
    {
        // Confirm the entry belongs to this tenant (through its event) before
        // deleting, since the DLQ table itself carries no tenant column.
        if (! $this->dlqForTenant($this->tenantId($request))->where('d.id', $id)->exists()) {
            return response()->json(['error' => 'dlq_entry_not_found'], 404);
        }

        DB::table('hpbrain_dead_letter_queue')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    public function consumers(): JsonResponse
    {
        return response()->json(DB::table('hpbrain_consumer_state')->orderBy('consumer_name')->get());
    }
}
