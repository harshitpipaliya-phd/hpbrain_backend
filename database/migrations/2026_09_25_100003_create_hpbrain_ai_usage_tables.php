<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usage metering and token quotas for AI & Intelligence.
 *
 * Mirrors G2G's ai_usage_events / ai_usage_quotas (hp_erp 2026_09_21_150000).
 * hpbrain_ai_usage_quotas rather than hpbrain_ai_quotas, which already exists for
 * the older /ai/quotas screens. Every model call made through AiModelClient writes
 * one hpbrain_ai_usage_events row — success, failure or quota refusal.
 */
return new class extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        if (! Schema::hasTable('hpbrain_ai_usage_events')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_usage_events (
  id                  VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id           VARCHAR(36) NOT NULL,
  ai_module           VARCHAR(64) NOT NULL,
  provider            VARCHAR(40) NOT NULL,
  model               VARCHAR(120) NULL,
  source              VARCHAR(24) NULL,
  input_tokens        INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens       INT UNSIGNED NOT NULL DEFAULT 0,
  latency_ms          INT UNSIGNED NULL,
  estimated_cost_usd  DECIMAL(12,6) NULL,
  outcome             VARCHAR(16) NOT NULL DEFAULT \'success\',
  finish_reason       VARCHAR(40) NULL,
  error               TEXT NULL,
  related_type        VARCHAR(80) NULL,
  related_id          VARCHAR(64) NULL,
  user_id             VARCHAR(64) NULL,
  created_date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hb_ai_usage_tenant_time (tenant_id, created_date),
  KEY idx_hb_ai_usage_tenant_module_time (tenant_id, ai_module, created_date)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_usage_quotas')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_usage_quotas (
  id               VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id        VARCHAR(36) NOT NULL,
  ai_module        VARCHAR(64) NULL,
  period           VARCHAR(16) NOT NULL DEFAULT \'month\',
  token_limit      BIGINT UNSIGNED NOT NULL,
  warn_at_percent  TINYINT UNSIGNED NOT NULL DEFAULT 80,
  status           TINYINT(1) NOT NULL DEFAULT 1,
  created_by       VARCHAR(64) NULL,
  created_date     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hb_ai_usage_quotas_scope (tenant_id, ai_module, period)
) ' . self::TABLE_OPTIONS);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_ai_usage_quotas');
        Schema::dropIfExists('hpbrain_ai_usage_events');
    }
};
