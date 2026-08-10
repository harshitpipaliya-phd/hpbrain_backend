<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** People — Person in the Brain's vocabulary — come from the tenant's own source. */
final class PersonController extends Controller
{
    /**
     * The universal fields map() reads. Resolved to source columns per tenant.
     *
     * These reads used to be SELECT * against the ERP's widest table. That
     * pulled every column of every employee into PHP so that map() could throw
     * all but eleven of them away, and among the discarded ones were `password`
     * and `plain_password`: credential material crossing the wire and sitting in
     * process memory for a screen that only ever renders a name and a
     * department. Naming the fields keeps that closed.
     *
     * @var array<int, string>
     */
    private const LIST_FIELDS = [
        'id', 'externalRef', 'firstName', 'lastName', 'email', 'phone',
        'gender', 'unit',
    ];

    public function __construct(private readonly EntityResolver $resolver)
    {
    }

    /**
     * Source columns for the listing, plus the tenant key and audit columns
     * map() also reads.
     *
     * columns() skips universal fields the tenant has not mapped, so a source
     * without a gender column simply selects one column fewer rather than
     * failing — the field is absent, and map() renders it null.
     *
     * @return array<int, string>
     */
    private function listColumns(ResolvedSource $person): array
    {
        return array_values(array_unique(array_merge(
            array_values($person->columns(self::LIST_FIELDS)),
            [$person->tenantKey, 'created_at', 'updated_at'],
        )));
    }

    public function index(Request $request): JsonResponse
    {
        $t = $this->authTenantId($request);
        $person = $this->resolver->resolve($t, 'Person');

        return response()->json(
            DB::table($person->table)
                ->select($this->listColumns($person))
                ->where($person->tenantKey, $t)
                ->whereNull('deleted_at')
                ->where($person->field('status'), 1)
                ->get()
                ->map(fn ($r) => $this->map((array) $r, $person))
                ->all()
        );
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $t = $this->authTenantId($request);
        $person = $this->resolver->resolve($t, 'Person');

        $searchable = $person->columns(['firstName', 'lastName', 'email', 'externalRef']);

        $rows = DB::table($person->table)
            ->select($this->listColumns($person))
            ->where($person->tenantKey, $t)
            ->whereNull('deleted_at')
            ->where($person->field('status'), 1)
            ->where(function ($w) use ($q, $searchable) {
                foreach ($searchable as $column) {
                    $w->orWhere($column, 'like', "%{$q}%");
                }
            })->limit(50)->get();

        return response()->json($rows->map(fn ($r) => $this->map((array) $r, $person))->all());
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->authTenantId($request);
        $person = $this->resolver->resolve($t, 'Person');

        $row = DB::table($person->table)
            ->select($this->listColumns($person))
            ->where($person->primaryKey, $id)
            ->where($person->tenantKey, $t)
            ->whereNull('deleted_at')
            ->where($person->field('status'), 1)
            ->first();

        return $row
            ? response()->json($this->map((array) $row, $person))
            : response()->json(['error' => 'person_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employeeId' => ['required', 'string'],
            'firstName'  => ['required', 'string'],
            'lastName'   => ['required', 'string'],
            'email'      => ['required', 'email'],
            'phone'      => ['nullable', 'string'],
            'gender'     => ['nullable', 'string'],
            'departmentId' => ['nullable', 'integer'],
            'joiningDate'  => ['nullable', 'date'],
        ]);

        $t = $this->authTenantId($request);
        $person = $this->resolver->resolve($t, 'Person');
        $profile = $this->resolver->resolve($t, 'PersonProfile');
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');

        if (!empty($data['departmentId']) && !DB::table($unit->table)
            ->where($unit->primaryKey, $data['departmentId'])
            ->where($unit->tenantKey, $t)
            ->whereNull('deleted_at')
            ->exists()) {
            return response()->json(['error' => 'department_not_found'], 422);
        }

        $profileId = DB::table($profile->table)
            ->where($profile->tenantKey, $t)
            ->where($profile->field('name'), 'Employee')
            ->where($profile->field('status'), 1)
            ->value($profile->primaryKey);

        if (! $profileId) {
            return response()->json(['error' => "no_employee_profile_for_org_{$t}"], 422);
        }

        $now = now()->format('Y-m-d H:i:s');
        $temp = substr(bin2hex(random_bytes(8)), 0, 12);

        $id = DB::table($person->table)->insertGetId([
            $person->field('externalRef') => $data['employeeId'],
            'password'                    => $temp,
            'plain_password'              => $temp,
            $person->field('firstName')   => $data['firstName'],
            $person->field('lastName')    => $data['lastName'],
            $person->field('email')       => $data['email'],
            $person->field('phone')       => $data['phone'] ?? null,
            $person->field('gender')      => $data['gender'] ?? null,
            $person->field('unit')        => $data['departmentId'] ?? null,
            $person->field('joinedDate')  => $data['joiningDate'] ?? null,
            $person->tenantKey            => $t,
            $person->field('profile')     => $profileId,
            $person->field('status')      => 1,
            'created_by'                  => $this->actorErpId($request),
            'created_at'                  => $now,
            'updated_at'                  => $now,
        ]);

        $created = DB::table($person->table)
            ->select($this->listColumns($person))
            ->where($person->primaryKey, $id)
            ->first();

        return response()->json(
            $this->map((array) $created, $person) + ['tempPassword' => $temp],
            201
        );
    }

