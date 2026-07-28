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

    /** The Brain's view of a person: capabilities held and how firmly known. */
    public function twin(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->query()->where('id', $id)->first();

        if (! $row) {
            return response()->json(['error' => 'person_not_found'], 404);
        }

        $t = $this->tenantId($request);

        $assignments = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $t)->where('person_id', $id)->get();

        $proficiency = $assignments->isEmpty() ? collect() : DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $t)->whereIn('assignment_id', $assignments->pluck('id')->all())->get();

        return response()->json([
            'person'       => $this->map((array) $row),
            'capabilities' => $assignments,
            'proficiency'  => $proficiency,
            // Surfaced explicitly: how much of what we "know" is merely claimed.
            'assertedOnly' => $proficiency->where('capability_state', 'Asserted')->count(),
        ]);
    }
}
