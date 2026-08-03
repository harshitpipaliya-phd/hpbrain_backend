<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class CompetencySeeder extends Seeder
{
    public function run(): void
    {
        $competencies = [
            ['competency_key' => 'strategic_thinking', 'name' => 'Strategic Thinking', 'category' => 'Leadership', 'framework' => 'generic'],
            ['competency_key' => 'team_collaboration', 'name' => 'Team Collaboration', 'category' => 'Interpersonal', 'framework' => 'generic'],
            ['competency_key' => 'execution', 'name' => 'Execution Excellence', 'category' => 'Operational', 'framework' => 'generic'],
            ['competency_key' => 'innovation', 'name' => 'Innovation', 'category' => 'Cognitive', 'framework' => 'generic'],
        ];

        foreach ($competencies as $competency) {
            DB::table('hpbrain_competencies')->updateOrInsert(
                ['competency_key' => $competency['competency_key'], 'tenant_id' => 'platform'],
                array_merge($competency, [
                    'tenant_id'     => 'platform',
                    'description'   => 'Platform default competency',
                    'level_descriptors' => json_encode([]),
                    'metadata'      => json_encode([]),
                    'status'        => 'active',
                    'created_by'    => 'system',
                    'created_date'  => date('Y-m-d H:i:s'),
                    'updated_date'  => date('Y-m-d H:i:s'),
                ])
            );
        }
    }
}
