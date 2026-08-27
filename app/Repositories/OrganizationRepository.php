<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\SourceSchema;
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
    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly SourceSchema $schema,
    ) {
    }

    /**
     * The organization-profile fields the product knows how to show and edit,
     * in the order a reader wants them.
     *
     * THIS LIST IS THE VOCABULARY, NOT THE CONTRACT. Which of them a tenant
     * actually has is answered per tenant by the mapping plus SourceSchema, and
     * published to the client as `profileFields` so the UI can render exactly
     * the fields that exist rather than a fixed form with permanent blanks.
     *
     * @var array<int, string>
     */
    public const PROFILE_FIELDS = [
        'legalName',
        'registrationNumber',
        'taxId',
        'country',
        'address',
        'email',
        'phone',
        'website',
        'contactPerson',
        'employeeCount',
        'workWeek',
        'logo',
    ];

    /**
     * The snake_case alias a universal field is published under.
     *
     * The API has always spoken snake_case for these rows and the client
     * normalises both spellings; deriving the alias keeps the two in step
     * without a second list to forget to update.
     */
    public static function alias(string $universalField): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $universalField));
    }

    /**
     * The profile fields this tenant's source system can actually hold.
     *
     * @return array<int, string> universal field names, in PROFILE_FIELDS order
     */
    public function supportedProfileFields(string $tenantId): array
    {
        $profile = $this->resolver->resolve($tenantId, 'OrganizationProfile');

        return array_keys($this->schema->usable($profile, self::PROFILE_FIELDS));
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

        /*
            THE WHOLE PROFILE, one correlated sub-select per field.

            A field the tenant's ERP has no column for is selected as NULL
            rather than omitted, so every row in the response has the same shape
            and the client never has to tell "absent from this payload" apart
            from "absent from this organization". Which fields those are is
            published alongside as `profileFields`, and that — not the NULLs —
            is what the screens read to decide whether to offer a field at all.

            usable() rather than has(): the mapping describes the ERP in general
            and this deployment's copy of the table may be older, and selecting
            a column that is not there fails the whole page rather than one
            field on it.
        */
        $usableProfile = $this->schema->usable($profile, self::PROFILE_FIELDS);

        foreach (self::PROFILE_FIELDS as $field) {
            $alias = self::alias($field);

            if (isset($usableProfile[$field])) {
                $query->selectRaw(
                    '(SELECT '.$usableProfile[$field].' FROM '.$profile->table
                    .' WHERE '.$profile->tenantKey.' = d.'.$key.' LIMIT 1) as '.$alias
                );
            } else {
                $query->selectRaw('NULL as '.$alias);
            }
        }

        if ($org->has('deletedAt')) {
            $query->whereNull('d.'.$org->field('deletedAt'));
        }

        /*
            WHICH FIELDS THIS TENANT CAN HOLD, published beside the values.

            Without it a client cannot tell a field that is empty from one the
            source system has no column for, and the only rendering available to
            it is a fixed form with "Not recorded" against both. With it the
            Organization screen shows and edits exactly the fields that exist —
            different for an HR-shaped ERP and a school-shaped one, and correct
            for a third without either side being edited.
        */
        $profileFields = array_keys($usableProfile);
        $identityFields = array_values(array_intersect(
            ['name', 'code', 'industry'],
            array_keys($this->schema->usable($org, ['name', 'code', 'industry'])),
        ));

        return $query
            ->where('d.'.$key, $tenantId)
            ->groupBy('d.'.$key)
            ->orderByDesc('d.'.$key)
            ->get()
            ->map(fn ($r) => ((array) $r) + [
                'profile_fields' => $profileFields,
                'identity_fields' => $identityFields,
            ])
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
