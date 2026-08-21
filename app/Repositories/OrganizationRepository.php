<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Universal\EntityResolver;
use Illuminate\Support\Facades\DB;

/**
 * Organizations are NOT owned by the Brain. They are read from the tenant's
 * existing system of record, because the Product Manifesto is explicit that the
 * Brain is "an intelligence layer above existing ERP/LMS/HRMS: we integrate, we
 * do not replace."
 *
 * WHICH tables those are is no longer written here. Every table and column below
 * comes from EntityResolver, so the institute ERP's organization/profile table
 * pair is one tenant's answer rather than the only answer. A hospital keeping
 * organizations elsewhere needs mapping rows, not an edit to this file.
 *
 * WHY tenantId STOPPED BEING OPTIONAL. It was `?string $tenantId = null`, where
 * null meant "every organization the ERP has". That question no longer has an
 * answer: mappings are per tenant, so without one there is no table to select
 * from. No caller passed null — OrganizationController is the only consumer and
 * always passes the authenticated tenant — so the parameter is now required
 * rather than kept alive with a union across tenants nothing asks for.
 *
 * Consequence that bit the previous implementation and still holds: organizations
 * are ALWAYS present on a real tenant, so they can never be used as a "has this
 * been seeded?" sentinel. Use Brain-owned data (signals) for that.
 */
final class OrganizationRepository
{
    public function __construct(private readonly EntityResolver $resolver)
    {
    }

    public function list(string $tenantId): array
    {
        $org = $this->resolver->resolve($tenantId, 'Organization');
        $profile = $this->resolver->resolve($tenantId, 'OrganizationProfile');

        $key = $org->tenantKey;

        // Identifiers are interpolated because SQL has no placeholder for them;
        // values stay bound. Every fragment here originates in a mapping row,
        // and the mapping table is administrator-configured data — which is why
        // the tenant filter below is a binding and not a concatenation.
        $query = DB::table($org->table.' as d')
            ->selectRaw('d.'.$org->field('id').' as id')
            ->selectRaw('MAX(d.'.$org->field('name').') as name')
            ->selectRaw($org->has('code') ? 'MAX(d.'.$org->field('code').') as org_code' : "'' as org_code")
            ->selectRaw($org->has('industry') ? 'MAX(d.'.$org->field('industry').') as industry' : 'NULL as industry')
            ->selectRaw('MAX(d.created_at) as created_date')
            ->selectRaw('MAX(d.updated_at) as updated_date');

        if ($profile->has('legalName')) {
            $query->selectRaw(
                '(SELECT '.$profile->field('legalName').' FROM '.$profile->table
                .' WHERE '.$profile->tenantKey.' = d.'.$key.' LIMIT 1) as legal_name'
            );
        } else {
            $query->selectRaw('NULL as legal_name');
        }

        if ($profile->has('logo')) {
            $query->selectRaw(
                '(SELECT '.$profile->field('logo').' FROM '.$profile->table
                .' WHERE '.$profile->tenantKey.' = d.'.$key.' LIMIT 1) as logo'
            );
        } else {
            $query->selectRaw('NULL as logo');
        }

        if ($org->has('deletedAt')) {
            $query->whereNull('d.'.$org->field('deletedAt'));
        }

        return $query
            ->where('d.'.$key, $tenantId)
            ->groupBy('d.'.$key)
            ->orderByDesc('d.'.$key)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Creating an organization creates a TENANT, which is the one operation here
     * that cannot resolve against its own subject: the new tenant has no
     * mappings yet. $mappingTenantId names the tenant whose mappings describe
     * where organizations live — in practice the authenticated caller's.
     *
     * It is a separate parameter rather than a key inside $input because $input
     * is echoed back in the response, and a plumbing detail has no business
     * appearing in the API payload.
     */
    public function create(string $mappingTenantId, array $input): array
    {
        $org = $this->resolver->resolve($mappingTenantId, 'Organization');
        $profile = $this->resolver->resolve($mappingTenantId, 'OrganizationProfile');
        $personProfile = $this->resolver->resolve($mappingTenantId, 'PersonProfile');

        return DB::transaction(function () use ($input, $org, $profile, $personProfile) {
            $key = $org->tenantKey;
            $next = (int) (DB::table($org->table)->max($key) ?? 0) + 1;
            $now = now()->format('Y-m-d H:i:s');

            DB::table($org->table)->insert([
                $key                    => $next,
                $org->field('name')     => $input['name'],
                $org->field('code')     => $input['orgCode'] ?? null,
                $org->field('industry') => $input['industry'] ?? null,
                'created_by'            => $input['createdBy'],
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);

            DB::table($profile->table)->insert([
                $profile->tenantKey          => $next,
                $profile->field('legalName') => $input['legalName'] ?? null,
                $profile->field('logo')      => $input['logo'] ?? null,
                'created_by'                 => $input['createdBy'],
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);

            // The ERP requires every institute to own an 'Employee' user
            // profile; PersonRepository resolves it on every person create and
            // throws when it is missing. Creating an institute here means
            // creating one the ERP has never seen, so the Brain must provision
            // that row itself. Without this, every person created in a
            // Brain-created organization fails.
            $hasProfile = DB::table($personProfile->table)
                ->where($personProfile->tenantKey, $next)
                ->where($personProfile->field('name'), 'Employee')
                ->where($personProfile->field('status'), 1)
                ->exists();

            if (! $hasProfile) {
                DB::table($personProfile->table)->insert([
                    $personProfile->tenantKey       => $next,
                    $personProfile->field('name')   => 'Employee',
                    $personProfile->field('status') => 1,
                ]);
            }

            return ['id' => (string) $next] + $input;
        });
    }
}
