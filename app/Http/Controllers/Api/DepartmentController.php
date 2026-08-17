<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Departments — OrganizationUnit in the Brain's vocabulary — are read from
 * whichever table the tenant maps that entity to.
 *
 * NOT YET UNIVERSAL, and worth naming: deleted_at, created_at, updated_at and
 * created_by are still written literally below. They are soft-delete and audit
 * conventions rather than entity fields, so they are not in the universal field
 * set, and a source system that spells them differently would still need code.
 * Recorded as remaining work rather than papered over with a fallback, because a
 * fallback is the one thing the resolver must never do.
 */
final class DepartmentController extends Controller
{
    public function __construct(private readonly EntityResolver $resolver)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $t = $this->authTenantId($request);
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');

        $query = DB::table($unit->table)
            ->where($unit->tenantKey, $t)
            ->whereNull('deleted_at')
            ->orderBy($unit->primaryKey);

        $this->applyDepartmentVisibilityScope($query, $unit, $t);

        $rows = $query->get();

        return response()->json($rows->map(fn ($r) => $this->map((array) $r, $unit))->all());
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->authTenantId($request);
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');

        $query = DB::table($unit->table)
            ->where($unit->primaryKey, $id)
            ->where($unit->tenantKey, $t)
            ->whereNull('deleted_at');

        $this->applyDepartmentVisibilityScope($query, $unit, $t);

        $row = $query->first();

        return $row
            ? response()->json($this->map((array) $row, $unit))
            : response()->json(['error' => 'department_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'min:1'],
            'description' => ['nullable', 'string'],
            'parentId'    => ['nullable', 'integer'],
        ]);

        $t = $this->authTenantId($request);
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');

        $now = now()->format('Y-m-d H:i:s');

        $id = DB::table($unit->table)->insertGetId([
            $unit->field('name')        => $data['name'],
            $unit->field('description') => $data['description'] ?? null,
            $unit->field('parent')      => $data['parentId'] ?? 0,
            $unit->field('status')      => 1,
            'is_calculated'             => 0,
            $unit->tenantKey            => $t,
            'created_by'                => $this->actorErpId($request),
            'created_at'                => $now,
            'updated_at'                => $now,
        ]);

