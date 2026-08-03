<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\IndustryRepository;
use App\Repositories\BrandingRepository;
use App\Repositories\ModuleRepository;
use App\Repositories\OrganizationConfigRepository;
use App\Repositories\TerminologyRepository;
use Illuminate\Support\Facades\DB;

final class ConfigurationEngine
{
    public function __construct(
        private readonly OrganizationConfigRepository $orgConfigRepo,
        private readonly IndustryRepository $industryRepo,
        private readonly BrandingRepository $brandingRepo,
        private readonly ModuleRepository $moduleRepo,
        private readonly TerminologyRepository $terminologyRepo,
        private readonly TenantConfigCache $cache,
    ) {
    }

    public function get(string $tenantId, string $orgId, string $key, $default = null)
    {
        $cacheKey = "config.{$orgId}.{$key}";

        return $this->cache->remember($tenantId, $cacheKey, function () use ($tenantId, $orgId, $key, $default) {
            $row = $this->orgConfigRepo->findConfig($tenantId, $orgId, $key);

            return $row ? $this->decodeValue($row['config_value'], $row['config_type']) : $default;
        });
    }

    public function set(string $tenantId, string $orgId, string $key, $value, string $type = 'scalar'): array
    {
        $existing = $this->orgConfigRepo->findConfig($tenantId, $orgId, $key);

        if ($existing) {
            return $this->orgConfigRepo->update($tenantId, $existing['id'], [
                'config_value' => $type === 'json' ? json_encode($value) : (string) $value,
                'config_type'  => $type,
            ]);
        }

        return $this->orgConfigRepo->create($tenantId, [
            'org_id'      => $orgId,
            'config_key'  => $key,
            'config_value'=> $type === 'json' ? json_encode($value) : (string) $value,
            'config_type' => $type,
            'created_by'  => 'system',
        ]);
    }

    public function getIndustry(string $industryCode): ?array
    {
        return $this->industryRepo->findByCode('platform', $industryCode);
    }

    public function resolveTerminology(string $tenantId, string $industryCode, string $entityType): ?string
    {
        $row = $this->terminologyRepo->findByIndustryAndEntity($tenantId, $industryCode, $entityType);

        return $row ? $row['display_name'] : null;
    }

    public function getNavigation(string $tenantId, string $industryCode, string $roleKey): array
    {
        return $this->cache->remember($tenantId, "nav.{$industryCode}.{$roleKey}", function () use ($tenantId, $industryCode, $roleKey) {
            return $this->buildNavigation($tenantId, $industryCode, $roleKey);
        });
    }

    public function getBranding(string $tenantId, string $orgId): ?array
    {
        $branding = $this->brandingRepo->findByOrg($tenantId, $orgId);

        if ($branding) {
            return $branding;
        }

        $org = DB::table('institute_detail')->where('sub_institute_id', $orgId)->first();
        if ($org && $org->industry_type) {
            $industry = $this->industryRepo->findByCode($tenantId, $org->industry_type);
            if ($industry && isset($industry['settings']['branding'])) {
                return $industry['settings']['branding'];
            }
        }

        return null;
    }

    public function getModules(string $tenantId, string $orgId): array
    {
        return $this->cache->remember($tenantId, "modules.{$orgId}", function () use ($tenantId, $orgId) {
            $orgModules = DB::table('hpbrain_organization_modules')
                ->where('tenant_id', $tenantId)
                ->where('org_id', $orgId)
                ->where('is_enabled', true)
                ->pluck('module_id')
                ->all();

            $modules = DB::table('hpbrain_modules')
                ->where('tenant_id', $tenantId)
                ->where('is_enabled', true)
                ->get()
                ->map(fn ($m) => (array) $m)
                ->all();

            foreach ($modules as &$module) {
                $module['enabled_for_org'] = in_array($module['id'], $orgModules, true);
            }

            return $modules;
        });
    }

    public function isFeatureEnabled(string $tenantId, string $flagKey, array $context = []): bool
    {
        $cacheKey = "flag.{$flagKey}";

        return $this->cache->remember($tenantId, $cacheKey, function () use ($tenantId, $flagKey, $context) {
            $flag = DB::table('hpbrain_feature_flags')
                ->where('tenant_id', $tenantId)
                ->where('flag_key', $flagKey)
                ->where('enabled', true)
                ->orderByDesc('level')
                ->first();

            if (!$flag) {
                return false;
            }

            if ($flag->rollout_percentage < 100) {
                $userId = $context['user_id'] ?? null;
                if ($userId && (crc32($userId) % 100) >= $flag->rollout_percentage) {
                    return false;
                }
            }

            return true;
        });
    }

    public function getDashboard(string $tenantId, string $orgId, string $dashboardKey): ?array
    {
        $dashboard = DB::table('hpbrain_dashboards')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($orgId, $dashboardKey) {
                $q->where('org_id', $orgId)->where('dashboard_key', $dashboardKey);
            })
            ->orWhere(function ($q) use ($tenantId, $dashboardKey) {
                $q->where('tenant_id', $tenantId)->whereNull('org_id')->where('dashboard_key', $dashboardKey);
            })
            ->first();

        return $dashboard ? (array) $dashboard : null;
    }

    public function validateConfig(array $config, array $schema): bool
    {
        foreach ($schema['required'] ?? [] as $requiredKey) {
            if (!array_key_exists($requiredKey, $config)) {
                return false;
            }
        }

        return true;
    }

    private function decodeValue(string $value, string $type)
    {
        if ($type === 'json' && is_string($value)) {
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $value;
    }

    private function buildNavigation(string $tenantId, string $industryCode, string $roleKey): array
    {
        $items = DB::table('hpbrain_navigation_items')
            ->where('tenant_id', $tenantId)
            ->where('industry_code', $industryCode)
            ->where('role_key', $roleKey)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $tree = [];
        $byParent = [];

        foreach ($items as $item) {
            $parentId = $item['parent_id'] ?? null;
            $byParent[$parentId ?? ''] = $byParent[$parentId ?? ''] ?? [];
            $byParent[$parentId ?? ''][] = $item;
        }

        $buildTree = function (?string $parentId) use (&$buildTree, $byParent): array {
            $result = [];
            $children = $byParent[$parentId ?? ''] ?? [];

            foreach ($children as $child) {
                $child['children'] = $buildTree($child['id']);
                $result[] = $child;
            }

            return $result;
        };

        return $buildTree(null);
    }
}
