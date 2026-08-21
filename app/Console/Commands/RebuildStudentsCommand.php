<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\School\AcademicIntelligenceService;
use App\Domain\School\StudentProjectionBuilder;
use App\Domain\Universal\EntityResolver;
use App\Repositories\AcademicRecordRepository;
use App\Services\TenantScopedCache;
use Illuminate\Console\Command;

/**
 * Rebuild a tenant's student projection from its datasets.
 *
 * Run this after any import that adds academic or fee rows. It is idempotent,
 * derives every value from hpbrain_operational_records, and creates no student
 * who is not named in a file.
 *
 * IT DOES NOT DELETE. A student whose records disappear from both datasets keeps
 * their row with both flags cleared rather than being removed, because a
 * projection that silently drops rows makes an ingestion mistake invisible.
 */
final class RebuildStudentsCommand extends Command
{
    protected $signature = 'students:rebuild
        {tenant?     : Tenant id to rebuild}
        {--all       : Rebuild every organization this installation has, in turn}
        {--academic= : Override the academic dataset key}
        {--fees=     : Override the fee dataset key}
        {--no-warm   : Skip warming the derived caches}';

    protected $description = 'Collapse a tenant\'s academic and fee records into one row per student.';

    public function handle(
        StudentProjectionBuilder $builder,
        AcademicIntelligenceService $intelligence,
        AcademicRecordRepository $records,
        TenantScopedCache $cache,
        EntityResolver $resolver,
    ): int {
        $tenants = $this->targetTenants($resolver);

        if ($tenants === []) {
            $this->error('Name a tenant, or pass --all to rebuild every organization.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($tenants as $tenantId) {
            $failed += $this->rebuildOne($tenantId, $builder, $intelligence, $records, $cache) === self::SUCCESS ? 0 : 1;
        }

        // ONE ORGANIZATION'S FAILURE IS NOT THE RUN'S. Across an installation
        // with sixty of them, stopping at the first tenant whose datasets are
        // not configured would leave the other fifty-nine unbuilt for a reason
        // that has nothing to do with them. Every tenant is attempted and the
        // count is reported at the end.
        if (count($tenants) > 1) {
            $this->newLine();
            $this->info(sprintf('%d of %d organizations rebuilt.', count($tenants) - $failed, count($tenants)));
        }

        return $failed === count($tenants) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Every organization this installation has, or the one that was named.
     *
     * READ THROUGH THE RESOLVER, so "every organization" means every tenant
     * whose Organization entity is mapped — the same register login and
     * provisioning already treat as the list of organizations — rather than a
     * table named here.
     *
     * @return array<int, string>
     */
    private function targetTenants(EntityResolver $resolver): array
    {
        $named = (string) ($this->argument('tenant') ?? '');

        if ($named !== '') {
            return [$named];
        }

        if (! $this->option('all')) {
            return [];
        }

        // Cast: array_keys() hands back an int for a numeric tenant id, and
        // tenant comparison is by string everywhere in this codebase.
        return array_map('strval', array_keys($resolver->everyTenantWith('Organization')));
    }

    private function rebuildOne(
        string $tenantId,
        StudentProjectionBuilder $builder,
        AcademicIntelligenceService $intelligence,
        AcademicRecordRepository $records,
        TenantScopedCache $cache,
    ): int {
        $this->info("Rebuilding student projection for tenant {$tenantId}…");
        $startedAt = microtime(true);

        $result = $builder->rebuild(
            $tenantId,
            $this->option('academic') ?: null,
            $this->option('fees') ?: null,
        );

        if ($result['skipped'] !== null) {
            $this->warn('Nothing done: '.$result['skipped']);

            return $result['skipped'] === 'no_datasets' ? self::FAILURE : self::SUCCESS;
        }

        $this->info(sprintf('Done in %ds.', (int) round(microtime(true) - $startedAt)));
        $this->line('  rows touched by the roster pass    '.$result['roster']);
        $this->line('  rows touched by the academic pass  '.$result['academic']);
        $this->line('  rows touched by the fee pass       '.$result['fees']);
        $this->line('  students now in the projection     '.$result['students']);

        if (! $this->option('no-warm')) {
            $this->warmDerivedCaches($tenantId, $intelligence, $records, $cache);
        }

        return self::SUCCESS;
    }

    /**
     * Compute the two expensive derived views now, so no user pays for them.
     *
     * WHY HERE. Both are cached against a fingerprint of the tenant's import and
     * projection high-water marks, and a rebuild is precisely what MOVES that
     * fingerprint — so the moment this command finishes, both caches are stale
     * by construction and the next person to open Departments or the
     * Intelligence Workspace pays the full cost of recomputing them.
     *
     * The cost is real: the intelligence view aggregates marks across every one
     * of the tenant's result rows, which cannot be answered from an index
     * because SUM(metric_value) needs the rows themselves. Paying it once, in a
     * command already expected to take minutes, is strictly better than paying
     * it on a screen.
     *
     * FAILURE HERE IS NOT FAILURE OF THE REBUILD. The projection is already
     * written and correct; a warm that errors leaves a cold cache, which is slow
     * and not wrong. It is reported and the command still succeeds.
     */
    private function warmDerivedCaches(
        string $tenantId,
        AcademicIntelligenceService $intelligence,
        AcademicRecordRepository $records,
        TenantScopedCache $cache,
    ): void {
        $this->newLine();
        $this->info('Warming derived caches…');

        try {
            $startedAt = microtime(true);
            $version = $intelligence->dataVersion($tenantId);

            $cache->remember(
                $tenantId,
                "hpbrain:school:structure:v1:{$tenantId}:{$version}",
                21600,
                fn (): array => $records->structure($tenantId),
            );
            $this->line(sprintf('  academic structure   %ds', (int) round(microtime(true) - $startedAt)));

            $startedAt = microtime(true);
            $intelligence->forTenant($tenantId, true);
            $this->line(sprintf('  school intelligence  %ds', (int) round(microtime(true) - $startedAt)));
        } catch (\Throwable $e) {
            $this->warn('  Could not warm the caches: '.$e->getMessage());
            $this->warn('  The projection is still correct; the first page view will be slower.');
        }
    }
}
