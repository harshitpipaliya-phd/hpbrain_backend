<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Configuration;

use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A product module's own model choice, read and written — hpbrain_ai_module_model_bindings.
 *
 * Ported from G2G's ModuleModelBindings. The AI Stack inside an HP Brain area is
 * decentralised: what happens on the Signals area's Models tab stays in Signals. One
 * row per product module × capability × tenant.
 *
 *   `product_module`  what the page is about — `signals`, `kasba`. An hpbrain_ai_modules key.
 *   `capability`      what the call IS — `conversational_ai`, `signal_intelligence`.
 *                     An AiModuleRegistry key.
 *
 * It sits AHEAD of the capability configuration the central console writes, and only
 * for a caller that names the product module. A module with no binding resolves
 * exactly as it always did.
 *
 * PRECEDENCE: this tenant's own row, then the platform's ('*'), the same two steps
 * hpbrain_ai_api_keys uses.
 */
class ModuleModelBindings
{
    public const TABLE = 'hpbrain_ai_module_model_bindings';

    /** @var array<string, array<int, object>|null> Rows per tenant, fetched once per request. */
    private array $cache = [];

    public function available(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The binding that applies to one module's call of one kind, or null — the normal
     * answer, meaning "resolve as you would have".
     */
    public function find(?string $productModule, ?string $capability, string $tenantId): ?object
    {
        if ($productModule === null || $productModule === '' || $capability === null || $capability === '') {
            return null;
        }

        if (! $this->available()) {
            return null;
        }

        $rows = $this->rows($tenantId);

        if ($rows === null) {
            // A table outage falls through to the central configuration.
            return null;
        }

        foreach ([['module_binding', $tenantId], ['module_binding_platform', Platform::TENANT]] as [$source, $owner]) {
            foreach ($rows as $row) {
                if ((string) $row->product_module !== $productModule
                    || (string) $row->capability !== $capability
                    || (string) $row->tenant_id !== $owner) {
                    continue;
                }

                // A row with no provider chooses nothing and must not shadow the centre.
                if (trim((string) ($row->provider ?? '')) === '') {
                    continue;
                }

                $found = clone $row;
                $found->source = $source;

                return $found;
            }
        }

        return null;
    }

    /**
     * Every binding a module has, for the screen that edits them — the tenant's own
     * row where one exists, the platform's otherwise.
     *
     * @return array<string, object> capability => row
     */
    public function forModule(string $productModule, string $tenantId): array
    {
        if (! $this->available()) {
            return [];
        }

        $rows = $this->rows($tenantId) ?? [];
        $byCapability = [];

        foreach ([Platform::TENANT, $tenantId] as $owner) {
            foreach ($rows as $row) {
                if ((string) $row->tenant_id !== $owner || (string) $row->product_module !== $productModule) {
                    continue;
                }

                $byCapability[(string) $row->capability] = $row;
            }
        }

        return $byCapability;
    }

    /**
     * Save one module's choice for one capability, for this tenant only.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(string $productModule, string $capability, string $tenantId, array $values, ?string $actorId = null): ?object
    {
        if (! $this->available()) {
            return null;
        }

        $now = Platform::now();

        $payload = [
            'provider' => $this->trimOrNull($values['provider'] ?? null),
            'model' => $this->trimOrNull($values['model'] ?? null),
            'api_key_id' => $this->trimOrNull($values['api_key_id'] ?? null),
            'max_output_tokens' => isset($values['max_output_tokens']) && is_numeric($values['max_output_tokens'])
                && (int) $values['max_output_tokens'] > 0
                ? (int) $values['max_output_tokens']
                : null,
            'status' => array_key_exists('status', $values) && $values['status'] !== null ? (int) (bool) $values['status'] : 1,
            'updated_by' => $actorId,
            'updated_date' => $now,
        ];

        $existing = $this->own($productModule, $capability, $tenantId);

        if ($existing !== null) {
            DB::table(self::TABLE)->where('id', $existing->id)->where('tenant_id', $tenantId)->update($payload);
        } else {
            DB::table(self::TABLE)->insert($payload + [
                'id' => Platform::id(),
                'tenant_id' => $tenantId,
                'product_module' => $productModule,
                'capability' => $capability,
                'created_by' => $actorId,
                'created_date' => $now,
            ]);
        }

        $this->forget();

        return $this->own($productModule, $capability, $tenantId);
    }

    /**
     * Remove this tenant's choice. A platform row is never deleted from here — clearing
     * a choice must not clear it for every other tenant.
     */
    public function clear(string $productModule, string $capability, string $tenantId): bool
    {
        if (! $this->available()) {
            return false;
        }

        $deleted = DB::table(self::TABLE)
            ->where('product_module', $productModule)
            ->where('capability', $capability)
            ->where('tenant_id', $tenantId)
            ->delete();

        $this->forget();

        return $deleted > 0;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function own(string $productModule, string $capability, string $tenantId): ?object
    {
        return DB::table(self::TABLE)
            ->where('product_module', $productModule)
            ->where('capability', $capability)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /** @return array<int, object>|null Null, distinct from empty, when the table could not be read. */
    private function rows(string $tenantId): ?array
    {
        if (array_key_exists($tenantId, $this->cache)) {
            return $this->cache[$tenantId];
        }

        try {
            $rows = Platform::visible(DB::table(self::TABLE)->where('status', 1), $tenantId)
                ->orderByDesc('updated_date')
                ->get()
                ->all();
        } catch (Throwable) {
            return $this->cache[$tenantId] = null;
        }

        return $this->cache[$tenantId] = $rows;
    }

    private function trimOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
