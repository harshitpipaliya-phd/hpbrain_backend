<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The decentralised, per-module "AI Stack" — what each HP Brain area's own AI tab
 * (Usage & Cost, Guardrails, Activity, Models, Templates → Build report, Automations)
 * needs beyond the central AI & Intelligence console's tables.
 *
 * Ported from G2G (hp_erp 2026_09_25_100000_ai_stack_module_scoping,
 * 2026_09_25_110000_ai_stack_reports, and the agentic_agents / agentic_agent_runs
 * shape the Automations tab used there) with HP Brain's conventions: hpbrain_
 * prefix, `tenant_id VARCHAR(36)` on every table ('*' = platform row), UUID
 * VARCHAR(36) ids generated in PHP, `created_date` / `updated_date` DATETIME.
 * Nothing here reads or writes G2G's ai_* / agentic_* tables.
 *
 *   hpbrain_ai_modules (+capabilities, +registry_keys)
 *       `capabilities`  JSON flag map (conversational / generative / agent /
 *                       workflow / ontology). The Guardrails and Models tabs read it:
 *                       Models offers a choice only for a capability the area uses.
 *       `registry_keys` JSON list of AiModuleRegistry keys — the AI consumers behind
 *                       the area. Usage events and credentials are keyed by
 *                       consumer, so this is the join between the two vocabularies.
 *
 *   hpbrain_ai_module_model_bindings
 *       product module × capability → provider / model / credential, per tenant.
 *       AiConfigurationResolver consults it ("step 0") ONLY when a caller names the
 *       product module, so every existing caller resolves exactly as before.
 *
 *   hpbrain_ai_generated_reports
 *       Saved documents a module's "Build report" produced (G2G ai_generated_reports;
 *       no client_id — HP Brain has no client tier above the tenant).
 *
 *   hpbrain_ai_tool_agents / hpbrain_ai_tool_agent_runs
 *       Automations-tab agents. HP Brain has no agentic_agents store, so these are
 *       their own tables. An agent may run one of its own module's read-only data
 *       sources and nothing else; there is no model call.
 *
 * Raw DDL, each step guarded, so a partial run is resumable.
 */
return new class extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        if (Schema::hasTable('hpbrain_ai_modules')) {
            if (! Schema::hasColumn('hpbrain_ai_modules', 'capabilities')) {
                DB::unprepared('ALTER TABLE hpbrain_ai_modules ADD COLUMN capabilities TEXT NULL AFTER description');
            }

            if (! Schema::hasColumn('hpbrain_ai_modules', 'registry_keys')) {
                DB::unprepared('ALTER TABLE hpbrain_ai_modules ADD COLUMN registry_keys TEXT NULL AFTER capabilities');
            }
        }

        if (! Schema::hasTable('hpbrain_ai_module_model_bindings')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_module_model_bindings (
  id                 VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id          VARCHAR(36) NOT NULL,
  product_module     VARCHAR(80) NOT NULL,
  capability         VARCHAR(100) NOT NULL,
  provider           VARCHAR(60) NULL,
  model              VARCHAR(190) NULL,
  api_key_id         VARCHAR(36) NULL,
  max_output_tokens  INT UNSIGNED NULL,
  status             TINYINT NOT NULL DEFAULT 1,
  created_by         VARCHAR(64) NULL,
  updated_by         VARCHAR(64) NULL,
  created_date       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hb_ai_mmb_scope (product_module, capability, tenant_id),
  KEY idx_hb_ai_mmb_tenant (tenant_id, status)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_generated_reports')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_generated_reports (
  id                  VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id           VARCHAR(36) NOT NULL,
  module_key          VARCHAR(80) NOT NULL,
  layout_template_id  VARCHAR(36) NULL,
  title               VARCHAR(250) NOT NULL,
  html_content        LONGTEXT NOT NULL,
  question            TEXT NULL,
  source_tool         VARCHAR(120) NULL,
  arguments           LONGTEXT NULL,
  row_count           INT UNSIGNED NOT NULL DEFAULT 0,
  status              TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_by          VARCHAR(64) NULL,
  created_date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hb_ai_gr_tenant_created (tenant_id, created_date),
  KEY idx_hb_ai_gr_tenant_module (tenant_id, module_key),
  KEY idx_hb_ai_gr_layout (layout_template_id)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_tool_agents')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_tool_agents (
  id             VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id      VARCHAR(36) NOT NULL,
  name           VARCHAR(191) NOT NULL,
  description    TEXT NULL,
  module         VARCHAR(80) NOT NULL,
  tools_allowed  JSON NULL,
  instructions   TEXT NULL,
  status         VARCHAR(16) NOT NULL DEFAULT \'draft\',
  created_by     VARCHAR(64) NULL,
  updated_by     VARCHAR(64) NULL,
  created_date   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hb_ai_ta_tenant_module (tenant_id, module, status)
) ' . self::TABLE_OPTIONS);
        }

        if (! Schema::hasTable('hpbrain_ai_tool_agent_runs')) {
            DB::unprepared('CREATE TABLE IF NOT EXISTS hpbrain_ai_tool_agent_runs (
  id             VARCHAR(36) NOT NULL PRIMARY KEY,
  tenant_id      VARCHAR(36) NOT NULL,
  agent_id       VARCHAR(36) NOT NULL,
  module         VARCHAR(80) NOT NULL,
  tool           VARCHAR(120) NULL,
  status         VARCHAR(16) NOT NULL,
  `trigger`      VARCHAR(24) NOT NULL DEFAULT \'manual\',
  input          JSON NULL,
  output         JSON NULL,
  row_count      INT UNSIGNED NOT NULL DEFAULT 0,
  error_message  TEXT NULL,
  duration_ms    INT UNSIGNED NOT NULL DEFAULT 0,
  started_at     DATETIME NULL,
  completed_at   DATETIME NULL,
  created_by     VARCHAR(64) NULL,
  created_date   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hb_ai_tar_tenant_agent (tenant_id, agent_id, created_date),
  KEY idx_hb_ai_tar_tenant_module (tenant_id, module, created_date)
) ' . self::TABLE_OPTIONS);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_ai_tool_agent_runs');
        Schema::dropIfExists('hpbrain_ai_tool_agents');
        Schema::dropIfExists('hpbrain_ai_generated_reports');
        Schema::dropIfExists('hpbrain_ai_module_model_bindings');

        if (Schema::hasTable('hpbrain_ai_modules')) {
            foreach (['registry_keys', 'capabilities'] as $column) {
                if (Schema::hasColumn('hpbrain_ai_modules', $column)) {
                    DB::unprepared("ALTER TABLE hpbrain_ai_modules DROP COLUMN {$column}");
                }
            }
        }
    }
};
