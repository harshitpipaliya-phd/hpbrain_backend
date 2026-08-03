<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_reporting_structures (
  id                  VARCHAR(36) PRIMARY KEY,
  tenant_id           VARCHAR(36) NOT NULL,
  org_id              VARCHAR(36),
  reporter_person_id  VARCHAR(36) NOT NULL,
  reportee_person_id  VARCHAR(36) NOT NULL,
  reporting_type      VARCHAR(50) NOT NULL DEFAULT \'direct\',
  unit_id             VARCHAR(36),
  start_date          DATE,
  end_date            DATE,
  metadata            JSON,
  created_by          TEXT NOT NULL,
  created_date        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT reporting_structures_tenant_reporter_reportee_type_unique UNIQUE (tenant_id, reporter_person_id, reportee_person_id, reporting_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_reporting_structures_tenant_id ON hpbrain_reporting_structures (tenant_id)');
        DB::unprepared('CREATE INDEX idx_reporting_structures_org_id ON hpbrain_reporting_structures (org_id)');
        DB::unprepared('CREATE INDEX idx_reporting_structures_unit_id ON hpbrain_reporting_structures (unit_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_reporting_structures');
    }
};
