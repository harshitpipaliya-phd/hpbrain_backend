<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\School\DatasetRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Declare what one of a tenant's sources IS, so the screens can stop guessing.
 *
 * Two things are recorded, and both are read at request time:
 *
 *   source_type = 'dataset'  — routes commit through IngestionService::
 *     commitDataset(), which writes hpbrain_operational_records instead of one
 *     Signal per row. Without it a 10,430-row fee register becomes 10,430
 *     signals and no queryable facts.
 *
 *   config.dataset_role      — 'academic' or 'fees'. DatasetRegistry reads this
 *     to answer "which dataset holds this tenant's exam results", which is what
 *     keeps a literal dataset name out of every controller.
 *
 * THE FIELD MAP IS PART OF THE DECLARATION. Which source column becomes
 * subject_ref decides whether the two files can be joined at all: for Lions,
 * academic.enrollment_no and fees."GR NO." must BOTH land on subject_ref, or the
 * student projection has nothing to match on.
 */
final class ConfigureDatasetCommand extends Command
{
    protected $signature = 'dataset:configure
        {tenant       : Tenant id that owns the source}
        {source       : source_key as registered in hpbrain_data_sources}
        {--role=      : academic|fees — the role this dataset plays}
        {--dataset=   : Value to write into the dataset column (defaults to source_key)}
        {--map=       : Field map as JSON; merged over whatever is stored}
        {--show       : Print the resulting configuration and change nothing}';

    protected $description = 'Declare a tenant source as a dataset and record the role it plays.';

    public function handle(DatasetRegistry $registry): int
    {
        $tenantId = (string) $this->argument('tenant');
        $sourceKey = (string) $this->argument('source');

        $source = DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('source_key', $sourceKey)
            ->first();

        if ($source === null) {
            $this->error("No source '{$sourceKey}' for tenant {$tenantId}.");

            return self::FAILURE;
        }

        if ($this->option('show')) {
            $this->line(json_encode([
                'source_key'  => $source->source_key,
                'source_type' => $source->source_type,
                'config'      => json_decode((string) $source->config, true),
                'field_map'   => json_decode((string) $source->field_map, true),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $config = json_decode((string) $source->config, true);
        $config = is_array($config) ? $config : [];

        $role = $this->option('role');

        if ($role !== null) {
            if (! in_array($role, [DatasetRegistry::ROLE_ACADEMIC, DatasetRegistry::ROLE_FEES], true)) {
                $this->error("Role must be 'academic' or 'fees'.");

                return self::FAILURE;
            }

            $config['dataset_role'] = $role;
        }

        $config['dataset'] = (string) ($this->option('dataset') ?: ($config['dataset'] ?? $sourceKey));

        $update = [
            'source_type'  => 'dataset',
            'config'       => json_encode($config),
            'updated_date' => gmdate('Y-m-d H:i:s'),
        ];

        if ($mapJson = $this->option('map')) {
            $map = json_decode((string) $mapJson, true);

            if (! is_array($map)) {
                $this->error('--map must be valid JSON.');

                return self::FAILURE;
            }

            $stored = json_decode((string) $source->field_map, true);
            $update['field_map'] = json_encode(array_merge(is_array($stored) ? $stored : [], $map));
        }

        DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('source_key', $sourceKey)
            ->update($update);

        $registry->forget($tenantId);

        $this->info("Configured {$sourceKey} for tenant {$tenantId}:");
        $this->line('  dataset      '.$config['dataset']);
        $this->line('  role         '.($config['dataset_role'] ?? '(unset)'));
        $this->line('  field_map    '.($update['field_map'] ?? '(unchanged)'));

        return self::SUCCESS;
    }
}
