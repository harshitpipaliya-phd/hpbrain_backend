<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Industry\IndustryClassifier;
use App\Domain\Industry\IndustryPack;
use App\Domain\Universal\EntityResolver;
use App\Repositories\BaseRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Give a tenant the capability register and vocabulary its industry implies.
 *
 * This is the step that was missing between "the organization exists" and "the
 * Brain has something to say about it". Every capability screen reads
 * hpbrain_capabilities; with no rows there, coverage, deficit and criticality
 * are all correctly empty, and the product looks broken when it is merely
 * unprovisioned.
 *
 * IT NEVER OVERWRITES. A capability whose code already exists for the tenant is
 * left exactly as it is, including if somebody renamed it, re-weighted its
 * criticality or retired it. The packs are a starting register, and an
 * organization's own edits are worth more than the default that seeded them —
 * so re-running this is safe, and is how a tenant picks up capabilities added
 * to a pack later.
 *
 * IT REFUSES TO GUESS AN INDUSTRY. An organization whose industry does not
 * classify is reported and skipped. See IndustryClassifier for why a default
 * would be worse than nothing.
 */
final class ProvisionTenants extends Command
{
    protected $signature = 'brain:provision
        {--tenant= : Provision one tenant instead of every organization}
        {--industry= : Override the classified industry, for an organization the ERP labels wrongly}
        {--dry-run : Report what would be written without writing it}';

    protected $description = "Seed a tenant's capability register and terminology from its industry";

