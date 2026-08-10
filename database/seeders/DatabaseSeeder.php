<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LoopSeeder::class,
            OrganizationTypeSeeder::class,
            RoleSeeder::class,
            SkillSeeder::class,
            CompetencySeeder::class,
            LocationTypeSeeder::class,
            DemoLionsSeeder::class,
        ]);
    }
}
