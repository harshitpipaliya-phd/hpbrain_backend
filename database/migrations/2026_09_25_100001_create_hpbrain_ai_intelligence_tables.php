<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The configuration half of AI & Intelligence (`/api/v1/ai-intelligence/*`):
 * provider credentials, the model catalogue, HP Brain's product areas, templates
 * and their module bindings, policies, and the AI audit trail.
 *
 * Column shapes mirror G2G's ai_* tables (hp_erp 2026_09_19_120000) with HP Brain's
 * conventions: hpbrain_ prefix, `tenant_id VARCHAR(36)` on every table (`'*'` =
 * platform row visible to every tenant), UUID VARCHAR(36) ids generated in PHP,
 * `created_date` / `updated_date` DATETIME. These are HP Brain's OWN tables — G2G's
 * ai_* tables live in the same physical database and are never read or written.
 *
 * Names chosen to avoid the existing hpbrain_ai_* tables the older /ai screens use
 * (providers, prompt_templates, evaluations, quotas, executions, feedback,
 * safety_rules, fallback_chains): none of those is touched.
 *
 * hpbrain_ai_api_keys.api_key holds Crypt::encryptString() ciphertext, never a
 * plaintext credential (G2G stored plaintext).
 *
 * Raw DDL, guarded on each table's absence, so a partial run is resumable.
 */
