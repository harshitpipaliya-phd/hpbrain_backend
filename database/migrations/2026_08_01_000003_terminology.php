<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_terminology (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  industry_code   VARCHAR(100) NOT NULL,
  entity_type     VARCHAR(100) NOT NULL,
  display_name    VARCHAR(255) NOT NULL,
  plural_name     VARCHAR(255),
  description     TEXT,
  icon            VARCHAR(255),
  sort_order      INT DEFAULT 0,
  status          VARCHAR(50) NOT NULL DEFAULT \'active\',
  created_by      TEXT NOT NULL,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT terminology_tenant_industry_entity_unique UNIQUE (tenant_id, industry_code, entity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_terminology_tenant_id ON hpbrain_terminology (tenant_id)');
        DB::unprepared('CREATE INDEX idx_terminology_status ON hpbrain_terminology (status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_terminology');
    }
};
