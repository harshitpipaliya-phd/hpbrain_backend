<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_person_roles (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  person_id       VARCHAR(36) NOT NULL,
  role_id         VARCHAR(36) NOT NULL,
  org_id          VARCHAR(36),
  unit_id         VARCHAR(36),
  start_date      DATE,
  end_date        DATE,
  is_primary      BOOLEAN DEFAULT FALSE,
  metadata        JSON,
  created_by      TEXT NOT NULL,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT person_roles_tenant_person_role_org_unique UNIQUE (tenant_id, person_id, role_id, org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_person_roles_tenant_id ON hpbrain_person_roles (tenant_id)');
        DB::unprepared('CREATE INDEX idx_person_roles_person_id ON hpbrain_person_roles (person_id)');
        DB::unprepared('CREATE INDEX idx_person_roles_unit_id ON hpbrain_person_roles (unit_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_person_roles');
    }
};
