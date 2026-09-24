<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_quotas (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  quota_type      VARCHAR(50) NOT NULL,
  quota_key       VARCHAR(255) NOT NULL,
  limit_value     INT NOT NULL,
  current_usage   INT NOT NULL DEFAULT 0,
  reset_period    VARCHAR(50) NOT NULL DEFAULT \'monthly\',
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,
  created_by      TEXT NOT NULL,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_ai_quotas_tenant_id ON hpbrain_ai_quotas (tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_ai_quotas');
    }
};
