<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_locations (
  id              VARCHAR(36) PRIMARY KEY,
  tenant_id       VARCHAR(36) NOT NULL,
  org_id          VARCHAR(36),
  location_type_id VARCHAR(36),
  name            VARCHAR(255) NOT NULL,
  address         TEXT,
  city            VARCHAR(100),
  state           VARCHAR(100),
  country         VARCHAR(100),
  postal_code     VARCHAR(20),
  timezone        VARCHAR(50),
  phone           VARCHAR(50),
  email           VARCHAR(255),
  metadata        JSON,
  is_headquarters BOOLEAN DEFAULT FALSE,
  status          VARCHAR(50) NOT NULL DEFAULT \'active\',
  created_by      TEXT NOT NULL,
  created_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_locations_tenant_id ON hpbrain_locations (tenant_id)');
        DB::unprepared('CREATE INDEX idx_locations_org_id ON hpbrain_locations (org_id)');
        DB::unprepared('CREATE INDEX idx_locations_is_headquarters ON hpbrain_locations (is_headquarters)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_locations');
    }
};
