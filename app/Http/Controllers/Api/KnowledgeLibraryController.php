<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class KnowledgeLibraryController extends Controller
{
    private const TABLE = 'hpbrain_knowledge_assets';

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

        return $row ? response()->json($row) : response()->json(['error' => 'knowledge_asset_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'    => ['required', 'string', 'min:1'],
            'content'  => ['required', 'string', 'min:1'],
            'category' => ['nullable', 'string'],
        ]);

        return response()->json($this->insertRow($request, [
            'title'       => $data['title'],
            'content'     => $data['content'],
            'category'    => $data['category'] ?? null,
            'reuse_count' => 0,
            'status'      => 'active',
        ]), 201);
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return response()->json([]);
        }

        return response()->json(
            DB::table(self::TABLE)->where('tenant_id', $this->tenantId($request))
                ->where(fn ($w) => $w->where('title', 'like', "%{$term}%")
                                     ->orWhere('content', 'like', "%{$term}%"))
                ->limit(100)->get()
        );
    }

    /**
     * Reuse is counted, not merely flagged. "Is our knowledge base actually
     * being used?" should be answerable from data, not from opinion.
     */
    public function markReused(Request $request, string $tenantId, string $id): JsonResponse
    {
        $n = DB::table(self::TABLE)
            ->where('tenant_id', $this->tenantId($request))->where('id', $id)
            ->update(['reuse_count' => DB::raw('COALESCE(reuse_count, 0) + 1')]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'knowledge_asset_not_found'], 404);
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
