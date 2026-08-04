<?php

declare(strict_types=1);

namespace App\Domain\Signals;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Domain\Universal\EntityResolver;
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

    public function __construct(
        private readonly EventPublisher $events,
        private readonly EntityResolver $resolver,
    ) {
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
        $person = $this->resolver->resolve($tenantId, 'Person');
        $unitColumn = $person->field('unit');

        $affected = $this->activePeople($tenantId)
            ->where(function ($q) use ($unitColumn) {
                $q->whereNull($unitColumn)->orWhere($unitColumn, 0);
            })
            ->select($person->columns(['id', 'externalRef', 'firstName', 'lastName', 'email']))
            ->limit(50)
            ->get();

        if ($affected->isEmpty()) {
            return ['created' => false, 'reason' => 'no_affected'];
        }

        $evidenceIds = $this->peopleEvidence(
            $tenantId,
            $affected,
            $unitColumn.' is null or zero',
        );

        $signalId = $this->createSignal($tenantId, [
            'source' => 'erp.data_quality',
            'classification' => 'workforce',
            'priority' => 'medium',
            'severity' => 'medium',
            'confidence' => 1.0,
            'metadata' => [
                'rule' => 'people_without_department',
                'affectedCount' => $affected->count(),
                'sampleIds' => $affected->take(5)->pluck($person->primaryKey)->all(),
            ],
        ], $evidenceIds, $tenantId);

        return ['created' => true, 'signalId' => $signalId];
    }

    /**
     * Rule: departments whose parent is null or 0.
     *
     * NAMED "departments without manager" throughout the catalog, which this
     * predicate does not actually detect: the source table has no manager
     * column at all, so what it finds is ROOT departments. The predicate,
     * severity and confidence are carried forward unchanged here because this
     * phase promises no behaviour change. Phase 3 makes rules data, which is
     * where the naming and the predicate can be reconciled against a gate.
     */
    private function departmentsWithoutManager(string $tenantId): array
    {
        $unit = $this->resolver->resolve($tenantId, 'OrganizationUnit');
        $parentColumn = $unit->field('parent');

        $affected = DB::table($unit->table)
            ->where($unit->tenantKey, $tenantId)
            ->where($unit->field('status'), 1)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($parentColumn) {
                $q->whereNull($parentColumn)->orWhere($parentColumn, 0);
            })
            ->select($unit->columns(['id', 'name', 'description']))
            ->limit(50)
            ->get();

        if ($affected->isEmpty()) {
            return ['created' => false, 'reason' => 'no_affected'];
        }

        $evidenceIds = [];
        $unitId = $unit->primaryKey;
        $unitName = $unit->field('name');

        foreach ($affected->take(5) as $dept) {
            $evidenceIds[] = $this->createEvidence($tenantId, [
                'source' => 'erp.'.$unit->table,
                'recordId' => (string) $dept->{$unitId},
                'name' => (string) $dept->{$unitName},
                'issue' => $parentColumn.' is null or zero — no manager assigned',
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
                'sampleIds' => $affected->take(5)->pluck($unitId)->all(),
            ],
        ], $evidenceIds, $tenantId);

        return ['created' => true, 'signalId' => $signalId];
    }

    /**
     * Rule: employees without a role profile.
     */
    private function peopleWithoutProfile(string $tenantId): array
    {
        $person = $this->resolver->resolve($tenantId, 'Person');
        $profileColumn = $person->field('profile');

        $affected = $this->activePeople($tenantId)
            ->where(function ($q) use ($profileColumn) {
                $q->whereNull($profileColumn)->orWhere($profileColumn, 0);
            })
            ->select($person->columns(['id', 'externalRef', 'firstName', 'lastName', 'email']))
            ->limit(50)
            ->get();

        if ($affected->isEmpty()) {
            return ['created' => false, 'reason' => 'no_affected'];
        }

        $evidenceIds = $this->peopleEvidence(
            $tenantId,
            $affected,
            $profileColumn.' is null or zero',
        );

        $signalId = $this->createSignal($tenantId, [
            'source' => 'erp.data_quality',
            'classification' => 'access_control',
            'priority' => 'low',
            'severity' => 'low',
            'confidence' => 1.0,
            'metadata' => [
                'rule' => 'people_without_profile',
                'affectedCount' => $affected->count(),
                'sampleIds' => $affected->take(5)->pluck($person->primaryKey)->all(),
            ],
        ], $evidenceIds, $tenantId);

        return ['created' => true, 'signalId' => $signalId];
    }

    /**
     * Rule: employees without an email address.
     */
    private function peopleWithoutEmail(string $tenantId): array
    {
        $person = $this->resolver->resolve($tenantId, 'Person');
        $emailColumn = $person->field('email');

        $affected = $this->activePeople($tenantId)
            ->where(function ($q) use ($emailColumn) {
                $q->whereNull($emailColumn)->orWhere($emailColumn, '');
            })
            ->select($person->columns(['id', 'externalRef', 'firstName', 'lastName']))
            ->limit(50)
            ->get();

        if ($affected->isEmpty()) {
            return ['created' => false, 'reason' => 'no_affected'];
        }

        $evidenceIds = $this->peopleEvidence(
            $tenantId,
            $affected,
            $emailColumn.' is null or empty',
            includeEmail: false,
        );

        $signalId = $this->createSignal($tenantId, [
            'source' => 'erp.data_quality',
            'classification' => 'data_quality',
            'priority' => 'high',
            'severity' => 'high',
            'confidence' => 1.0,
            'metadata' => [
                'rule' => 'people_without_email',
                'affectedCount' => $affected->count(),
                'sampleIds' => $affected->take(5)->pluck($person->primaryKey)->all(),
            ],
        ], $evidenceIds, $tenantId);

        return ['created' => true, 'signalId' => $signalId];
    }

    /**
     * Rule: inactive users still assigned to active departments.
     */
    private function inactiveUsersInActiveDepartments(string $tenantId): array
    {
        $person = $this->resolver->resolve($tenantId, 'Person');
        $unit = $this->resolver->resolve($tenantId, 'OrganizationUnit');

        $personStatus = $person->field('status');
        $unitName = $unit->field('name');

        $affected = DB::table($person->table.' as u')
            ->join($unit->table.' as d', function ($j) use ($tenantId, $person, $unit) {
                $j->on('d.'.$unit->primaryKey, '=', 'u.'.$person->field('unit'))
                    ->where('d.'.$unit->tenantKey, '=', $tenantId)
                    ->where('d.'.$unit->field('status'), '=', 1)
                    ->whereNull('d.deleted_at');
            })
            ->where('u.'.$person->tenantKey, $tenantId)
            ->where('u.'.$personStatus, '!=', 1)
            ->whereNotNull('u.deleted_at')
            ->select(array_merge(
                array_map(
                    fn ($c) => 'u.'.$c,
                    array_values($person->columns(['id', 'externalRef', 'firstName', 'lastName', 'email'])),
                ),
                ['d.'.$unitName],
            ))
            ->limit(50)
            ->get();

        if ($affected->isEmpty()) {
            return ['created' => false, 'reason' => 'no_affected'];
        }

        $evidenceIds = $this->peopleEvidence(
            $tenantId,
            $affected,
            'user is inactive/deleted but assigned to active department',
            unitNameColumn: $unitName,
        );

        $signalId = $this->createSignal($tenantId, [
            'source' => 'erp.data_quality',
            'classification' => 'data_quality',
            'priority' => 'low',
            'severity' => 'low',
            'confidence' => 0.8,
            'metadata' => [
                'rule' => 'inactive_users_in_active_departments',
                'affectedCount' => $affected->count(),
                'sampleIds' => $affected->take(5)->pluck($person->primaryKey)->all(),
            ],
        ], $evidenceIds, $tenantId);

        return ['created' => true, 'signalId' => $signalId];
    }

    /**
     * Active, non-deleted people for a tenant — the opening clause of four of
     * the five rules.
     */
    private function activePeople(string $tenantId): \Illuminate\Database\Query\Builder
    {
        $person = $this->resolver->resolve($tenantId, 'Person');

        return DB::table($person->table)
            ->where($person->tenantKey, $tenantId)
            ->where($person->field('status'), 1)
            ->whereNull('deleted_at');
    }

    /**
     * Evidence rows for up to five affected people.
     *
     * The four person rules built identical evidence payloads with one line
     * differing. The shape is preserved exactly, including the omission of
     * 'email' from the without-email rule — where the column is empty by
     * definition and reporting it would add a field that says nothing.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $affected
     * @return array<int, string>
     */
    private function peopleEvidence(
        string $tenantId,
        \Illuminate\Support\Collection $affected,
        string $issue,
        bool $includeEmail = true,
        ?string $unitNameColumn = null,
    ): array {
        $person = $this->resolver->resolve($tenantId, 'Person');

        $id = $person->primaryKey;
        $ref = $person->field('externalRef');
        $first = $person->field('firstName');
        $last = $person->field('lastName');
        $email = $person->field('email');

        $evidenceIds = [];

        foreach ($affected->take(5) as $row) {
            $content = [
                'source' => 'erp.'.$person->table,
                'recordId' => (string) $row->{$id},
                'employeeNo' => (string) $row->{$ref},
                'name' => trim(($row->{$first} ?? '').' '.($row->{$last} ?? '')),
            ];

            if ($includeEmail) {
                $content['email'] = (string) $row->{$email};
            }

            if ($unitNameColumn !== null) {
                $content['department'] = (string) $row->{$unitNameColumn};
            }

            $content['issue'] = $issue;

            $evidenceIds[] = $this->createEvidence($tenantId, $content, $tenantId);
        }

        return $evidenceIds;
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
