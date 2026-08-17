<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\People\PersonProfileService;
use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        'gender', 'unit', 'profile',
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
        $displayName = trim((string) (($value('firstName') ?? '').' '.($value('lastName') ?? '')));
        $profileId = $value('profile');
        $profileName = null;

        if ($profileId !== null && Schema::hasTable('tbluserprofilemaster')) {
            $profileName = DB::table('tbluserprofilemaster')
                ->where('id', $profileId)
                ->value('name');
        }

        $role = $profileName !== null && trim((string) $profileName) !== '' ? (string) $profileName : null;

        return [
            'id'           => (string) $r[$person->primaryKey],
            'employeeId'   => $value('externalRef'),
            'firstName'    => $value('firstName'),
            'lastName'     => $value('lastName'),
            'displayName'  => $displayName !== '' ? $displayName : null,
            'email'        => $value('email'),
            'phone'        => $value('phone'),
            'gender'       => $value('gender'),
            'departmentId' => $unit !== null ? (string) $unit : null,
            'designation'  => $role,
            'employmentType' => $role !== null ? strtolower((string) $role) : null,
            'employmentStatus' => 'active',
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

    /**
     * Everything the installation knows about one person.
     *
     * WHAT CHANGED AND WHY. This used to compose the response inline from four
     * loop tables — capability assignments, decisions, ESO executions, learnings
     * — and nothing else. Those four are empty for every tenant onboarded so far,
     * so the screen rendered five dashes and three "nothing recorded" panels
     * while the rows that actually describe a person went unread: their ERP
     * master row's mapped fields, their class-section unit, their profile, and
     * the operational records their reference appears in (twelve fee invoices per
     * student for the school tenant). The aggregation now lives in
     * PersonProfileService, which reads all of it and returns null — never zero —
     * for what genuinely is not there.
     *
     * THREE BUGS WENT WITH IT. The old response overrode the mapped firstName,
     * lastName and email with `$person['first_name']`, `['last_name']` and
     * `['email']` read literally, so a tenant whose source names those columns
     * anything else got empty strings from an endpoint that had already resolved
     * the right columns a few lines above. jobTitle was read from a hardcoded
     * `hrms_job_titles`, which is the school ERP's table and not necessarily
     * anyone else's. And the capability/decision/execution/learning queries named
     * their tables unguarded, so the endpoint 500'd rather than degrading on an
     * installation where a loop table has not been migrated.
     *
     * THE LEGACY KEYS ARE STILL HERE. capabilityScores, decisionParticipation,
     * executionHistory, recentActivity, individualScore, guardians and
     * capabilityCount are unchanged in name and shape, because they are what the
     * shipped SPA reads. Removing them would have been a breaking change
     * disguised as a refactor; they are now projections of the same service the
     * new keys come from, so the two can never disagree.
     */
    public function twin(Request $request, string $tenantId, string $id, PersonProfileService $profiles): JsonResponse
    {
        $profile = $profiles->build($this->authTenantId($request), $id);

        if ($profile === null) {
            return response()->json(['error' => 'person_not_found'], 404);
        }

        $intelligence = $profile['intelligence'];

        return response()->json($profile + [
            // ---- Compatibility projection (see the note above) ---------------
            'capabilityCount'       => count($intelligence['capabilities']),
            'capabilityScores'      => $intelligence['capabilities'],
            'decisionParticipation' => [
                'total'    => $intelligence['decisions']['total'],
                'approved' => $intelligence['decisions']['approved'],
            ],
            'learningContributions' => $intelligence['learnings'],
            'recentActivity'        => array_map(static fn ($a) => [
                'type'       => $a['action'],
                'entityType' => $a['entityType'],
                'createdAt'  => $a['createdAt'],
            ], $profile['audit']),
            'guardians'             => $profile['contacts']['guardians'],
            'executionHistory'      => $intelligence['executions'],
            'individualScore'       => $intelligence['score'],
        ]);
    }
}
