<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_readiness_checks (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  org_id          VARCHAR(36),
  check_type      VARCHAR(100) NOT NULL,
  check_name      VARCHAR(255) NOT NULL,
  status          VARCHAR(50) NOT NULL DEFAULT \'pending\',
  message         TEXT,
  metadata        JSON,
  checked_date    DATETIME,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_readiness_checks_tenant_id ON hpbrain_readiness_checks (tenant_id)');
        DB::unprepared('CREATE INDEX idx_readiness_checks_org_id ON hpbrain_readiness_checks (org_id)');
        DB::unprepared('CREATE INDEX idx_readiness_checks_check_type ON hpbrain_readiness_checks (check_type)');
        DB::unprepared('CREATE INDEX idx_readiness_checks_status ON hpbrain_readiness_checks (status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_readiness_checks');
    }
};
