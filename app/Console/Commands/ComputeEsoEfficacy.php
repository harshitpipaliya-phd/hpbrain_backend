<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Eso\EsoEfficacy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The missing writer for hpbrain_eso_efficacy_records.
 *
 * The table, its indexes, the API that reads it and the UI that renders it all
 * existed; nothing ever produced a row. That is why every ESO in the product
 * reported "efficacy not yet measurable" regardless of how much outcome
 * evidence the organization had actually recorded — the sentence was correct
 * about the table and wrong about the organization.
 *
 * A ROW IS WRITTEN ONLY WHERE ONE CAN BE EARNED. EsoEfficacy returns MEASURABLE
 * only when at least one completed execution has a measurement plan carrying a
 * baseline AND a target, plus an outcome recording a reading for that plan's
 * metric. Anything less is skipped and reported, never written as a zero — a
 * stored 0.0 would be read for ever afterwards as "measured, and it failed",
 * which is the opposite of "we do not know".
 *
 * SNAPSHOTS, NOT UPDATES. Each run appends a row dated today rather than
 * rewriting yesterday's. An ESO's efficacy changing as evidence accumulates is
 * the most interesting thing the table can record, and an UPDATE would destroy
 * exactly that.
 */
final class ComputeEsoEfficacy extends Command
{
    protected $signature = 'brain:compute-eso-efficacy
                            {--tenant= : Only this tenant. Omitted, every tenant that has ESO definitions.}
                            {--dry-run : Report what would be written without writing it.}';

    protected $description = 'Compute ESO efficacy from recorded measurement plans and outcomes, and snapshot what is measurable.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $definitions = DB::table('hpbrain_eso_definitions')
            ->when($this->option('tenant'), fn ($q, $tenant) => $q->where('tenant_id', $tenant))
            ->orderBy('tenant_id')
            ->orderBy('eso_code')
            ->get(['id', 'tenant_id', 'eso_code', 'name', 'gap_types']);

        if ($definitions->isEmpty()) {
            $this->warn('No ESO definitions found'.($this->option('tenant') ? ' for tenant '.$this->option('tenant') : '').'.');

            return self::SUCCESS;
        }

        $written = 0;
        $skipped = 0;
        $now = now()->format('Y-m-d H:i:s');

        foreach ($definitions as $definition) {
            $tenantId = (string) $definition->tenant_id;
            $analysis = EsoEfficacy::forDefinition($tenantId, (string) $definition->id);

            if ($analysis['status'] !== EsoEfficacy::MEASURABLE) {
                $skipped++;
                $this->line(sprintf(
                    '  <fg=yellow>skip</> %-24s %s',
                    $definition->eso_code,
                    $analysis['status'] === EsoEfficacy::NOT_MEASURABLE ? 'never executed' : 'insufficient evidence',
                ));

                continue;
            }

            $this->line(sprintf(
                '  <fg=green>ok</>   %-24s %s over %d execution%s (%s)',
                $definition->eso_code,
                number_format((float) $analysis['score'], 4),
                $analysis['sampleSize'],
                $analysis['sampleSize'] === 1 ? '' : 's',
                $analysis['verdict'],
            ));

            if ($dryRun) {
                continue;
            }

            DB::table('hpbrain_eso_efficacy_records')->insert([
                'id' => Uuid::uuid4()->toString(),
                'tenant_id' => $tenantId,
                'eso_definition_id' => (string) $definition->id,
                // The gap this ESO claims to close, from its own declaration.
                // Not inferred: an efficacy record filed against a gap type
                // nobody declared would be a claim this command invented.
                'gap_type' => $this->firstGapType($definition->gap_types) ?? 'unspecified',
                // The metric the plans actually named, so a reader of the table
                // alone can tell what the score is a score OF.
                'population' => (string) ($analysis['metric'] ?? 'unspecified'),
                'efficacy_score' => $analysis['score'],
                'sample_size' => $analysis['sampleSize'],
                'computed_date' => $now,
                'created_by' => 'brain:compute-eso-efficacy',
                'created_date' => $now,
            ]);

            $written++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d efficacy record%s; %d definition%s had no measurable evidence.',
            $dryRun ? 'Would write' : 'Wrote',
            $written,
            $written === 1 ? '' : 's',
            $skipped,
            $skipped === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    private function firstGapType(mixed $value): ?string
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $entry) {
            if (is_scalar($entry) && trim((string) $entry) !== '') {
                return (string) $entry;
            }
        }

        return null;
    }
}
