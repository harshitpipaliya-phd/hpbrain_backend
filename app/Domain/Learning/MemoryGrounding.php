<?php

declare(strict_types=1);

namespace App\Domain\Learning;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Services\RetrievalService;
use Illuminate\Support\Facades\DB;

/**
 * Organizational Memory grounding (ADR-005) — the flywheel.
 *
 * ADR-005 calls this "the most important architectural decision in the suite",
 * and it was absent from both prior builds: learnings were written, listed, even
 * clustered into patterns — and then never read back into reasoning. A system
 * that writes learnings it never consults is a clever one-shot advisor, not a
 * compounding asset, and the moat does not exist.
 *
 * WHY THIS FILE WAS REWRITTEN. KNOWN_LIMITATIONS #6 described this class as
 * "implemented and correct", needing only to be called. It was not correct: it
 * disagreed with the schema in three places and threw SQLSTATE 42S22 on every
 * path, which is how we know it had never once executed —
 *
 *   1. retrieveFor() filtered on hpbrain_learnings.domain, a column that did
 *      not exist until 2026_07_29_000200_learning_domain.
 *   2. recordGrounding() inserted `event_type` and `created_date` into
 *      hpbrain_event_store, whose columns are `type` and `created_at`, and
 *      omitted actor_id, which is NOT NULL. Its idempotency key was also 60+
 *      characters against a VARCHAR(36) UNIQUE column, so MySQL would have
 *      truncated unrelated groundings into collisions and silently discarded
 *      them under the unique index.
 *   3. compoundingStats() queried the same non-existent `event_type`, so the
 *      one metric that answers "is memory compounding?" could never return.
 *
 * The lesson worth keeping: a class with no call sites has no schema contract.
 * "Reviewed and correct" is not the same claim as "executed once". The event
 * write is now delegated to EventPublisher — one writer to the event store
 * means one place the column names can be got wrong.
 */
final class MemoryGrounding
{
    public function __construct(
        private readonly EventPublisher $events,
        private readonly RetrievalService $retrieval,
    ) {
    }

    /**
     * Retrieve prior learnings that bear on a question, most confident first.
     *
     * Only REUSABLE learnings ground future reasoning: a failed outcome is
     * recorded so the organization learns from it, and is never offered back as
     * a pattern to repeat (ADR-005).
     *
     * The domain filter is `domain = ? OR domain IS NULL`, deliberately not a
     * bare equality. A learning with no domain is CROSS-domain — it applies
     * everywhere — so a bare equality would hide every general lesson the moment
     * a caller named a domain, which is exactly when general lessons matter
     * most.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retrieveFor(string $tenantId, ?string $domain = null, int $limit = 10): array
    {
        $q = DB::table('hpbrain_learnings')
            ->where('tenant_id', $tenantId)
            ->where('reusable', 1);

        if ($domain !== null && $domain !== '') {
            $q->where(fn ($w) => $w->where('domain', $domain)->orWhereNull('domain'));
        }

        return $q->orderByDesc('confidence')->orderByDesc('created_date')
            ->limit($limit)->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Record that a learning actually influenced a result — the traceability
     * ADR-005 requires, and what makes the memory-reuse KPI real rather than
     * notional.
     *
     * ENTITY IS THE LEARNING, not the thing being grounded, and that is a
     * change from the original. EventPublisher derives the idempotency key from
     * (type, tenant, entityType, entityId), so keying the event on the grounded
     * entity would collapse "signal X was grounded on learnings A, B and C"
     * into a single event and lose two thirds of the record. Keying on the
     * learning gives one event per learning, which is also what makes
     * compoundingStats' ratio bounded and meaningful: distinct entity_id over
     * reusable learnings is "how much of memory has ever been used", where the
     * old shape counted grounded entities and could exceed 1.
     *
     * The consequence, stated plainly: a learning grounded twice produces one
     * event, so groundingEvents counts learnings-ever-reused, not total reuses.
     * Per-use counting needs a key the publisher does not currently expose.
     *
     * The grounded entity travels in the payload and in correlation_id, so the
     * thread still reads "this grounding belongs to that case".
     */
    public function recordGrounding(
        string $tenantId,
        string $learningId,
        string $groundedEntityType,
        string $groundedEntityId,
        string $actorId,
        ?string $correlationId = null,
    ): void {
        // The original signature had no actor at all, which is the fourth
        // disagreement with the schema: actor_id is NOT NULL, so this method
        // could not have satisfied it without an API change.
        $this->events->emit(
            LoopEvent::LEARNING_GROUNDED,
            $tenantId,
            'Learning',
            $learningId,
            $actorId,
            [
                'learningId'         => $learningId,
                'groundedEntityType' => $groundedEntityType,
                'groundedEntityId'   => $groundedEntityId,
            ],
            correlationId: $correlationId ?? $groundedEntityId,
        );
    }

