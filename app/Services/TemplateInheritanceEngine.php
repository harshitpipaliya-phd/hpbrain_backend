<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TemplateOverrideRepository;
use App\Repositories\IndustryTemplateRepository;
use App\Repositories\OrganizationConfigRepository;

final class TemplateInheritanceEngine
{
    private const INHERITANCE_ORDER = ['platform', 'industry', 'organization', 'role', 'user'];

    public function __construct(
        private readonly TemplateOverrideRepository $overrideRepository,
        private readonly IndustryTemplateRepository $industryTemplateRepository,
        private readonly OrganizationConfigRepository $configRepository,
    ) {
    }

    public function resolve(string $tenantId, string $orgId, string $templateType, string $templateKey, string $context = 'organization'): ?array
    {
        foreach (self::INHERITANCE_ORDER as $level) {
            $value = $this->getEffectiveValue($tenantId, $orgId, $templateType, $templateKey, $level, $context);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    public function getEffectiveValue(string $tenantId, string $orgId, string $templateType, string $templateKey, ?string $level = null, ?string $context = 'organization'): ?array
    {
        $levels = $level !== null ? [$level] : self::INHERITANCE_ORDER;

        foreach ($levels as $lvl) {
            $row = $this->overrideRepository->findByTemplate($tenantId, $templateType, $templateKey, $lvl);

            if ($row && $row['is_active']) {
                $overrideData = $row['override_data'] ?? null;

                if (is_string($overrideData)) {
                    $overrideData = json_decode($overrideData, true);
                }

                return $overrideData;
            }
        }

        $industry = $this->configRepository->findByOrg($tenantId, $orgId);
        if ($industry) {
            $industryCode = $industry['industry_code'] ?? null;

            if ($industryCode) {
                $template = $this->industryTemplateRepository->findByCode($tenantId, $industryCode);

                if ($template) {
                    $templateData = $template['template_data'] ?? null;

                    if (is_string($templateData)) {
                        $templateData = json_decode($templateData, true);
                    }

                    if (isset($templateData[$templateType][$templateKey])) {
                        return $templateData[$templateType][$templateKey];
                    }
                }
            }
        }

        return null;
    }

    public function setOverride(string $tenantId, string $orgId, string $templateType, string $templateKey, string $level, array $data): array
    {
        $existing = $this->overrideRepository->findByTemplate($tenantId, $templateType, $templateKey, $level);

        if ($existing) {
            return $this->overrideRepository->update($tenantId, $existing['id'], [
                'override_data' => $data,
                'is_active'     => true,
            ]);
        }

        return $this->overrideRepository->create($tenantId, [
            'org_id'         => $orgId,
            'template_type'  => $templateType,
            'template_key'   => $templateKey,
            'override_level' => $level,
            'override_data'  => $data,
            'is_active'      => true,
            'created_by'     => 'system',
        ]);
    }
}
