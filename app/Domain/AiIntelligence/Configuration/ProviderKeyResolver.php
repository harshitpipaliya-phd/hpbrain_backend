<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Configuration;

use App\Domain\AiIntelligence\Support\ApiKeyVault;
use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One tenant-aware lookup for a provider's pool credential.
 *
 * The tenant's own key wins, the platform key (`tenant_id = '*'`) is the fallback,
 * and within either the newest active row wins, which is what a rotated key
 * expects. When the pool has nothing, the env credential from config/brain.php is
 * used — so nothing changes for a deployment until somebody saves a row.
 *
 * Ported from G2G's ProviderKeyResolver; reads hpbrain_ai_api_keys and decrypts.
 */
class ProviderKeyResolver
{
    public function __construct(private readonly ApiKeyVault $vault)
    {
    }

    /**
     * @return array{api_key:string, api_limit:int|null, id:string|null, scope:string}|null
     */
    public function resolve(string $apiType, string $tenantId, ?string $envFallback = null): ?array
    {
        $row = $this->fromPool($apiType, $tenantId);

        if ($row !== null) {
            return $row;
        }

        $envKey = trim((string) $envFallback, " \t\n\r\0\x0B'\"");

        if ($envKey === '') {
            return null;
        }

        return ['api_key' => $envKey, 'api_limit' => null, 'id' => null, 'scope' => 'env'];
    }

    /** @return array{api_key:string, api_limit:int|null, id:string|null, scope:string}|null */
    private function fromPool(string $apiType, string $tenantId): ?array
    {
        if (! Schema::hasTable('hpbrain_ai_api_keys')) {
            return null;
        }

        try {
            // Any active key for the provider, as in G2G: the pool is "whatever
            // credential this tenant (or the platform) holds for the driver".
            $rows = Platform::visible(
                DB::table('hpbrain_ai_api_keys')
                    ->where('api_type', $apiType)
                    ->where('status', 1),
                $tenantId
            )
                ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
                ->orderByDesc('created_date')
                ->get();
        } catch (Throwable) {
            return null;
        }

        foreach ($rows as $row) {
            $key = $this->vault->open($row->api_key ?? null);

            if ($key === null) {
                continue;
            }

            return [
                'api_key' => $key,
                'api_limit' => is_numeric($row->api_limit ?? null) ? (int) $row->api_limit : null,
                'id' => (string) $row->id,
                'scope' => Platform::isPlatform($row) ? 'platform' : 'institute',
            ];
        }

        return null;
    }
}
