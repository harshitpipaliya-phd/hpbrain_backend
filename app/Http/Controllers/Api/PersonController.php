<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** People are read from the ERP tables tbluser / tbluserprofilemaster. */
final class PersonController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json($this->query()->get()->map(fn ($r) => $this->map((array) $r))->all());
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $rows = $this->query()->where(function ($w) use ($q) {
            $w->where('first_name', 'like', "%{$q}%")
              ->orWhere('last_name', 'like', "%{$q}%")
              ->orWhere('email', 'like', "%{$q}%")
              ->orWhere('employee_no', 'like', "%{$q}%");
        })->limit(50)->get();

        return response()->json($rows->map(fn ($r) => $this->map((array) $r))->all());
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->query()->where('id', $id)->first();

        return $row
            ? response()->json($this->map((array) $row))
            : response()->json(['error' => 'person_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employeeId' => ['required', 'string'],
            'firstName'  => ['required', 'string'],
            'lastName'   => ['required', 'string'],
            'email'      => ['required', 'email'],
            'orgId'      => ['required', 'integer'],
            'phone'      => ['nullable', 'string'],
            'gender'     => ['nullable', 'string'],
        ]);

        // The ERP requires an 'Employee' profile for the institute. It is
        // provisioned by OrganizationRepository::create(); if it is missing the
        // institute predates the Brain and needs one added.
        $profileId = DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $data['orgId'])
            ->where('name', 'Employee')->where('status', 1)->value('id');

        if (! $profileId) {
            return response()->json(['error' => "no_employee_profile_for_org_{$data['orgId']}"], 422);
        }

        $now = now()->format('Y-m-d H:i:s');
        $temp = substr(bin2hex(random_bytes(8)), 0, 12);

        $id = DB::table('tbluser')->insertGetId([
            'employee_no'      => $data['employeeId'],
            'password'         => $temp,
            'plain_password'   => $temp,
            'first_name'       => $data['firstName'],
            'last_name'        => $data['lastName'],
            'email'            => $data['email'],
            'mobile'           => $data['phone'] ?? null,
            'gender'           => $data['gender'] ?? null,
            'sub_institute_id' => $data['orgId'],
            'user_profile_id'  => $profileId,
            'status'           => 1,
            'created_by'       => $this->actorId($request),
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return response()->json(
            $this->map((array) DB::table('tbluser')->find($id)) + ['tempPassword' => $temp],
            201
        );
    }

    private function query()
    {
        return DB::table('tbluser')->whereNull('deleted_at')->where('status', 1);
    }

    private function map(array $r): array
    {
        return [
            'id'           => (string) $r['id'],
            'employeeId'   => $r['employee_no'] ?? null,
            'firstName'    => $r['first_name'] ?? null,
            'lastName'     => $r['last_name'] ?? null,
            'email'        => $r['email'] ?? null,
            'phone'        => $r['mobile'] ?? null,
            'gender'       => $r['gender'] ?? null,
            'departmentId' => isset($r['department_id']) ? (string) $r['department_id'] : null,
            'orgId'        => (string) ($r['sub_institute_id'] ?? ''),
            'status'       => 'active',
            'createdDate'  => $r['created_at'] ?? null,
            'updatedDate'  => $r['updated_at'] ?? null,
        ];
    }

    public function audit(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_audit_logs')
                ->where('tenant_id', $this->tenantId($request))
                ->where('entity_type', 'Person')->where('entity_id', $id)
                ->orderByDesc('created_date')->get()
        );
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'firstName' => ['sometimes', 'string', 'min:1'],
            'lastName'  => ['sometimes', 'string', 'min:1'],
            'email'     => ['sometimes', 'email'],
            'phone'     => ['sometimes', 'nullable', 'string'],
        ]);

        $map = ['firstName' => 'first_name', 'lastName' => 'last_name', 'email' => 'email', 'phone' => 'mobile'];
        $fields = [];
        foreach ($data as $k => $v) { $fields[$map[$k]] = $v; }

        if ($fields === []) {
            return response()->json(['error' => 'no_fields_to_update'], 422);
        }

        $fields['updated_at'] = now()->format('Y-m-d H:i:s');
        $n = DB::table('tbluser')->where('id', $id)->whereNull('deleted_at')->update($fields);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'person_not_found'], 404);
    }

    public function archive(Request $request, string $tenantId, string $id): JsonResponse
    {
        $n = DB::table('tbluser')->where('id', $id)->whereNull('deleted_at')
            ->update(['deleted_at' => now()->format('Y-m-d H:i:s'), 'status' => 0]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'person_not_found'], 404);
    }

    /** The five KASBA dimensions, in the order the UI renders them. */
    private const KASBA = ['knowledge', 'ability', 'skill', 'behaviour', 'attitude'];

    /**
     * The Brain's view of a person: capabilities held, how firmly known, and
     * what the person has actually decided and executed.
     *
     * Two things this method must get right, both of which it previously did
     * not:
     *
     * 1. hpbrain_capability_assignments is polymorphic — (target_type,
     *    target_id) — exactly as CapabilityController::assign() writes it.
     *    Filtering on a `person_id` column raised
     *      SQLSTATE[42S22]: Unknown column 'person_id' in 'where clause'
     *    on every visit to a person's profile.
     *
     * 2. The response shape is a contract. web/src/components/person/
     *    PersonTwin.tsx and workspace/PersonIntelligence.tsx both read
     *    capabilityScores[], decisionParticipation, executionHistory[],
     *    guardians[] and individualScore. Returning raw assignment rows left
     *    every one of those undefined, and `twin.capabilityScores.length`
     *    threw before React could paint — a blank screen, not an error.
     */
    public function twin(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->query()->where('id', $id)->first();

        if (! $row) {
            return response()->json(['error' => 'person_not_found'], 404);
        }

        $person = (array) $row;
        $t = $this->tenantId($request);
        $pid = (string) $id;

        $assignments = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $t)
            ->where('target_type', 'Person')
            ->where('target_id', $pid)
            ->orderBy('assigned_date')
            ->get();

        // One proficiency record per assignment: the most recent assessment.
        // Older rows are history, not a second opinion to average in.
        $proficiency = $assignments->isEmpty() ? collect() : DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $t)
            ->whereIn('assignment_id', $assignments->pluck('id')->all())
            ->orderByDesc('assessed_date')
            ->get()
            ->groupBy('assignment_id')
            ->map(fn ($rows) => $rows->first());

        $capabilityNames = $assignments->isEmpty() ? collect() : DB::table('hpbrain_capabilities')
            ->where('tenant_id', $t)
            ->whereIn('id', $assignments->pluck('capability_id')->all())
            ->pluck('name', 'id');

        // Gaps are measured against the target level for the person's job role.
        // With no jobtitle_id, or no requirements recorded for it, there is no
        // target — and an unmeasurable gap is reported as no gap, not as zero.
        $jobRoleId = isset($person['jobtitle_id']) && $person['jobtitle_id'] !== null
            ? (string) $person['jobtitle_id']
            : null;

        $requirements = $jobRoleId === null ? collect() : DB::table('hpbrain_job_role_capability_requirements')
            ->where('tenant_id', $t)->where('job_role_id', $jobRoleId)
            ->get()->keyBy('capability_id');

        $capabilityScores = $assignments->map(function ($a) use ($proficiency, $capabilityNames, $requirements) {
            $p = $proficiency->get($a->id);

            $scores = [];
            $assessed = [];

            foreach (self::KASBA as $dim) {
                $raw = $p->{$dim.'_level'} ?? null;
                $val = $raw === null ? null : (float) $raw;
                $scores[$dim] = $val;
                if ($val !== null) { $assessed[] = $val; }
            }

            $scores['overall'] = $assessed === []
                ? null
                : round(array_sum($assessed) / count($assessed), 2);

            $req = $requirements->get($a->capability_id);
            $target = $req ? (float) $req->required_level : null;
            $gaps = [];

            if ($target !== null) {
                foreach (self::KASBA as $dim) {
                    $current = $scores[$dim];
                    if ($current === null || $current < $target) {
                        $gaps[] = [
                            'dimension'    => $dim,
                            'currentLevel' => $current,
                            'targetLevel'  => $target,
                            'gap'          => round($target - ($current ?? 0.0), 2),
                        ];
                    }
                }
            }

            return [
                'capabilityId'    => (string) $a->capability_id,
                'capabilityName'  => (string) ($capabilityNames[$a->capability_id] ?? $a->capability_id),
                'assignmentId'    => (string) $a->id,
                // Surfaced explicitly: how much of what we "know" is merely claimed.
                'capabilityState' => (string) ($p->capability_state ?? 'Unassessed'),
                'scores'          => $scores,
                'gaps'            => $gaps,
                'assessedDate'    => $p->assessed_date ?? null,
            ];
        })->values();

        $decisions = DB::table('hpbrain_decisions')
            ->where('tenant_id', $t)->where('decided_by', $pid)->get();

        $approved = $decisions->filter(
            fn ($d) => in_array(strtolower((string) $d->status), ['approved', 'accepted'], true)
        )->count();

        $executions = DB::table('hpbrain_eso_executions')
            ->where('tenant_id', $t)->where('executed_by', $pid)
            ->orderByDesc('created_date')->limit(50)->get();

        $completed = $executions->filter(
            fn ($e) => in_array(strtolower((string) $e->status), ['completed', 'succeeded', 'success'], true)
        )->count();

        // Activity attributed to this person, either as the actor or as the
        // subject of the record.
        $recentActivity = DB::table('hpbrain_audit_logs')
            ->where('tenant_id', $t)
            ->where(function ($w) use ($pid) {
                $w->where('actor_id', $pid)
                  ->orWhere(fn ($q) => $q->where('entity_type', 'Person')->where('entity_id', $pid));
            })
            ->orderByDesc('created_at')->limit(25)->get()
            ->map(fn ($a) => [
                'type'       => (string) $a->action,
                'entityType' => (string) $a->entity_type,
                'createdAt'  => $a->created_at,
            ])->values();

        $guardians = DB::table('hpbrain_guardians')
            ->where('tenant_id', $t)->where('student_person_id', $pid)->get()
            ->map(fn ($g) => [
                'firstName'        => (string) ($g->first_name ?? ''),
                'lastName'         => (string) ($g->last_name ?? ''),
                'relationship'     => (string) ($g->relationship ?? ''),
                'email'            => $g->email ?? null,
                'phone'            => $g->phone ?? null,
                'isPrimaryContact' => (bool) $g->is_primary_contact,
            ])->values();

        $learningContributions = DB::table('hpbrain_learnings')
            ->where('tenant_id', $t)->where('created_by', $pid)->count();

        // Each component is a 0-100 reading of one evidence stream. A stream
        // with no records contributes nothing rather than a zero, so a person
        // with one assessed capability and no decisions is not scored as
        // having failed every decision they never made.
        $overalls = $capabilityScores->pluck('scores.overall')->filter(fn ($v) => $v !== null);

        $breakdown = [
            'capabilityScore'  => $overalls->isEmpty() ? null : round(($overalls->avg() / 5) * 100, 1),
            'decisionQuality'  => $decisions->isEmpty() ? null : round($approved / $decisions->count() * 100, 1),
            'executionSuccess' => $executions->isEmpty() ? null : round($completed / $executions->count() * 100, 1),
        ];

        $present = array_values(array_filter($breakdown, fn ($v) => $v !== null));

        return response()->json([
            'person' => array_merge($this->map($person), [
                // PersonIntelligence reads person.firstName[0] for the avatar
                // initials; a null there is a TypeError, so these are strings.
                'firstName' => (string) ($person['first_name'] ?? ''),
                'lastName'  => (string) ($person['last_name'] ?? ''),
                'email'     => (string) ($person['email'] ?? ''),
                'jobTitle'  => $jobRoleId === null ? null : DB::table('hrms_job_titles')
                    ->where('id', $jobRoleId)->whereNull('deleted_at')->value('title'),
            ]),
            'capabilityCount'       => $assignments->count(),
            'capabilityScores'      => $capabilityScores,
            'decisionParticipation' => ['total' => $decisions->count(), 'approved' => $approved],
            'learningContributions' => $learningContributions,
            'recentActivity'        => $recentActivity,
            'guardians'             => $guardians,
            'executionHistory'      => $executions->map(fn ($e) => [
                'id'            => (string) $e->id,
                'esoId'         => (string) ($e->eso_id ?? ''),
                'status'        => (string) ($e->status ?? 'unknown'),
                'completedDate' => $e->completed_date ?? null,
                'createdDate'   => $e->created_date ?? null,
            ])->values(),
            'individualScore' => [
                'score'     => $present === [] ? null : round(array_sum($present) / count($present), 1),
                'breakdown' => $breakdown,
            ],
        ]);
    }
}
