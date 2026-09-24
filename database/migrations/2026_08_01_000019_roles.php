<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_roles (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  role_key        VARCHAR(100) NOT NULL,
  name            VARCHAR(255) NOT NULL,
  description     TEXT,
  category        VARCHAR(100),
  permissions     JSON,
  is_system       BOOLEAN DEFAULT FALSE,
  status          VARCHAR(50) NOT NULL DEFAULT \'active\',
  created_by      TEXT NOT NULL,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT roles_tenant_role_key_unique UNIQUE (tenant_id, role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_roles_tenant_id ON hpbrain_roles (tenant_id)');
        DB::unprepared('CREATE INDEX idx_roles_role_key ON hpbrain_roles (role_key)');
        DB::unprepared('CREATE INDEX idx_roles_category ON hpbrain_roles (category)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_roles');
    }
};
