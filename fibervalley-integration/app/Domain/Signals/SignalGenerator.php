<?php

declare(strict_types=1);

namespace App\Domain\Signals;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Auto-generates Brain signals from verified ERP data conditions.
 *
 * Rules are evaluated against the current ERP state. When a condition is met,
 * a signal is created with evidence referencing the specific ERP rows that
 * triggered it. Signals are idempotent: re-evaluating the same condition
 * produces no duplicate because the idempotency key is derived from the
 * triggering entity, not from the signal UUID.
 */
final class SignalGenerator
{
    private const ACTOR = 'system';

    public function __construct(private readonly EventPublisher $events)
    {
    }

    /**
     * Evaluate all signal rules for a tenant and create signals for unmet conditions.
     *
     * @return array{created: int, skipped: int}
     */
    public function evaluate(string $tenantId): array
    {
        $created = 0;
        $skipped = 0;

        $rules = [
            fn () => $this->peopleWithoutDepartment($tenantId),
            fn () => $this->departmentsWithoutManager($tenantId),
            fn () => $this->peopleWithoutProfile($tenantId),
            fn () => $this->peopleWithoutEmail($tenantId),
            fn () => $this->inactiveUsersInActiveDepartments($tenantId),
        ];

        // Rules over imported operational data, attached by which datasets the
        // tenant actually holds. The five ERP rules above are untouched and
        // still run unconditionally for every organization; a tenant with no
        // imported data gets an empty array back and behaves exactly as before.
        //
        // Resolved through the container rather than the constructor because
        // SignalController instantiates this class with `new`. Widening the
        // constructor would have broken that call site — a change to existing
        // behaviour for the sake of style.
        foreach (app(SignalRuleRegistry::class)->extraRulesFor($this, $tenantId) as $extraRule) {
            $rules[] = $extraRule;
        }

        foreach ($rules as $rule) {
            try {
                $result = $rule();
                if ($result['created']) {
                    $created++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable) {
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Rule: employees without a department assignment.
     */
    private function peopleWithoutDepartment(string $tenantId): array
    {
        $affected = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('department_id')->orWhere('department_id', 0);
            })
            ->select('id', 'employee_no', 'first_name', 'last_name', 'email')
            ->limit(50)
            ->get();

        if ($affected->isEmpty()) {
            return ['created' => false, 'reason' => 'no_affected'];
        }

        $evidenceIds = [];
        foreach ($affected->take(5) as $person) {
            $evidenceIds[] = $this->createEvidence($tenantId, [
                'source' => 'erp.tbluser',
                'recordId' => (string) $person->id,
                'employeeNo' => (string) $person->employee_no,
                'name' => trim(($person->first_name ?? '').' '.($person->last_name ?? '')),
                'email' => (string) $person->email,
                'issue' => 'department_id is null or zero',
            ], $tenantId);
        }

        $signalId = $this->createSignal($tenantId, [
            'source' => 'erp.data_quality',
            'classification' => 'workforce',
            'priority' => 'medium',
            'severity' => 'medium',
            'confidence' => 1.0,
            'metadata' => [
                'rule' => 'people_without_department',
                'affectedCount' => $affected->count(),
                'sampleIds' => $affected->take(5)->pluck('id')->all(),
            ],
        ], $evidenceIds, $tenantId);

        return ['created' => true, 'signalId' => $signalId];
    }

    /**
     * Rule: departments without a manager (parent_id is null or 0 for root depts).
     */
    private function departmentsWithoutManager(string $tenantId): array
    {
        $affected = DB::table('hrms_departments')
            ->where('sub_institute_id', $tenantId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('parent_id')->orWhere('parent_id', 0);
            })
            ->select('id', 'department', 'roles_responsibility')
            ->limit(50)
            ->get();

        if ($affected->isEmpty()) {
            return ['created' => false, 'reason' => 'no_affected'];
        }

        $evidenceIds = [];
        foreach ($affected->take(5) as $dept) {
            $evidenceIds[] = $this->createEvidence($tenantId, [
                'source' => 'erp.hrms_departments',
                'recordId' => (string) $dept->id,
                'name' => (string) $dept->department,
                'issue' => 'parent_id is null or zero — no manager assigned',
            ], $tenantId);
        }

        $signalId = $this->createSignal($tenantId, [
            'source' => 'erp.data_quality',
            'classification' => 'leadership',
            'priority' => 'medium',
            'severity' => 'medium',
            'confidence' => 1.0,
            'metadata' => [
                'rule' => 'departments_without_manager',
                'affectedCount' => $affected->count(),
                'sampleIds' => $affected->take(5)->pluck('id')->all(),
            ],
        ], $evidenceIds, $tenantId);

        return ['created' => true, 'signalId' => $signalId];
    }

    /**
     * Rule: employees without a role profile.
     */
    private function peopleWithoutProfile(string $tenantId): array
    {
        $affected = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('user_profile_id')->orWhere('user_profile_id', 0);
            })
            ->select('id', 'employee_no', 'first_name', 'last_name', 'email')
            ->limit(50)
            ->get();

