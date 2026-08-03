<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class IndustryTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $tenantId = 'platform';

        $industries = ['healthcare', 'k12_education', 'higher_education', 'corporate', 'manufacturing', 'retail', 'government', 'bfsi', 'ngo', 'technology'];

        $defaultTerminology = [
            'Person' => 'Employee', 'OrganizationUnit' => 'Department', 'Role' => 'Role',
            'Skill' => 'Skill', 'Competency' => 'Competency', 'Capability' => 'Capability',
        ];

        foreach ($industries as $industryCode) {
            DB::table('hpbrain_industry_templates')->insert([
                'id'            => Uuid::uuid4()->toString(),
                'tenant_id'     => $tenantId,
                'industry_code' => $industryCode,
                'template_name' => ucwords(str_replace('_', ' ', $industryCode)) . ' Template',
                'description'   => 'Default configuration for ' . ucwords(str_replace('_', ' ', $industryCode)),
                'terminology'   => json_encode($defaultTerminology),
                'modules'       => json_encode(['intelligence', 'capabilities', 'decisions', 'analytics']),
                'navigation'    => json_encode([]),
                'dashboards'    => json_encode([]),
                'branding'      => json_encode([]),
                'workflows'     => json_encode([]),
                'integrations'  => json_encode([]),
                'is_system'     => false,
                'is_active'     => true,
                'created_by'    => 'system',
                'created_date'  => $now,
                'updated_date'  => $now,
            ]);
        }
    }
}
