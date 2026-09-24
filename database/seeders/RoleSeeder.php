<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['role_key' => 'super_admin', 'name' => 'Super Admin', 'category' => 'Administration', 'is_system' => true],
            ['role_key' => 'admin', 'name' => 'Administrator', 'category' => 'Administration', 'is_system' => true],
            ['role_key' => 'manager', 'name' => 'Manager', 'category' => 'Management', 'is_system' => true],
            ['role_key' => 'supervisor', 'name' => 'Supervisor', 'category' => 'Management', 'is_system' => true],
            ['role_key' => 'employee', 'name' => 'Employee', 'category' => 'General', 'is_system' => true],
            ['role_key' => 'viewer', 'name' => 'Viewer', 'category' => 'General', 'is_system' => true],
        ];

        foreach ($roles as $role) {
            DB::table('hpbrain_roles')->updateOrInsert(
                ['role_key' => $role['role_key'], 'tenant_id' => 'platform'],
                array_merge($role, [
                    'tenant_id'    => 'platform',
                    'description'  => 'Platform default role',
                    'permissions'  => json_encode([]),
                    'status'       => 'active',
                    'created_by'   => 'system',
                    'created_date' => date('Y-m-d H:i:s'),
                    'updated_date' => date('Y-m-d H:i:s'),
                ])
            );
        }
    }
}
