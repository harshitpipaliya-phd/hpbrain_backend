<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'hpbrain_signals', 'hpbrain_capabilities', 'hpbrain_departments',
            'hpbrain_people', 'hpbrain_process_definitions', 'hpbrain_context_entities',
            'hpbrain_reasoning_patterns', 'hpbrain_eso_definitions', 'hpbrain_telemetry_events',
        ];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'org_id')) {
                DB::unprepared("ALTER TABLE `{$table}` MODIFY COLUMN `org_id` VARCHAR(36) NULL");
            }
        }
    }
    public function down(): void {}
};