<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_forms (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  org_id          VARCHAR(36) NOT NULL,
  form_key        VARCHAR(255) NOT NULL,
  name            VARCHAR(255) NOT NULL,
  description     TEXT,
  entity_type     VARCHAR(100),
  fields          JSON,
  validation_rules JSON,
  submit_action   TEXT,
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,
  version         INT NOT NULL DEFAULT 1,
  created_by      TEXT NOT NULL,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT forms_tenant_org_key_version_unique UNIQUE (tenant_id, org_id, form_key, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_forms_tenant_id ON hpbrain_forms (tenant_id)');
        DB::unprepared('CREATE INDEX idx_forms_entity_type ON hpbrain_forms (entity_type)');
        DB::unprepared('CREATE INDEX idx_forms_is_active ON hpbrain_forms (is_active)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_forms');
    }
};
