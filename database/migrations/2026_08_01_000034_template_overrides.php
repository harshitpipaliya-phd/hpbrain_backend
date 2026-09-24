<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_template_overrides (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  org_id          VARCHAR(36),
  template_type   VARCHAR(100) NOT NULL,
  template_key    VARCHAR(255) NOT NULL,
  override_level  VARCHAR(50) NOT NULL DEFAULT \'organization\',
  override_data   JSON,
  is_active       BOOLEAN DEFAULT TRUE,
  created_by      TEXT NOT NULL,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT template_overrides_tenant_org_type_key_level_unique UNIQUE (tenant_id, org_id, template_type, template_key, override_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_template_overrides_tenant_id ON hpbrain_template_overrides (tenant_id)');
        DB::unprepared('CREATE INDEX idx_template_overrides_org_id ON hpbrain_template_overrides (org_id)');
        DB::unprepared('CREATE INDEX idx_template_overrides_is_active ON hpbrain_template_overrides (is_active)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_template_overrides');
    }
};
