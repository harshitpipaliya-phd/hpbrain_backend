<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Support;

use App\Repositories\BrandingRepository;
use App\Repositories\OrganizationRepository;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The signed-in tenant's own name and logo, read from where HP Brain already
 * keeps them: the organisation name through OrganizationRepository (resolved per
 * tenant by EntityResolver, the same source OrganizationController serves) and
 * the logo from hpbrain_branding.
 *
 * Null rather than a default when a tenant has set none — an assistant or a
 * letterhead must never carry another organisation's, or a made-up, name.
 */
final class OrganisationProfile
{
    /** @var array<string, array{name: ?string, logo: ?string}> */
    private array $memo = [];

    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly BrandingRepository $branding,
    ) {
    }

    public function name(string $tenantId): ?string
    {
        return $this->load($tenantId)['name'];
    }

    /** @return array{institute_name: ?string, logo_url: ?string, tenant_id: string} */
    public function branding(string $tenantId): array
    {
        $profile = $this->load($tenantId);

        return [
            'institute_name' => $profile['name'],
            'logo_url' => $profile['logo'],
            'tenant_id' => $tenantId,
        ];
    }

    /** @return array{name: ?string, logo: ?string} */
    private function load(string $tenantId): array
    {
        if (isset($this->memo[$tenantId])) {
            return $this->memo[$tenantId];
        }

        $name = null;
        $logo = null;

        try {
            foreach ($this->organizations->list($tenantId) as $org) {
                $candidate = trim((string) ($org['name'] ?? ''));

                if ($candidate !== '') {
                    $name = $candidate;
                    break;
                }
            }
        } catch (Throwable) {
            // Decoration, not a dependency: a lookup failure leaves the name unset.
        }

        try {
            if (Schema::hasTable('hpbrain_branding')) {
                foreach ($this->branding->list($tenantId) as $row) {
                    $candidate = trim((string) ($row['logo_url'] ?? ''));

                    if ($candidate !== '') {
                        $logo = $candidate;
                        break;
                    }
                }
            }
        } catch (Throwable) {
        }

        return $this->memo[$tenantId] = ['name' => $name, 'logo' => $logo];
    }
}
