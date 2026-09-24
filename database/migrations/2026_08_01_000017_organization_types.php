<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_organization_types (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  type_key        VARCHAR(100) NOT NULL,
  name            VARCHAR(255) NOT NULL,
  description     TEXT,
  icon            VARCHAR(255),
  sort_order      INT DEFAULT 0,
  status          VARCHAR(50) NOT NULL DEFAULT \'active\',
  created_by      TEXT NOT NULL,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT org_types_tenant_type_key_unique UNIQUE (tenant_id, type_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_org_types_tenant_id ON hpbrain_organization_types (tenant_id)');
        DB::unprepared('CREATE INDEX idx_org_types_type_key ON hpbrain_organization_types (type_key)');
        DB::unprepared('CREATE INDEX idx_org_types_status ON hpbrain_organization_types (status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_organization_types');
    }
};
