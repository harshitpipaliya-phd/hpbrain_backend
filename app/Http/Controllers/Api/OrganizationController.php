<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Universal\EntityResolver;
use App\Http\Controllers\Controller;
use App\Repositories\OrganizationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'min:1', 'max:255'],
            'orgCode'   => ['sometimes', 'nullable', 'string'],
            'industry'  => ['sometimes', 'nullable', 'string'],
        ]);

        $t = $this->tenantId($request);
        $org = $this->resolver->resolve($t, 'Organization');

        $map = [
            'name'     => $org->field('name'),
            'orgCode'  => $org->field('code'),
            'industry' => $org->field('industry'),
        ];
        $fields = [];
        foreach ($data as $k => $v) { $fields[$map[$k]] = $v; }

        if ($fields === []) {
            return response()->json(['error' => 'no_fields_to_update'], 422);
        }

        $fields['updated_at'] = now()->format('Y-m-d H:i:s');
        $n = DB::table($org->table)
            ->where($org->tenantKey, $id)
            ->where($org->tenantKey, $t)
            ->whereNull('deleted_at')
            ->update($fields);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'organization_not_found'], 404);
    }

    /**
     * Soft delete only. These rows are ERP-owned system-of-record data; the
     * Brain archives, it never destroys what it does not own.
     */
    public function archive(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);
        $org = $this->resolver->resolve($t, 'Organization');

        $n = DB::table($org->table)
            ->where($org->tenantKey, $id)
            ->where($org->tenantKey, $t)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()->format('Y-m-d H:i:s')]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'organization_not_found'], 404);
    }

    public function structure(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);
        $org = $this->resolver->resolve($t, 'Organization');
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');
        $person = $this->resolver->resolve($t, 'Person');

        $exists = DB::table($org->table)
            ->where($org->tenantKey, $id)
            ->where($org->tenantKey, $t)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            return response()->json(['error' => 'organization_not_found'], 404);
        }

        $unitId = $unit->field('id');
        $unitName = $unit->field('name');
        $unitParent = $unit->field('parent');
        $unitStatus = $unit->field('status');

        $departments = DB::table($unit->table)
            ->where($unit->tenantKey, $t)
            ->whereNull('deleted_at')
            ->get([$unitId, $unitName, $unitParent, $unitStatus])
            ->map(fn ($d) => [
                'id' => (string) $d->{$unitId},
                'name' => (string) $d->{$unitName},
                'parentId' => (string) $d->{$unitParent},
                'status' => (string) $d->{$unitStatus},
            ])->values();

        $personUnit = $person->field('unit');

        $peopleByDepartment = DB::table($person->table)
            ->where($person->tenantKey, $t)
            ->where($person->field('status'), 1)
            ->whereNull('deleted_at')
            ->select($personUnit, DB::raw('COUNT(*) as count'))
            ->groupBy($personUnit)
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->{$personUnit} => (int) $r->count]);

        $heads = DB::table($unit->table)
            ->where($unit->tenantKey, $t)
            ->whereNull('deleted_at')
            ->get([$unitId, $unitName])
            ->mapWithKeys(fn ($d) => [(string) $d->{$unitId} => (string) $d->{$unitName}]);

        return response()->json([
            'departments' => $departments,
            'peopleByDepartment' => $peopleByDepartment,
            'heads' => $heads,
        ]);
    }

    public function dataQuality(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->tenantId($request);
        $org = $this->resolver->resolve($t, 'Organization');
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');
        $person = $this->resolver->resolve($t, 'Person');

        $exists = DB::table($org->table)
            ->where($org->tenantKey, $id)
            ->where($org->tenantKey, $t)
            ->whereNull('deleted_at')
            ->exists();

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

        $deptsWithoutHead = $activeUnits()
            ->where(function ($q) use ($unitParent) {
                $q->whereNull($unitParent)->orWhere($unitParent, 0);
            })
            ->count();

        $totalPeople = $activePeople()->count();
        $totalDepts = $activeUnits()->count();

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
}
