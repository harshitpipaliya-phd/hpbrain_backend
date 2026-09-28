<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Recommendations;

use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads and decides on HP Brain's own recommendations (hpbrain_recommendations),
 * with the explanation chain the intelligence loop already writes:
 * recommendation → hpbrain_reasoning_steps → hpbrain_recommendation_evidence →
 * hpbrain_evidence.
 *
 * Adapted from G2G's RecommendationRepository (which read these same tables in
 * place) with one deliberate change: G2G scoped with `tenant_id = X OR '*'`, so an
 * organisation could approve a platform-wide recommendation for everyone. Here
 * every read and every decision is scoped to the caller's tenant ONLY — a '*' row
 * is nobody's to decide from a tenant console.
 *
 * Ids are UUIDs, so ordering is by date; `ORDER BY id` would be alphabetical.
 */
final class RecommendationRepository
{
    private const TABLE = 'hpbrain_recommendations';

    public const DECISIONS = [
        'approve' => 'accepted',
        'reject' => 'rejected',
        'defer' => 'deferred',
    ];

    /** The loop writes 'pending' (the column default) and 'open'. */
    public const PENDING_STATUSES = ['open', 'pending'];

    public function available(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /** @return array<int, array<string, mixed>> Most confident first — a work queue. */
    public function pending(string $tenantId, int $limit = 50): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->scoped($tenantId)
            ->whereIn(DB::raw('LOWER(TRIM(status))'), self::PENDING_STATUSES)
            ->orderByDesc('confidence')
            ->orderByDesc('created_date')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->present($row))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function all(string $tenantId, ?string $status = null, int $limit = 100): array
    {
        if (! $this->available()) {
            return [];
        }

        $query = $this->scoped($tenantId);

        if ($status !== null && $status !== '') {
            $wanted = strtolower(trim($status));

            // "Pending" is the queue, and HP Brain's loop writes new
            // recommendations as 'open'. Filtering on the literal word would show
            // a Pending count (counts() adds both) above a list missing those rows.
            if ($wanted === 'pending') {
                $placeholders = implode(',', array_fill(0, count(self::PENDING_STATUSES), '?'));
                $query->whereRaw("LOWER(TRIM(status)) IN ({$placeholders})", self::PENDING_STATUSES);
            } else {
                $query->whereRaw('LOWER(TRIM(status)) = ?', [$wanted]);
            }
        }

        return $query->orderByDesc('created_date')->limit($limit)->get()
            ->map(fn ($row) => $this->present($row))
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function find(string $id, string $tenantId): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $row = $this->scoped($tenantId)->where('id', $id)->first();

        return $row === null ? null : $this->present($row);
    }

    /** @return array<string, mixed> */
    public function explain(string $id, string $tenantId): array
    {
        $recommendation = $this->find($id, $tenantId);

        if ($recommendation === null) {
            return ['recommendation' => null, 'reasoning' => null, 'evidence' => []];
        }

        return [
            'recommendation' => $recommendation,
            'reasoning' => $this->reasoningStep($recommendation['reasoning_step_id'], $tenantId),
            'evidence' => $this->evidence($id, $tenantId),
        ];
    }

    /**
     * Record a decision. Only a pending recommendation can be decided; the
     * conditional UPDATE re-checks the status so two administrators deciding at
     * once cannot both succeed.
     *
     * @return array{ok: bool, message: string, status?: string}
     */
    public function decide(string $id, string $tenantId, string $decision): array
    {
        if (! array_key_exists($decision, self::DECISIONS)) {
            return ['ok' => false, 'message' => "\"{$decision}\" is not a decision."];
        }

        $row = $this->scoped($tenantId)->where('id', $id)->first();

        if ($row === null) {
            return ['ok' => false, 'message' => 'That recommendation was not found.'];
        }

        $current = strtolower(trim((string) $row->status));

        if (! in_array($current, self::PENDING_STATUSES, true)) {
            return [
                'ok' => false,
                'message' => sprintf('This recommendation is already %s. Only an open recommendation can be decided.', $current),
            ];
        }

        $next = self::DECISIONS[$decision];

        $changed = DB::table(self::TABLE)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->whereIn(DB::raw('LOWER(TRIM(status))'), self::PENDING_STATUSES)
            ->update(['status' => $next, 'updated_date' => Platform::now()]);

        if ($changed === 0) {
            return ['ok' => false, 'message' => 'This recommendation was decided by someone else a moment ago. Reload to see its status.'];
        }

        return ['ok' => true, 'message' => 'Decision recorded.', 'status' => $next];
    }

