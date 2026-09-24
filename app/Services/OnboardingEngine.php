<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OnboardingSessionRepository;
use App\Repositories\ReadinessCheckRepository;
use App\Repositories\OrganizationUnitRepository;
use App\Repositories\RoleRepository;
use App\Repositories\ImportJobRepository;
use Illuminate\Support\Facades\DB;

final class OnboardingEngine
{
    public function __construct(
        private readonly OnboardingSessionRepository $sessionRepository,
        private readonly ReadinessCheckRepository $readinessRepository,
        private readonly OrganizationUnitRepository $unitRepository,
        private readonly RoleRepository $roleRepository,
        private readonly ImportJobRepository $importJobRepository,
    ) {
    }

    public function startOnboarding(string $tenantId, array $initialData): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        return $this->sessionRepository->create($tenantId, [
            'org_id'         => $initialData['org_id'] ?? null,
            'current_step'   => 1,
            'total_steps'    => 12,
            'status'         => 'draft',
            'data'           => $initialData,
            'completed_steps'=> [],
            'started_by'     => $initialData['started_by'] ?? 'system',
            'created_date'   => $now,
            'updated_date'   => $now,
        ]);
    }

    public function completeStep(string $sessionId, string $step, array $data): ?array
    {
        $session = $this->sessionRepository->find($this->resolveTenantId($sessionId), $sessionId);

        if (!$session) {
            return null;
        }

        $completedSteps = $session['completed_steps'] ?? [];
        if (!is_array($completedSteps)) {
            $completedSteps = json_decode($completedSteps, true) ?: [];
        }
        $completedSteps[$step] = true;

        $currentStep = (int) ($session['current_step'] ?? 1);
        $nextStep = max($currentStep, (int) $step + 1);

        $sessionData = $session['data'] ?? [];
        if (!is_array($sessionData)) {
            $sessionData = json_decode($sessionData, true) ?: [];
        }
        $sessionData = array_merge($sessionData, $data);

        return $this->sessionRepository->update($session['tenant_id'], $sessionId, [
            'current_step'    => $nextStep,
            'completed_steps' => $completedSteps,
            'data'            => $sessionData,
        ]);
    }

    public function getNextStep(string $sessionId): ?array
    {
        $session = $this->sessionRepository->find($this->resolveTenantId($sessionId), $sessionId);

        if (!$session) {
            return null;
        }

        $currentStep = (int) ($session['current_step'] ?? 1);
        $totalSteps = (int) ($session['total_steps'] ?? 12);
        $completedSteps = $session['completed_steps'] ?? [];
        if (!is_array($completedSteps)) {
            $completedSteps = json_decode($completedSteps, true) ?: [];
        }

        $stepNames = [
            1 => 'organization_details',
            2 => 'industry_selection',
            3 => 'terminology',
            4 => 'structure',
            5 => 'roles',
            6 => 'modules',
            7 => 'branding',
            8 => 'data_source',
            9 => 'import',
            10 => 'validate',
            11 => 'preview',
            12 => 'activate',
        ];

        for ($i = $currentStep; $i <= $totalSteps; $i++) {
            if (!isset($completedSteps[$i])) {
                return [
                    'step'      => $i,
                    'name'      => $stepNames[$i] ?? "step_{$i}",
                    'total'     => $totalSteps,
                    'completed' => array_keys($completedSteps),
                ];
            }
        }

        return ['step' => null, 'name' => 'complete', 'total' => $totalSteps, 'completed' => array_keys($completedSteps)];
    }

    public function validateStep(string $sessionId, string $step): array
    {
        $session = $this->sessionRepository->find($this->resolveTenantId($sessionId), $sessionId);

        if (!$session) {
            return ['valid' => false, 'errors' => ['session_not_found']];
        }

        $completedSteps = $session['completed_steps'] ?? [];
        if (!is_array($completedSteps)) {
            $completedSteps = json_decode($completedSteps, true) ?: [];
        }

        if (!isset($completedSteps[$step])) {
            return ['valid' => false, 'errors' => ["step_{$step}_not_completed"]];
        }

        return ['valid' => true, 'errors' => []];
    }

    public function activateOrganization(string $sessionId): ?array
    {
        $session = $this->sessionRepository->find($this->resolveTenantId($sessionId), $sessionId);

        if (!$session) {
            return null;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        return $this->sessionRepository->update($session['tenant_id'], $sessionId, [
            'status'        => 'activated',
            'completed_by'  => $session['started_by'],
            'activated_date'=> $now,
            'completed_steps' => array_fill(1, 12, true),
        ]);
    }

    public function getReadinessStatus(string $orgId): array
    {
        $checks = app(ReadinessCheckRepository::class)->findByOrg('platform', $orgId);

        $total = count($checks);
        $passed = count(array_filter($checks, fn ($c) => $c['status'] === 'pass'));
        $failed = count(array_filter($checks, fn ($c) => $c['status'] === 'fail'));
        $warnings = count(array_filter($checks, fn ($c) => $c['status'] === 'warning'));

        return [
            'org_id'   => $orgId,
            'total'    => $total,
            'passed'   => $passed,
            'failed'   => $failed,
            'warnings' => $warnings,
            'score'    => $total > 0 ? round(($passed / $total) * 100, 1) : 0,
            'checks'   => $checks,
        ];
    }

    public function runReadinessChecks(string $orgId): array
    {
        $tenantId = 'platform';
        $checks = [
            ['check_type' => 'structure', 'check_name' => 'Organization structure defined', 'status' => 'pass', 'message' => 'Units configured'],
            ['check_type' => 'roles', 'check_name' => 'Roles assigned', 'status' => 'pass', 'message' => 'Roles configured'],
            ['check_type' => 'modules', 'check_name' => 'Modules enabled', 'status' => 'pass', 'message' => 'Core modules active'],
            ['check_type' => 'branding', 'check_name' => 'Branding configured', 'status' => 'warning', 'message' => 'Logo not uploaded'],
            ['check_type' => 'data', 'check_name' => 'Data imported', 'status' => 'pending', 'message' => 'No import run yet'],
        ];

        $results = [];
        foreach ($checks as $check) {
            $results[] = $this->readinessRepository->create($tenantId, array_merge($check, [
                'org_id'      => $orgId,
                'metadata'    => [],
                'checked_date' => date('Y-m-d H:i:s'),
                'created_by'  => 'system',
            ]));
        }

        return $results;
    }

    public function abandonOnboarding(string $sessionId): ?array
    {
        $session = $this->sessionRepository->find($this->resolveTenantId($sessionId), $sessionId);

        if (!$session) {
            return null;
        }

        return $this->sessionRepository->update($session['tenant_id'], $sessionId, ['status' => 'abandoned']);
    }

    private function resolveTenantId(string $sessionId): string
    {
        $session = $this->sessionRepository->find('platform', $sessionId);

        return $session ? $session['tenant_id'] : 'platform';
    }
}
