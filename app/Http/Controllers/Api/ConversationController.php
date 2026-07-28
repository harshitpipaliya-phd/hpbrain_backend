<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_conversation_sessions')->where('tenant_id', $this->tenantId($request))
                ->orderByDesc('pinned')->orderByDesc('updated_date')->limit(200)->get()
        );
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return response()->json([]);
        }

        return response()->json(
            DB::table('hpbrain_conversation_sessions')->where('tenant_id', $this->tenantId($request))
                ->where('title', 'like', "%{$term}%")->limit(100)->get()
        );
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = DB::table('hpbrain_conversation_sessions')
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)->first();

        return $row ? response()->json($row) : response()->json(['error' => 'session_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'min:1', 'max:255']]);
        $now = now()->format('Y-m-d H:i:s');

        $row = [
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => $this->tenantId($request),
            'title' => $data['title'], 'pinned' => 0, 'created_by' => $this->actorId($request),
            'created_date' => $now, 'updated_date' => $now,
        ];

        DB::table('hpbrain_conversation_sessions')->insert($row);

        return response()->json($row, 201);
    }

    public function messages(Request $request, string $tenantId, string $id): JsonResponse
    {
        if (! $this->sessionExists($request, $id)) {
            return response()->json(['error' => 'session_not_found'], 404);
        }

        return response()->json(
            DB::table('hpbrain_conversation_messages')
                ->where('tenant_id', $this->tenantId($request))->where('session_id', $id)
                ->orderBy('created_date')->get()
        );
    }

    public function sendMessage(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'min:1'],
            'role'    => ['nullable', 'in:user,assistant,system'],
        ]);

        if (! $this->sessionExists($request, $id)) {
            return response()->json(['error' => 'session_not_found'], 404);
        }

        $now = now()->format('Y-m-d H:i:s');
        $tenant = $this->tenantId($request);

        $row = [
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => $tenant, 'session_id' => $id,
            'role' => $data['role'] ?? 'user', 'content' => $data['content'],
            'created_by' => $this->actorId($request), 'created_date' => $now,
        ];

        DB::transaction(function () use ($row, $tenant, $id, $now) {
            DB::table('hpbrain_conversation_messages')->insert($row);
            // Keep the session's ordering key current, or the list view drifts
            // out of recency order as soon as anyone replies to an old thread.
            DB::table('hpbrain_conversation_sessions')
                ->where('tenant_id', $tenant)->where('id', $id)
                ->update(['updated_date' => $now]);
        });

        return response()->json($row, 201);
    }

    public function setPinned(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate(['pinned' => ['required', 'boolean']]);

        $n = DB::table('hpbrain_conversation_sessions')
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)
            ->update(['pinned' => $data['pinned'] ? 1 : 0]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'session_not_found'], 404);
    }

    public function rename(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'min:1', 'max:255']]);

        $n = DB::table('hpbrain_conversation_sessions')
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)
            ->update(['title' => $data['title'], 'updated_date' => now()->format('Y-m-d H:i:s')]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'session_not_found'], 404);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->tenantId($request);

        return DB::transaction(function () use ($tenant, $id) {
            // Messages first — orphaned message rows would otherwise accumulate
            // invisibly, since nothing lists messages without a session.
            DB::table('hpbrain_conversation_messages')
                ->where('tenant_id', $tenant)->where('session_id', $id)->delete();

            $n = DB::table('hpbrain_conversation_sessions')
                ->where('tenant_id', $tenant)->where('id', $id)->delete();

            return $n
                ? response()->json(['ok' => true])
                : response()->json(['error' => 'session_not_found'], 404);
        });
    }

    public function promptTemplates(Request $request): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_prompt_templates')->where('tenant_id', $this->tenantId($request))
                ->orderBy('name')->get()
        );
    }

    public function storePromptTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'min:1', 'max:190'],
            'template' => ['required', 'string', 'min:1'],
            'version'  => ['nullable', 'string', 'max:50'],
        ]);

        $now = now()->format('Y-m-d H:i:s');

        // Prompts are versioned artifacts (ADR-004): changing one is a reviewed,
        // version-bumped change, never an in-place edit.
        $row = [
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => $this->tenantId($request),
            'name' => $data['name'], 'template' => $data['template'],
            'version' => $data['version'] ?? 'v1',
            'created_by' => $this->actorId($request), 'created_date' => $now,
        ];

        DB::table('hpbrain_prompt_templates')->insert($row);

        return response()->json($row, 201);
    }

    private function sessionExists(Request $request, string $id): bool
    {
        return DB::table('hpbrain_conversation_sessions')
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)->exists();
    }
}
