<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConfigVersionRepository;
use App\Repositories\OrganizationModuleRepository;
use App\Repositories\ModuleRepository;
use Illuminate\Support\Facades\DB;

final class ConfigVersionService
{
    public function __construct(
        private readonly ConfigVersionRepository $configVersionRepo,
        private readonly OrganizationModuleRepository $orgModuleRepo,
        private readonly ModuleRepository $moduleRepo,
    ) {
    }

    public function createVersion(
        string $tenantId,
        string $orgId,
        string $configType,
        string $configKey,
        array $data,
        string $changeSummary,
        string $createdBy
    ): array {
        $existing = $this->configVersionRepo->list($tenantId, $configType, $configKey);
        $maxVersion = 0;

        foreach ($existing as $version) {
            if ($version['status'] !== 'rolled_back') {
                $maxVersion = max($maxVersion, (int) $version['version']);
            }
        }

        return $this->configVersionRepo->create($tenantId, [
            'org_id'         => $orgId,
            'config_type'    => $configType,
            'config_key'     => $configKey,
            'version'        => $maxVersion + 1,
            'data'           => $data,
            'status'         => 'draft',
            'change_summary' => $changeSummary,
            'created_by'     => $createdBy,
        ]);
    }

    public function activateVersion(string $tenantId, string $versionId, string $activatedBy): ?array
    {
        $version = $this->configVersionRepo->find($tenantId, $versionId);

        if (!$version) {
            return null;
        }

        DB::transaction(function () use ($tenantId, $version, $versionId, $activatedBy) {
            DB::table('hpbrain_config_versions')
                ->where('tenant_id', $tenantId)
                ->where('config_type', $version['config_type'])
                ->where('config_key', $version['config_key'])
                ->where('status', 'active')
                ->update([
                    'status'         => 'archived',
                    'updated_date'   => DB::raw('CURRENT_TIMESTAMP'),
                ]);

            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            DB::table('hpbrain_config_versions')
                ->where('id', $versionId)
                ->update([
                    'status'          => 'active',
                    'activated_by'    => $activatedBy,
                    'activated_date'  => $now,
                    'updated_date'    => $now,
                ]);
        });

        return $this->configVersionRepo->find($tenantId, $versionId);
    }

    public function rollbackVersion(string $tenantId, string $versionId, string $rolledBackBy): ?array
    {
        $version = $this->configVersionRepo->find($tenantId, $versionId);

        if (!$version) {
            return null;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        DB::transaction(function () use ($tenantId, $version, $versionId, $rolledBackBy, $now) {
            DB::table('hpbrain_config_versions')
                ->where('tenant_id', $tenantId)
                ->where('config_type', $version['config_type'])
                ->where('config_key', $version['config_key'])
                ->where('status', 'active')
                ->update([
                    'status'         => 'rolled_back',
                    'rolled_back_by' => $rolledBackBy,
                    'rolled_back_date' => $now,
                    'updated_date'   => $now,
                ]);

            DB::table('hpbrain_config_versions')
                ->where('id', $versionId)
                ->update([
                    'status'          => 'active',
                    'activated_by'    => $rolledBackBy,
                    'activated_date'  => $now,
                    'updated_date'    => $now,
                ]);
        });

        return $this->configVersionRepo->find($tenantId, $versionId);
    }

    public function getActiveVersion(string $tenantId, string $configType, string $configKey): ?array
    {
        return $this->configVersionRepo->getActiveVersion($tenantId, $configType, $configKey);
    }

    public function getVersionHistory(string $tenantId, string $configType, string $configKey): array
    {
        return $this->configVersionRepo->list($tenantId, $configType, $configKey);
    }
}
