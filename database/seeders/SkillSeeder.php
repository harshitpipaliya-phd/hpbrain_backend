<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class SkillSeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            ['skill_key' => 'communication', 'name' => 'Communication', 'category' => 'Soft Skills', 'level' => 'intermediate'],
            ['skill_key' => 'leadership', 'name' => 'Leadership', 'category' => 'Management', 'level' => 'intermediate'],
            ['skill_key' => 'problem_solving', 'name' => 'Problem Solving', 'category' => 'Cognitive', 'level' => 'intermediate'],
            ['skill_key' => 'technical_writing', 'name' => 'Technical Writing', 'category' => 'Technical', 'level' => 'intermediate'],
            ['skill_key' => 'data_analysis', 'name' => 'Data Analysis', 'category' => 'Technical', 'level' => 'intermediate'],
            ['skill_key' => 'project_management', 'name' => 'Project Management', 'category' => 'Management', 'level' => 'intermediate'],
        ];

        foreach ($skills as $skill) {
            DB::table('hpbrain_skills')->updateOrInsert(
                ['skill_key' => $skill['skill_key'], 'tenant_id' => 'platform'],
                array_merge($skill, [
                    'tenant_id'    => 'platform',
                    'description'  => 'Platform default skill',
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
