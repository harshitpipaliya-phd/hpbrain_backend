<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Events\EventPublisher;
use App\Domain\Signals\SignalGenerator;
use App\Services\Import\ImportProfileRegistry;
use App\Services\ImportEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import an organization's workbooks and, optionally, evaluate signals.
 *
 * Usage (PowerShell-safe — one option per token, no && chaining):
 *
 *   php artisan brain:import fibervalley --tenant=7
 *   php artisan brain:import fibervalley --tenant=7 --only=complaints
 *   php artisan brain:import fibervalley --tenant=7 --dry-run
 *   php artisan brain:import fibervalley --tenant=7 --generate-signals
 *   php artisan brain:import --list
 *
 * Re-running is the normal case, not an error case. Every profile is keyed, so
 * a second run of the same workbook updates changed rows, skips unchanged ones
 * and inserts only what is new. Next quarter's larger export is imported by the
 * same command with no arguments changed.
 */
final class ImportOrganizationData extends Command
{
    protected $signature = 'brain:import
        {org? : Organization slug — the directory under storage/imports/}
        {--tenant= : Target sub_institute_id. Required unless --dry-run or --list.}
        {--only=* : Import only these profile keys}
        {--dry-run : Parse and validate without writing anything}
        {--generate-signals : Run signal generation after a successful import}
        {--log-rows : Write a hpbrain_import_logs row per record (slow; errors are always logged)}
        {--path= : Override the source directory}
        {--list : Show configured organizations and profiles, then exit}';

    protected $description = 'Import an organization\'s Excel workbooks into the Brain';

    public function handle(
        ImportEngine $engine,
        ImportProfileRegistry $registry,
        EventPublisher $events
    ): int {
        if ($this->option('list')) {
            return $this->listProfiles($registry);
        }

        $org = (string) $this->argument('org');

        if ($org === '') {
            $this->error('An organization slug is required. Run with --list to see the configured ones.');

            return self::FAILURE;
        }

        $profiles = $registry->forOrganization($org);

        if ($profiles === []) {
            $this->error("No import profiles configured for '{$org}'. Add one to config/import_profiles.php.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $tenant = (string) ($this->option('tenant') ?? '');

        if (! $dryRun) {
            if ($tenant === '') {
                $this->error('--tenant is required. It is the sub_institute_id the data belongs to.');

                return self::FAILURE;
            }

            if (! $this->tenantExists($tenant)) {
                // Fail closed. Importing 65,000 rows under a tenant id that no
                // organization owns would make them invisible to every screen
                // and impossible to find without knowing the wrong id.
                $this->error("No organization with sub_institute_id '{$tenant}' exists in institute_detail.");
                $this->line('Run: php artisan db:seed --class=FiberValleySeeder');

                return self::FAILURE;
            }
        }

        $options = [
            'actor'          => 'artisan:brain:import',
            'dry_run'        => $dryRun,
            'only'           => (array) $this->option('only'),
            'log_rows'       => (bool) $this->option('log-rows'),
            'base_directory' => $this->option('path') ?: null,
        ];

        $this->info($dryRun
            ? "Validating '{$org}' (dry run — nothing will be written)"
            : "Importing '{$org}' into tenant {$tenant}");
        $this->newLine();

        $rows = [];
        $failed = 0;

        foreach ($profiles as $key => $profile) {
            if ($options['only'] !== [] && ! in_array($key, $options['only'], true)) {
                continue;
            }

            $started = microtime(true);

            try {
                $result = app(\App\Services\Import\WorkbookImporter::class)
                    ->import($tenant !== '' ? $tenant : 'dry-run', $profile, $options);

                $counts = $result['counts'];

                $rows[] = [
                    $key,
                    $result['file'],
                    $counts['read'],
                    $counts['created'],
                    $counts['updated'],
                    $counts['skipped'],
                    $counts['error'],
                    sprintf('%.1fs', microtime(true) - $started),
                ];

                foreach ($result['errors'] as $error) {
                    $this->warn("  {$key} row {$error['row']}: {$error['error']}");
                }
            } catch (\Throwable $e) {
                $failed++;
                $rows[] = [$key, '—', 0, 0, 0, 0, 'FAILED', '—'];
                $this->error("  {$key}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->table(
            ['Profile', 'File', 'Read', 'Created', 'Updated', 'Skipped', 'Errors', 'Time'],
            $rows
        );

        if ($failed > 0) {
            $this->error("{$failed} profile(s) failed. Nothing else was rolled back — successful profiles are committed.");

            return self::FAILURE;
        }

        if ($this->option('generate-signals') && ! $dryRun) {
            $this->newLine();
            $this->info('Evaluating signal rules...');

            $result = (new SignalGenerator($events))->evaluate($tenant);

            $this->line("  Signals created: {$result['created']}");
            $this->line("  Rules not triggered: {$result['skipped']}");
        }

        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function listProfiles(ImportProfileRegistry $registry): int
    {
        foreach ($registry->organizations() as $org) {
            $this->newLine();
            $this->info($org);

            $rows = [];

            foreach ($registry->forOrganization($org) as $key => $profile) {
                $path = $registry->resolveFile($profile);

                $rows[] = [
                    $key,
                    $profile->filePattern(),
                    $profile->sheet(),
                    $profile->shape(),
                    $profile->loader(),
                    $path === null ? 'MISSING' : 'found',
                ];
            }

            $this->table(['Profile', 'File pattern', 'Sheet', 'Shape', 'Loader', 'Source'], $rows);
        }

        return self::SUCCESS;
    }

    private function tenantExists(string $tenantId): bool
    {
        try {
            return DB::table('institute_detail')
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
