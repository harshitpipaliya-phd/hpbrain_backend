<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_evaluations (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  evaluation_name VARCHAR(255) NOT NULL,
  evaluation_type VARCHAR(255) NOT NULL,
  dataset         JSON,
  results         JSON,
  model           VARCHAR(255),
  status          VARCHAR(50) NOT NULL DEFAULT \'pending\',
  run_by          TEXT,
  run_date        TIMESTAMP NULL,
  created_by      TEXT NOT NULL,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_ai_evaluations_tenant_id ON hpbrain_ai_evaluations (tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_ai_evaluations');
    }
};
