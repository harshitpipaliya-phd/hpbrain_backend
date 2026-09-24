<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE hpbrain_capabilities ADD COLUMN IF NOT EXISTS org_unit_id VARCHAR(36) AFTER org_id');
        DB::unprepared('CREATE INDEX IF NOT EXISTS idx_capabilities_org_unit_id ON hpbrain_capabilities (org_unit_id)');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE hpbrain_capabilities DROP COLUMN IF EXISTS org_unit_id');
    }
};
