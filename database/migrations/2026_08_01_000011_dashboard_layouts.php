<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_dashboard_layouts (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  dashboard_id    VARCHAR(36) NOT NULL,
  layout_type     VARCHAR(50) NOT NULL DEFAULT \'grid\',
  grid_columns    INT DEFAULT 12,
  grid_rows       INT DEFAULT 12,
  widgets         JSON,
  created_by      TEXT NOT NULL,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_dashboard_layouts_tenant_id ON hpbrain_dashboard_layouts (tenant_id)');
        DB::unprepared('CREATE INDEX idx_dashboard_layouts_dashboard_id ON hpbrain_dashboard_layouts (dashboard_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_dashboard_layouts');
    }
};
