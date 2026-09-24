<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Registers FiberValley as an organization the ERP knows about.
 *
 * This is the entire "make FiberValley a first-class organization" step. Once
 * these rows exist:
 *
 *   - OrganizationRepository::list() returns FiberValley, so it appears in the
 *     org switcher with no frontend change;
 *   - EnsureTenantScope::organizationExists() passes, so an admin token may
 *     address /workspace/{id}/..., /signals/{id}, /analytics/{id} and the rest;
 *   - PersonRepository can create people, because the 'Employee' user profile
 *     the ERP demands is provisioned here.
 *
 * It writes to institute_detail / org_details / tbluserprofilemaster in exactly
 * the pattern OrganizationRepository::create() uses, rather than inventing a
 * second way to register an organization.
 *
 * IDEMPOTENT. Re-running finds the existing row and reports its id. It never
 * allocates a second sub_institute_id for the same organization, which would
 * split the imported data across two tenants and make half of it unreachable.
 *
 * Run:  php artisan db:seed --class=FiberValleySeeder
 */
final class FiberValleySeeder extends Seeder
{
    private const ORG_NAME  = 'FiberValley';
    private const ORG_CODE  = 'FIBERVALLEY';
    private const LEGAL     = 'FiberValley Broadband Services';
    private const INDUSTRY  = 'technology';

    /**
     * Codes that all mean this organization.
     *
     * The seeder used to match ORG_CODE alone. The row the ERP actually holds
     * is coded 'FIBER-VALLEY', so the check missed it and the seeder moved on
     * to allocate a SECOND sub_institute_id for an organization that already
     * had one — the precise failure the idempotency check exists to prevent,
     * and one that would have split the imported data across two tenants.
     *
     * Matching on a set rather than tightening to the ERP's spelling keeps a
     * deployment that used the other code working.
     */
    private const ORG_CODES = ['FIBERVALLEY', 'FIBER-VALLEY', 'FIBER_VALLEY'];

    public function run(): void
    {
        $existing = DB::table('institute_detail')
            ->whereIn('organization_code', self::ORG_CODES)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            $this->command?->info(
                "FiberValley already registered as sub_institute_id {$existing->sub_institute_id} — nothing to do."
            );
            $this->command?->line(
                "Import with: php artisan brain:import fibervalley --tenant={$existing->sub_institute_id}"
            );

            return;
        }

        $tenantId = DB::transaction(function () {
            $next = (int) (DB::table('institute_detail')->max('sub_institute_id') ?? 0) + 1;
            $now  = now()->format('Y-m-d H:i:s');

            DB::table('institute_detail')->insert([
                'sub_institute_id'  => $next,
                'organization_name' => self::ORG_NAME,
                'organization_code' => self::ORG_CODE,
                'industry_type'     => self::INDUSTRY,
                // NULL, not a marker string. institute_detail.created_by is a
                // bigint FOREIGN KEY to tbluser.id, so 'seed:fibervalley' was
                // rejected outright by MySQL (SQLSTATE 22007). There is no
                // seeding user to name, and inventing one would put a
                // fictional actor in the ERP's audit trail — the existing
                // FiberValley row carries NULL here for the same reason.
                'created_by'        => null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            DB::table('org_details')->insert([
                'sub_institute_id' => $next,
                'legal_name'       => self::LEGAL,
                'logo'             => null,
                'created_by'       => null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            // Required by PersonRepository, which throws when an institute has
            // no 'Employee' profile. Without this every person the roster
            // import creates would fail.
            $hasProfile = DB::table('tbluserprofilemaster')
                ->where('sub_institute_id', $next)
                ->where('name', 'Employee')
                ->where('status', 1)
                ->exists();

            if (! $hasProfile) {
                DB::table('tbluserprofilemaster')->insert([
                    'sub_institute_id' => $next,
                    'name'             => 'Employee',
                    'status'           => 1,
                ]);
            }

            return $next;
        });

        $this->command?->info("FiberValley registered as sub_institute_id {$tenantId}.");
        $this->command?->line('Next:');
        $this->command?->line("  php artisan brain:import fibervalley --tenant={$tenantId} --generate-signals");
    }
}
