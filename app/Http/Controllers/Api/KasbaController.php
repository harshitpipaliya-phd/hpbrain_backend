<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Capability\CapabilityState;
use App\Domain\Capability\DemandService;
use App\Domain\Kasba\AssessmentModelResolver;
use App\Domain\Kasba\KasbaService;
use App\Domain\Universal\EntityResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class KasbaController extends Controller
{
    public function __construct(
        private readonly KasbaService $kasba,
        private readonly EntityResolver $resolver,
        private readonly AssessmentModelResolver $models,
        private readonly DemandService $demand,
    ) {
    }

    public function assessment(Request $request, string $tenantId, string $assignmentId, string $capabilityId): JsonResponse
    {
        $tenant = $this->tenantId($request);

        $latest = DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $tenant)->where('assignment_id', $assignmentId)
            ->orderByDesc('created_date')->first();

        $capability = DB::table('hpbrain_capabilities')
            ->where('tenant_id', $tenant)->where('id', $capabilityId)->first();

        if (! $capability) {
            return response()->json(['error' => 'capability_not_found'], 404);
        }

        $latestArr = $latest ? (array) $latest : null;

        $model = $this->models->forTenant($tenant);

        $targets = [];
        foreach ($model->dimensions as $d) {
            $raw = $capability->{$d} ?? null;
            $targets[$d] = is_string($raw) ? json_decode($raw, true) : $raw;
        }

        return response()->json([
            'scores'       => $this->kasba->forModel($model)->computeScores($latestArr),
            'gaps'         => $this->kasba->forModel($model)->computeGaps($latestArr, $targets),
            'assessedDate' => $latestArr['assessed_date'] ?? null,
        ]);
    }

    public function heatmap(Request $request): JsonResponse
    {
        $tenant = $this->tenantId($request);

        // Latest proficiency per assignment. A heatmap built from ALL history
        // would double-count people who have been reassessed.
        $rows = DB::table('hpbrain_capability_proficiency as p')
            ->join(DB::raw('(SELECT assignment_id, MAX(created_date) AS latest
                             FROM hpbrain_capability_proficiency
                             WHERE tenant_id = ? GROUP BY assignment_id) AS m'),
                   function ($j) {
                       $j->on('p.assignment_id', '=', 'm.assignment_id')
                         ->on('p.created_date', '=', 'm.latest');
                   })
            ->addBinding($tenant, 'join')
            ->where('p.tenant_id', $tenant)
            ->select('p.*')
            ->get();

        $model = $this->models->forTenant($tenant);
        $dims = $model->dimensions;
        $summary = [];

        foreach ($dims as $d) {
            $vals = $rows->pluck($d.'_level')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
            $summary[$d] = [
                // Null, not zero, when nothing has been assessed on a dimension.
                'assessed' => $vals->count(),
                'average'  => $vals->count() ? round($vals->avg(), 2) : null,
            ];
        }

        return response()->json([
            'cells'       => $this->heatmapCells($tenant, $rows, $dims),
            'dimensions'  => $summary,
            'assignments' => $rows->count(),
            // The model itself, so the SPA renders N axes and N columns
            // from the response rather than from a constant of its own.
            // A four-dimension tenant needs no frontend change.
            'model'       => $this->models->forTenant($tenant)->toArray(),
            // Demand and deficit per capability (Phase 5a). The heatmap could
            // say what people HAVE and never what the organization NEEDS, so
            // every cell was half an answer — a level with nothing to be short
            // of. deficit is NULL wherever either side is unknown, and stays
            // null all the way to the renderer.
            'deficit'     => $this->demand->perCapability($tenant),
        ]);
    }

    /**
     * The heatmap proper: one cell per (capability, department).
     *
     * The endpoint only ever returned the five-dimension roll-up, so the KASBA
     * Explorer — which assigns the response straight into a cell array and maps
     * over it — called .map on an object and blanked the screen.
     *
     * Aggregates only. A cell carries an average and a count of how many
     * assessments stand behind it, never a person id and never an individual
     * level, which is the privacy line this screen is built not to cross.
     *
     * @param  \Illuminate\Support\Collection  $rows  latest proficiency per assignment
     * @param  array<int, string>  $dims
     * @return array<int, array<string, mixed>>
     */
    private function heatmapCells(string $tenant, $rows, array $dims): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $assignments = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $tenant)
            ->whereIn('id', $rows->pluck('assignment_id')->unique()->all())
            ->get()->keyBy('id');

        // A capability held by a person belongs to that person's department;
        // one assigned to a department belongs to it directly. Anything else
        // (JobRole, Organization) has no department and is reported under a
        // null departmentId rather than dropped.
        $personIds = $assignments->where('target_type', 'Person')->pluck('target_id')->unique()->all();

        $person = $this->resolver->resolve($tenant, 'Person');

        $departmentOfPerson = $personIds === [] ? collect() : DB::table($person->table)
            ->whereIn($person->primaryKey, $personIds)
            ->where($person->tenantKey, $tenant)
            ->whereNull('deleted_at')
            ->pluck($person->field('unit'), $person->primaryKey);

        $buckets = [];

        foreach ($rows as $p) {
            $assignment = $assignments->get($p->assignment_id);

            if (! $assignment) {
                continue;
            }

            $levels = [];
            foreach ($dims as $d) {
                $value = $p->{$d.'_level'} ?? null;
                if ($value !== null) {
                    $levels[] = (float) $value;
                }
            }

            if ($levels === []) {
                continue;
            }

            $departmentId = match ($assignment->target_type) {
                'Person'     => ($d = $departmentOfPerson[$assignment->target_id] ?? null) !== null ? (string) $d : null,
                'Department' => (string) $assignment->target_id,
                default      => null,
            };

            $key = $assignment->capability_id.'|'.($departmentId ?? '');
            $buckets[$key] ??= [
                'capabilityId' => (string) $assignment->capability_id,
                'departmentId' => $departmentId,
                'levels' => [], 'states' => [],
            ];
            $buckets[$key]['levels'][] = array_sum($levels) / count($levels);
            $buckets[$key]['states'][] = (string) ($p->capability_state ?? CapabilityState::UNKNOWN);
        }

        $cells = array_values(array_map(fn (array $b) => [
            'capabilityId'  => $b['capabilityId'],
            'departmentId'  => $b['departmentId'],
            'averageLevel'  => round(array_sum($b['levels']) / count($b['levels']), 2),
            'assessedCount' => count($b['levels']),
            // The WEAKEST state in the cell, not the average or the best.
            // Averaging states would invent a confidence nobody holds, and
            // reporting the best would let one assessed row make four
            // unknown ones look measured. A cell is only as known as its
            // least-known member (Invariant 6, Pilot §A: show UNKNOWN honestly).
            'capabilityState' => $this->weakestState($b['states']),
            'unknownCount'    => count(array_filter(
                $b['states'], fn (string $s) => $s === CapabilityState::UNKNOWN
            )),
        ], $buckets));

        usort($cells, fn ($a, $b) => $b['averageLevel'] <=> $a['averageLevel']);

        return $cells;
    }

    /**
     * The lowest-ranked state present. An unrecognised value is treated as
     * Unknown rather than skipped: a state this code does not understand is
     * not evidence of anything, and skipping it would quietly raise the cell.
     *
     * @param  array<int, string>  $states
     */
    private function weakestState(array $states): string
    {
        $weakest = null;

        foreach ($states as $state) {
            try {
                $rank = CapabilityState::rank($state);
            } catch (InvalidArgumentException) {
                return CapabilityState::UNKNOWN;
            }

            if ($weakest === null || $rank < CapabilityState::rank($weakest)) {
                $weakest = $state;
            }
        }

        return $weakest ?? CapabilityState::UNKNOWN;
    }

    public function tasksForCapability(Request $request, string $tenantId, string $capabilityId): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_capability_tasks')
                ->where('tenant_id', $this->tenantId($request))->where('capability_id', $capabilityId)
                ->orderBy('name')->get()
        );
    }

    public function storeTask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'capabilityId'     => ['required', 'string'],
            'name'             => ['required', 'string', 'min:1', 'max:255'],
            'description'      => ['nullable', 'string'],
            'evidenceRequired' => ['nullable', 'boolean'],
        ]);

        $row = [
            'id'                => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'tenant_id'         => $this->tenantId($request),
            'capability_id'     => $data['capabilityId'],
            'name'              => $data['name'],
            'description'       => $data['description'] ?? null,
            'evidence_required' => (bool) ($data['evidenceRequired'] ?? false),
            'created_by'        => $this->actorId($request),
            'created_date'      => now()->format('Y-m-d H:i:s'),
        ];

        DB::table('hpbrain_capability_tasks')->insert($row);

        return response()->json($row, 201);
    }

    public function proficiencyHistory(Request $request, string $tenantId, string $assignmentId): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_capability_proficiency')
                ->where('tenant_id', $this->tenantId($request))->where('assignment_id', $assignmentId)
                ->orderBy('created_date')->get()
        );
    }

    public function proficiencyTrend(Request $request, string $tenantId, string $assignmentId): JsonResponse
    {
        $history = DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $this->tenantId($request))->where('assignment_id', $assignmentId)
            ->orderBy('created_date')->get();

        // A trend needs two points. One assessment is a reading, not a
        // direction — reporting "improving" from a single row would be invented.
        if ($history->count() < 2) {
            return response()->json([
                'direction' => null,
                'reason'    => 'insufficient_history',
                'points'    => $history->count(),
            ]);
        }

        $avg = function ($row) {
            $vals = collect($this->models->forTenant($tenant)->dimensions)
                ->map(fn ($d) => $row->{$d.'_level'})
                ->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);

            return $vals->count() ? $vals->avg() : null;
        };

        $first = $avg($history->first());
        $last  = $avg($history->last());

        if ($first === null || $last === null) {
            return response()->json(['direction' => null, 'reason' => 'no_assessed_dimensions']);
        }

        $delta = round($last - $first, 2);

        return response()->json([
            'direction' => $delta > 0 ? 'improving' : ($delta < 0 ? 'declining' : 'stable'),
            'delta'     => $delta,
            'first'     => round($first, 2),
            'latest'    => round($last, 2),
            'points'    => $history->count(),
        ]);
    }

    /**
     * Record a proficiency assessment — level AND state (Invariant 6).
     *
     * WHAT WAS WRONG. The state columns have existed since the January
     * migration and this method wrote none of them, so every row it created
     * carried real numeric levels beside a capability_state of 'Unknown'. That
     * is the exact failure the state model exists to prevent: a number on the
     * screen with nothing saying whether anyone measured it, which reads to a
     * user as a fact and is actually a claim.
     */
    public function recordProficiency(Request $request): JsonResponse
    {
        $dimensions = $this->models->forTenant($this->tenantId($request))->dimensions;

        $rules = ['assignmentId' => ['required', 'string']];

        foreach ($dimensions as $d) {
            $rules[$d.'Level'] = ['nullable', 'numeric', 'between:0,'
                .$this->models->forTenant($this->tenantId($request))->maxLevel];
        }

        $rules['evidenceConfidence'] = ['nullable', 'numeric', 'between:0,1'];
        // Defaults to Asserted rather than Unknown: someone is recording a
        // number, so at minimum a claim has been made. Unknown means nobody
        // has said anything, which is no longer true once this endpoint runs.
        $rules['capabilityState'] = ['nullable', Rule::in(CapabilityState::all())];
        $rules['evidenceRef']     = ['nullable', 'string', 'size:36'];
        // Which dimension the state describes. Required to tell Observed from
        // Demonstrated, which are not interchangeable.
        $rules['dimension']       = ['nullable', Rule::in($dimensions)];
        $rules['stateSource']     = ['nullable', 'string', 'max:100'];
        $rules['downgradeReason'] = ['nullable', 'string', 'max:500'];

        $data = $request->validate($rules);

        $tenant      = $this->tenantId($request);
        $actor       = $this->actorId($request);
        $toState     = $data['capabilityState'] ?? CapabilityState::ASSERTED;
        $evidenceRef = $data['evidenceRef'] ?? null;

        // The evidence must be OURS. The column is a bare VARCHAR with no
        // foreign key, so nothing but this check stops a caller citing another
        // tenant's evidence — which would make the state provably traceable to
        // a row they are not allowed to read.
        if ($evidenceRef !== null) {
            $ownsEvidence = DB::table('hpbrain_evidence')
                ->where('tenant_id', $tenant)->where('id', $evidenceRef)->exists();

            if (! $ownsEvidence) {
                return response()->json(['error' => 'evidence_not_found'], 422);
            }
        }

        // State advances from wherever this assignment already stands, not from
        // Unknown: a fresh row is a new reading of the same capability, and
        // ignoring the previous state is how silent regression happens.
        $current = DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $tenant)
            ->where('assignment_id', $data['assignmentId'])
            ->orderByDesc('created_date')
            ->value('capability_state') ?? CapabilityState::UNKNOWN;

        try {
            $state = CapabilityState::advance(
                from: (string) $current,
                to: $toState,
                evidenceRef: $evidenceRef,
                allowDowngrade: isset($data['downgradeReason']),
                downgradeReason: $data['downgradeReason'] ?? null,
                dimension: $data['dimension'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            // The guard's own message names the rule that was broken, which is
            // more useful to a caller than a generic validation error.
            return response()->json([
                'error'  => 'capability_state_transition_rejected',
                'reason' => $e->getMessage(),
                'from'   => $current,
                'to'     => $toState,
            ], 422);
        }

        $now = now()->format('Y-m-d H:i:s');

        $row = [
            'id'            => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'tenant_id'     => $tenant,
            'assignment_id' => $data['assignmentId'],
            'assessed_by'   => $actor,
            'assessed_date' => $now,
            'created_date'  => $now,
        ];

        // An unassessed dimension stays NULL. It is never defaulted to zero —
        // "not measured" is a different claim from "measured as zero".
        foreach ($dimensions as $d) {
            $row[$d.'_level'] = $data[$d.'Level'] ?? null;
        }

        $row['evidence_confidence'] = $data['evidenceConfidence'] ?? null;
        $row['capability_state']    = $state;
        $row['evidence_ref']        = $evidenceRef;
        // Who or what asserted it. Defaults to the authenticated actor rather
        // than to a system label, so an unattributed state is impossible.
        $row['state_source']        = $data['stateSource'] ?? 'api:'.$actor;
        $row['state_changed_date']  = $state === $current ? null : $now;
        $row['state_change_reason'] = $data['downgradeReason'] ?? null;

        DB::table('hpbrain_capability_proficiency')->insert($row);

        return response()->json($row, 201);
    }
}
