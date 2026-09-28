<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Reports;

use App\Repositories\OrganizationRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The signed-in organisation's own name and logo, for a report layout's letterhead.
 *
 * HP Brain's port of G2G's OrganisationBranding. Read from where HP Brain's own screens
 * keep them: the name from OrganizationRepository (the tenant's mapped Organization
 * register — institute_detail / school_setup through EntityResolver), the logo from the
 * tenant's active hpbrain_branding row.
 *
 * Null rather than a default when an organisation has set none — a report must never
 * carry another organisation's, or a made-up, letterhead.
 */
final class OrganisationBranding
{
    public function __construct(private readonly OrganizationRepository $organizations)
    {
    }

    /** @return array{institute_name: string|null, logo_url: string|null, tenant_id: string} */
    public function for(string $tenantId): array
    {
        $name = null;
        $logo = null;

        try {
            foreach ($this->organizations->list($tenantId) as $row) {
                $candidate = trim((string) (((array) $row)['name'] ?? ''));

                if ($candidate !== '') {
                    $name = $candidate;

                    break;
                }
            }
        } catch (Throwable) {
            // Branding is decoration. An unmapped register leaves the letterhead blank
            // rather than failing the report.
        }

        try {
            if (Schema::hasTable('hpbrain_branding')) {
                $value = DB::table('hpbrain_branding')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', 1)
                    ->whereNotNull('logo_url')
                    ->orderByDesc('updated_date')
                    ->value('logo_url');

                $logo = trim((string) $value) !== '' ? trim((string) $value) : null;
            }
        } catch (Throwable) {
            // As above.
        }

        return ['institute_name' => $name, 'logo_url' => $logo, 'tenant_id' => $tenantId];
    }
}
