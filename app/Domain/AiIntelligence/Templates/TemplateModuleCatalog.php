<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Templates;

use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The HP Brain product areas a template (or a policy) can be filed under —
 * rows in hpbrain_ai_modules.
 *
 * G2G seeded its equivalent from its menu table. HP Brain's areas are seeded as
 * platform rows ('*') by migration 2026_09_25_100004 — Signals, Evidence,
 * Deliberation, the Intelligence Workspace, Executions, Knowledge and so on — and
 * a tenant may add a row of its own that shadows a platform label.
 *
 * `__shared__` (column NULL) is a real choice: a template for every area.
 */
class TemplateModuleCatalog
{
    public const SHARED = '__shared__';

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $memo = [];

    /** @return array<int, array{key:string, label:string, description:?string, icon:?string, shared:bool}> */
    public function all(string $tenantId): array
    {
        if (isset($this->memo[$tenantId])) {
            return $this->memo[$tenantId];
        }

        $modules = [[
            'key' => self::SHARED,
            'label' => 'Shared — every module',
            'description' => 'Templates that apply across HP Brain, such as page and record analysis.',
            'icon' => 'layers',
            'shared' => true,
        ]];

        if (! Schema::hasTable('hpbrain_ai_modules')) {
            return $this->memo[$tenantId] = $modules;
        }

        $rows = Platform::visible(DB::table('hpbrain_ai_modules')->where('status', 1), $tenantId)
            // Tenant rows first, so a tenant's own label wins over the platform's.
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['module_key', 'label', 'description', 'icon', 'sort_order']);

        $seen = [];

        foreach ($rows as $row) {
            $key = (string) $row->module_key;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = [
                'key' => $key,
                'label' => (string) $row->label,
                'description' => $row->description === null ? null : (string) $row->description,
                'icon' => $row->icon === null ? null : (string) $row->icon,
                'shared' => false,
                'sort' => (int) $row->sort_order,
            ];
        }

        uasort($seen, fn ($a, $b) => [$a['sort'], $a['label']] <=> [$b['sort'], $b['label']]);

        $list = array_map(function (array $module) {
            unset($module['sort']);

            return $module;
        }, array_values($seen));

        return $this->memo[$tenantId] = array_merge($modules, $list);
    }

    /** @return array<int, string> */
    public function keys(string $tenantId): array
    {
        return array_values(array_filter(
            array_column($this->all($tenantId), 'key'),
            fn (string $key) => $key !== self::SHARED
        ));
    }

    public function exists(string $key, string $tenantId): bool
    {
        return $key === self::SHARED || in_array($key, $this->keys($tenantId), true);
    }

    public function label(?string $key, string $tenantId): string
    {
        if ($key === null || $key === '' || $key === self::SHARED) {
            return 'Shared — every module';
        }

        foreach ($this->all($tenantId) as $module) {
            if ($module['key'] === $key) {
                return $module['label'];
            }
        }

        return $key;
    }

    public function toColumn(?string $key): ?string
    {
        return $key === null || $key === '' || $key === self::SHARED ? null : $key;
    }

    public function fromColumn(?string $column): string
    {
        return $column === null || $column === '' ? self::SHARED : $column;
    }
}
