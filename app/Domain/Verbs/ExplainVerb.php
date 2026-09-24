<?php

declare(strict_types=1);

namespace App\Domain\Verbs;

use App\Domain\Authorization\Permission;
use App\Domain\Authorization\Role;
use App\Domain\Learning\MemoryGrounding;
use App\Domain\Undetermined\VerbResult;
use Illuminate\Support\Facades\DB;

/**
 * EXPLAIN, wired end to end through VerbPipeline.
 *
 * WHY THIS VERB FIRST, AND WHY IT CONTAINS NO LLM CALL. EXPLAIN assembles a
 * structured frame out of rows the Brain already holds — evidence, hypotheses,
 * prior learnings — and needs no model to do it. Wiring it proves the pipeline,
 * the grounding step and the sufficiency gate for free; when a provider is
 * added later, that is one new thing to debug rather than two.
 *
 * WHY A SERVICE AND NOT A CONTROLLER METHOD. The three callables need database
 * access and no HTTP at all. Living here they are unit-testable without a
 * request, reusable by the ASSESS sibling, and out of a controller that already
 * owns reasoning-step creation. The controller keeps what is genuinely HTTP:
 * reading the actor, catching governance denial, returning the VerbResult.
 *
 * THE FRAME IS DERIVED, NEVER INVENTED. Every one of the seven UODM questions
 * below is answered from a real row or left unanswered. An unanswered question
 * makes SufficiencyCheck return UNDETERMINED naming it, which is the honest
 * result — a frame padded with plausible strings would turn "we do not know"
 * into a decision, which is the precise failure UNDETERMINED exists to prevent.
 */
final class ExplainVerb
{
    public function __construct(
        private readonly VerbPipeline $pipeline,
        private readonly MemoryGrounding $memory,
    ) {
    }

    /**
     * @param  string  $role  the caller's role name, from the token
     */
    public function run(string $tenantId, string $signalId, string $actorId, string $role): VerbResult
    {
        $signal = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)->where('id', $signalId)->first();

