<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AiIntelligence\Stack\AiStackExampleSeeder;
use Illuminate\Console\Command;

/**
 * One real example of every AI Stack record, per tenant × module.
 *
 * For each tenant (default: every tenant that holds HP Brain data) and each of its
 * active AI Stack modules, creates — only where the tenant has no row of that kind for
 * that module yet — a module policy, a published prompt template, a published report
 * layout on the module's own data source, the module's preset tool agent and one real
 * run of it, one report built from the layout, a model choice that pins what the module
 * already resolves to, and (with --with-model-calls) one real assistant conversation
 * asked from the module. Every example goes through the same controller actions the AI
 * Stack screens use (see AiStackExampleSeeder), is written only to hpbrain_ai_* tables,
 * attributed to `system:ai-stack-examples`, and logged on the module's Activity ledger.
 *
 * Idempotent: a second run creates nothing. --dry-run reports what would be created and
 * writes nothing.
 */
final class SeedAiStackExamples extends Command
{
    protected $signature = 'ai-stack:seed-examples
        {--tenant=* : One or more tenant ids (default: every tenant that has HP Brain data)}
        {--module=* : Only these AI Stack module keys}
        {--with-model-calls : Also ask one real assistant question per module (calls the configured provider)}
        {--dry-run : Report what would be created without writing anything}';

    protected $description = 'Create one real, tenant- and module-scoped example of each AI Stack record where none exists yet.';

    private const SHORT = [
        'policy' => 'policy',
        'prompt' => 'prompt',
        'report_template' => 'report tpl',
        'agent' => 'agent',
        'agent_run' => 'agent run',
        'report' => 'report',
        'model_binding' => 'model',
        'conversation' => 'conversation',
    ];

    public function handle(AiStackExampleSeeder $seeder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $withModelCalls = (bool) $this->option('with-model-calls');

        $tenants = array_values(array_filter(array_map('strval', (array) $this->option('tenant')), fn ($t) => trim($t) !== ''));
        $tenants = $tenants === [] ? $seeder->tenants() : $tenants;

        $only = array_values(array_filter(array_map('strval', (array) $this->option('module')), fn ($m) => trim($m) !== ''));
        $unknown = array_diff($only, $seeder->knownModules());

        if ($unknown !== []) {
            $this->error('Unknown module key(s): ' . implode(', ', $unknown) . '. Known: ' . implode(', ', $seeder->knownModules()) . '.');

            return self::INVALID;
        }

        if ($tenants === []) {
            $this->warn('No tenant with HP Brain data was found. Name one with --tenant.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry run] ' : '') . 'Seeding AI Stack examples for ' . count($tenants) . ' tenant(s)'
            . ($withModelCalls ? ', with one real model call per module' : '') . '.');

        $rows = [];
        $problems = [];
        $totals = [];

        foreach ($tenants as $tenant) {
            $modules = $seeder->modulesFor($tenant, $only);

            if ($modules === []) {
                $this->warn("tenant {$tenant}: no active AI Stack module to seed.");

                continue;
            }

            foreach ($modules as $module) {
                $result = $seeder->seed($tenant, $module, $withModelCalls, $dryRun);
                $row = [$tenant, $module];

                foreach (AiStackExampleSeeder::KINDS as $kind) {
                    $status = $result[$kind]['status'] ?? '-';
                    $row[] = $status;
                    $totals[$status] = ($totals[$status] ?? 0) + 1;

                    if ($status === AiStackExampleSeeder::FAILED) {
                        $problems[] = sprintf('%s / %s / %s: %s', $tenant, $module, $kind, $result[$kind]['detail']);
                    } elseif ($this->output->isVerbose()) {
                        $this->line(sprintf('  %s / %s / %s: %s — %s', $tenant, $module, $kind, $status, $result[$kind]['detail'] ?? ''));
                    }
                }

                $rows[] = $row;
            }
        }

        $this->table(array_merge(['tenant', 'module'], array_values(self::SHORT)), $rows);

        ksort($totals);
        $this->line('Totals: ' . implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($totals), $totals)));

        if ($problems !== []) {
            $this->warn('Failures (recorded as they happened; nothing was retried):');

            foreach ($problems as $problem) {
                $this->line('  - ' . $problem);
            }
        }

        if ($dryRun) {
            $this->comment('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }
}
