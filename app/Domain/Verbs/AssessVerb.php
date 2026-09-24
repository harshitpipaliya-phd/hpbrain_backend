<?php

declare(strict_types=1);

namespace App\Domain\Verbs;

use App\Domain\Authorization\Permission;
use App\Domain\Authorization\Role;
use App\Domain\Kasba\KasbaService;
use App\Domain\Learning\MemoryGrounding;
use App\Domain\Undetermined\VerbResult;
use Illuminate\Support\Facades\DB;

/**
 * ASSESS, wired through the same pipeline as EXPLAIN and with the same three
 * callables — the point of a fixed pipeline being that the second verb costs
 * only its grounding and its frame.
 *
 * It grounds on capability proficiency rows rather than signal evidence: an
 * assessment's evidence IS the record of prior assessments, each carrying its
 * own evidence_confidence and assessor. Memory is added on top, exactly as in
 * EXPLAIN, so a lesson learned about assessing this domain reaches the next
 * assessment.
 *
 * The gap analysis reuses KasbaService rather than recomputing it. That class
 * already enforces the rule that matters — an unassessed dimension is NULL,
 * never zero — and a second implementation here would eventually disagree with
 * it, which is how a system starts reporting two different truths about the
 * same person.
 */
final class AssessVerb
{
    public function __construct(
        private readonly VerbPipeline $pipeline,
        private readonly MemoryGrounding $memory,
        private readonly KasbaService $kasba,
    ) {
    }

    public function run(
        string $tenantId,
        string $assignmentId,
        string $capabilityId,
        string $actorId,
        string $role,
    ): VerbResult {
        $assignment = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $tenantId)->where('id', $assignmentId)->first();

        $capability = DB::table('hpbrain_capabilities')
            ->where('tenant_id', $tenantId)->where('id', $capabilityId)->first();

        return $this->pipeline->run(
            Verb::ASSESS,
            fn () => $this->governance($role),
            fn () => $this->ground($tenantId, $assignmentId, $actorId, $capability),
            fn (array $grounding) => $this->reason($grounding, $assignment, $capability),
        );
    }

    /** @return array{allowed: bool, reason: string} */
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
     * Proficiency history plus memory. An assignment with no proficiency row
     * has never been assessed, and with no learnings either the grounding is
     * empty — the pipeline then returns UNDETERMINED rather than inventing a
     * level, which is the KASBA rule (null is not zero) expressed at the verb
     * level.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ground(string $tenantId, string $assignmentId, string $actorId, ?object $capability): array
    {
        $proficiency = DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $tenantId)
            ->where('assignment_id', $assignmentId)
            ->orderByDesc('created_date')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'kind' => 'proficiency', 'row' => (array) $r])
            ->all();

        // The capability's category is the memory wedge: lessons about
        // assessing 'pedagogy' should not be retrieved when assessing 'finance'.
        $learnings = array_map(
            fn (array $l) => ['id' => $l['id'], 'kind' => 'learning', 'row' => $l],
            $this->memory->groundAndRecord(
                $tenantId,
                'CapabilityAssignment',
                $assignmentId,
                $actorId,
                $capability->category ?? null,
                $assignmentId,
            )
        );

        return array_merge($proficiency, $learnings);
    }

    /**
     * @param  array<int, array<string, mixed>>  $grounding
     * @return array{frame: array<string, mixed>, value: array<string, mixed>, confidence: float|null}
     */
    private function reason(array $grounding, ?object $assignment, ?object $capability): array
    {
        $proficiency = array_values(array_filter($grounding, fn ($g) => $g['kind'] === 'proficiency'));
        $learnings   = array_values(array_filter($grounding, fn ($g) => $g['kind'] === 'learning'));

        $latest   = $proficiency[0]['row'] ?? null;
        $earliest = $proficiency === [] ? null : end($proficiency)['row'];

        $targets = [];
        foreach (config('brain.kasba.dimensions') as $dimension) {
            $raw = $capability->{$dimension} ?? null;
            $targets[$dimension] = is_string($raw) ? json_decode($raw, true) : $raw;
        }

        $scores = $this->kasba->computeScores($latest);
        $gaps   = $this->kasba->computeGaps($latest, $targets);

        $frame = [
            // What changed: this assessment against the one before it. A single
            // assessment is a baseline, which is a real and stateable answer.
            'what_changed' => $latest === null ? null : (count($proficiency) > 1
                ? $this->movement($latest, $earliest)
                : 'baseline assessment; no prior proficiency record'),

            'who_is_affected' => $assignment === null ? null : sprintf(
                '%s:%s', (string) $assignment->target_type, (string) $assignment->target_id
            ),

            'when_did_it_start' => $earliest['assessed_date'] ?? $earliest['created_date'] ?? null,

            // The gap is the capability's own stated target minus the assessed
            // level, per dimension. A dimension with no target is skipped by
            // KasbaService, never treated as met.
            'how_large_is_the_gap' => $gaps === [] ? null : $gaps,

            // Each proficiency row is itself the evidence: an assessment made
            // by a named assessor with a stated confidence.
            'what_evidence_supports_it' => array_column($proficiency, 'id'),

            // Falsified by reassessment. Naming the dimensions that carry the
            // gap says precisely which measurement would overturn this.
            'what_would_falsify_it' => $gaps === [] ? null : [
                'reassess_dimensions'  => array_column($gaps, 'dimension'),
                'assessed_by'          => $latest['assessed_by'] ?? null,
                'evidence_confidence'  => isset($latest['evidence_confidence'])
                    ? (float) $latest['evidence_confidence'] : null,
            ],

            // For a capability gap the root-cause family is the dimension the
            // shortfall sits in — knowledge is a different problem from
            // attitude, and they have different interventions.
            'what_is_the_root_cause_family' => $gaps === [] ? null : 'kasba_'.$gaps[0]['dimension'],
        ];

        return [
            'frame' => $frame,
            'value' => [
                'assignmentId'        => $assignment->id ?? null,
                'capabilityId'        => $capability->id ?? null,
                'scores'              => $scores,
                'gaps'                => $gaps,
                'assessedDate'        => $latest['assessed_date'] ?? null,
                'groundedOnLearnings' => array_column($learnings, 'id'),
            ],
            // The confidence of the assessment is the assessor's own stated
            // evidence confidence, not a number computed here.
            'confidence' => isset($latest['evidence_confidence'])
                ? (float) $latest['evidence_confidence'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $latest
     * @param  array<string, mixed>|false|null  $earliest
     */
    private function movement(array $latest, $earliest): string
    {
        $moved = [];

        foreach (config('brain.kasba.dimensions') as $dimension) {
            $now    = $latest[$dimension.'_level'] ?? null;
            $before = is_array($earliest) ? ($earliest[$dimension.'_level'] ?? null) : null;

            // Only dimensions measured BOTH times can be said to have moved.
            // A dimension newly assessed has not improved; it was unknown.
            if ($now === null || $before === null || (float) $now === (float) $before) {
                continue;
            }

            $moved[] = sprintf('%s %+.2f', $dimension, (float) $now - (float) $before);
        }

        return $moved === []
            ? 'reassessed with no measured movement'
            : 'movement since first assessment: '.implode(', ', $moved);
    }
}
