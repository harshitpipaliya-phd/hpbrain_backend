<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Intelligence\IntelligenceEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Computes each organization's intelligence outside the request cycle.
 *
 * The engine caches against a data fingerprint, so the expensive part only runs
 * when an organization's records actually change — but *something* has to run
 * it, and until now that was whichever unlucky reader arrived first after an
 * import. On the largest tenant that reader waited minutes.
 *
 * Run this on a schedule and after every import. The engine's own lock means a
 * warm that overlaps a reader will not duplicate work: one of them computes and
 * the other takes the result.
 */
final class WarmIntelligence extends Command
{
    protected $signature = 'intelligence:warm {--tenant=* : Warm only these tenant ids} {--fresh : Recompute even if the current fingerprint is already cached}';

    protected $description = 'Precompute organizational intelligence so page requests never pay for a cold analysis';

    public function handle(IntelligenceEngine $engine): int
    {
        $tenants = $this->option('tenant') ?: $this->tenants();

        if ($tenants === []) {
            $this->warn('No tenants found to warm.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($tenants as $tenantId) {
            $tenantId = (string) $tenantId;
            $startedAt = microtime(true);

            try {
                $result = $engine->forOrganization($tenantId, (bool) $this->option('fresh'));
                $this->info(sprintf(
                    'tenant %-10s warmed in %6.1fs (version %s)',
                    $tenantId,
                    microtime(true) - $startedAt,
                    $result['dataVersion'] ?? '?'
                ));
            } catch (\Throwable $e) {
                $failed++;
                $this->error(sprintf('tenant %-10s FAILED after %.1fs: %s', $tenantId, microtime(true) - $startedAt, $e->getMessage()));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every organization that actually holds records, largest last — so a run
     * that is cut short has still warmed the cheap tenants.
     *
     * @return list<string>
     */
    private function tenants(): array
    {
        return DB::table('hpbrain_operational_records')
            ->select('tenant_id')
            ->selectRaw('COUNT(*) AS records')
            ->groupBy('tenant_id')
            ->orderBy('records')
            ->pluck('tenant_id')
            ->map(static fn ($id) => (string) $id)
            ->all();
    }
}
