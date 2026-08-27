<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Organization\OrganizationStructureService;
use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use App\Domain\Universal\SourceSchema;
use App\Http\Controllers\Controller;
use App\Repositories\OrganizationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizations come from the tenant's own system of record, not from a
 * Brain-owned table. See README "Where the data comes from".
 *
 * The tables behind them are resolved per tenant through EntityResolver rather
 * than named here, so which ERP a customer runs is configuration.
 *
 * Every list and detail response is scoped to the authenticated tenant. Only a
 * platform admin may address an organization that is not the caller's own, and
 * that is bounded by EnsureTenantScope (the tenant must exist and not be
 * archived).
 */
final class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationRepository $repository,
        private readonly EntityResolver $resolver,
        private readonly OrganizationStructureService $structure,
        private readonly SourceSchema $schema,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $t = $this->tenantId($request);

        return response()->json($this->repository->list($t));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);

        $row = collect($this->repository->list($t))->firstWhere('id', (int) $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'organization_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'min:1'],
            'orgCode'   => ['nullable', 'string'],
            'industry'  => ['nullable', 'string'],
            'legalName' => ['nullable', 'string'],
            'logo'      => ['nullable', 'string'],
        ]);

        $data['createdBy'] = $this->actorErpId($request);

        // The new organization has no mappings of its own yet, so the caller's
        // tenant supplies the description of where organizations live.
        return response()->json(
            $this->repository->create($this->tenantId($request), $data),
            201,
        );
    }

    public function audit(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_audit_logs')
                ->where('tenant_id', $this->tenantId($request))
                ->where('entity_type', 'Organization')->where('entity_id', $id)
                ->orderByDesc('created_at')->get()
        );
    }

    /**
     * Edit the organization record.
     *
     * WHAT IS EDITABLE IS A PROPERTY OF THE TENANT, NOT OF THIS METHOD. The
     * validator accepts the product's whole organization vocabulary; which of
     * those fields reach a column is decided per tenant by the mapping and then
     * narrowed by SourceSchema to the columns the table physically has. A field
     * this tenant's ERP cannot hold is dropped rather than rejected, because the
     * client is told up front — `profileFields` on every listed row — which
     * fields exist, and only ever offers those.
     *
     * The response echoes the freshly-read row, so the caller never has to guess
     * what the source system made of the write.
     */
    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'name'               => ['sometimes', 'string', 'min:1', 'max:255'],
            'orgCode'            => ['sometimes', 'nullable', 'string', 'max:255'],
            'industry'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'legalName'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'logo'               => ['sometimes', 'nullable', 'string', 'max:2048'],
            'country'            => ['sometimes', 'nullable', 'string', 'max:255'],
            'address'            => ['sometimes', 'nullable', 'string', 'max:1000'],
            'email'              => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone'              => ['sometimes', 'nullable', 'string', 'max:255'],
            'website'            => ['sometimes', 'nullable', 'string', 'max:2048'],
            'contactPerson'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'registrationNumber' => ['sometimes', 'nullable', 'string', 'max:255'],
            'taxId'              => ['sometimes', 'nullable', 'string', 'max:255'],
            // A BAND, NOT A NUMBER. The ERP records '51-200', which is what an
            // onboarding form asks for and what every organization in it holds.
            'employeeCount'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'workWeek'           => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $t = $this->tenantId($request);
        $org = $this->resolver->resolve($t, 'Organization');
        $profile = $this->resolver->resolve($t, 'OrganizationProfile');

        // The organization row owns identity; the profile row owns description.
        $orgWritable = $this->schema->usable($org, ['name', 'code', 'industry']);
        $profileWritable = $this->schema->usable($profile, OrganizationRepository::PROFILE_FIELDS);

        // The API spells one of the organization's own fields differently from
        // the universal vocabulary. Nothing else needs translating.
        $inputToUniversal = ['orgCode' => 'code'];

        $orgFields = [];
        $profileFields = [];

        foreach ($data as $input => $value) {
            $universal = $inputToUniversal[$input] ?? $input;

            if (isset($orgWritable[$universal])) {
                $orgFields[$orgWritable[$universal]] = $value;

                continue;
            }

            if (isset($profileWritable[$universal])) {
                $profileFields[$profileWritable[$universal]] = $value;
            }
        }

        /*
            ONE ERP KEEPS BOTH ON THE SAME ROW. The school-shaped register maps
            Organization and OrganizationProfile to `school_setup`, so a write
            split across two statements would touch the same row twice, and the
            "insert a profile row when none was updated" path below would create
            a second school. Merging first is what keeps that from happening,
            and it is also simply the correct statement.
        */
        if ($profile->table === $org->table) {
            $orgFields += $profileFields;
            $profileFields = [];
        }

        if ($orgFields === [] && $profileFields === []) {
            return response()->json(['error' => 'no_editable_fields_for_tenant'], 422);
        }

        if (! $this->sourceRowExists($org, $t, $id)) {
            return response()->json(['error' => 'organization_not_found'], 404);
        }

        DB::transaction(function () use ($org, $profile, $id, $t, $orgFields, $profileFields) {
            $now = now()->format('Y-m-d H:i:s');

            if ($orgFields !== []) {
                $query = DB::table($org->table)
                    ->where($org->tenantKey, $id)
                    ->where($org->tenantKey, $t);

                $this->activeSourceRows($query, $org);
                $query->update($this->withTimestamp($org->table, $orgFields, 'updated_at', $now));
            }

            if ($profileFields === []) {
                return;
            }

            $updated = DB::table($profile->table)
                ->where($profile->tenantKey, $id)
                ->where($profile->tenantKey, $t)
                ->update($this->withTimestamp($profile->table, $profileFields, 'updated_at', $now));

            /*
                A tenant can legitimately have no profile row yet — the ERP
                creates one lazily — and the edit that discovers this is the one
                that should create it. Safe only because the profile table is
                genuinely a satellite here: the same-table case was merged away
                above, so this can never insert a duplicate organization.
            */
            if ($updated === 0) {
                DB::table($profile->table)->insert(
                    $this->withTimestamp(
                        $profile->table,
                        [$profile->tenantKey => $id] + $profileFields,
                        'created_at',
                        $now,
                    ),
                );
            }
        });

        /*
            `ok` FIRST, THE ROW BESIDE IT. The shipped contract is {ok:true} and
            clients already depend on it, so it stays; the freshly-read row is
            added rather than substituted, which saves the caller a round trip
            without taking anything away from one that does not want it.
        */
        $row = collect($this->repository->list($t))->firstWhere('id', (int) $id);

        return response()->json(['ok' => true] + ($row ?: []));
    }

    /**
     * Stamp a write with a timestamp only where the table keeps one.
     *
     * ERP tables are not uniformly Laravel-shaped, and naming a column that is
     * not there turns a good edit into a SQL error.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function withTimestamp(string $table, array $fields, string $column, string $now): array
    {
        try {
            if (! Schema::hasColumn($table, $column)) {
                return $fields;
            }
        } catch (\Throwable) {
            return $fields;
        }

        return $fields + [$column => $now];
    }

    /**
     * Soft delete only. These rows are ERP-owned system-of-record data; the
     * Brain archives, it never destroys what it does not own.
     */
    public function archive(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);
        $org = $this->resolver->resolve($t, 'Organization');

        if (! $org->has('deletedAt')) {
            return response()->json(['error' => 'archive_not_supported_by_source'], 422);
        }

        $query = DB::table($org->table)
            ->where($org->tenantKey, $id)
            ->where($org->tenantKey, $t);

        $this->activeSourceRows($query, $org);

        $n = $query->update([$org->field('deletedAt') => now()->format('Y-m-d H:i:s')]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'organization_not_found'], 404);
    }

    public function structure(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);
        $org = $this->resolver->resolve($t, 'Organization');
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');
        $person = $this->resolver->resolve($t, 'Person');

        $exists = $this->sourceRowExists($org, $t, $id);

        if (! $exists) {
            return response()->json(['error' => 'organization_not_found'], 404);
        }

        /*
          THE SHARED STRUCTURE. All three lists below were built here from their
          own queries over the unit and person tables, with no visibility filter
          — so this aggregate reported units the Departments screen said did not
          exist, and CommandCenter had to intersect the two by hand to avoid
          listing them. OrganizationStructureService is the single definition of
          what this organization's departments are and who is in them.

          `memberType` is published because the members are STAFF for an
          organization whose units come from its HR system and STUDENTS for one
          whose structure is derived from imported academic data. A consumer that
          prints a headcount must be able to label it correctly.
        */
        $structure = $this->structure->forTenant($t);

        $departments = collect($structure['departments'])->map(fn (array $d) => [
            'id' => (string) $d['id'],
            'name' => (string) $d['name'],
            'parentId' => $d['parentId'],
            'status' => (string) $d['status'],
            'source' => (string) $d['source'],
        ])->values();

        return response()->json([
            'departments' => $departments,
            'peopleByDepartment' => (object) $this->structure->getPeopleCountByDepartment($t),
            'memberType' => $structure['memberType'],
            'source' => $structure['source'],
            'heads' => (object) collect($structure['departments'])
                ->mapWithKeys(fn (array $d) => [(string) $d['id'] => (string) $d['name']])
                ->all(),
        ]);
    }

    public function dataQuality(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);
        $org = $this->resolver->resolve($t, 'Organization');
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');
        $person = $this->resolver->resolve($t, 'Person');

        $exists = $this->sourceRowExists($org, $t, $id);

        if (! $exists) {
            return response()->json(['error' => 'organization_not_found'], 404);
        }

        // Active rows of one entity, as every count below starts.
        $activePeople = fn () => DB::table($person->table)
            ->where($person->tenantKey, $t)
            ->where($person->field('status'), 1)
            ->whereNull('deleted_at');

        $activeUnits = fn () => DB::table($unit->table)
            ->where($unit->tenantKey, $t)
            ->where($unit->field('status'), 1)
            ->whereNull('deleted_at');

        $personUnit = $person->field('unit');
        $personProfileField = $person->field('profile');
        $personEmail = $person->field('email');
        $unitParent = $unit->field('parent');

        $peopleWithoutDept = $activePeople()
            ->where(function ($q) use ($personUnit) {
                $q->whereNull($personUnit)->orWhere($personUnit, 0);
            })
            ->count();

        $peopleWithoutProfile = $activePeople()
            ->where(function ($q) use ($personProfileField) {
                $q->whereNull($personProfileField)->orWhere($personProfileField, 0);
            })
            ->count();

        $peopleWithoutEmail = $activePeople()
            ->where(function ($q) use ($personEmail) {
                $q->whereNull($personEmail)->orWhere($personEmail, '');
            })
            ->count();

        /*
          THE SHARED DEPARTMENT COUNT, and a quality check that only fires where
          it can mean something.

          `$totalDepts` came from a fourth independent COUNT here, so the data
          quality report scored an organization against a different number of
          departments than every screen showed it. It is the shared count now.

          `deptsWithoutHead` is raised ONLY for departments that come from a
          connected source system. A derived teaching section has no head column
          to fill in and no ERP screen an administrator could go and fix it on,
          so reporting it as a data-quality issue would ask somebody to correct
          something that does not exist.
        */
        $totalDepts = $this->structure->departmentCount($t);

        $deptsWithoutHead = $this->structure->isSourceSystemBacked($t)
            ? $activeUnits()
                ->where(function ($q) use ($unitParent) {
                    $q->whereNull($unitParent)->orWhere($unitParent, 0);
                })
                ->count()
            : 0;

        $totalPeople = $activePeople()->count();

        $issues = [];

        // The `field` values stay the SOURCE column names, unchanged. They are
        // what the SPA renders and what an administrator would go and fix in the
        // ERP, so translating them to universal names here would be a behaviour
        // change wearing a tidy-up's clothes. Phase 7 is where the UI starts
        // reading labels from the terminology engine instead.
        if ($peopleWithoutDept > 0) {
            $issues[] = ['field' => $personUnit, 'count' => $peopleWithoutDept, 'severity' => 'medium'];
        }
        if ($peopleWithoutProfile > 0) {
            $issues[] = ['field' => $personProfileField, 'count' => $peopleWithoutProfile, 'severity' => 'medium'];
        }
        if ($peopleWithoutEmail > 0) {
            $issues[] = ['field' => $personEmail, 'count' => $peopleWithoutEmail, 'severity' => 'high'];
        }
        if ($deptsWithoutHead > 0) {
            $issues[] = ['field' => $unitParent, 'count' => $deptsWithoutHead, 'severity' => 'low'];
        }

        $score = $totalPeople + $totalDepts > 0
            ? round((1 - array_sum(array_column($issues, 'count')) / ($totalPeople + $totalDepts)) * 100, 1)
            : 100.0;

        return response()->json([
            'score' => max(0.0, min(100.0, $score)),
            'totalPeople' => $totalPeople,
            'totalDepartments' => $totalDepts,
            'issues' => $issues,
            'completeness' => [
                'peopleWithDepartment' => $totalPeople - $peopleWithoutDept,
                'peopleWithProfile' => $totalPeople - $peopleWithoutProfile,
                'peopleWithEmail' => $totalPeople - $peopleWithoutEmail,
                'departmentsWithHead' => $totalDepts - $deptsWithoutHead,
            ],
        ]);
    }

    private function sourceRowExists(ResolvedSource $source, string $tenantId, string $id): bool
    {
        $query = DB::table($source->table)
            ->where($source->primaryKey, $id)
            ->where($source->tenantKey, $tenantId);

        $this->activeSourceRows($query, $source);

        return $query->exists();
    }

    private function activeSourceRows(\Illuminate\Database\Query\Builder $query, ResolvedSource $source): void
    {
        if ($source->has('deletedAt')) {
            $query->whereNull($source->field('deletedAt'));
        }
    }
}
