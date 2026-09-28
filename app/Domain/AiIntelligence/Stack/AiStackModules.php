<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Stack;

use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The HP Brain areas that have a per-module AI Stack — hpbrain_ai_modules rows — as the
 * AI Stack routes see them.
 *
 * One lookup rule, used by every AI Stack controller: the tenant's own row first, then
 * the platform's ('*'), and only an ACTIVE row counts. A `{module}` that is not such a
 * row is a 404, so a module key in a URL can never name something this tenant cannot see.
 *
 * `capabilities` / `registry_keys` come from the first visible row that carries them, so
 * a tenant row that only re-labels an area does not silently switch its AI Stack off.
 */
class AiStackModules
{
    public const TABLE = 'hpbrain_ai_modules';

    /** @var array<string, array<int, object>> */
    private array $rows = [];

    /** The active row this tenant sees for a module key, or null. */
    public function find(string $module, string $tenantId): ?object
    {
        $rows = $this->rowsFor($module, $tenantId);

        if ($rows === [] || (int) $rows[0]->status !== 1) {
            return null;
        }

        return $rows[0];
    }

    public function exists(string $module, string $tenantId): bool
    {
        return $this->find($module, $tenantId) !== null;
    }

    public function label(string $module, string $tenantId): string
    {
        $row = $this->rowsFor($module, $tenantId)[0] ?? null;

        return $row === null ? $module : (string) $row->label;
    }

    /** @return array{key:string, label:string, registered:bool, status?:int, scope?:string, capabilities:array<string,bool>} */
    public function identity(string $module, string $tenantId): array
    {
        $row = $this->rowsFor($module, $tenantId)[0] ?? null;

        if ($row === null) {
            return ['key' => $module, 'label' => $module, 'registered' => false, 'capabilities' => []];
        }

        return [
            'key' => (string) $row->module_key,
            'label' => (string) $row->label,
            'registered' => true,
            'status' => (int) $row->status,
            'scope' => Platform::isPlatform($row) ? 'platform' : 'institute',
            'capabilities' => $this->capabilities($module, $tenantId),
        ];
    }

    /** @return array<string, bool> The module's AI Stack capability flags. */
    public function capabilities(string $module, string $tenantId): array
    {
        $out = [];

        foreach ($this->json($module, $tenantId, 'capabilities') as $key => $enabled) {
            $out[(string) $key] = (bool) $enabled;
        }

        return $out;
    }

    /** @return array<int, string> The module's AiModuleRegistry consumer keys. */
    public function registryKeys(string $module, string $tenantId): array
    {
        return array_values(array_filter($this->json($module, $tenantId, 'registry_keys'), 'is_string'));
    }

    /**
     * The module's catalogue entry as the AI Stack profile serves it — every field read
     * from hpbrain_ai_modules, the tenant's row shadowing the platform's.
     *
     * @return array{key:string, label:string, description:?string, icon:?string, capabilities:array<string,bool>, registry_keys:array<int,string>}|null
     */
    public function descriptor(string $module, string $tenantId): ?array
    {
        $row = $this->find($module, $tenantId);

        if ($row === null) {
            return null;
        }

        return [
            'key' => (string) $row->module_key,
            'label' => (string) $row->label,
            'description' => $this->firstText($module, $tenantId, 'description'),
            'icon' => $this->firstText($module, $tenantId, 'icon'),
            'capabilities' => $this->capabilities($module, $tenantId),
            'registry_keys' => $this->registryKeys($module, $tenantId),
        ];
    }

    /**
     * The tool-agent presets the module offers (hpbrain_ai_modules.presets, JSON), from
     * the first visible row that carries any.
     *
     * @return array<int, array{name:string, description:string, module:string, tools_allowed:array<int,string>, instructions:string, status:string}>
     */
    public function presets(string $module, string $tenantId): array
    {
        $out = [];

        foreach ($this->json($module, $tenantId, 'presets') as $preset) {
            if (! is_array($preset) || trim((string) ($preset['name'] ?? '')) === '') {
                continue;
            }

            $out[] = [
                'name' => (string) $preset['name'],
                'description' => (string) ($preset['description'] ?? ''),
                'module' => $module,
                'tools_allowed' => array_values(array_filter((array) ($preset['tools_allowed'] ?? []), 'is_string')),
                'instructions' => (string) ($preset['instructions'] ?? ''),
                'status' => in_array($preset['status'] ?? null, ['draft', 'active'], true) ? (string) $preset['status'] : 'draft',
            ];
        }

        return $out;
    }

    /** The first non-empty value of a text column across the visible rows, tenant's first. */
    private function firstText(string $module, string $tenantId, string $column): ?string
    {
        foreach ($this->rowsFor($module, $tenantId) as $row) {
            $value = trim((string) ($row->{$column} ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Keys of the active modules this tenant sees that have an AI Stack (a
     * `capabilities` map) — the modules a tool agent may belong to.
     *
     * @return array<int, string>
     */
    public function stackKeys(string $tenantId): array
    {
        if (! $this->available() || ! $this->hasColumn('capabilities')) {
            return [];
        }

        $rows = Platform::visible(DB::table(self::TABLE), $tenantId)
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
            ->get(['module_key', 'status', 'capabilities', 'tenant_id']);

        $seen = [];

        foreach ($rows as $row) {
            $key = (string) $row->module_key;
            $seen[$key] ??= ['active' => (int) $row->status === 1, 'stack' => false];

            if (trim((string) ($row->capabilities ?? '')) !== '') {
                $seen[$key]['stack'] = true;
            }
        }

        return array_keys(array_filter($seen, fn (array $s) => $s['active'] && $s['stack']));
    }

    public function available(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    private function hasColumn(string $column): bool
    {
        try {
            return Schema::hasColumn(self::TABLE, $column);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<mixed> */
    private function json(string $module, string $tenantId, string $column): array
    {
        if (! $this->hasColumn($column)) {
            return [];
        }

        foreach ($this->rowsFor($module, $tenantId) as $row) {
            $raw = $row->{$column} ?? null;

            if (trim((string) $raw) === '') {
                continue;
            }

            return Platform::decode($raw);
        }

        return [];
    }

    /** @return array<int, object> Visible rows for one key, tenant's first. */
    private function rowsFor(string $module, string $tenantId): array
    {
        $cacheKey = $tenantId . '|' . $module;

        if (isset($this->rows[$cacheKey])) {
            return $this->rows[$cacheKey];
        }

        if (! $this->available()) {
            return $this->rows[$cacheKey] = [];
        }

        return $this->rows[$cacheKey] = Platform::visible(DB::table(self::TABLE)->where('module_key', $module), $tenantId)
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
            ->get()
            ->all();
    }
}
