<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrganizationTypeRepository;
use App\Repositories\OrganizationUnitRepository;
use App\Repositories\RoleRepository;
use App\Repositories\PositionRepository;
use Illuminate\Support\Facades\DB;

final class OrganizationEngine
{
    public function __construct(
        private readonly OrganizationTypeRepository $typeRepository,
        private readonly OrganizationUnitRepository $unitRepository,
        private readonly RoleRepository $roleRepository,
        private readonly PositionRepository $positionRepository,
    ) {
    }

    public function createOrganization(string $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $orgId = $data['org_id'] ?? $this->generateUuid();

            if (isset($data['units']) && is_array($data['units'])) {
                foreach ($data['units'] as $unit) {
                    $this->unitRepository->create($tenantId, [
                        'org_id'     => $orgId,
                        'unit_type'  => $unit['unit_type'] ?? 'department',
                        'name'       => $unit['name'],
                        'code'       => $unit['code'] ?? null,
                        'parent_unit_id' => $unit['parent_unit_id'] ?? null,
                        'head_id'    => $unit['head_id'] ?? null,
                        'status'     => $unit['status'] ?? 'active',
                        'created_by' => $data['created_by'],
                    ]);
                }
            }

            if (isset($data['roles']) && is_array($data['roles'])) {
                foreach ($data['roles'] as $role) {
                    $this->roleRepository->create($tenantId, [
                        'role_key'   => $role['role_key'],
                        'name'       => $role['name'],
                        'description'=> $role['description'] ?? null,
                        'category'   => $role['category'] ?? null,
                        'permissions'=> $role['permissions'] ?? [],
                        'is_system'  => (bool) ($role['is_system'] ?? false),
                        'status'     => $role['status'] ?? 'active',
                        'created_by' => $data['created_by'],
                    ]);
                }
            }

            return ['org_id' => $orgId, 'status' => 'created'];
        });
    }

    public function updateOrganization(string $tenantId, string $orgId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $orgId, $data) {
            if (isset($data['units']) && is_array($data['units'])) {
                foreach ($data['units'] as $unit) {
                    if (isset($unit['id'])) {
                        $this->unitRepository->update($tenantId, $unit['id'], $unit);
                    } else {
                        $this->unitRepository->create($tenantId, array_merge($unit, ['org_id' => $orgId, 'created_by' => $data['created_by']]));
                    }
                }
            }

            return ['org_id' => $orgId, 'status' => 'updated'];
        });
    }

    public function archiveOrganization(string $tenantId, string $orgId): bool
    {
        $units = $this->unitRepository->findByOrg($tenantId, $orgId);

        return DB::transaction(function () use ($tenantId, $orgId, $units) {
            foreach ($units as $unit) {
                $this->unitRepository->update($tenantId, $unit['id'], ['status' => 'archived']);
            }

            return true;
        });
    }

    public function getHierarchy(string $tenantId, string $orgId): array
    {
        return $this->unitRepository->getHierarchy($tenantId, $orgId);
    }

    public function getReportingStructure(string $tenantId, string $orgId, string $personId): array
    {
        $chain = [];
        $current = $personId;
        $visited = [];

        while ($current !== null && !isset($visited[$current])) {
            $visited[$current] = true;

            $reporters = DB::table('hpbrain_reporting_structures')
                ->where('tenant_id', $tenantId)
                ->where('org_id', $orgId)
                ->where('reportee_person_id', $current)
                ->where(function ($q) {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', date('Y-m-d'));
                })
                ->get(['reporter_person_id', 'reportee_person_id', 'reporting_type', 'unit_id'])
                ->map(fn ($r) => (array) $r)
                ->all();

            foreach ($reporters as $reporter) {
                $chain[] = $reporter;
            }

            $next = $reporters[0]['reporter_person_id'] ?? null;
            $current = $next;
        }

        return $chain;
    }

    public function assignRole(string $tenantId, string $personId, string $roleId, array $data): array
    {
        return app(PersonRoleRepository::class)->create($tenantId, array_merge($data, [
            'person_id' => $personId,
            'role_id'   => $roleId,
            'created_by'=> $data['created_by'] ?? 'system',
        ]));
    }

    public function assignSkill(string $tenantId, string $personId, string $skillId, array $data): array
    {
        return app(PersonSkillRepository::class)->create($tenantId, array_merge($data, [
            'person_id' => $personId,
            'skill_id'  => $skillId,
            'created_by'=> $data['created_by'] ?? 'system',
        ]));
    }

    public function assignCompetency(string $tenantId, string $personId, string $competencyId, array $data): array
    {
        return app(PersonCompetencyRepository::class)->create($tenantId, array_merge($data, [
            'person_id'     => $personId,
            'competency_id' => $competencyId,
            'created_by'    => $data['created_by'] ?? 'system',
        ]));
    }

    private function generateUuid(): string
    {
        return \Ramsey\Uuid\Uuid::uuid4()->toString();
    }
}
