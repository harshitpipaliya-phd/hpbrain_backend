<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Knowledge\KnowledgeLibraryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class KnowledgeLibraryController extends Controller
{
    private const TABLE = 'hpbrain_knowledge_assets';

    /**
     * The shelf: one page of one filter, graded.
     *
     * TENANT SCOPE COMES FROM THE RESOLVED ATTRIBUTE, NOT THE PATH. The
     * {tenantId} segment is what the caller asked for; $this->tenantId() is
     * what EnsureTenantScope decided they may have. Only the second is used.
     */
    public function index(Request $request, KnowledgeLibraryService $service): JsonResponse
    {
        return response()->json($service->list($this->tenantId($request), [
            'q' => $request->query('q'),
            'category' => $request->query('category'),
            'department' => $request->query('department'),
            'owner' => $request->query('owner'),
            'status' => $request->query('status'),
            'freshness' => $request->query('freshness'),
            'provenance' => $request->query('provenance'),
            'hasEvidence' => $request->boolean('hasEvidence') ?: null,
            'sort' => $request->query('sort'),
            'page' => $request->query('page'),
            'pageSize' => $request->query('pageSize'),
        ]));
    }

    /** The counters above the shelf, and the filter vocabulary this tenant holds. */
    public function summary(Request $request, KnowledgeLibraryService $service): JsonResponse
    {
        return response()->json($service->summary($this->tenantId($request)));
    }

    public function show(Request $request, string $tenantId, string $id, KnowledgeLibraryService $service): JsonResponse
    {
        $payload = $service->detail($this->tenantId($request), $id);

        return $payload
            ? response()->json($payload)
            : response()->json(['error' => 'knowledge_asset_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:1'],
            'content' => ['required', 'string', 'min:1'],
            'category' => ['nullable', 'string'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        /*
            A NEW ASSET STARTS UNSCORED, NOT CERTAIN.

            The column is NOT NULL and defaults to 1.0000, so an asset created
            through this endpoint — which has never carried a confidence field —
            was stored at full confidence and graded CONFIRMED the moment it was
            reused once. Nobody assessed it. Writing 0 explicitly makes it grade
            as UNDETERMINED until somebody does.
        */
        return response()->json($this->insertRow($request, [
            'title' => $data['title'],
            'content' => $data['content'],
            'category' => $data['category'] ?? null,
            'confidence' => $data['confidence'] ?? 0,
            'reuse_count' => 0,
            'status' => 'active',
        ]), 201);
    }

    /**
     * Kept for the existing contract, now answered by the same builder the
     * shelf uses — so a search and a filter compose instead of the search
     * silently discarding whatever the reader had narrowed to.
     */
    public function search(Request $request, KnowledgeLibraryService $service): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return response()->json(['items' => [], 'page' => 1, 'pageSize' => 0, 'total' => 0, 'pages' => 0]);
        }

        return response()->json($service->list($this->tenantId($request), [
            'q' => $term,
            'category' => $request->query('category'),
            'page' => $request->query('page'),
            'pageSize' => $request->query('pageSize'),
        ]));
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
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => $this->tenantId($request),
            'created_by' => $this->actorId($request),
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        DB::table(self::TABLE)->insert($row);

        return $row;
    }
}