        if ($affected->isEmpty()) {
            return ['created' => false, 'reason' => 'no_affected'];
        }

        $evidenceIds = [];
        foreach ($affected->take(5) as $person) {
            $evidenceIds[] = $this->createEvidence($tenantId, [
                'source' => 'erp.tbluser',
                'recordId' => (string) $person->id,
                'employeeNo' => (string) $person->employee_no,
                'name' => trim(($person->first_name ?? '').' '.($person->last_name ?? '')),
                'email' => (string) $person->email,
                'issue' => 'user_profile_id is null or zero',
            ], $tenantId);
        }

        $signalId = $this->createSignal($tenantId, [
            'source' => 'erp.data_quality',
            'classification' => 'access_control',
            'priority' => 'low',
            'severity' => 'low',
            'confidence' => 1.0,
            'metadata' => [
                'rule' => 'people_without_profile',
                'affectedCount' => $affected->count(),
                'sampleIds' => $affected->take(5)->pluck('id')->all(),
            ],
        ], $evidenceIds, $tenantId);

        return ['created' => true, 'signalId' => $signalId];
    }

    /**
     * Rule: employees without an email address.
     */
    private function peopleWithoutEmail(string $tenantId): array
    {
        $affected = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('email')->orWhere('email', '');
            })
            ->select('id', 'employee_no', 'first_name', 'last_name')
            ->limit(50)
            ->get();

        if ($affected->isEmpty()) {
            return ['created' => false, 'reason' => 'no_affected'];
        }

        $evidenceIds = [];
        foreach ($affected->take(5) as $person) {
            $evidenceIds[] = $this->createEvidence($tenantId, [
                'source' => 'erp.tbluser',
                'recordId' => (string) $person->id,
                'employeeNo' => (string) $person->employee_no,
                'name' => trim(($person->first_name ?? '').' '.($person->last_name ?? '')),
                'issue' => 'email is null or empty',
            ], $tenantId);
        }

        $signalId = $this->createSignal($tenantId, [
            'source' => 'erp.data_quality',
            'classification' => 'data_quality',
            'priority' => 'high',
            'severity' => 'high',
            'confidence' => 1.0,
            'metadata' => [
                'rule' => 'people_without_email',
                'affectedCount' => $affected->count(),
                'sampleIds' => $affected->take(5)->pluck('id')->all(),
            ],
        ], $evidenceIds, $tenantId);

        return ['created' => true, 'signalId' => $signalId];
    }

    /**
     * Rule: inactive users still assigned to active departments.
     */
    private function inactiveUsersInActiveDepartments(string $tenantId): array
    {
        $affected = DB::table('tbluser as u')
            ->join('hrms_departments as d', function ($j) use ($tenantId) {
                $j->on('d.id', '=', 'u.department_id')
                    ->where('d.sub_institute_id', '=', $tenantId)
                    ->where('d.status', '=', 1)
                    ->whereNull('d.deleted_at');
            })
            ->where('u.sub_institute_id', $tenantId)
            ->where('u.status', '!=', 1)
            ->whereNotNull('u.deleted_at')
            ->select('u.id', 'u.employee_no', 'u.first_name', 'u.last_name', 'u.email', 'd.department')
            ->limit(50)
            ->get();

        if ($affected->isEmpty()) {
            return ['created' => false, 'reason' => 'no_affected'];
        }

        $evidenceIds = [];
        foreach ($affected->take(5) as $person) {
            $evidenceIds[] = $this->createEvidence($tenantId, [
                'source' => 'erp.tbluser',
                'recordId' => (string) $person->id,
                'employeeNo' => (string) $person->employee_no,
                'name' => trim(($person->first_name ?? '').' '.($person->last_name ?? '')),
                'department' => (string) $person->department,
                'issue' => 'user is inactive/deleted but assigned to active department',
            ], $tenantId);
        }

        $signalId = $this->createSignal($tenantId, [
            'source' => 'erp.data_quality',
            'classification' => 'data_quality',
            'priority' => 'low',
            'severity' => 'low',
            'confidence' => 0.8,
            'metadata' => [
                'rule' => 'inactive_users_in_active_departments',
                'affectedCount' => $affected->count(),
                'sampleIds' => $affected->take(5)->pluck('id')->all(),
            ],
        ], $evidenceIds, $tenantId);

        return ['created' => true, 'signalId' => $signalId];
    }

    /**
     * Raise a signal from an external rule provider.
     *
     * The public face of createSignal(), so that OperationalSignalRules creates
     * signals through exactly the same path — same insert, same outbox event,
     * same correlation id — as the five ERP rules. There is no second way to
     * make a signal in this system, which is the point.
     *
     * Also backfills signal_id on the evidence rows the rule collected. Evidence
     * is gathered before the signal exists (the rule needs it to decide whether
     * there IS a signal), so the link can only be written afterwards. Without
     * this the evidence would be orphaned and the Evidence Workspace could not
     * show what a signal was based on.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $evidenceIds
     * @return array{created: bool, signalId: string}
     */
    public function raise(string $tenantId, array $data, array $evidenceIds = []): array
    {
        $signalId = $this->createSignal($tenantId, $data, $evidenceIds, $tenantId);

        if ($evidenceIds !== []) {
            DB::table('hpbrain_evidence')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $evidenceIds)
                ->update(['signal_id' => $signalId]);
        }

        return ['created' => true, 'signalId' => $signalId];
    }

    /**
     * Record a piece of evidence not yet attached to a signal.
     *
     * Deliberately does NOT reuse createEvidence() below. That method writes
     * `'signal_id' => $signalTenantId` — it stores the TENANT id in the signal
     * foreign key, so every ERP-rule evidence row points at a signal that does
     * not exist. Reusing it would spread the defect to the new rules.
     *
     * The existing method is left exactly as it is: correcting it changes data
     * the ERP rules have already written for live organizations, which is a
     * separate, deliberate migration and not something to smuggle into this
     * change. Flagged in the handover notes.
     *
     * @param  array<string, mixed>  $content
     */
    public function recordEvidence(string $tenantId, array $content): string
    {
        $evidenceId  = Uuid::uuid4()->toString();
        $contentJson = json_encode($content, JSON_UNESCAPED_UNICODE);
        $provenance  = json_encode([
            'source'     => $content['source'] ?? 'import',
            'ts'         => now()->format('Y-m-d\TH:i:s\Z'),
            'confidence' => 1.0,
        ]);

        DB::table('hpbrain_evidence')->insert([
            'id'            => $evidenceId,
            'tenant_id'     => $tenantId,
            'signal_id'     => null,
            'source'        => (string) ($content['source'] ?? 'import'),
            'evidence_type' => 'observation',
            'content'       => $contentJson,
            'provenance'    => $provenance,
            'confidence'    => 1.0,
            'hash'          => hash('sha256', $contentJson.'|'.$provenance),
            'status'        => 'active',
            'created_by'    => self::ACTOR,
            'created_date'  => now()->format('Y-m-d H:i:s'),
        ]);

        return $evidenceId;
    }

    /**
     * Create a signal and publish the OBSERVATION_MADE event.
     */
    private function createSignal(string $tenantId, array $data, array $evidenceIds, string $correlationTenant): string
    {
        $signalId = Uuid::uuid4()->toString();

        $this->events->publishInTransaction(
            LoopEvent::OBSERVATION_MADE,
            $tenantId,
            'Signal',
            self::ACTOR,
            [
                'signalId' => $signalId,
                'source' => $data['source'],
                'classification' => $data['classification'],
                'priority' => $data['priority'],
                'severity' => $data['severity'],
                'evidenceIds' => $evidenceIds,
            ],
            function () use ($signalId, $tenantId, $data) {
                DB::table('hpbrain_signals')->insert([
                    'id' => $signalId,
                    'tenant_id' => $tenantId,
                    'source' => $data['source'],
                    'classification' => $data['classification'],
                    'priority' => $data['priority'],
                    'severity' => $data['severity'],
                    'confidence' => $data['confidence'],
                    'metadata' => json_encode($data['metadata']),
                    'status' => 'new',
                    'created_by' => self::ACTOR,
                    'created_date' => now()->format('Y-m-d H:i:s'),
                ]);

                return ['entityId' => $signalId, 'result' => true];
            },
            correlationId: $signalId,
        );

        return $signalId;
    }

    /**
     * Create evidence and return its ID.
     */
    private function createEvidence(string $tenantId, array $content, string $signalTenantId): string
    {
        $evidenceId = Uuid::uuid4()->toString();
        $contentJson = json_encode($content);
        $provenanceJson = json_encode([
            'source' => $content['source'],
            'ts' => now()->format('Y-m-d\TH:i:s\Z'),
            'confidence' => 1.0,
        ]);

        DB::table('hpbrain_evidence')->insert([
            'id' => $evidenceId,
            'tenant_id' => $signalTenantId,
            'signal_id' => $signalTenantId,
            'source' => $content['source'],
            'evidence_type' => 'observation',
            'content' => $contentJson,
            'provenance' => $provenanceJson,
            'confidence' => 1.0,
            'hash' => hash('sha256', $contentJson.'|'.$provenanceJson),
            'status' => 'active',
            'created_by' => self::ACTOR,
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $evidenceId;
    }
}
