<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_navigation_items (
  id                  VARCHAR(36) PRIMARY KEY,
  tenant_id           VARCHAR(36) NOT NULL,
  industry_code       VARCHAR(100) NOT NULL,
  role_key            VARCHAR(100) NOT NULL,
  item_key            VARCHAR(255) NOT NULL,
  label               VARCHAR(255) NOT NULL,
  icon                VARCHAR(255),
  route               VARCHAR(500),
  parent_id           VARCHAR(36),
  sort_order          INT DEFAULT 0,
  is_visible          BOOLEAN NOT NULL DEFAULT TRUE,
  required_permission VARCHAR(255),
  required_flag       VARCHAR(255),
  required_module     VARCHAR(255),
  children            JSON,
  created_by          TEXT NOT NULL,
  created_date        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT navigation_items_tenant_industry_role_key_unique UNIQUE (tenant_id, industry_code, role_key, item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_navigation_items_tenant_id ON hpbrain_navigation_items (tenant_id)');
        DB::unprepared('CREATE INDEX idx_navigation_items_is_visible ON hpbrain_navigation_items (is_visible)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_navigation_items');
    }
};
