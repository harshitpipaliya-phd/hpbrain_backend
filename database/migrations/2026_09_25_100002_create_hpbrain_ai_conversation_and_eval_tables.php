<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assistant transcripts and AI Evaluation runs for AI & Intelligence.
 *
 * Mirrors G2G's ai_conversations / ai_conversation_turns / ai_evaluations /
 * ai_evaluation_cases (hp_erp 2026_09_21_140000) with HP Brain's conventions.
 * The evaluation tables are hpbrain_ai_eval_runs / hpbrain_ai_eval_cases because
 * hpbrain_ai_evaluations already exists and belongs to the older /ai screens.
 *
 * (session_key, tenant_id) is unique so a client-supplied session key can never
 * reach another tenant's conversation.
 */
return new class extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        if (! Schema::hasTable('hpbrain_ai_conversations')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_conversations (
  id            VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id     VARCHAR(36) NOT NULL,
  session_key   VARCHAR(64) NOT NULL,
  title         VARCHAR(200) NULL,
  module_key    VARCHAR(80) NULL,
  turn_count    INT UNSIGNED NOT NULL DEFAULT 0,
  status        VARCHAR(24) NOT NULL DEFAULT \'active\',
  last_turn_at  DATETIME NULL,
  user_id       VARCHAR(64) NULL,
  created_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hb_ai_conv_session_tenant (session_key, tenant_id),
  KEY idx_hb_ai_conv_tenant_recent (tenant_id, last_turn_at)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_conversation_turns')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_conversation_turns (
  id               VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id        VARCHAR(36) NOT NULL,
  conversation_id  VARCHAR(36) NOT NULL,
  turn_index       INT UNSIGNED NOT NULL DEFAULT 0,
  role             VARCHAR(16) NOT NULL,
  content          LONGTEXT NOT NULL,
  provider         VARCHAR(40) NULL,
  model            VARCHAR(120) NULL,
  input_tokens     INT UNSIGNED NULL,
  output_tokens    INT UNSIGNED NULL,
  latency_ms       INT UNSIGNED NULL,
  finish_reason    VARCHAR(40) NULL,
  error            TEXT NULL,
  created_date     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hb_ai_turns_conversation (tenant_id, conversation_id, turn_index)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_eval_runs')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_eval_runs (
  id                   VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id            VARCHAR(36) NOT NULL,
  name                 VARCHAR(200) NOT NULL,
  description          TEXT NULL,
  template_key         VARCHAR(120) NULL,
  template_version     INT UNSIGNED NULL,
  module_key           VARCHAR(80) NULL,
  provider             VARCHAR(40) NULL,
  model                VARCHAR(120) NULL,
  status               VARCHAR(24) NOT NULL DEFAULT \'draft\',
  case_count           INT UNSIGNED NOT NULL DEFAULT 0,
  passed_count         INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count         INT UNSIGNED NOT NULL DEFAULT 0,
  score                DECIMAL(5,4) NULL,
  total_input_tokens   INT UNSIGNED NOT NULL DEFAULT 0,
  total_output_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms          INT UNSIGNED NULL,
  error                TEXT NULL,
  started_at           DATETIME NULL,
  finished_at          DATETIME NULL,
  created_by           VARCHAR(64) NULL,
  created_date         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hb_ai_eval_runs_tenant (tenant_id, created_date),
  KEY idx_hb_ai_eval_runs_template (tenant_id, template_key)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_eval_cases')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_eval_cases (
  id               VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id        VARCHAR(36) NOT NULL,
  evaluation_id    VARCHAR(36) NOT NULL,
  label            VARCHAR(200) NOT NULL,
  variables        JSON NULL,
  expect_contains  JSON NULL,
  expect_absent    JSON NULL,
  output           LONGTEXT NULL,
  score            DECIMAL(5,4) NULL,
  passed           TINYINT(1) NULL,
  verdict          TEXT NULL,
  input_tokens     INT UNSIGNED NULL,
  output_tokens    INT UNSIGNED NULL,
  latency_ms       INT UNSIGNED NULL,
  error            TEXT NULL,
  sort_order       INT UNSIGNED NOT NULL DEFAULT 0,
  created_date     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hb_ai_eval_cases_run (tenant_id, evaluation_id, sort_order)
) ' . self::TABLE_OPTIONS);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_ai_eval_cases');
        Schema::dropIfExists('hpbrain_ai_eval_runs');
        Schema::dropIfExists('hpbrain_ai_conversation_turns');
        Schema::dropIfExists('hpbrain_ai_conversations');
    }
};
