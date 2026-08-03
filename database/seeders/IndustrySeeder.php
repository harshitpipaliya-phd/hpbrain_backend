<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class IndustrySeeder extends Seeder
{
    public function run(): void
    {
        $industries = [
            ['code' => 'healthcare', 'name' => 'Healthcare', 'description' => 'Hospitals, clinics, and healthcare providers', 'icon' => 'local_hospital'],
            ['code' => 'k12_education', 'name' => 'K-12 Education', 'description' => 'Primary and secondary schools', 'icon' => 'school'],
            ['code' => 'higher_education', 'name' => 'Higher Education', 'description' => 'Colleges and universities', 'icon' => 'account_balance'],
            ['code' => 'corporate', 'name' => 'Corporate', 'description' => 'Enterprise and corporate organizations', 'icon' => 'business'],
            ['code' => 'manufacturing', 'name' => 'Manufacturing', 'description' => 'Manufacturing and industrial units', 'icon' => 'precision_manufacturing'],
            ['code' => 'retail', 'name' => 'Retail', 'description' => 'Retail and e-commerce businesses', 'icon' => 'shopping_cart'],
            ['code' => 'government', 'name' => 'Government', 'description' => 'Government and public sector', 'icon' => 'account_balance'],
            ['code' => 'bfsi', 'name' => 'BFSI', 'description' => 'Banking, Financial Services, and Insurance', 'icon' => 'account_balance_wallet'],
            ['code' => 'ngo', 'name' => 'NGO', 'description' => 'Non-governmental organizations', 'icon' => 'volunteer_activism'],
            ['code' => 'technology', 'name' => 'Technology', 'description' => 'Technology and software companies', 'icon' => 'computer'],
        ];

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $tenantId = 'platform';

        foreach ($industries as $industry) {
            DB::table('hpbrain_industries')->insert([
                'id'           => Uuid::uuid4()->toString(),
                'tenant_id'    => $tenantId,
                'code'         => $industry['code'],
                'name'         => $industry['name'],
                'description'  => $industry['description'],
                'icon'         => $industry['icon'],
                'sort_order'   => 0,
                'status'       => 'active',
                'settings'     => json_encode([]),
                'created_by'   => 'system',
                'created_date' => $now,
                'updated_date' => $now,
            ]);
        }
    }
}
