<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class NavigationBuilder
{
    public function build(string $tenantId, string $industryCode, string $roleKey, array $enabledModules, array $featureFlags): array
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

        return $this->filterAndBuildTree($items, $enabledModules, $featureFlags);
    }

    private function filterAndBuildTree(array $items, array $enabledModules, array $featureFlags): array
    {
        $byParent = [];
        $filtered = [];

        foreach ($items as $item) {
            $parentId = $item['parent_id'] ?? null;
            $byParent[$parentId ?? ''] = $byParent[$parentId ?? ''] ?? [];
            $byParent[$parentId ?? ''][] = $item;
        }

        $filter = function (?string $parentId) use (&$filter, $byParent, $enabledModules, $featureFlags, &$filtered): array {
            $result = [];
            $children = $byParent[$parentId ?? ''] ?? [];

            foreach ($children as $child) {
                $include = true;

                if (!empty($child['required_module']) && !in_array($child['required_module'], $enabledModules, true)) {
                    $include = false;
                }

                if (!empty($child['required_flag']) && !isset($featureFlags[$child['required_flag']])) {
                    $include = false;
                }

                if ($include) {
                    $child['children'] = $filter($child['id']);
                    $result[] = $child;
                    $filtered[] = $child;
                }
            }

            return $result;
        };

        return $filter(null);
    }
}
