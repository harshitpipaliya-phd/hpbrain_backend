<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_import_logs (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  import_job_id   VARCHAR(36) NOT NULL,
  row_number      INT,
  action          VARCHAR(50) NOT NULL,
  entity_type     VARCHAR(100),
  entity_id       VARCHAR(36),
  data            JSON,
  error_message   TEXT,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_import_logs_tenant_id ON hpbrain_import_logs (tenant_id)');
        DB::unprepared('CREATE INDEX idx_import_logs_import_job_id ON hpbrain_import_logs (import_job_id)');
        DB::unprepared('CREATE INDEX idx_import_logs_action ON hpbrain_import_logs (action)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_import_logs');
    }
};
