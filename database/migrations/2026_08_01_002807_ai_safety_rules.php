<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_safety_rules (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  rule_name       VARCHAR(255) NOT NULL,
  rule_type       VARCHAR(100) NOT NULL,
  pattern         TEXT NOT NULL,
  action          VARCHAR(50) NOT NULL DEFAULT \'block\',
  severity        VARCHAR(50) NOT NULL DEFAULT \'high\',
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,
  created_by      TEXT NOT NULL,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_ai_safety_rules_tenant_id ON hpbrain_ai_safety_rules (tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_ai_safety_rules');
    }
};
