<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_industry_templates (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  industry_code   VARCHAR(100) NOT NULL,
  template_name   VARCHAR(255) NOT NULL,
  description     TEXT,
  terminology     JSON,
  modules         JSON,
  navigation      JSON,
  dashboards      JSON,
  branding        JSON,
  workflows       JSON,
  integrations    JSON,
  is_system       BOOLEAN NOT NULL DEFAULT FALSE,
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,
  created_by      TEXT NOT NULL,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT industry_templates_tenant_code_unique UNIQUE (tenant_id, industry_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_industry_templates_tenant_id ON hpbrain_industry_templates (tenant_id)');
        DB::unprepared('CREATE INDEX idx_industry_templates_is_active ON hpbrain_industry_templates (is_active)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_industry_templates');
    }
};
