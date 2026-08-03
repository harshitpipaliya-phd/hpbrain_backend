<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class LocationTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['type_key' => 'headquarters', 'name' => 'Headquarters'],
            ['type_key' => 'branch_office', 'name' => 'Branch Office'],
            ['type_key' => 'remote', 'name' => 'Remote'],
            ['type_key' => 'warehouse', 'name' => 'Warehouse'],
            ['type_key' => 'clinical_facility', 'name' => 'Clinical Facility'],
        ];

        foreach ($types as $type) {
            DB::table('hpbrain_location_types')->updateOrInsert(
                ['type_key' => $type['type_key'], 'tenant_id' => 'platform'],
                array_merge($type, [
                    'tenant_id'    => 'platform',
                    'description'  => 'Platform default location type',
                    'metadata'     => json_encode([]),
                    'status'       => 'active',
                    'created_by'   => 'system',
                    'created_date' => date('Y-m-d H:i:s'),
                    'updated_date' => date('Y-m-d H:i:s'),
                ])
            );
        }
    }
}