    private function map(array $r, ResolvedSource $person): array
    {
        // An unmapped field reads as null rather than throwing: this is a
        // rendering path, and a source without a gender column has no gender to
        // report. That is different from a source that has one and left it empty
        // only in that the ERP could never fill it — a distinction the UI layers
        // in Phase 6 are built to show.
        $value = function (string $field) use ($r, $person) {
            if (! $person->has($field)) {
                return null;
            }

            return $r[$person->field($field)] ?? null;
        };

        $unit = $value('unit');

        return [
            'id'           => (string) $r[$person->primaryKey],
            'employeeId'   => $value('externalRef'),
            'firstName'    => $value('firstName'),
            'lastName'     => $value('lastName'),
            'email'        => $value('email'),
            'phone'        => $value('phone'),
            'gender'       => $value('gender'),
            'departmentId' => $unit !== null ? (string) $unit : null,
            'orgId'        => (string) ($r[$person->tenantKey] ?? ''),
            'status'       => 'active',
            'createdDate'  => $r['created_at'] ?? null,
            'updatedDate'  => $r['updated_at'] ?? null,
        ];
    }

    public function audit(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_audit_logs')
                ->where('tenant_id', $this->authTenantId($request))
                ->where('entity_type', 'Person')->where('entity_id', $id)
                ->orderByDesc('created_at')->get()
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

        $t = $this->authTenantId($request);
        $person = $this->resolver->resolve($t, 'Person');

        $map = [
            'firstName' => $person->field('firstName'),
            'lastName'  => $person->field('lastName'),
            'email'     => $person->field('email'),
            'phone'     => $person->field('phone'),
        ];
        $fields = [];
        foreach ($data as $k => $v) { $fields[$map[$k]] = $v; }

        if ($fields === []) {
            return response()->json(['error' => 'no_fields_to_update'], 422);
        }

        $fields['updated_at'] = now()->format('Y-m-d H:i:s');
        $n = DB::table($person->table)
            ->where($person->primaryKey, $id)
            ->where($person->tenantKey, $t)
            ->whereNull('deleted_at')
            ->update($fields);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'person_not_found'], 404);
    }

    public function archive(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->authTenantId($request);
        $person = $this->resolver->resolve($t, 'Person');

        $n = DB::table($person->table)
            ->where($person->primaryKey, $id)
            ->where($person->tenantKey, $t)
            ->whereNull('deleted_at')
            ->update([
                'deleted_at'             => now()->format('Y-m-d H:i:s'),
                $person->field('status') => 0,
            ]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'person_not_found'], 404);
    }

    /** The five KASBA dimensions, in the order the UI renders them. */
    private const KASBA = ['knowledge', 'ability', 'skill', 'behaviour', 'attitude'];

    public function twin(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->authTenantId($request);
        $source = $this->resolver->resolve($t, 'Person');

        $row = DB::table($source->table)
            ->where($source->primaryKey, $id)
            ->where($source->tenantKey, $t)
            ->whereNull('deleted_at')
            ->where($source->field('status'), 1)
            ->first();

        if (! $row) {
            return response()->json(['error' => 'person_not_found'], 404);
        }

        $person = (array) $row;
        $pid = (string) $id;

        $assignments = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $t)
            ->where('target_type', 'Person')
            ->where('target_id', $pid)
            ->orderBy('assigned_date')
            ->get();

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

        // Person.position is the job role this person holds. A source that does
        // not map it has no job role to compare against, so there are no
        // requirements and therefore no gaps — null, not an empty target.
        $positionColumn = $source->has('position') ? $source->field('position') : null;

        $jobRoleId = $positionColumn !== null && ($person[$positionColumn] ?? null) !== null
            ? (string) $person[$positionColumn]
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

        $overalls = $capabilityScores->pluck('scores.overall')->filter(fn ($v) => $v !== null);

        $breakdown = [
            'capabilityScore'  => $overalls->isEmpty() ? null : round(($overalls->avg() / 5) * 100, 1),
            'decisionQuality'  => $decisions->isEmpty() ? null : round($approved / $decisions->count() * 100, 1),
            'executionSuccess' => $executions->isEmpty() ? null : round($completed / $executions->count() * 100, 1),
        ];

        $present = array_values(array_filter($breakdown, fn ($v) => $v !== null));

        return response()->json([
            'person' => array_merge($this->map($person, $source), [
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
