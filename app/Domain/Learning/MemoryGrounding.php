<?php

declare(strict_types=1);

namespace App\Domain\Learning;

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
 * This class closes the loop. retrieveFor() is called during the grounding step
 * of a verb, so the NEXT signal is interpreted against a smarter organization.
 * Reuse is recorded, which makes "is memory actually compounding?" a measurable
 * question rather than an article of faith.
 */
final class MemoryGrounding
{
    /**
     * Retrieve prior learnings relevant to a domain, most confident first.
     * Only reusable learnings ground future reasoning — a failed outcome is
     * recorded so the loop learns from it, but it is never offered as a pattern
     * to repeat.
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
     * Record that a learning actually influenced a result. This is the
     * traceability ADR-005 requires — being able to answer "which learning
     * shaped this decision?" — and it is what makes the memory-reuse KPI real
     * rather than notional.
     */
    public function recordGrounding(string $tenantId, string $learningId, string $groundedEntityType, string $groundedEntityId): void
    {
        DB::table('hpbrain_event_store')->insert([
            'id'              => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'tenant_id'       => $tenantId,
            'event_type'      => 'LearningGrounded',
            'entity_type'     => $groundedEntityType,
            'entity_id'       => $groundedEntityId,
            'payload'         => json_encode(['learningId' => $learningId]),
            'idempotency_key' => "grounded-{$learningId}-{$groundedEntityType}-{$groundedEntityId}",
            'status'          => 'pending',
            'created_date'    => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Is memory compounding? Counts learnings written versus learnings actually
     * reused in later grounding (EB-DP Ch.15 KPI).
     */
    public function compoundingStats(string $tenantId): array
    {
        $written = DB::table('hpbrain_learnings')->where('tenant_id', $tenantId)->count();
        $reusable = DB::table('hpbrain_learnings')->where('tenant_id', $tenantId)->where('reusable', 1)->count();
        $grounded = DB::table('hpbrain_event_store')
            ->where('tenant_id', $tenantId)->where('event_type', 'LearningGrounded')
            ->distinct()->count('entity_id');

        return [
            'learningsWritten'  => $written,
            'learningsReusable' => $reusable,
            'groundingEvents'   => $grounded,
            // Null rather than 0 when nothing is reusable yet — no denominator
            // is not the same claim as a zero reuse rate.
            'reuseRate'         => $reusable > 0 ? round($grounded / $reusable, 4) : null,
        ];
    }
}