    /** @return array{total:int, pending:int, accepted:int, rejected:int, deferred:int} */
    public function counts(string $tenantId): array
    {
        if (! $this->available()) {
            return ['total' => 0, 'pending' => 0, 'accepted' => 0, 'rejected' => 0, 'deferred' => 0];
        }

        $rows = $this->scoped($tenantId)
            ->selectRaw('LOWER(TRIM(status)) AS s, COUNT(*) AS c')
            ->groupBy(DB::raw('LOWER(TRIM(status))'))
            ->pluck('c', 's');

        return [
            'total' => (int) $rows->sum(),
            'pending' => (int) $rows->get('open', 0) + (int) $rows->get('pending', 0),
            'accepted' => (int) $rows->get('accepted', 0),
            'rejected' => (int) $rows->get('rejected', 0),
            'deferred' => (int) $rows->get('deferred', 0),
        ];
    }

    /** @return array<string, mixed>|null */
    private function reasoningStep(?string $stepId, string $tenantId): ?array
    {
        if ($stepId === null || $stepId === '' || ! Schema::hasTable('hpbrain_reasoning_steps')) {
            return null;
        }

        $row = DB::table('hpbrain_reasoning_steps')
            ->where('id', $stepId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'description' => (string) ($row->description ?? ''),
            'step_order' => ($row->step_order ?? null) === null ? null : (int) $row->step_order,
            'confidence' => ($row->confidence_score ?? null) === null ? null : (float) $row->confidence_score,
            'case_id' => ($row->case_id ?? null) === null ? null : (string) $row->case_id,
            'signal_id' => ($row->signal_id ?? null) === null ? null : (string) $row->signal_id,
            'created_date' => ($row->created_date ?? null) === null ? null : (string) $row->created_date,
        ];
    }

    /** @return array<int, array<string, mixed>> Content truncated — this is a list view. */
    private function evidence(string $recommendationId, string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_recommendation_evidence') || ! Schema::hasTable('hpbrain_evidence')) {
            return [];
        }

        $observed = Schema::hasColumn('hpbrain_evidence', 'observed_date') ? 'observed_date' : 'created_date';

        return DB::table('hpbrain_recommendation_evidence as link')
            ->join('hpbrain_evidence as e', function ($join) {
                $join->on('e.id', '=', 'link.evidence_id')->on('e.tenant_id', '=', 'link.tenant_id');
            })
            ->where('link.recommendation_id', $recommendationId)
            ->where('link.tenant_id', $tenantId)
            ->orderByDesc("e.{$observed}")
            ->limit(25)
            ->get([
                'e.id', 'e.evidence_type', 'e.source', 'e.content',
                'e.confidence', 'e.status', "e.{$observed} as observed_date",
            ])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'evidence_type' => (string) ($row->evidence_type ?? ''),
                'source' => (string) ($row->source ?? ''),
                'content' => mb_substr((string) ($row->content ?? ''), 0, 600),
                'confidence' => $row->confidence === null ? null : (float) $row->confidence,
                'status' => (string) ($row->status ?? ''),
                'observed_date' => $row->observed_date === null ? null : (string) $row->observed_date,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $status = strtolower(trim((string) ($row->status ?? '')));

        return [
            'id' => (string) $row->id,
            'title' => (string) ($row->title ?? ''),
            'description' => (string) ($row->description ?? ''),
            'category' => (string) ($row->category ?? ''),
            'priority' => (string) ($row->priority ?? ''),
            'urgency' => (string) ($row->urgency ?? ''),
            'confidence' => ($row->confidence ?? null) === null ? null : (float) $row->confidence,
            'expected_roi' => ($row->expected_roi ?? null) === null ? null : (float) $row->expected_roi,
            'impact' => (string) ($row->impact ?? ''),
            'cost' => (string) ($row->cost ?? ''),
            'risk' => (string) ($row->risk ?? ''),
            'status' => $status,
            'is_pending' => in_array($status, self::PENDING_STATUSES, true),
            'reasoning_step_id' => ($row->reasoning_step_id ?? null) === null ? null : (string) $row->reasoning_step_id,
            'created_date' => ($row->created_date ?? null) === null ? null : (string) $row->created_date,
            'updated_date' => ($row->updated_date ?? null) === null ? null : (string) $row->updated_date,
        ];
    }

    /** The one place the tenant filter is written — this tenant only, never '*'. */
    private function scoped(string $tenantId): Builder
    {
        return DB::table(self::TABLE)->where('tenant_id', $tenantId);
    }
}
