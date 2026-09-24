<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hpbrain_students')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_students (
  id                  VARCHAR(36) PRIMARY KEY,
  tenant_id           VARCHAR(36) NOT NULL,
  student_ref         VARCHAR(191) NOT NULL,
  student_name        VARCHAR(255) NOT NULL,
  standard            VARCHAR(191) NULL,
  division            VARCHAR(191) NULL,
  batch               VARCHAR(191) NULL,
  student_quota       VARCHAR(191) NULL,
  unique_id           VARCHAR(191) NULL,
  source_dataset      VARCHAR(64) NULL,
  academic_year       VARCHAR(64) NULL,
  created_date        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_students_tenant_ref (tenant_id, student_ref),
  INDEX idx_students_tenant_standard (tenant_id, standard),
  UNIQUE KEY uq_student_tenant_ref (tenant_id, student_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_students');
    }
};
