<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_prompt_templates (
  id                  VARCHAR(36) PRIMARY KEY,
  tenant_id           VARCHAR(36) NOT NULL,
  prompt_key          VARCHAR(255) NOT NULL,
  version             INT NOT NULL,
  name                VARCHAR(255) NOT NULL,
  description         TEXT,
  purpose             VARCHAR(255),
  system_prompt       TEXT NOT NULL,
  user_prompt_template TEXT NOT NULL,
  response_schema     JSON,
  allowed_roles       JSON,
  data_sources        JSON,
  model_capability    VARCHAR(255),
  generation_settings JSON,
  safety_profile      VARCHAR(255),
  status              VARCHAR(50) NOT NULL DEFAULT \'draft\',
  change_summary      TEXT,
  created_by          TEXT NOT NULL,
  created_date        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_ai_prompt_templates_tenant_id ON hpbrain_ai_prompt_templates (tenant_id)');
        DB::unprepared('CREATE INDEX idx_ai_prompt_templates_prompt_key ON hpbrain_ai_prompt_templates (prompt_key)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_ai_prompt_templates');
    }
};
