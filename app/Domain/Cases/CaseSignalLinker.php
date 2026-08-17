<?php

declare(strict_types=1);

namespace App\Domain\Cases;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single writer for a case's signal relationships.
 *
 * WHY A SINGLE WRITER IS THE WHOLE POINT. The moment hpbrain_case_signals
 * existed there were two places a case's primary signal could be recorded: the
 * hpbrain_cases.signal_id column that seven readers already depend on — two of
 * them inside ExplainVerb's hypothesis join — and a junction row claiming
 * role='primary'. Nothing in the database enforces that those agree. Two
 * writers keeping two copies in step by convention is how they drift, and a
 * drifted primary means EXPLAIN reasons about one signal while every aggregate
 * view reports another. So both are written here, together, or neither is.
 *
 * THE COLUMN REMAINS AUTHORITATIVE FOR THE PRIMARY LINK. The junction mirrors
 * it; it does not replace it. That is deliberate and not a transitional state —
 * ExplainVerb is proven correct by GoldenIntelligenceFlowTest and reads the
 * column, and rewriting a verb that works in order to relocate data it already
 * has would be churn. The junction earns its place by carrying the signals the
 * column CANNOT hold: the additional ones.
 *
 * WHAT THIS CLASS REFUSES TO DO, AND WHY REFUSING IS THE FEATURE.
 *
 *   - It will not silently repoint a case's primary signal. A case whose
 *     primary already differs raises rather than quietly demoting the old one,
 *     because "which signal is this case actually about" is the fact every
 *     hypothesis on it was reasoned from. Changing it is a decision somebody
 *     should make explicitly, not a side effect of calling a linker twice.
 *   - It will not let one signal be both primary and related on the same case.
 *     That state has no meaning and can only be a caller bug.
 *   - It will not link across tenants, and it checks rather than trusting the
 *     foreign keys: hpbrain_case_signals references hpbrain_cases(id) and
 *     hpbrain_signals(id), and NEITHER constraint says anything about tenant_id.
 *     A case id paired with another organization's signal id satisfies both
 *     foreign keys perfectly.
 *
 * NO EVENT IS EMITTED. Linking a signal to a case is not a loop stage — the
 * stages are what happens INSIDE a case, and CaseController already emits
 * SubjectSelected when the container is created. Emitting here would put a
 * second, near-duplicate marker in the audit trail for the same act.
 */
final class CaseSignalLinker
{
    public const PRIMARY = 'primary';

    public const RELATED = 'related';

    /**
     * Record the signal a case is about, in both places, atomically.
     *
     * ORDER INSIDE THE TRANSACTION IS LOAD-BEARING: the column is updated first
     * and the junction row second, so a failure writing the junction rolls the
     * column back with it. The reverse order would leave the older, more widely
     * read copy as the one at risk.
     *
     * Idempotent for the same signal — re-linking the primary a case already
     * has succeeds and writes nothing new, so a caller that cannot cheaply tell
     * whether it has run before does not have to.
     *
     * @throws RuntimeException when the case or signal is unknown to this tenant,
     *                          or the case already has a DIFFERENT primary
     */
    public function linkPrimary(string $tenantId, string $caseId, string $signalId, string $actorId): void
    {
        $this->assertBelongs($tenantId, $caseId, $signalId);

        $current = $this->primarySignalId($tenantId, $caseId);

        if ($current !== null && $current !== $signalId) {
            throw new RuntimeException(
                "case_already_has_primary_signal: {$caseId} is about {$current}, refusing to repoint to {$signalId}"
            );
        }

        // Already recorded in both places: nothing to do, and saying so by
        // returning is better than writing identical rows over themselves.
        if ($current === $signalId && $this->hasLink($caseId, $signalId, self::PRIMARY)) {
            return;
        }

        DB::transaction(function () use ($tenantId, $caseId, $signalId, $actorId): void {
            DB::table('hpbrain_cases')
                ->where('tenant_id', $tenantId)
                ->where('id', $caseId)
                ->update([
                    'signal_id'    => $signalId,
                    'updated_date' => $this->now(),
                ]);

            $this->insertLink($tenantId, $caseId, $signalId, self::PRIMARY, $actorId);
        });
    }