        return response()->json(
            $this->map((array) DB::table($unit->table)->where($unit->primaryKey, $id)->first(), $unit),
            201,
        );
    }

    /** Source row -> the shape web/src/api/department.ts expects. */
    private function map(array $row, ResolvedSource $unit): array
    {
        $parentColumn = $unit->field('parent');
        $parent = ($row[$parentColumn] ?? 0) ? (string) $row[$parentColumn] : null;

        return [
            'id'                 => (string) $row[$unit->primaryKey],
            'name'               => (string) ($row[$unit->field('name')] ?? ''),
            'description'        => $row[$unit->field('description')] ?? null,
            'departmentType'     => 'department',
            'parentDepartmentId' => $parent,
            // Stays null. The universal field 'head' has no column behind it in
            // this ERP, and has() would report false — see EntityMappingSeeder.
            'headId'             => null,
            'orgId'              => (string) ($row[$unit->tenantKey] ?? ''),
            'status'             => $row['deleted_at']
                ? 'archived'
                : (((int) ($row[$unit->field('status')] ?? 0)) === 1 ? 'active' : 'inactive'),
            'createdBy'          => (string) ($row['created_by'] ?? 'unknown'),
            'createdDate'        => $row['created_at'] ?? null,
            'updatedDate'        => $row['updated_at'] ?? null,
        ];
    }

    public function audit(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(
            DB::table('hpbrain_audit_logs')
                ->where('tenant_id', $this->authTenantId($request))
                ->where('entity_type', 'Department')->where('entity_id', $id)
                ->orderByDesc('created_at')->get()
        );
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'parentId'    => ['sometimes', 'nullable', 'integer'],
        ]);

        $t = $this->authTenantId($request);
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');

        $map = [
            'name'        => $unit->field('name'),
            'description' => $unit->field('description'),
            'parentId'    => $unit->field('parent'),
        ];
        $fields = [];
        foreach ($data as $k => $v) { $fields[$map[$k]] = $v; }

        if ($fields === []) {
            return response()->json(['error' => 'no_fields_to_update'], 422);
        }

        $fields['updated_at'] = now()->format('Y-m-d H:i:s');
        $query = DB::table($unit->table)
            ->where($unit->primaryKey, $id)
            ->where($unit->tenantKey, $t)
            ->whereNull('deleted_at');

        $this->applyDepartmentVisibilityScope($query, $unit, $t);

        $n = $query->update($fields);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'department_not_found'], 404);
    }

    public function archive(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->authTenantId($request);
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');

        $query = DB::table($unit->table)
            ->where($unit->primaryKey, $id)
            ->where($unit->tenantKey, $t)
            ->whereNull('deleted_at');

        $this->applyDepartmentVisibilityScope($query, $unit, $t);

        $n = $query->update(['deleted_at' => now()->format('Y-m-d H:i:s')]);

        return $n ? response()->json(['ok' => true]) : response()->json(['error' => 'department_not_found'], 404);
    }

    public function twin(Request $request, string $tenantId, string $id): JsonResponse
    {
        $t = $this->authTenantId($request);
        $unit = $this->resolver->resolve($t, 'OrganizationUnit');
        $person = $this->resolver->resolve($t, 'Person');

        $query = DB::table($unit->table)
            ->where($unit->primaryKey, $id)
            ->where($unit->tenantKey, $t)
            ->whereNull('deleted_at');

        $this->applyDepartmentVisibilityScope($query, $unit, $t);

        $row = $query->first();

        if (! $row) {
            return response()->json(['error' => 'department_not_found'], 404);
        }

        $did = (string) $id;

        $personIds = DB::table($person->table)
            ->where($person->tenantKey, $t)
            ->where($person->field('unit'), $id)
            ->whereNull('deleted_at')
            ->where($person->field('status'), 1)
            ->pluck($person->primaryKey)->map(fn ($v) => (string) $v)->all();

        $assignments = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $t)
            ->where(function ($w) use ($did, $personIds) {
                $w->where(fn ($q) => $q->where('target_type', 'Department')->where('target_id', $did));
                if ($personIds !== []) {
                    $w->orWhere(fn ($q) => $q->where('target_type', 'Person')->whereIn('target_id', $personIds));
                }
            })
            ->get();

        $proficiency = $assignments->isEmpty() ? collect() : DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $t)
            ->whereIn('assignment_id', $assignments->pluck('id')->all())
            ->orderByDesc('assessed_date')
            ->get()
            ->groupBy('assignment_id')
            ->map(fn ($rows) => $rows->first());

        $capabilityHeatmap = $assignments
            ->groupBy('capability_id')
            ->map(function ($group, $capabilityId) use ($proficiency, $did) {
                $levels = [];

                foreach ($group as $a) {
                    $p = $proficiency->get($a->id);
                    if (! $p) { continue; }

                    $dims = array_values(array_filter(
                        [$p->knowledge_level, $p->ability_level, $p->skill_level, $p->behaviour_level, $p->attitude_level],
                        fn ($v) => $v !== null
                    ));

                    if ($dims !== []) {
                        $levels[] = array_sum(array_map('floatval', $dims)) / count($dims);
                    }
                }

                return [
                    'capabilityId'  => (string) $capabilityId,
                    'departmentId'  => $did,
                    'averageLevel'  => $levels === [] ? 0.0 : round(array_sum($levels) / count($levels), 2),
                    'assessedCount' => count($levels),
                ];
            })->values();

        $openRiskSignals = DB::table('hpbrain_signals')
            ->where('tenant_id', $t)
            ->where('department_id', $did)
            ->whereRaw('LOWER(status) NOT IN (?, ?, ?)', ['resolved', 'closed', 'dismissed'])
            ->whereRaw('LOWER(severity) IN (?, ?)', ['high', 'critical'])
            ->count();

        $decisions = $personIds === [] ? collect() : DB::table('hpbrain_decisions')
            ->where('tenant_id', $t)->whereIn('decided_by', $personIds)->get();

        $approved = $decisions->filter(
            fn ($d) => in_array(strtolower((string) $d->status), ['approved', 'accepted'], true)
        )->count();

        $timeline = DB::table('hpbrain_audit_logs')
            ->where('tenant_id', $t)
            ->where(function ($w) use ($did, $personIds) {
                $w->where(fn ($q) => $q->where('entity_type', 'Department')->where('entity_id', $did));
                if ($personIds !== []) {
                    $w->orWhereIn('actor_id', $personIds);
                }
            })
            ->orderByDesc('created_at')->limit(25)->get()
            ->map(fn ($a) => [
                'type'      => (string) $a->action,
                'actorId'   => (string) ($a->actor_id ?? ''),
                'createdAt' => $a->created_at,
            ])->values();

        $departmentPayload = $this->map((array) $row, $unit);

        return response()->json([
            'department'           => $departmentPayload,
            'personCount'          => count($personIds),
            'capabilityHeatmap'    => $capabilityHeatmap,
            'openRiskSignalCount'  => $openRiskSignals,
            'decisionCount'        => $decisions->count(),
            'decisionApprovalRate' => $decisions->isEmpty() ? null : round($approved / $decisions->count(), 4),
            'feeIntelligence'      => $this->feeIntelligenceForDepartment($t, (string) $departmentPayload['name']),
            'timeline'             => $timeline,
            'openCasesTenantWide'  => DB::table('hpbrain_cases')
                ->where('tenant_id', $t)->whereNotIn('status', ['closed'])->count(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function feeIntelligenceForDepartment(string $tenantId, string $departmentName): ?array
    {
        if (! Schema::hasTable('hpbrain_operational_records')) {
            return null;
        }

        $rows = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $tenantId)
            ->where('dataset', 'school_fee')
            ->where('area', $departmentName)
            ->get(['subject_ref', 'metric_value', 'status', 'payload']);

        if ($rows->isEmpty()) {
            return null;
        }

        $students = [];
        $net = 0.0;
        $paid = 0.0;
        $outstanding = 0.0;
        $overdue = 0.0;
        $expected = 0.0;
        $criticalStudents = [];
        $highStudents = [];
        $reminders = 0;
        $failedTransactions = 0;

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            $payload = is_array($payload) ? $payload : [];
            $studentRef = (string) ($row->subject_ref ?? $payload['student_id'] ?? '');
            if ($studentRef !== '') {
                $students[$studentRef] = true;
            }

            $net += is_numeric($row->metric_value) ? (float) $row->metric_value : (float) ($payload['net_fee_amount'] ?? $payload['net_amount'] ?? 0);
            $paid += is_numeric($payload['amount_paid'] ?? null) ? (float) $payload['amount_paid'] : 0.0;
            $balance = is_numeric($payload['outstanding_amount'] ?? null)
                ? (float) $payload['outstanding_amount']
                : (is_numeric($payload['balance_amount'] ?? null) ? (float) $payload['balance_amount'] : 0.0);
            $outstanding += $balance;

            $daysOverdue = is_numeric($payload['days_overdue'] ?? null) ? (int) $payload['days_overdue'] : 0;
            if ($daysOverdue > 0 || strtolower((string) ($row->status ?? '')) === 'overdue') {
                $overdue += $balance;
            }

            $expected += is_numeric($payload['expected_collectable_amount'] ?? null) ? (float) $payload['expected_collectable_amount'] : 0.0;
            $reminders += is_numeric($payload['reminder_count'] ?? null) ? (int) $payload['reminder_count'] : 0;

            if (! in_array(strtolower((string) ($payload['transaction_status'] ?? '')), ['', 'success', 'successful'], true)) {
                $failedTransactions++;
            }

            $risk = strtolower((string) ($payload['risk_band'] ?? $payload['risk_level'] ?? ''));
            if ($studentRef !== '' && $risk === 'critical') {
                $criticalStudents[$studentRef] = true;
            } elseif ($studentRef !== '' && $risk === 'high') {
                $highStudents[$studentRef] = true;
            }
        }

        return [
            'records' => $rows->count(),
            'students' => count($students),
            'net' => round($net, 2),
            'collected' => round($paid, 2),
            'outstanding' => round($outstanding, 2),
            'overdue' => round($overdue, 2),
            'expectedCollectable' => round($expected, 2),
            'atRiskAmount' => round(max(0, $outstanding - $expected), 2),
            'collectionRate' => $net > 0 ? round($paid / $net, 4) : null,
            'criticalStudents' => count($criticalStudents),
            'highStudents' => count($highStudents),
            'reminders' => $reminders,
            'failedTransactions' => $failedTransactions,
        ];
    }

    private function applyDepartmentVisibilityScope(Builder $query, ResolvedSource $unit, string $tenantId): void
    {
        if ($unit->table !== 'hrms_departments') {
            return;
        }

        if ($this->hasColumn($unit->table, 'is_calculated')) {
            $query->where(fn (Builder $w) => $w->where('is_calculated', 0)->orWhereNull('is_calculated'));
        }

        if (! $this->hasColumns($unit->table, ['is_calculated', 'created_by', 'created_at', 'deleted_at'])) {
            return;
        }

        $currentCohortStart = DB::table($unit->table)
            ->where($unit->tenantKey, $tenantId)
            ->whereNull('deleted_at')
            ->where(fn (Builder $w) => $w->where('is_calculated', 0)->orWhereNull('is_calculated'))
            ->whereNull('created_by')
            ->whereNotNull('created_at')
            ->min('created_at');

        if ($currentCohortStart === null) {
            return;
        }

        $hasTemplateRows = DB::table($unit->table)
            ->where($unit->tenantKey, $tenantId)
            ->whereNull('deleted_at')
            ->where('is_calculated', 1)
            ->exists();

        $hasOlderManualRows = DB::table($unit->table)
            ->where($unit->tenantKey, $tenantId)
            ->whereNull('deleted_at')
            ->where(fn (Builder $w) => $w->where('is_calculated', 0)->orWhereNull('is_calculated'))
            ->whereNotNull('created_by')
            ->where('created_at', '<', $currentCohortStart)
            ->exists();

        if ($hasTemplateRows && $hasOlderManualRows) {
            $query->where('created_at', '>=', $currentCohortStart);
        }
    }

    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! $this->hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }
}