        return $this->pipeline->run(
            Verb::EXPLAIN,
            fn () => $this->governance($role),
            fn () => $this->ground($tenantId, $signalId, $actorId, $signal),
            fn (array $grounding) => $this->reason($tenantId, $signal, $grounding),
        );
    }

    /**
     * Authorization for the verb itself, distinct from the route's permission
     * gate. EXPLAIN is read-oriented (Verb::isReadOnly), so it requires READ —
     * and, like RequirePermission, an unrecognised role is denied rather than
     * waved through.
     *
     * @return array{allowed: bool, reason: string}
     */
    private function governance(string $role): array
    {
        $resolved = Role::tryFromName($role);

        if ($resolved === null) {
            return ['allowed' => false, 'reason' => 'unknown_role'];
        }

        if (! $resolved->grants(Permission::READ)) {
            return ['allowed' => false, 'reason' => 'read_permission_required'];
        }

        return ['allowed' => true, 'reason' => 'permitted'];
    }

    /**
     * Grounding is evidence AND memory, both.
     *
     * Prior learnings alone are not grounding for a SPECIFIC question — they
     * are what the organization knows in general, and answering a particular
     * question from them alone is how a system starts confidently repeating
     * itself. Evidence alone is grounding without memory, which is the
     * one-shot advisor ADR-005 rejects.
     *
     * Every row is normalised to carry an `id`, because VerbPipeline builds
     * evidenceRefs with array_column($grounding, 'id') and a row without one
     * would silently vanish from the answer's audit trail.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ground(string $tenantId, string $signalId, string $actorId, ?object $signal): array
    {
        $evidence = DB::table('hpbrain_evidence')
            ->where('tenant_id', $tenantId)
            ->where('signal_id', $signalId)
            ->orderByDesc('confidence')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'kind' => 'evidence', 'row' => (array) $r])
            ->all();

        // The signal's classification decides which wedge of memory is
        // relevant; with no signal there is no domain, and retrieveFor() then
        // returns everything reusable rather than nothing.
        $learnings = $this->memory->groundAndRecord(
            $tenantId,
            'Signal',
            $signalId,
            $actorId,
            $signal->classification ?? null,
            $signalId,
        );

        $learnings = array_map(
            fn (array $l) => ['id' => $l['id'], 'kind' => 'learning', 'row' => $l],
            $learnings
        );

        return array_merge($evidence, $learnings);
    }

    /**
     * Assemble the UODM frame. No model, no generation — every value is read
     * off a row or left null.
     *
     * @param  array<int, array<string, mixed>>  $grounding
     * @return array{frame: array<string, mixed>, value: array<string, mixed>, confidence: float|null}
     */
    private function reason(string $tenantId, ?object $signal, array $grounding): array
    {
        $evidence  = array_values(array_filter($grounding, fn ($g) => $g['kind'] === 'evidence'));
        $learnings = array_values(array_filter($grounding, fn ($g) => $g['kind'] === 'learning'));

        // The leading hypothesis across every case attached to this signal.
        // Highest confidence, never a rejected one: a rejected hypothesis is a
        // thing we have already decided is not the explanation.
        $hypothesis = $signal === null ? null : DB::table('hpbrain_hypotheses as h')
            ->join('hpbrain_cases as c', 'c.id', '=', 'h.case_id')
            ->where('h.tenant_id', $tenantId)
            ->where('c.signal_id', $signal->id)
            ->where('h.status', '!=', 'rejected')
            ->orderByDesc('h.confidence')
            ->select('h.*')
            ->first();

        $alternatives = $hypothesis === null ? [] : DB::table('hpbrain_hypotheses as h')
            ->join('hpbrain_cases as c', 'c.id', '=', 'h.case_id')
            ->where('h.tenant_id', $tenantId)
            ->where('c.signal_id', $signal->id)
            ->where('h.id', '!=', $hypothesis->id)
            ->pluck('h.root_cause_family')->unique()->values()->all();

        $evidenceIds = array_column($evidence, 'id');

        $frame = [
            // What changed: the signal is by definition the report of a change.
            'what_changed' => $signal === null ? null : sprintf(
                '%s signal from %s', (string) $signal->classification, (string) $signal->source
            ),

            // Who is affected: the entity the signal points at, falling back to
            // the department and then the organization. Never guessed — if all
            // three are absent this stays null and the verb says so.
            'who_is_affected' => $this->affectedParty($signal),

            // When it started: the earliest observation we hold, not the time
            // the signal was filed — those differ, and the second is when
            // somebody noticed rather than when it began.
            'when_did_it_start' => $this->earliestObservation($evidence, $signal),

            // How large the gap is: the signal's own severity and the strength
            // of what corroborates it. Two numbers off two rows.
            'how_large_is_the_gap' => $signal === null ? null : [
                'severity'           => $signal->severity ?? null,
                'signalConfidence'   => $signal->confidence === null ? null : (float) $signal->confidence,
                'corroboratingCount' => count($evidence),
            ],

            'what_evidence_supports_it' => $evidenceIds,

            // What would falsify it: the evidence this explanation depends on
            // (retract it and the explanation fails) plus the rival root-cause
            // families on the same signal. Null when no hypothesis exists,
            // because an explanation nobody has stated cannot be falsified —
            // and UNDETERMINED naming this gap is the correct answer to
            // "explain this" when nothing has been hypothesised yet.
            'what_would_falsify_it' => $hypothesis === null ? null : [
                'dependent_evidence_ids' => $this->supportingEvidenceIds($hypothesis) ?: $evidenceIds,
                'alternative_families'   => $alternatives,
            ],

            'what_is_the_root_cause_family' => $hypothesis->root_cause_family ?? null,
        ];

        return [
            'frame' => $frame,
            'value' => [
                'signalId'    => $signal->id ?? null,
                'summary'     => $frame['what_changed'],
                'rootCause'   => $frame['what_is_the_root_cause_family'],
                'hypothesisId' => $hypothesis->id ?? null,
                'evidenceCount' => count($evidence),
                // Named, so a reader can click through to why this answer looks
                // the way it does (Principle 1).
                'groundedOnLearnings' => array_column($learnings, 'id'),
            ],
            'confidence' => $hypothesis === null ? null : (float) $hypothesis->confidence,
        ];
    }

    private function affectedParty(?object $signal): ?string
    {
        if ($signal === null) {
            return null;
        }

        if (! empty($signal->related_entity_id)) {
            return sprintf('%s:%s', (string) ($signal->related_entity_type ?? 'entity'), (string) $signal->related_entity_id);
        }

        if (! empty($signal->department_id)) {
            return 'department:'.(string) $signal->department_id;
        }

        return empty($signal->org_id) ? null : 'organization:'.(string) $signal->org_id;
    }

    /** @param array<int, array<string, mixed>> $evidence */
    private function earliestObservation(array $evidence, ?object $signal): ?string
    {
        $dates = [];

        foreach ($evidence as $e) {
            $row = $e['row'];
            $provenance = is_string($row['provenance'] ?? null) ? json_decode($row['provenance'], true) : null;

            // provenance.ts is the observation time; created_date is only when
            // it was typed in. Module 2 made the first mandatory.
            $dates[] = $provenance['ts'] ?? $row['observed_date'] ?? $row['created_date'] ?? null;
        }

        $dates = array_values(array_filter($dates, fn ($d) => is_string($d) && $d !== ''));

        if ($dates === []) {
            return $signal->created_date ?? null;
        }

        usort($dates, fn ($a, $b) => strtotime($a) <=> strtotime($b));

        return $dates[0];
    }

    /** @return array<int, string> */
    private function supportingEvidenceIds(object $hypothesis): array
    {
        $ids = json_decode((string) ($hypothesis->supporting_evidence_ids ?? '[]'), true);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }
}
