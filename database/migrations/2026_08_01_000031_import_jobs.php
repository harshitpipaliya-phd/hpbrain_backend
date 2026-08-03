<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_import_jobs (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  org_id          VARCHAR(36),
  import_type     VARCHAR(50) NOT NULL,
  entity_type     VARCHAR(100) NOT NULL,
  status          VARCHAR(50) NOT NULL DEFAULT \'pending\',
  total_rows      INT DEFAULT 0,
  processed_rows  INT DEFAULT 0,
  success_count   INT DEFAULT 0,
  error_count     INT DEFAULT 0,
  duplicate_count INT DEFAULT 0,
  error_report    JSON,
  rollback_data   JSON,
  started_by      TEXT NOT NULL,
  completed_date  DATETIME,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_import_jobs_tenant_id ON hpbrain_import_jobs (tenant_id)');
        DB::unprepared('CREATE INDEX idx_import_jobs_org_id ON hpbrain_import_jobs (org_id)');
        DB::unprepared('CREATE INDEX idx_import_jobs_status ON hpbrain_import_jobs (status)');
        DB::unprepared('CREATE INDEX idx_import_jobs_import_type ON hpbrain_import_jobs (import_type)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_import_jobs');
    }
};
