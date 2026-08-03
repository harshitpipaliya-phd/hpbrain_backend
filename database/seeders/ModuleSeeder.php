<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            ['module_key' => 'intelligence', 'name' => 'Intelligence', 'description' => 'Core intelligence engine', 'category' => 'core', 'is_core' => true, 'sort_order' => 1],
            ['module_key' => 'capabilities', 'name' => 'Capabilities', 'description' => 'Capability management', 'category' => 'core', 'is_core' => true, 'sort_order' => 2],
            ['module_key' => 'decisions', 'name' => 'Decisions', 'description' => 'Decision intelligence', 'category' => 'core', 'is_core' => true, 'sort_order' => 3],
            ['module_key' => 'analytics', 'name' => 'Analytics', 'description' => 'Analytics and reporting', 'category' => 'core', 'is_core' => true, 'sort_order' => 4],
            ['module_key' => 'ai_workspace', 'name' => 'AI Workspace', 'description' => 'AI-powered workspace', 'category' => 'ai', 'is_core' => false, 'sort_order' => 5],
            ['module_key' => 'graph_explorer', 'name' => 'Graph Explorer', 'description' => 'Knowledge graph explorer', 'category' => 'core', 'is_core' => true, 'sort_order' => 6],
            ['module_key' => 'learning', 'name' => 'Learning', 'description' => 'Learning and development', 'category' => 'core', 'is_core' => true, 'sort_order' => 7],
            ['module_key' => 'policies', 'name' => 'Policies', 'description' => 'Policy management', 'category' => 'core', 'is_core' => true, 'sort_order' => 8],
            ['module_key' => 'risks', 'name' => 'Risks', 'description' => 'Risk assessment', 'category' => 'core', 'is_core' => true, 'sort_order' => 9],
            ['module_key' => 'notifications', 'name' => 'Notifications', 'description' => 'Notification system', 'category' => 'core', 'is_core' => true, 'sort_order' => 10],
        ];

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $tenantId = 'platform';

        foreach ($modules as $module) {
            DB::table('hpbrain_modules')->insert([
                'id'           => Uuid::uuid4()->toString(),
                'tenant_id'    => $tenantId,
                'module_key'   => $module['module_key'],
                'name'         => $module['name'],
                'description'  => $module['description'],
                'version'      => '1.0.0',
                'category'     => $module['category'],
                'is_core'      => $module['is_core'],
                'is_enabled'   => true,
                'dependencies' => json_encode([]),
                'config_schema'=> json_encode([]),
                'sort_order'   => $module['sort_order'],
                'created_by'   => 'system',
                'created_date' => $now,
                'updated_date' => $now,
            ]);
        }
    }
}
