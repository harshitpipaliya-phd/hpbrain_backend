<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * Organizations are NOT owned by the Brain. They are read from the institute's
 * existing ERP tables — institute_detail joined to org_details — because the
 * Product Manifesto is explicit that the Brain is "an intelligence layer above
 * existing ERP/LMS/HRMS: we integrate, we do not replace."
 *
 * Consequence that bit the previous implementation: organizations are ALWAYS
 * present on a real tenant, so they can never be used as a "has this been
 * seeded?" sentinel. Use Brain-owned data (signals) for that.
 */
final class OrganizationRepository
{
    public function list(): array
    {
        return DB::table('institute_detail as d')
            ->selectRaw('d.sub_institute_id as id')
            ->selectRaw('MAX(d.organization_name) as name')
            ->selectRaw('MAX(d.organization_code) as org_code')
            ->selectRaw('MAX(d.industry_type) as industry')
            ->selectRaw('MAX(d.created_at) as created_date')
            ->selectRaw('MAX(d.updated_at) as updated_date')
            ->selectRaw('(SELECT legal_name FROM org_details WHERE sub_institute_id = d.sub_institute_id LIMIT 1) as legal_name')
            ->selectRaw('(SELECT logo FROM org_details WHERE sub_institute_id = d.sub_institute_id LIMIT 1) as logo')
            ->whereNull('d.deleted_at')
            ->groupBy('d.sub_institute_id')
            ->orderByDesc('d.sub_institute_id')
            // No hydrate() here: this class does not extend BaseRepository —
            // the ERP tables it reads are not Brain-owned and hold no JSON
            // columns.
            ->get()->map(fn ($r) => (array) $r)->all();
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $next = (int) (DB::table('institute_detail')->max('sub_institute_id') ?? 0) + 1;
            $now = now()->format('Y-m-d H:i:s');

            DB::table('institute_detail')->insert([
                'sub_institute_id'  => $next,
                'organization_name' => $input['name'],
                'organization_code' => $input['orgCode'] ?? null,
                'industry_type'     => $input['industry'] ?? null,
                'created_by'        => $input['createdBy'],
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            DB::table('org_details')->insert([
                'sub_institute_id' => $next,
                'legal_name'       => $input['legalName'] ?? null,
                'logo'             => $input['logo'] ?? null,
                'created_by'       => $input['createdBy'],
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            // The ERP requires every institute to own an 'Employee' user
            // profile; PersonRepository resolves it on every person create and
            // throws when it is missing. Creating an institute here means
            // creating one the ERP has never seen, so the Brain must provision
            // that row itself. Without this, every person created in a
            // Brain-created organization fails.
            $hasProfile = DB::table('tbluserprofilemaster')
                ->where('sub_institute_id', $next)->where('name', 'Employee')->where('status', 1)->exists();

            if (! $hasProfile) {
                DB::table('tbluserprofilemaster')->insert([
                    'sub_institute_id' => $next,
                    'name'             => 'Employee',
                    'status'           => 1,
                ]);
            }

            return ['id' => (string) $next] + $input;
        });
    }
}
