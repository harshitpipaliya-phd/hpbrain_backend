<?php

declare(strict_types=1);

namespace App\Domain\Kasba;

use App\Domain\Universal\EntityResolver;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a tenant's assessment model from its industry template.
 *
 * Lookup order:
 *   1. the tenant's own industry template, if it declares assessment_model
 *   2. the platform template for that industry, if it declares one
 *   3. config('brain.kasba') — the five KASBA dimensions
 *
 * WHY THE CONFIG FALLBACK IS NOT A FALLBACK IN THE SENSE EntityResolver FORBIDS.
 * The resolver must never guess where a tenant's DATA lives, because guessing
 * wrong reads another tenant's rows. An assessment model is not data — it is a
 * vocabulary for scoring, and defaulting to KASBA is the documented behaviour
 * every tenant has today. Getting it wrong shows five labelled axes instead of
 * four; it cannot cross a tenant boundary.
 *
 * Cached per tenant per request, for the same reason and in the same way as
 * EntityResolver: templates are configuration and change while the application
 * runs.
 */
final class AssessmentModelResolver
{
    /** @var array<string, AssessmentModel> */
    private array $cache = [];

    public function __construct(private readonly EntityResolver $resolver)
    {
    }

    public function forTenant(string $tenantId): AssessmentModel
    {
        return $this->cache[$tenantId] ??= $this->load($tenantId);
    }

    public function flush(?string $tenantId = null): void
    {
        if ($tenantId === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$tenantId]);
    }

    private function load(string $tenantId): AssessmentModel
    {
        $industry = $this->industryOf($tenantId);

        if ($industry !== null) {
            // The tenant's own template first, then the platform's for the same
            // industry. ordered so that a local override wins.
            $rows = DB::table('hpbrain_industry_templates')
                ->where('industry_code', $industry)
                ->where('is_active', 1)
                ->whereIn('tenant_id', [$tenantId, 'platform'])
                ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
                ->get();

            foreach ($rows as $row) {
                $model = $this->decode($row->assessment_model ?? null);

                if ($model !== null) {
                    return AssessmentModel::fromArray($model, 'template');
                }
            }
        }

        return AssessmentModel::fromArray([
            'dimensions'            => config('brain.kasba.dimensions'),
            'maxLevel'              => config('brain.kasba.max_level'),
            'assessableEntityTypes' => ['Person', 'OrganizationUnit'],
        ], 'config');
    }

    private function industryOf(string $tenantId): ?string
    {
        try {
            $org = $this->resolver->resolve($tenantId, 'Organization');

            if (! $org->has('industry')) {
                return null;
            }

            $value = DB::table($org->table)
                ->where($org->tenantKey, $tenantId)
                ->whereNull('deleted_at')
                ->value($org->field('industry'));

            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable) {
            // An unmapped tenant has no industry to read. The config model is
            // still a safe answer: it describes how to score, not what to read.
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }
}
