<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_person_skills (
  id                  VARCHAR(36) PRIMARY KEY,
  tenant_id           VARCHAR(36) NOT NULL,
  person_id           VARCHAR(36) NOT NULL,
  skill_id            VARCHAR(36) NOT NULL,
  proficiency_level   VARCHAR(50),
  proficiency_score   INT,
  assessed_by         VARCHAR(36),
  assessed_date       DATE,
  metadata            JSON,
  created_by          TEXT NOT NULL,
  created_date        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT person_skills_tenant_person_skill_unique UNIQUE (tenant_id, person_id, skill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE INDEX idx_person_skills_tenant_id ON hpbrain_person_skills (tenant_id)');
        DB::unprepared('CREATE INDEX idx_person_skills_person_id ON hpbrain_person_skills (person_id)');
        DB::unprepared('CREATE INDEX idx_person_skills_proficiency_level ON hpbrain_person_skills (proficiency_level)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_person_skills');
    }
};