    /**
     * Retrieve, record, return — the callable VerbPipeline expects as $ground.
     *
     * Recording happens here rather than being left to the caller because a
     * retrieval that is not recorded is invisible to the reuse metric, and the
     * metric is the only evidence that the flywheel is turning.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groundAndRecord(
        string $tenantId,
        string $entityType,
        string $entityId,
        string $actorId,
        ?string $domain = null,
        ?string $correlationId = null,
    ): array {
        $learnings = $this->retrieveFor($tenantId, $domain);

        foreach ($learnings as $learning) {
            $this->recordGrounding(
                $tenantId,
                (string) $learning['id'],
                $entityType,
                $entityId,
                $actorId,
                $correlationId ?? $entityId,
            );
        }

        return $learnings;
    }

    /**
     * The semantic counterpart to retrieveFor() above — this is the piece of
     * MemoryGrounding this class's own "bridging class between learning and
     * RAG" role actually needed and did not yet have. retrieveFor() ranks by
     * DOMAIN EQUALITY (plus the cross-domain NULL rule) and confidence; that
     * is the right shape for the five implemented verbs, which always have a
     * domain in hand (a signal's classification, a capability's category)
     * and never a free-text question. A caller asking "what have we learned
     * about X", in X's own words, has no domain to equate against — it needs
     * RetrievalService::searchMemory()'s real embedding-based retrieval
     * (keyword-scored fallback when embeddings are unavailable, exactly as
     * searchMemory() itself documents), ranked by MEANING rather than by a
     * column match.
     *
     * ADR-005's REUSABLE-ONLY RULE IS ENFORCED HERE TOO, even though
     * searchMemory() itself has no opinion on it — that method ranks every
     * learning in hpbrain_learnings regardless of the flag, because it also
     * serves KnowledgeAskController, which is answering a different question
     * ("what does this org know", failures included) and is right to. This
     * method is answering the SAME question retrieveFor() answers — "what
     * may ground new reasoning" — so it cross-checks every ranked id against
     * `reusable = 1` before returning it, the same floor retrieveFor() never
     * crosses.
     *
     * ADDITIVE, NOT A REPLACEMENT. retrieveFor()/groundAndRecord() — what
     * ExplainVerb, AssessVerb, EvaluateVerb, RecommendVerb and CoachVerb all
     * call — are untouched by this method and do not call it. None of those
     * five verbs ask a free-text question; this is for the caller that does
     * (LearningController::ask()).
     *
     * @return array<int, array{id:string,type:string,content:string,score:float,retrieval:string}>
     */
    public function retrieveSemantic(string $tenantId, string $query, int $limit = 10): array
    {
        $ranked = array_values(array_filter(
            $this->retrieval->searchMemory($tenantId, $query),
            static fn (array $r): bool => ($r['type'] ?? null) === 'memory',
        ));

        if ($ranked === []) {
            return [];
        }

        $rankedIds = array_column($ranked, 'id');

        $reusableIds = DB::table('hpbrain_learnings')
            ->where('tenant_id', $tenantId)
            ->where('reusable', 1)
            ->whereIn('id', $rankedIds)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $reusable = array_values(array_filter(
            $ranked,
            static fn (array $r): bool => in_array((string) $r['id'], $reusableIds, true),
        ));

        return array_slice($reusable, 0, $limit);
    }

    /**
     * Is memory compounding? Learnings written versus learnings actually reused
     * in later grounding (EB-DP Ch.15 KPI).
     *
     * @return array{learningsWritten:int, learningsReusable:int, groundingEvents:int, reuseRate:float|null}
     */
    public function compoundingStats(string $tenantId): array
    {
        $written = DB::table('hpbrain_learnings')->where('tenant_id', $tenantId)->count();

        $reusable = DB::table('hpbrain_learnings')
            ->where('tenant_id', $tenantId)->where('reusable', 1)->count();

        $grounded = DB::table('hpbrain_event_store')
            ->where('tenant_id', $tenantId)
            ->where('type', LoopEvent::LEARNING_GROUNDED->value)
            ->distinct()->count('entity_id');

        return [
            'learningsWritten'  => $written,
            'learningsReusable' => $reusable,
            'groundingEvents'   => $grounded,
            // NULL rather than 0.0 when nothing is reusable. No denominator is
            // not the same claim as a zero reuse rate, and a dashboard that
            // renders 0% for "nothing to reuse yet" reports a failure that has
            // not happened.
            'reuseRate'         => $reusable > 0 ? round($grounded / $reusable, 4) : null,
        ];
    }
}
