<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Kasba\KasbaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class KasbaController extends Controller
{
    public function __construct(private readonly KasbaService $kasba)
    {
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

        $targets = [];
        foreach (config('brain.kasba.dimensions') as $d) {
            $raw = $capability->{$d} ?? null;
            $targets[$d] = is_string($raw) ? json_decode($raw, true) : $raw;
        }

        return response()->json([
            'scores'       => $this->kasba->computeScores($latestArr),
            'gaps'         => $this->kasba->computeGaps($latestArr, $targets),
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

        $dims = config('brain.kasba.dimensions');
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

        $departmentOfPerson = $personIds === [] ? collect() : DB::table('tbluser')
            ->whereIn('id', $personIds)->whereNull('deleted_at')
            ->pluck('department_id', 'id');

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
            $buckets[$key] ??= ['capabilityId' => (string) $assignment->capability_id, 'departmentId' => $departmentId, 'levels' => []];
            $buckets[$key]['levels'][] = array_sum($levels) / count($levels);
        }

        $cells = array_values(array_map(fn (array $b) => [
            'capabilityId'  => $b['capabilityId'],
            'departmentId'  => $b['departmentId'],
            'averageLevel'  => round(array_sum($b['levels']) / count($b['levels']), 2),
            'assessedCount' => count($b['levels']),
        ], $buckets));

        usort($cells, fn ($a, $b) => $b['averageLevel'] <=> $a['averageLevel']);

        return $cells;
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
            $vals = collect(config('brain.kasba.dimensions'))
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

    public function recordProficiency(Request $request): JsonResponse
    {
        $rules = ['assignmentId' => ['required', 'string']];

        foreach (config('brain.kasba.dimensions') as $d) {
            $rules[$d.'Level'] = ['nullable', 'numeric', 'between:0,'.config('brain.kasba.max_level')];
        }

        $rules['evidenceConfidence'] = ['nullable', 'numeric', 'between:0,1'];
        $data = $request->validate($rules);

        $now = now()->format('Y-m-d H:i:s');

        $row = [
            'id'            => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'tenant_id'     => $this->tenantId($request),
            'assignment_id' => $data['assignmentId'],
            'assessed_by'   => $this->actorId($request),
            'assessed_date' => $now,
            'created_date'  => $now,
        ];

        // An unassessed dimension stays NULL. It is never defaulted to zero —
        // "not measured" is a different claim from "measured as zero".
        foreach (config('brain.kasba.dimensions') as $d) {
            $row[$d.'_level'] = $data[$d.'Level'] ?? null;
        }

        $row['evidence_confidence'] = $data['evidenceConfidence'] ?? null;

        DB::table('hpbrain_capability_proficiency')->insert($row);

        return response()->json($row, 201);
    }
}
