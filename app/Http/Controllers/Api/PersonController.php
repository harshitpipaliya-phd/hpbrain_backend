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
        $columns = array_values(array_unique(array_merge(
            array_values($person->columns(self::LIST_FIELDS)),
            [$person->tenantKey],
        )));

        foreach (['created_at', 'updated_at'] as $column) {
            if ($this->sourceHasColumn($person, $column)) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * The roster, optionally narrowed and paged ON THE SERVER.
     *
     * WHAT THIS FIXES. Every caller used to receive the tenant's entire
     * workforce and narrow it in the browser — `person.ts` still contains the
     * client-side `scope()` that did it. The Department page then rendered ten
     * of them: on Fiber Valley that is 768 rows serialised, sent and discarded
     * to show a first page of ten, on every department switch.
     *
     * BACKWARD COMPATIBLE BY CONSTRUCTION. With no query string this returns
     * exactly what it always returned — a bare JSON array of every active
     * person. The paged envelope appears only when a caller asks for a page, so
     * the screens that still consume the array keep working unchanged. That is
     * why the return shape is conditional rather than always an envelope.
     *
     * `unitId` is applied in SQL against the mapped unit column, so a department
     * of ten costs ten rows regardless of how large the organization is.
     */
    public function index(Request $request): JsonResponse
    {
        $t = $this->authTenantId($request);
        $person = $this->resolver->resolve($t, 'Person');

        $unitId = trim((string) $request->query('unitId', ''));
        $search = trim((string) $request->query('q', ''));
        $paged = $request->query('page') !== null || $request->query('perPage') !== null;

        $query = DB::table($person->table)
            ->where($person->tenantKey, $t)
            ->where($person->field('status'), 1)
            ->tap(fn ($q) => $this->activeSourceRows($q, $person));

        if ($unitId !== '' && $person->has('unit')) {
            $query->where($person->field('unit'), $unitId);
        }

        if ($search !== '') {
            // The same fields search() offers, so a name that is findable there
            // is findable here rather than in a second, subtly different set.
            $searchable = $person->columns(['firstName', 'lastName', 'email', 'externalRef']);
            $query->where(function ($w) use ($search, $searchable) {
                foreach ($searchable as $column) {
                    $w->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        if (! $paged) {
            $rows = $query->select($this->listColumns($person))->get();
            $roles = $this->profileNames($rows, $person);

            return response()->json($rows->map(fn ($r) => $this->map((array) $r, $person, $roles))->all());
        }

        // COUNT BEFORE THE PAGE, on the same builder, so "page 3 of 77" and the
        // rows on page 3 can never describe different filters.
        $total = (int) (clone $query)->count();

        $perPage = max(1, min(100, (int) $request->query('perPage', 20)));
        $page = max(1, (int) $request->query('page', 1));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $rows = $query
            ->select($this->listColumns($person))
            ->orderBy($person->primaryKey)
            ->forPage($page, $perPage)
            ->get();

        $roles = $this->profileNames($rows, $person);

        return response()->json([
            'people' => $rows->map(fn ($r) => $this->map((array) $r, $person, $roles))->all(),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => $pages,
        ]);
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
            ->where($person->field('status'), 1)
            ->where(function ($w) use ($q, $searchable) {
                foreach ($searchable as $column) {
                    $w->orWhere($column, 'like', "%{$q}%");
                }
            })
            ->tap(fn ($query) => $this->activeSourceRows($query, $person))
            ->limit(50)
            ->get();

        $roles = $this->profileNames($rows, $person);

        return response()->json($rows->map(fn ($r) => $this->map((array) $r, $person, $roles))->all());
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->authTenantId($request);
        $person = $this->resolver->resolve($t, 'Person');

        $row = DB::table($person->table)
            ->select($this->listColumns($person))
            ->where($person->primaryKey, $id)
            ->where($person->tenantKey, $t)
            ->where($person->field('status'), 1)
            ->tap(fn ($query) => $this->activeSourceRows($query, $person))
            ->first();

        return $row
            ? response()->json($this->map((array) $row, $person))
            : response()->json(['error' => 'person_not_found'], 404);
    }

    private function sourceHasColumn(ResolvedSource $source, string $column): bool
    {
        try {
            return Schema::hasColumn($source->table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    private function activeSourceRows(\Illuminate\Database\Query\Builder $query, ResolvedSource $source): void
    {
        if ($source->has('deletedAt')) {
            $query->whereNull($source->field('deletedAt'));
        }
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

    /**
     * Role names for a set of people, in ONE query.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, string> profile id => role name
     */
    private function profileNames($rows, ResolvedSource $person): array
    {
        if (! $person->has('profile')) {
            return [];
        }

        $column = $person->field('profile');

        $ids = $rows
            ->map(fn ($r) => ((array) $r)[$column] ?? null)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        try {
            $profile = $this->resolver->resolve($person->tenantId, 'PersonProfile');
        } catch (\Throwable) {
            return [];
        }

        return DB::table($profile->table)
            ->where($profile->tenantKey, $person->tenantId)
            ->whereIn($profile->primaryKey, $ids)
            ->pluck($profile->field('name'), $profile->primaryKey)
            ->map(fn ($n) => (string) $n)
            ->all();
    }

    /**
     * @param  array<string, string>|null  $roles  preloaded names, or null to look one up
     */
    private function roleFor(mixed $profileId, ?array $roles, ResolvedSource $person): ?string
    {
        if ($profileId === null || $profileId === '') {
            return null;
        }

        if ($roles !== null) {
            $name = $roles[(string) $profileId] ?? null;
        } else {
            try {
                $profile = $this->resolver->resolve($person->tenantId, 'PersonProfile');
                $name = DB::table($profile->table)
                    ->where($profile->tenantKey, $person->tenantId)
                    ->where($profile->primaryKey, $profileId)
                    ->value($profile->field('name'));
            } catch (\Throwable) {
                $name = null;
            }
        }

        return $name !== null && trim((string) $name) !== '' ? (string) $name : null;
    }

    /**
     * @param  array<string, string>|null  $roles  preloaded role names, keyed by
     *         profile id. Null makes this row resolve its own, which is correct
     *         for the single-row paths and wrong for a list — see profileNames.
     */
    private function map(array $r, ResolvedSource $person, ?array $roles = null): array
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

        // ROLE NAMES ARE RESOLVED IN ONE QUERY FOR THE WHOLE PAGE, NOT ONE PER
        // PERSON. This block used to run Schema::hasTable() AND a SELECT against
        // tbluserprofilemaster for every row it mapped — two round trips per
        // person. Against this deployment's remote database that made a list of
        // 81 people cost ~1.9 seconds while the same endpoint on a tenant with
        // one person answered in 50ms; the work scaled with the row count, not
        // the data. Measured before: 1392 / 1831 / 1932 ms.
        //
        // $roles is prepared once by the caller (see profileNames) and passed
        // down. It is optional so the single-row callers — show(), store() —
        // stay unchanged and simply resolve the one name they need.
        $role = $this->roleFor($profileId, $roles, $person);

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
