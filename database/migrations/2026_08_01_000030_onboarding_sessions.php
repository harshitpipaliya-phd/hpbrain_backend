<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_onboarding_sessions (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  org_id          VARCHAR(36),
  current_step    INT DEFAULT 1,
  total_steps     INT DEFAULT 12,
  status          VARCHAR(50) NOT NULL DEFAULT \'draft\',
  data            JSON,
  completed_steps JSON,
  started_by      TEXT NOT NULL,
  completed_by    TEXT,
  activated_date  DATETIME,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_onboarding_sessions_tenant_id ON hpbrain_onboarding_sessions (tenant_id)');
        DB::unprepared('CREATE INDEX idx_onboarding_sessions_org_id ON hpbrain_onboarding_sessions (org_id)');
        DB::unprepared('CREATE INDEX idx_onboarding_sessions_status ON hpbrain_onboarding_sessions (status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_onboarding_sessions');
    }
};