    /**
     * Attach an additional signal to a case, junction only.
     *
     * hpbrain_cases.signal_id is deliberately NOT touched: it holds one value,
     * that value is the primary, and overwriting it here would silently change
     * what every existing reader believes the case is about. A related signal is
     * exactly the thing the column cannot express, which is why the junction
     * exists.
     *
     * No transaction, because this is one statement. Wrapping a single insert
     * would suggest a compound write to whoever reads it next.
     *
     * @throws RuntimeException when the case or signal is unknown to this tenant,
     *                          or the signal is already this case's primary
     */
    public function linkRelated(string $tenantId, string $caseId, string $signalId, string $actorId): void
    {
        $this->assertBelongs($tenantId, $caseId, $signalId);

        if ($this->primarySignalId($tenantId, $caseId) === $signalId) {
            throw new RuntimeException(
                "signal_is_already_primary: {$signalId} is what case {$caseId} is about; it cannot also be related to it"
            );
        }

        $this->insertLink($tenantId, $caseId, $signalId, self::RELATED, $actorId);
    }

    /**
     * Every signal linked to a case, primary first.
     *
     * A reader, on the writer, on purpose: the ordering and the role vocabulary
     * are this class's to define, and a caller reconstructing them from raw rows
     * would be a second place that has to agree about what 'primary' means.
     *
     * @return array<int, array{signalId: string, role: string, linkedBy: string, linkedDate: string|null}>
     */
    public function signalsFor(string $tenantId, string $caseId): array
    {
        return DB::table('hpbrain_case_signals')
            ->where('tenant_id', $tenantId)
            ->where('case_id', $caseId)
            // Primary first regardless of when it was written; 'primary' sorts
            // before 'related' alphabetically, but relying on that would break
            // silently the day a third role is added.
            ->orderByRaw("case when role = ? then 0 else 1 end", [self::PRIMARY])
            ->orderBy('linked_date')
            ->orderBy('signal_id')
            ->get()
            ->map(fn ($r): array => [
                'signalId'   => (string) $r->signal_id,
                'role'       => (string) $r->role,
                'linkedBy'   => (string) $r->linked_by,
                'linkedDate' => $r->linked_date === null ? null : (string) $r->linked_date,
            ])
            ->all();
    }

    /**
     * insertOrIgnore against PRIMARY KEY (case_id, signal_id).
     *
     * The key is the idempotency, and it also means a link's ROLE is set once by
     * whoever created it. A second call cannot flip a primary to related by
     * accident — changing a role is a promotion or demotion, which is a
     * different operation from linking and does not exist yet because nothing
     * needs it.
     */
    private function insertLink(
        string $tenantId,
        string $caseId,
        string $signalId,
        string $role,
        string $actorId,
    ): void {
        DB::table('hpbrain_case_signals')->insertOrIgnore([
            'tenant_id'   => $tenantId,
            'case_id'     => $caseId,
            'signal_id'   => $signalId,
            'role'        => $role,
            'linked_by'   => $actorId,
            'linked_date' => $this->now(),
        ]);
    }

    /**
     * Both ends exist AND both belong to this tenant.
     *
     * Checked rather than delegated to the foreign keys, which constrain
     * existence and say nothing about ownership — see the class docblock.
     *
     * @throws RuntimeException
     */
    private function assertBelongs(string $tenantId, string $caseId, string $signalId): void
    {
        $caseExists = DB::table('hpbrain_cases')
            ->where('tenant_id', $tenantId)->where('id', $caseId)->exists();

        if (! $caseExists) {
            throw new RuntimeException("case_not_found_for_tenant: {$caseId}");
        }

        $signalExists = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)->where('id', $signalId)->exists();

        if (! $signalExists) {
            throw new RuntimeException("signal_not_found_for_tenant: {$signalId}");
        }
    }

    /**
     * The case's primary signal as the AUTHORITATIVE copy records it — the
     * column, not the junction. Where the two ever disagree the column wins,
     * because it is what ExplainVerb reasons from.
     */
    private function primarySignalId(string $tenantId, string $caseId): ?string
    {
        $value = DB::table('hpbrain_cases')
            ->where('tenant_id', $tenantId)->where('id', $caseId)->value('signal_id');

        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function hasLink(string $caseId, string $signalId, string $role): bool
    {
        return DB::table('hpbrain_case_signals')
            ->where('case_id', $caseId)->where('signal_id', $signalId)->where('role', $role)->exists();
    }

    /** MySQL-legal, matching BaseRepository. Never date('c') — that emits RFC-3339. */
    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
