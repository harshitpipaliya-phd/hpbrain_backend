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
    public function stats(Request $request): JsonResponse
    {
        $tenant = $this->tenantId($request);

        return response()->json([
            'total'      => DB::table('hpbrain_event_store')->where('tenant_id', $tenant)->count(),
            'byType'     => DB::table('hpbrain_event_store')->where('tenant_id', $tenant)
                              ->select('event_type', DB::raw('COUNT(*) as count'))
                              ->groupBy('event_type')->orderByDesc('count')->limit(50)->get(),
            'deadLetter' => DB::table('hpbrain_dead_letter_queue')->where('tenant_id', $tenant)->count(),
            'consumers'  => DB::table('hpbrain_consumer_state')->count(),
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
            'event_type'      => $original->event_type,
            'entity_type'     => $original->entity_type ?? null,
            'entity_id'       => $original->entity_id ?? null,
            'payload'         => $original->payload,
            'actor_id'        => $this->actorId($request),
            'correlation_id'  => $original->correlation_id ?? $original->id,
            'causation_id'    => $original->id,
            'idempotency_key' => "replay-{$original->id}-{$newId}",
            'status'          => 'pending',
            'created_date'    => now()->format('Y-m-d H:i:s'),
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
            DB::table('hpbrain_dead_letter_queue')->where('tenant_id', $this->tenantId($request))
                ->orderByDesc('created_date')->limit(200)->get()
        );
    }

    public function retryDlq(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $row = DB::table('hpbrain_dead_letter_queue')->where('tenant_id', $tenant)->where('id', $id)->first();

        if (! $row) {
            return response()->json(['error' => 'dlq_entry_not_found'], 404);
        }

        return DB::transaction(function () use ($row, $tenant, $id) {
            $newId = Uuid::uuid4()->toString();

            DB::table('hpbrain_event_store')->insert([
                'id'              => $newId,
                'tenant_id'       => $tenant,
                'event_type'      => $row->event_type,
                'payload'         => $row->payload,
                'correlation_id'  => $row->id,
                'idempotency_key' => "dlq-retry-{$row->id}-{$newId}",
                'status'          => 'pending',
                'created_date'    => now()->format('Y-m-d H:i:s'),
            ]);

            DB::table('hpbrain_dead_letter_queue')->where('tenant_id', $tenant)->where('id', $id)->delete();

            return response()->json(['ok' => true, 'eventId' => $newId], 202);
        });
    }

    public function deleteDlq(Request $request, string $id): JsonResponse
    {
        $n = DB::table('hpbrain_dead_letter_queue')
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)->delete();

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'dlq_entry_not_found'], 404);
    }

    public function consumers(): JsonResponse
    {
        return response()->json(DB::table('hpbrain_consumer_state')->orderBy('consumer_name')->get());
    }
}