return new class extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        if (! Schema::hasTable('hpbrain_ai_api_keys')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_api_keys (
  id               VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id        VARCHAR(36) NOT NULL,
  account_email    VARCHAR(191) NULL,
  api_type         VARCHAR(191) NULL,
  ai_module        VARCHAR(64) NULL,
  model            VARCHAR(120) NULL,
  api_key          MEDIUMTEXT NOT NULL,
  api_limit        VARCHAR(191) NULL,
  status           INT NOT NULL DEFAULT 1,
  created_by       VARCHAR(64) NULL,
  created_date     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hb_ai_keys_tenant (tenant_id),
  KEY idx_hb_ai_keys_type (tenant_id, api_type, status),
  KEY idx_hb_ai_keys_module (tenant_id, ai_module, status)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_models')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_models (
  id                  VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id           VARCHAR(36) NOT NULL,
  provider            VARCHAR(40) NOT NULL,
  model_id            VARCHAR(120) NOT NULL,
  label               VARCHAR(120) NOT NULL,
  max_output_tokens   INT UNSIGNED NULL,
  input_cost_per_1k   DECIMAL(12,6) NULL,
  output_cost_per_1k  DECIMAL(12,6) NULL,
  sort_order          INT UNSIGNED NOT NULL DEFAULT 0,
  status              INT NOT NULL DEFAULT 1,
  created_date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hb_ai_models_scope (provider, model_id, tenant_id),
  KEY idx_hb_ai_models_tenant (tenant_id, provider, status)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_modules')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_modules (
  id            VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id     VARCHAR(36) NOT NULL,
  module_key    VARCHAR(80) NOT NULL,
  label         VARCHAR(150) NOT NULL,
  domain        VARCHAR(40) NOT NULL DEFAULT \'hpbrain\',
  description   TEXT NULL,
  icon          VARCHAR(60) NULL,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status        TINYINT(1) NOT NULL DEFAULT 1,
  created_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hb_ai_modules_key_tenant (module_key, tenant_id),
  KEY idx_hb_ai_modules_tenant (tenant_id, status)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_templates')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_templates (
  id                 VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id          VARCHAR(36) NOT NULL,
  template_key       VARCHAR(120) NOT NULL,
  name               VARCHAR(200) NOT NULL,
  domain             VARCHAR(40) NOT NULL DEFAULT \'hpbrain\',
  module_key         VARCHAR(80) NULL,
  kind               VARCHAR(20) NOT NULL DEFAULT \'prompt\',
  category           VARCHAR(60) NULL,
  description        TEXT NULL,
  version            INT UNSIGNED NOT NULL DEFAULT 1,
  status             VARCHAR(24) NOT NULL DEFAULT \'draft\',
  system_prompt      LONGTEXT NULL,
  user_prompt        LONGTEXT NOT NULL,
  variables          JSON NULL,
  output_schema      JSON NULL,
  output_format      VARCHAR(24) NOT NULL DEFAULT \'text\',
  html_layout        LONGTEXT NULL,
  data_source        VARCHAR(120) NULL,
  data_arguments     JSON NULL,
  provider           VARCHAR(40) NULL,
  model              VARCHAR(120) NULL,
  temperature        DECIMAL(4,2) NULL,
  max_tokens         INT UNSIGNED NULL,
  safety_rules       JSON NULL,
  allow_as_evidence  TINYINT(1) NOT NULL DEFAULT 0,
  requires_review    TINYINT(1) NOT NULL DEFAULT 0,
  created_by         VARCHAR(64) NULL,
  created_date       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hb_ai_templates_key_ver_tenant (template_key, version, tenant_id),
  KEY idx_hb_ai_templates_tenant (tenant_id, module_key, status)
) ' . self::TABLE_OPTIONS);
        }

        // The binding that offers a published template in its module (G2G ai_suggestions).
        if (! Schema::hasTable('hpbrain_ai_suggestions')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_suggestions (
  id                    VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id             VARCHAR(36) NOT NULL,
  module_key            VARCHAR(80) NOT NULL,
  capability            VARCHAR(24) NOT NULL,
  label                 VARCHAR(200) NOT NULL,
  description           TEXT NULL,
  icon                  VARCHAR(60) NULL,
  action_type           VARCHAR(40) NOT NULL,
  action_ref            VARCHAR(150) NULL,
  prompt                TEXT NULL,
  payload               JSON NULL,
  requires_entity       TINYINT(1) NOT NULL DEFAULT 0,
  allowed_roles         JSON NULL,
  required_permissions  JSON NULL,
  sort_order            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status                TINYINT(1) NOT NULL DEFAULT 1,
  created_date          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hb_ai_suggestions_tenant (tenant_id, module_key, capability),
  KEY idx_hb_ai_suggestions_ref (tenant_id, action_ref)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_policies')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_policies (
  id                         VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id                  VARCHAR(36) NOT NULL,
  name                       VARCHAR(191) NOT NULL,
  description                TEXT NULL,
  policy_type                VARCHAR(80) NOT NULL,
  status                     TINYINT NOT NULL DEFAULT 1,
  require_disclosure         TINYINT NOT NULL DEFAULT 0,
  require_acknowledgement    TINYINT NOT NULL DEFAULT 0,
  ai_detection_required      TINYINT NOT NULL DEFAULT 0,
  plagiarism_check_required  TINYINT NOT NULL DEFAULT 0,
  detection_provider         VARCHAR(120) NULL,
  detection_threshold        DECIMAL(5,2) NULL,
  created_by                 VARCHAR(64) NULL,
  updated_by                 VARCHAR(64) NULL,
  created_date               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hb_ai_policies_tenant (tenant_id, status)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_policy_rules')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_policy_rules (
  id            VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id     VARCHAR(36) NOT NULL,
  policy_id     VARCHAR(36) NOT NULL,
  rule_key      VARCHAR(120) NOT NULL,
  rule_value    LONGTEXT NULL,
  created_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hb_ai_policy_rules (policy_id, rule_key),
  KEY idx_hb_ai_policy_rules_tenant (tenant_id, policy_id)
) ' . self::TABLE_OPTIONS);
        }

        // scope_id is a string: module and position ids are UUIDs, department ids
        // come from the ERP and may be numeric.
        if (! Schema::hasTable('hpbrain_ai_policy_assignments')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_policy_assignments (
  id            VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id     VARCHAR(36) NOT NULL,
  policy_id     VARCHAR(36) NOT NULL,
  scope_type    VARCHAR(40) NOT NULL,
  scope_id      VARCHAR(64) NULL,
  status        TINYINT NOT NULL DEFAULT 1,
  created_by    VARCHAR(64) NULL,
  created_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hb_ai_policy_assign_policy (tenant_id, policy_id),
  KEY idx_hb_ai_policy_assign_scope (tenant_id, scope_type, scope_id)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_audit_logs')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_audit_logs (
  id                  VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id           VARCHAR(36) NOT NULL,
  request_id          VARCHAR(64) NULL,
  event_type          VARCHAR(80) NOT NULL,
  actor_type          VARCHAR(24) NOT NULL DEFAULT \'system\',
  actor_id            VARCHAR(64) NULL,
  actor_label         VARCHAR(150) NULL,
  subject_entity_key  VARCHAR(100) NULL,
  subject_id          VARCHAR(64) NULL,
  related_type        VARCHAR(80) NULL,
  related_id          VARCHAR(64) NULL,
  outcome             VARCHAR(24) NULL,
  message             TEXT NULL,
  payload             LONGTEXT NULL,
  created_date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hb_ai_audit_tenant_time (tenant_id, created_date),
  KEY idx_hb_ai_audit_event (tenant_id, event_type, created_date),
  KEY idx_hb_ai_audit_related (tenant_id, related_type, related_id)
) ' . self::TABLE_OPTIONS);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_ai_audit_logs');
        Schema::dropIfExists('hpbrain_ai_policy_assignments');
        Schema::dropIfExists('hpbrain_ai_policy_rules');
        Schema::dropIfExists('hpbrain_ai_policies');
        Schema::dropIfExists('hpbrain_ai_suggestions');
        Schema::dropIfExists('hpbrain_ai_templates');
        Schema::dropIfExists('hpbrain_ai_modules');
        Schema::dropIfExists('hpbrain_ai_models');
        Schema::dropIfExists('hpbrain_ai_api_keys');
    }
};
