<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class OrganizationTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['type_key' => 'department', 'name' => 'Department', 'icon' => 'account_tree', 'sort_order' => 1],
            ['type_key' => 'school', 'name' => 'School', 'icon' => 'school', 'sort_order' => 2],
            ['type_key' => 'faculty', 'name' => 'Faculty', 'icon' => 'account_balance', 'sort_order' => 3],
            ['type_key' => 'branch', 'name' => 'Branch', 'icon' => 'location_city', 'sort_order' => 4],
            ['type_key' => 'clinical_unit', 'name' => 'Clinical Unit', 'icon' => 'local_hospital', 'sort_order' => 5],
            ['type_key' => 'division', 'name' => 'Division', 'icon' => 'view_week', 'sort_order' => 6],
            ['type_key' => 'business_unit', 'name' => 'Business Unit', 'icon' => 'business', 'sort_order' => 7],
            ['type_key' => 'custom', 'name' => 'Custom', 'icon' => 'extension', 'sort_order' => 8],
        ];

        foreach ($types as $type) {
            DB::table('hpbrain_organization_types')->updateOrInsert(
                ['type_key' => $type['type_key'], 'tenant_id' => 'platform'],
                array_merge($type, [
                    'tenant_id'   => 'platform',
                    'description' => 'Platform default organization type',
                    'status'      => 'active',
                    'created_by'  => 'system',
                    'created_date'=> date('Y-m-d H:i:s'),
                    'updated_date'=> date('Y-m-d H:i:s'),
                ])
            );
        }
    }
}