    public function handle(EntityResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $override = $this->option('industry');

        if ($override !== null && ! IndustryPack::has((string) $override)) {
            $this->error("Unknown industry '{$override}'. Known: ".implode(', ', IndustryPack::industries()));

            return self::FAILURE;
        }

        $organizations = $this->organizations($resolver, $this->option('tenant'));

        if ($organizations === []) {
            $this->warn('No organizations found to provision.');

            return self::SUCCESS;
        }

        $rows = [];
        $skipped = [];

        foreach ($organizations as $org) {
            $industry = $override !== null ? (string) $override : IndustryClassifier::classify($org['industry']);

            if ($industry === null) {
                $skipped[] = $org;

                continue;
            }

            $written = $dryRun
                ? $this->countMissing($org['tenantId'], $industry)
                : $this->provision($org['tenantId'], $industry);

            $rows[] = [
                $org['tenantId'],
                $this->truncate($org['name'], 28),
                $org['industry'] ?? '—',
                $industry,
                $written['capabilities'],
                $written['terminology'],
            ];
        }

        if ($rows !== []) {
            $this->table(
                ['Tenant', 'Organization', 'ERP industry', 'Pack', $dryRun ? 'Caps to add' : 'Caps added', $dryRun ? 'Terms to add' : 'Terms added'],
                $rows,
            );
        }

        $this->reportSkipped($skipped);

        if ($dryRun) {
            $this->line('');
            $this->comment('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * Organizations to provision, read through the vocabulary layer.
     *
     * Reading through the resolver rather than naming institute_detail is what
     * makes this command work for a customer whose organizations live somewhere
     * else entirely.
     *
     * @return array<int, array{tenantId: string, name: string, industry: ?string}>
     */
    private function organizations(EntityResolver $resolver, ?string $onlyTenant): array
    {
        $sources = $resolver->everyTenantWith('Organization');
        $out = [];

        foreach ($sources as $tenantId => $source) {
            // PHP silently converts a numeric string array key to an int, and
            // this ERP's tenant keys are '1'..'11'. Everything downstream types
            // a tenant id as string, so the cast belongs here at the boundary
            // rather than in each consumer.
            $tenantId = (string) $tenantId;

            if ($onlyTenant !== null && $tenantId !== $onlyTenant) {
                continue;
            }

            $query = DB::table($source->table)
                ->where($source->field(EntityResolver::FIELD_TENANT_KEY), $tenantId);

            if ($source->has('deletedAt')) {
                $query->whereNull($source->field('deletedAt'));
            }

            $row = $query->first();

            if ($row === null) {
                continue;
            }

            $nameField = $source->has('name') ? $source->field('name') : null;
            $industryField = $source->has('industry') ? $source->field('industry') : null;

            $out[] = [
                'tenantId' => $tenantId,
                'name'     => $nameField !== null ? (string) ($row->{$nameField} ?? '') : $tenantId,
                // Null, not '' — an organization with no industry recorded is a
                // different thing from one recorded as blank, and the classifier
                // has to be able to tell.
                'industry' => $industryField !== null ? ($row->{$industryField} ?? null) : null,
            ];
        }

        return $out;
    }

    /**
     * Write the capabilities and terminology this tenant is missing.
     *
     * @return array{capabilities: int, terminology: int}
     */
    private function provision(string $tenantId, string $industry): array
    {
        $now = $this->now();

        $existingCaps = DB::table('hpbrain_capabilities')
            ->where('tenant_id', $tenantId)
            ->pluck('capability_code')
            ->all();
        $existingCaps = array_flip(array_map('strval', $existingCaps));

        $capRows = [];

        foreach (IndustryPack::capabilities($industry) as $cap) {
            if (isset($existingCaps[$cap['code']])) {
                continue;
            }

            $capRows[] = [
                'id'              => Uuid::uuid4()->toString(),
                'tenant_id'       => $tenantId,
                'capability_code' => $cap['code'],
                'name'            => $cap['name'],
                'description'     => $cap['description'],
                'category'        => $cap['category'],
                'capability_type' => $cap['type'],
                'difficulty'      => $cap['difficulty'],
                'criticality'     => $cap['criticality'],
                'version'         => 1,
                'status'          => 'active',
                // The KASBA columns carry what each dimension MEANS for this
                // capability, so two assessors scoring "Behaviour" are scoring
                // the same thing.
                //
                // They are JSON columns — MariaDB enforces that with a
                // CHECK (json_valid(...)) constraint, and CapabilityRepository
                // declares them in jsonColumns() so they hydrate as arrays. Each
                // holds a LIST of indicators: the pack ships one, and an
                // organization can add its own without a schema change.
                'knowledge'       => json_encode([$cap['kasba']['knowledge']]),
                'ability'         => json_encode([$cap['kasba']['ability']]),
                'skill'           => json_encode([$cap['kasba']['skill']]),
                'behaviour'       => json_encode([$cap['kasba']['behaviour']]),
                'attitude'        => json_encode([$cap['kasba']['attitude']]),
                'created_by'      => 'brain:provision',
                'created_date'    => $now,
                'updated_date'    => $now,
            ];
        }

        $existingTerms = DB::table('hpbrain_terminology')
            ->where('tenant_id', $tenantId)
            ->pluck('entity_type')
            ->all();
        $existingTerms = array_flip(array_map('strval', $existingTerms));

        $termRows = [];
        $sort = 0;

        foreach (IndustryPack::terminology($industry) as $entity => $label) {
            $sort++;

            if (isset($existingTerms[$entity])) {
                continue;
            }

            $termRows[] = [
                'id'            => Uuid::uuid4()->toString(),
                'tenant_id'     => $tenantId,
                'industry_code' => $industry,
                'entity_type'   => $entity,
                'display_name'  => $label,
                'plural_name'   => $this->pluralise($label),
                'sort_order'    => $sort,
                'status'        => 'active',
                'created_by'    => 'brain:provision',
                'created_date'  => $now,
                'updated_date'  => $now,
            ];
        }

        DB::transaction(function () use ($capRows, $termRows) {
            foreach (array_chunk($capRows, 50) as $chunk) {
                DB::table('hpbrain_capabilities')->insert($chunk);
            }

            foreach (array_chunk($termRows, 50) as $chunk) {
                DB::table('hpbrain_terminology')->insert($chunk);
            }
        });

        return ['capabilities' => count($capRows), 'terminology' => count($termRows)];
    }

    /** @return array{capabilities: int, terminology: int} */
    private function countMissing(string $tenantId, string $industry): array
    {
        $caps = DB::table('hpbrain_capabilities')->where('tenant_id', $tenantId)->pluck('capability_code')->all();
        $caps = array_flip(array_map('strval', $caps));

        $terms = DB::table('hpbrain_terminology')->where('tenant_id', $tenantId)->pluck('entity_type')->all();
        $terms = array_flip(array_map('strval', $terms));

        return [
            'capabilities' => count(array_filter(
                IndustryPack::capabilities($industry),
                static fn (array $c) => ! isset($caps[$c['code']]),
            )),
            'terminology' => count(array_filter(
                array_keys(IndustryPack::terminology($industry)),
                static fn (string $e) => ! isset($terms[$e]),
            )),
        ];
    }

    /**
     * Enough pluralisation for the handful of labels a pack defines.
     *
     * Deliberately small. These are display labels chosen by this codebase, not
     * arbitrary user input, so the cases are known: 'Branch or Division' and
     * 'Ward or Unit' pluralise on the last word, 'Facility' on a -y ending.
     */
    private function pluralise(string $label): string
    {
        if (str_ends_with($label, 's') || str_ends_with($label, 'x')) {
            return $label.'es';
        }

        if (preg_match('/[^aeiou]y$/i', $label)) {
            return substr($label, 0, -1).'ies';
        }

        return $label.'s';
    }

    /** @param array<int, array{tenantId: string, name: string, industry: ?string}> $skipped */
    private function reportSkipped(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $this->line('');
        $this->warn(count($skipped).' organization(s) NOT provisioned — their industry did not classify:');

        foreach ($skipped as $org) {
            $label = $org['industry'] === null || trim((string) $org['industry']) === ''
                ? '(no industry recorded)'
                : "'".$org['industry']."'";

            $this->line("  tenant {$org['tenantId']}  {$org['name']}  {$label}");
        }

        $this->line('');
        $this->line('  Nothing was guessed for these. A wrong pack gives an organization a register');
        $this->line('  describing work it does not do, and every coverage and deficit figure derived');
        $this->line('  from it would be confidently wrong.');
        $this->line('');
        $this->line('  Fix the industry on the organization, or pass one explicitly:');
        $this->line('    php artisan brain:provision --tenant=<id> --industry=<'.implode('|', IndustryPack::industries()).'>');
    }

    /**
     * MySQL-legal timestamp, UTC.
     *
     * Same rule as BaseRepository::now(), which is an instance method on a
     * class this command is not. Never date('c') or a DateTime object: both
     * reach a DATETIME column as RFC-3339, which MySQL rejects outright with
     * error 1292.
     */
    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function truncate(string $value, int $length): string
    {
        return strlen($value) <= $length ? $value : substr($value, 0, $length - 1).'…';
    }
}
