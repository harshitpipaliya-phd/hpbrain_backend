<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\OperationalIntelligence;
use App\Domain\Operations\OrganizationScorecard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Computes each organization's derived operational intelligence outside the
 * request cycle.
 *
 * WHY THIS IS NOT OPTIONAL ON A LARGE TENANT. The engine caches against a data
 * fingerprint, so the expensive part runs only when the organization's records
 * actually change — but something has to run it, and until now that was whichever
 * reader arrived first after an import. Several of the aggregates are full scans
 * of the tenant's slice of a table holding three quarters of a million rows;
 * paying for that inside a page load is the difference between a screen that
 * appears and a screen that times out.
 *
 * Run after every import, and on a schedule. The engine's own lock means a warm
 * that overlaps a reader does not duplicate work: one computes, the other takes
 * the result, and a reader who arrives mid-computation is served the previous
 * answer flagged as stale rather than a spinner.
 */
final class WarmOperationalIntelligence extends Command
{
    protected $signature = 'operations:warm {--tenant=* : Warm only these tenant ids} {--fresh : Recompute even if the current fingerprint is already cached}';

    protected $description = 'Precompute derived operational intelligence so page requests never pay for a cold aggregation';

    public function handle(OperationalIntelligence $operations, OrganizationScorecard $scorecard): int
    {
        $tenants = $this->option('tenant') ?: $this->tenants();

        if ($tenants === []) {
            $this->warn('No tenants with operational records found to warm.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($tenants as $tenantId) {
            $tenantId = (string) $tenantId;
            $startedAt = microtime(true);

            try {
                $result = $operations->forTenant($tenantId, (bool) $this->option('fresh'));

                // The scorecard composes the same computation plus the loop
                // metrics; warming it here means the landing screen's single
                // round trip is a cache read end to end.
                $scored = $scorecard->forTenant($tenantId);

                $elapsed = round(microtime(true) - $startedAt, 1);

                $this->info(sprintf(
                    '%s — %s records, %d datasets, %d units, score %s (%ss)',
                    $tenantId,
                    number_format((int) ($result['totals']['records'] ?? 0)),
                    (int) ($result['totals']['datasets'] ?? 0),
                    (int) ($result['totals']['departmentsWithActivity'] ?? 0),
                    $scored['overall'] === null ? 'not measurable' : $scored['overall'].'%',
                    $elapsed,
                ));
            } catch (\Throwable $e) {
                $failed++;
                $this->error($tenantId.' — '.$e->getMessage());
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Tenants that actually have operational records. Warming a tenant with none
     * costs a query and stores an empty answer nobody reads.
     *
     * @return array<int, string>
     */
    private function tenants(): array
    {
        if (! Schema::hasTable('hpbrain_operational_records')) {
            return [];
        }

        return DB::table('hpbrain_operational_records')
            ->distinct()
            ->pluck('tenant_id')
            ->map(fn ($t) => (string) $t)
            ->all();
    }
}
