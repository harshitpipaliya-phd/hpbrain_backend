<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Builds the hpbrain_ tables the intelligence loop touches, on the in-memory
 * SQLite connection the suite is pinned to (phpunit.xml).
 *
 * WHY THIS EXISTS INSTEAD OF RefreshDatabase. RefreshDatabase runs the real
 * migrations, and they cannot execute on SQLite — the very first one fails on
 *
 *     SQLSTATE[HY000]: General error: 1 near "=": syntax error
 *     (CREATE TABLE hpbrain_organizations (... ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ...)
 *
 * because every migration in this project is raw MySQL DDL held deliberately as
 * DB::unprepared() strings (see the header of any 2026_01_01_* migration for
 * why), and several also issue `SHOW INDEX FROM`, which SQLite has no notion of.
 *
 * The trade this makes is real and worth stating plainly: these definitions are
 * a hand-maintained model of production, so a column added to a migration and
 * not added here is invisible to the suite until something fails. That has
 * already happened twice — `retry_count` and `causation_id` were missing from
 * earlier fixtures and only surfaced when the event publisher started writing
 * them. Every column below is copied from its migration, and where the live
 * MySQL table and the migration disagree the LIVE table wins, because that is
 * what production actually has.
 */
trait BuildsBrainSchema
{
    protected function buildBrainSchema(): void
    {
        // ---- Event backbone (2026_01_01_000500_events) ----------------------
        Schema::create('hpbrain_event_store', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('type');
            $t->string('tenant_id', 36);
            $t->string('entity_type');
            $t->string('entity_id', 36);
            $t->string('actor_id', 36);
            $t->text('payload');
            $t->text('metadata')->nullable();
            $t->string('correlation_id', 36)->nullable();
            $t->string('causation_id', 36)->nullable();
            $t->string('idempotency_key', 36)->nullable()->unique();
            $t->string('status')->default('pending');
            $t->integer('retry_count')->default(0);
            $t->timestamp('last_retry_at')->nullable();
            $t->text('failure_reason')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamp('completed_at')->nullable();
        });

        Schema::create('hpbrain_dead_letter_queue', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('event_id', 36);
            $t->string('consumer_name');
            $t->text('error_message');
            $t->text('error_stack')->nullable();
            $t->integer('retry_count')->default(0);
            $t->integer('max_retries')->default(3);
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('hpbrain_consumer_state', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('consumer_name')->unique();
            $t->string('last_processed_event_id', 36)->nullable();
            $t->timestamp('last_processed_at')->nullable();
            $t->text('status')->default('active');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        // ---- Audit (2026_01_01_000100_audit) --------------------------------
        Schema::create('hpbrain_audit_logs', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('entity_type');
            $t->string('entity_id', 36);
            $t->text('action');
            $t->string('actor_id', 36);
            $t->text('actor_name');
            $t->text('changes')->nullable();
            $t->text('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        // ---- Identity (2026_01_01_002800_auth_users) ------------------------
        Schema::create('hpbrain_auth_users', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('email');
            $t->string('name');
            $t->string('role')->default('member');
            $t->text('password_hash');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        // ---- Loop entities --------------------------------------------------
        Schema::create('hpbrain_signals', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            // The business identity of an INGESTED row — see
            // 2026_08_19_000100_signal_dedupe_key. Nullable and uniquely indexed
            // WITH tenant_id: rule-raised and hand-entered signals make no claim
            // to identity and stay null, and two organizations that number a
            // record identically stay two rows.
            $t->char('dedupe_key', 64)->nullable();
            $t->unique(['tenant_id', 'dedupe_key'], 'signals_dedupe_unique');
            $t->string('org_id', 36)->nullable();
            $t->string('department_id', 36)->nullable();
            $t->text('source');
            $t->text('classification')->default('unclassified');
            // Which rule raised the signal. Nullable: signals from reasoning
            // rather than from a rule have none, and re-detection must never
            // treat two of those as the same problem.
            $t->string('rule_key', 100)->nullable();
            $t->text('priority')->default('normal');
            $t->text('severity')->default('low');
            $t->decimal('confidence', 6, 4)->nullable();
            $t->text('related_entity_type')->nullable();
            $t->string('related_entity_id', 36)->nullable();
            $t->string('status')->default('new');
            $t->text('metadata')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_evidence', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('signal_id', 36)->nullable();
            $t->text('source');
            $t->string('evidence_type', 36)->default('observation');
            $t->text('content');
            $t->text('provenance');
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->text('hash');
            $t->integer('version')->default(1);
            $t->text('status')->default('active');
            $t->timestamp('observed_date')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_cases', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('signal_id', 36)->nullable();
            $t->text('title');
            $t->text('description')->nullable();
            $t->string('status')->default('open');
            $t->string('resolved_hypothesis_id', 36)->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_case_evidence', function ($t) {
            $t->string('tenant_id', 36);
            $t->string('case_id', 36);
            $t->string('evidence_id', 36);
            $t->timestamp('linked_date')->nullable();
            $t->primary(['case_id', 'evidence_id']);
        });

        // The junction that lets a case carry more than the one signal
        // hpbrain_cases.signal_id can hold. Mirrored here rather than left out
        // because a local replica that has drifted from the real table fails in
        // exactly one suite and nowhere else — which is how the missing
        // org_id/related_entity_* columns in HomeMetricsTest were found.
        Schema::create('hpbrain_case_signals', function ($t) {
            $t->string('tenant_id', 36);
            $t->string('case_id', 36);
            $t->string('signal_id', 36);
            $t->string('role', 32)->default('primary');
            $t->string('linked_by', 191);
            $t->timestamp('linked_date')->nullable();
            $t->primary(['case_id', 'signal_id']);
        });

        Schema::create('hpbrain_hypotheses', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('case_id', 36);
            $t->text('statement');
            $t->text('root_cause_family');
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->text('status')->default('proposed');
            $t->text('supporting_evidence_ids')->default('[]');
            $t->text('rejected_reason')->nullable();
            $t->text('proposed_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_mental_models', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('name');
            $t->text('description')->nullable();
            $t->text('domain');
            $t->text('rules')->nullable();
            $t->integer('version')->default(1);
            $t->text('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_reasoning_steps', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('case_id', 36)->nullable();
            $t->string('signal_id', 36)->nullable();
            $t->string('mental_model_id', 36)->nullable();
            $t->integer('step_order')->default(1);
            $t->text('description');
            $t->decimal('confidence_score', 6, 4)->default(0.5);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_recommendations', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('reasoning_step_id', 36)->nullable();
            $t->text('category')->default('watch');
            $t->text('title');
            $t->text('description')->nullable();
            $t->text('priority')->default('medium');
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->text('impact')->nullable();
            $t->text('cost')->nullable();
            $t->text('risk')->nullable();
            $t->text('dependencies')->default('[]');
            $t->text('status')->default('pending');
            // Added by 2026_07_30_000100. Nullable: watch and investigate
            // categories legitimately name no ESO.
            $t->string('eso_id', 36)->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        // ---- ESO library ----------------------------------------------------
        Schema::create('hpbrain_eso_definitions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('eso_code');
            $t->string('name');
        });

        // Invariant 4 (2026_07_30_000100). An ESO run is refused without one.
        Schema::create('hpbrain_measurement_plans', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('decision_id', 36);
            $t->text('baseline_metric');
            $t->decimal('baseline_value', 18, 4)->nullable();
            $t->decimal('target_value', 18, 4)->nullable();
            $t->string('metric_unit', 50)->nullable();
            $t->integer('measurement_window_days')->default(14);
            $t->string('owner_id', 36)->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        // Includes the approval columns from 2026_07_29_000100_decision_approval,
        // and status defaulting to 'proposed' rather than 'approved'.
        Schema::create('hpbrain_decisions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('recommendation_id', 36)->nullable();
            $t->string('decided_by', 36);
            $t->text('executor_type')->default('human');
            $t->text('rationale');
            $t->text('alternatives_considered')->default('[]');
            $t->string('status')->default('proposed');
            $t->string('approved_by', 36)->nullable();
            $t->timestamp('approved_date')->nullable();
            $t->text('approval_note')->nullable();
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->timestamp('created_date')->nullable();
        });

        // The REAL columns, not the ones the controller used to write: eso_id
        // and executed_by are NOT NULL, and there is no measurement_plan.
        Schema::create('hpbrain_eso_executions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('eso_id', 36);
            $t->string('decision_id', 36)->nullable();
            $t->text('status')->default('queued');
            $t->text('rollback_reason')->nullable();
            $t->text('executed_by');
            $t->text('executor_type')->default('human');
            $t->text('input');
            $t->text('output')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('started_date')->nullable();
            $t->timestamp('completed_date')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->string('eso_definition_id', 36)->nullable();
        });

        Schema::create('hpbrain_outcomes', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('decision_id', 36)->nullable();
            $t->text('result')->default('pending');
            $t->text('metrics');
            $t->text('kpis');
            $t->text('evidence_ids');
            $t->text('feedback')->nullable();
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        // Includes `domain` from 2026_07_29_000200_learning_domain.
        Schema::create('hpbrain_learnings', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('outcome_id', 36)->nullable();
            $t->string('mental_model_id', 36)->nullable();
            $t->text('pattern');
            $t->text('description')->nullable();
            $t->string('domain', 64)->nullable();
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->boolean('reusable')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        // ---- Universal Platform Foundation (Prompt 3.1) ----------------------
        Schema::create('hpbrain_industries', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('code');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('icon')->nullable();
            $t->integer('sort_order')->default(0);
            $t->string('status')->default('active');
            $t->text('settings')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_organization_configs', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36);
            $t->string('config_key');
            $t->text('config_value')->nullable();
            $t->string('config_type')->default('scalar');
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_terminology', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('industry_code');
            $t->string('entity_type');
            $t->string('display_name');
            $t->string('plural_name')->nullable();
            $t->text('description')->nullable();
            $t->string('icon')->nullable();
            $t->integer('sort_order')->default(0);
            $t->string('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_entity_mappings', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('source_system');
            $t->string('source_entity');
            $t->string('source_field');
            $t->string('universal_entity');
            $t->string('universal_field');
            $t->string('mapping_type')->default('direct');
            $t->text('transform_expression')->nullable();
            $t->string('lookup_table')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
            // Mirrors 2026_08_03_000100_entity_mappings_field_unique_key. The
            // original migration keyed on (tenant_id, source_system,
            // source_entity), which allowed exactly one mapped field per entity
            // and so made Person unmappable. This fixture declared no unique
            // index at all, which is why the suite could not have caught it.
            $t->unique(['tenant_id', 'universal_entity', 'universal_field'],
                'entity_mappings_tenant_universal_field_unique');
        });

        Schema::create('hpbrain_signal_rules', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('industry_code')->default('*');
            $t->string('rule_key');
            $t->string('universal_entity');
            $t->text('predicate');
            $t->string('join_entity')->nullable();
            $t->text('join_predicate')->nullable();
            $t->string('classification');
            $t->string('severity');
            $t->string('priority');
            // DECIMAL(6,4), never NUMERIC: MySQL would make an unqualified
            // NUMERIC a DECIMAL(10,0) and round every confidence to an integer.
            $t->decimal('confidence', 6, 4);
            $t->text('evidence_fields');
            $t->text('recommended_action');
            $t->string('root_cause_family')->nullable();
            $t->decimal('hypothesis_confidence', 6, 4)->nullable();
            $t->string('owner_role')->nullable();
            $t->string('threshold_op')->nullable();
            $t->decimal('threshold_value', 18, 4)->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->unique(['tenant_id', 'rule_key'], 'signal_rules_tenant_rule_key_unique');
        });

        Schema::create('hpbrain_feature_flags', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('flag_key');
            $t->string('flag_name');
            $t->text('description')->nullable();
            $t->boolean('enabled')->default(true);
            $t->string('level')->default('platform');
            $t->string('level_id')->nullable();
            $t->integer('rollout_percentage')->default(100);
            $t->text('rules')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_modules', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('module_key');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('version')->nullable();
            $t->string('category')->nullable();
            $t->boolean('is_core')->default(false);
            $t->boolean('is_enabled')->default(true);
            $t->text('dependencies')->nullable();
            $t->text('config_schema')->nullable();
            $t->integer('sort_order')->default(0);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_organization_modules', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36);
            $t->string('module_id', 36);
            $t->boolean('is_enabled')->default(true);
            $t->text('config')->nullable();
            $t->text('enabled_by')->nullable();
            $t->timestamp('enabled_date')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_navigation_items', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('industry_code');
            $t->string('role_key');
            $t->string('item_key');
            $t->string('label');
            $t->string('icon')->nullable();
            $t->string('route')->nullable();
            $t->string('parent_id', 36)->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_visible')->default(true);
            $t->string('required_permission')->nullable();
            $t->string('required_flag')->nullable();
            $t->string('required_module')->nullable();
            $t->text('children')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_dashboards', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('dashboard_key');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('industry_code')->nullable();
            $t->string('role_key')->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('is_system')->default(false);
            $t->text('layout')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_dashboard_widgets', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('widget_key');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('category')->nullable();
            $t->string('component_type');
            $t->text('config_schema')->nullable();
            $t->text('default_config')->nullable();
            $t->string('icon')->nullable();
            $t->boolean('is_system')->default(false);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_dashboard_layouts', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('dashboard_id', 36);
            $t->string('layout_type')->default('grid');
            $t->integer('grid_columns')->default(12);
            $t->integer('grid_rows')->default(12);
            $t->text('widgets')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_branding', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36);
            $t->string('name')->nullable();
            $t->text('logo_url')->nullable();
            $t->text('favicon_url')->nullable();
            $t->string('primary_color')->nullable();
            $t->string('secondary_color')->nullable();
            $t->string('accent_color')->nullable();
            $t->string('font_family')->nullable();
            $t->text('login_background_url')->nullable();
            $t->text('email_header_url')->nullable();
            $t->text('custom_css')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_themes', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('theme_key');
            $t->string('name');
            $t->text('description')->nullable();
            $t->text('colors')->nullable();
            $t->text('typography')->nullable();
            $t->text('spacing')->nullable();
            $t->text('borderRadius')->nullable();
            $t->text('shadows')->nullable();
            $t->boolean('is_dark')->default(false);
            $t->boolean('is_default')->default(false);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_forms', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36);
            $t->string('form_key');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('entity_type')->nullable();
            $t->text('fields')->nullable();
            $t->text('validation_rules')->nullable();
            $t->text('submit_action')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('version')->default(1);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_config_versions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36);
            $t->string('config_type');
            $t->string('config_key');
            $t->integer('version');
            $t->text('data')->nullable();
            $t->string('status')->default('draft');
            $t->text('activated_by')->nullable();
            $t->timestamp('activated_date')->nullable();
            $t->text('rolled_back_by')->nullable();
            $t->timestamp('rolled_back_date')->nullable();
            $t->text('change_summary')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_industry_templates', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('industry_code');
            $t->string('template_name');
            $t->text('description')->nullable();
            $t->text('terminology')->nullable();
            $t->text('modules')->nullable();
            $t->text('navigation')->nullable();
            $t->text('dashboards')->nullable();
            $t->text('branding')->nullable();
            $t->text('workflows')->nullable();
            // Per-industry assessment model (2026_08_03_000300). NULL means the
            // industry has not declared one and config/brain.php applies.
            $t->text('assessment_model')->nullable();
            $t->text('integrations')->nullable();
            $t->boolean('is_system')->default(false);
            $t->boolean('is_active')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_organization_types', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('type_key');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('icon')->nullable();
            $t->integer('sort_order')->default(0);
            $t->string('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_organization_units', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('unit_type')->default('department');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('code')->nullable();
            $t->string('parent_unit_id', 36)->nullable();
            $t->string('head_id', 36)->nullable();
            $t->string('location')->nullable();
            $t->string('cost_center')->nullable();
            $t->string('status')->default('active');
            $t->text('metadata')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_roles', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('role_key');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('category')->nullable();
            $t->text('permissions')->nullable();
            $t->boolean('is_system')->default(false);
            $t->string('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_positions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('unit_id', 36)->nullable();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('employment_type')->nullable();
            $t->boolean('is_vacant')->default(false);
            $t->string('reports_to_position_id', 36)->nullable();
            $t->text('metadata')->nullable();
            $t->string('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_skills', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('skill_key');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('category')->nullable();
            $t->string('level')->nullable();
            $t->text('metadata')->nullable();
            $t->string('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_competencies', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('competency_key');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('category')->nullable();
            $t->string('framework')->nullable();
            $t->text('level_descriptors')->nullable();
            $t->text('metadata')->nullable();
            $t->string('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_person_roles', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('person_id', 36);
            $t->string('role_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('unit_id', 36)->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->text('metadata')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_person_skills', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('person_id', 36);
            $t->string('skill_id', 36);
            $t->string('proficiency_level')->nullable();
            $t->integer('proficiency_score')->nullable();
            $t->string('assessed_by', 36)->nullable();
            $t->date('assessed_date')->nullable();
            $t->text('metadata')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_person_competencies', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('person_id', 36);
            $t->string('competency_id', 36);
            $t->string('current_level')->nullable();
            $t->string('target_level')->nullable();
            $t->date('assessed_date')->nullable();
            $t->text('metadata')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_location_types', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('type_key');
            $t->string('name');
            $t->text('description')->nullable();
            $t->text('metadata')->nullable();
            $t->string('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_locations', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('location_type_id', 36)->nullable();
            $t->string('name');
            $t->text('address')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->string('country')->nullable();
            $t->string('postal_code')->nullable();
            $t->string('timezone')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->text('metadata')->nullable();
            $t->boolean('is_headquarters')->default(false);
            $t->string('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_reporting_structures', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('reporter_person_id', 36);
            $t->string('reportee_person_id', 36);
            $t->string('reporting_type')->default('direct');
            $t->string('unit_id', 36)->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->text('metadata')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_onboarding_sessions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->integer('current_step')->default(1);
            $t->integer('total_steps')->default(12);
            $t->string('status')->default('draft');
            $t->text('data')->nullable();
            $t->text('completed_steps')->nullable();
            $t->string('started_by')->nullable();
            $t->string('completed_by')->nullable();
            $t->timestamp('activated_date')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_import_jobs', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('import_type')->nullable();
            $t->string('entity_type')->nullable();
            $t->string('status')->default('pending');
            $t->integer('total_rows')->default(0);
            $t->integer('processed_rows')->default(0);
            $t->integer('success_count')->default(0);
            $t->integer('error_count')->default(0);
            $t->integer('duplicate_count')->default(0);
            $t->text('error_report')->nullable();
            $t->text('rollback_data')->nullable();
            $t->string('started_by')->nullable();
            $t->timestamp('completed_date')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
            $t->string('source_id', 191)->nullable();
            $t->string('sync_type', 50)->nullable();
            $t->text('source_ref')->nullable();
        });

        Schema::create('hpbrain_import_logs', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('import_job_id', 36);
            $t->integer('row_number')->nullable();
            $t->string('action')->nullable();
            $t->string('entity_type')->nullable();
            $t->string('entity_id', 36)->nullable();
            $t->text('data')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_data_sources', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('source_key', 191);
            $t->string('source_type', 50);
            $t->string('display_name');
            $t->string('universal_entity', 100)->nullable();
            $t->text('config')->nullable();
            $t->text('field_map')->nullable();
            $t->string('checkpoint')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_readiness_checks', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('check_type')->nullable();
            $t->string('check_name')->nullable();
            $t->string('status')->default('pending');
            $t->text('message')->nullable();
            $t->text('metadata')->nullable();
            $t->timestamp('checked_date')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_template_overrides', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('template_type')->nullable();
            $t->string('template_key')->nullable();
            $t->string('override_level')->default('organization');
            $t->text('override_data')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        // ---- Universal AI Brain (Prompt 3.3) --------------------------------
        Schema::create('hpbrain_ai_providers', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('provider_name');
            $t->string('provider_type');
            $t->text('config')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('priority')->default(0);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_ai_fallback_chains', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('chain_name');
            $t->text('providers')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_ai_prompt_templates', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('prompt_key');
            $t->integer('version');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('purpose')->nullable();
            $t->text('system_prompt');
            $t->text('user_prompt_template');
            $t->text('response_schema')->nullable();
            $t->text('allowed_roles')->nullable();
            $t->text('data_sources')->nullable();
            $t->string('model_capability')->nullable();
            $t->text('generation_settings')->nullable();
            $t->string('safety_profile')->nullable();
            $t->string('status')->default('draft');
            $t->text('change_summary')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_ai_evaluations', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('evaluation_name');
            $t->string('evaluation_type');
            $t->text('dataset')->nullable();
            $t->text('results')->nullable();
            $t->string('model')->nullable();
            $t->string('status')->default('pending');
            $t->text('run_by')->nullable();
            $t->timestamp('run_date')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_ai_feedback', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('execution_id');
            $t->string('user_id');
            $t->string('rating');
            $t->text('feedback_text')->nullable();
            $t->string('feedback_type')->nullable();
            $t->text('metadata')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_ai_quotas', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('quota_type');
            $t->string('quota_key');
            $t->integer('limit_value');
            $t->integer('current_usage')->default(0);
            $t->string('reset_period')->default('monthly');
            $t->boolean('is_active')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_ai_safety_rules', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('rule_name');
            $t->string('rule_type');
            $t->text('pattern');
            $t->string('action')->default('block');
            $t->string('severity')->default('high');
            $t->boolean('is_active')->default(true);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        $this->buildCoreErpSchema();
    }

    /**
     * Tables that predate Part 3 but were never mirrored here.
     *
     * These are not new: every one has a 2026_01_01_* migration and exists in
     * the live MySQL database. They were simply absent from this trait, so any
     * service that touched them errored with "no such table" the moment a test
     * exercised it — which is exactly what the Part 3.3 AI services did, since
     * they ground answers against people, capabilities and policies rather than
     * against the loop tables the original fixture covered.
     *
     * Same rule as the rest of the file: columns copied from the migration,
     * live table wins on disagreement.
     */
    private function buildCoreErpSchema(): void
    {
        // ---- Person (2026_01_01_000300_person) ------------------------------
        Schema::create('hpbrain_people', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('employee_id', 36);
            $t->text('first_name');
            $t->text('last_name');
            $t->text('display_name')->nullable();
            $t->string('email');
            $t->text('phone')->nullable();
            $t->text('profile_photo')->nullable();
            $t->text('gender')->nullable();
            $t->date('date_of_birth')->nullable();
            $t->text('employment_type')->default('full_time');
            $t->text('employment_status')->default('active');
            $t->date('joining_date')->nullable();
            $t->string('department_id', 36)->nullable();
            $t->string('manager_id', 36)->nullable();
            $t->text('designation')->nullable();
            $t->text('location')->nullable();
            $t->string('reporting_manager_id', 36)->nullable();
            $t->string('org_id', 36);
            $t->string('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        // ---- Capability (2026_01_01_000400_capability) ----------------------
        Schema::create('hpbrain_capabilities', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36);
            $t->string('capability_code');
            $t->text('name');
            $t->text('description')->nullable();
            $t->string('category')->default('general');
            $t->text('capability_type')->default('competency');
            $t->text('difficulty')->default('intermediate');
            $t->text('criticality')->default('medium');
            $t->integer('version')->default(1);
            $t->string('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
            $t->text('knowledge')->nullable();
            $t->text('ability')->nullable();
            $t->text('skill')->nullable();
            $t->text('behaviour')->nullable();
            $t->text('attitude')->nullable();
        });

        Schema::create('hpbrain_capability_versions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('capability_id', 36);
            $t->string('tenant_id', 36);
            $t->integer('version');
            $t->text('name');
            $t->text('description')->nullable();
            $t->text('category')->nullable();
            $t->text('capability_type')->nullable();
            $t->text('difficulty')->nullable();
            $t->text('criticality')->nullable();
            $t->text('knowledge')->nullable();
            $t->text('ability')->nullable();
            $t->text('skill')->nullable();
            $t->text('behaviour')->nullable();
            $t->text('attitude')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_capability_assignments', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('capability_id', 36);
            $t->string('target_type');
            $t->string('target_id', 36);
            $t->text('assigned_by');
            $t->timestamp('assigned_date')->nullable();
            $t->text('status')->default('active');
        });

        Schema::create('hpbrain_capability_tasks', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('capability_id', 36);
            $t->string('parent_task_id', 36)->nullable();
            $t->text('name');
            $t->text('description')->nullable();
            $t->boolean('evidence_required')->default(false);
            $t->text('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_capability_proficiency', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('assignment_id', 36);
            $t->decimal('knowledge_level', 12, 4)->nullable();
            $t->decimal('ability_level', 12, 4)->nullable();
            $t->decimal('skill_level', 12, 4)->nullable();
            $t->decimal('behaviour_level', 12, 4)->nullable();
            $t->decimal('attitude_level', 12, 4)->nullable();
            $t->decimal('evidence_confidence', 6, 4)->nullable();
            $t->text('assessed_by')->nullable();
            $t->timestamp('assessed_date')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        // ---- Metric snapshots (2026_08_03_000400) ---------------------------
        Schema::create('hpbrain_metric_snapshots', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->date('snapshot_date');
            $t->string('metric_key');
            $t->string('dimension_key')->nullable();
            // DECIMAL, never NUMERIC — see the migration. Nullable because an
            // unmeasured metric is null, never zero.
            $t->decimal('value', 18, 4)->nullable();
            $t->decimal('confidence', 6, 4)->nullable();
            $t->integer('sample_n')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        // ---- Observability (2026_01_01_000600_observability) ----------------
        Schema::create('hpbrain_metrics', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36)->nullable();
            $t->string('metric_name');
            $t->decimal('metric_value', 18, 6);
            $t->text('tags')->nullable();
            $t->timestamp('recorded_at')->nullable();
        });

        // ---- Policies (000700_intelligence_entities + 003600 additions) -----
        Schema::create('hpbrain_policies', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('name');
            $t->text('scope');
            $t->text('allowed_executor_classes')->nullable();
            $t->text('trust_levels')->nullable();
            $t->text('routing_criteria')->nullable();
            $t->text('escalation_path')->nullable();
            $t->text('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
            // Added later by 2026_01_01_003600_policy_library_additions.
            $t->text('approval_gates')->nullable();
            $t->text('data_access_rules')->nullable();
            $t->text('regulatory_constraints')->nullable();
        });

        // ---- Executors (2026_01_01_001100_executors) ------------------------
        Schema::create('hpbrain_executors', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('executor_type');
            $t->text('name');
            $t->string('person_id', 36)->nullable();
            $t->text('capability_tags')->nullable();
            $t->decimal('trust_level', 5, 2)->default(0.5);
            $t->integer('max_concurrent')->default(1);
            $t->integer('current_workload')->default(0);
            $t->boolean('available')->default(true);
            $t->text('status')->default('active');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        // ---- Risks (2026_01_01_001200_decision_intelligence) ----------------
        Schema::create('hpbrain_risks', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('decision_id', 36)->nullable();
            $t->string('recommendation_id', 36)->nullable();
            $t->text('category');
            $t->decimal('probability', 6, 4)->default(0.5);
            $t->text('impact')->default('medium');
            $t->decimal('score', 10, 4)->default(0);
            $t->text('mitigation')->nullable();
            $t->text('status')->default('open');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        // ---- Conversation engine (2026_01_01_001600_conversation_engine) ----
        Schema::create('hpbrain_conversation_sessions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->text('title')->nullable();
            $t->text('context_type')->nullable();
            $t->string('context_entity_id', 36)->nullable();
            /*
              THIS FIXTURE ONCE CARRIED user_id AND is_pinned, AND NEITHER HAS
              EVER EXISTED IN THE DATABASE.

              The comment that stood here said both were present "so the fixture
              matches the live table, which carries the Part 3.3 columns". That
              was not true of any environment. The authoritative DDL is
              2026_01_01_001600_conversation_engine (created_by VARCHAR(255)
              NOT NULL) plus 2026_01_01_001700_conversation_pinning (pinned,
              deleted_date) — and 001600 goes as far as indexing
              (tenant_id, created_by) under the name
              idx_conversation_sessions_user, which settles which column means
              "the user". Confirmed against the live MariaDB table on
              2026-08-06: id, tenant_id, org_id, title, context_type,
              context_entity_id, created_by, created_date, updated_date,
              pinned, deleted_date.

              So the fixture had been shaped around AiWorkspaceService's bug
              instead of around the schema, and that is precisely why the suite
              stayed green while GET /v1/ai/workspace/sessions returned 500 in
              production (docs/API-FUNCTIONAL-AUDIT.md F2). A hand-built test
              schema only proves anything while it agrees with the migrations —
              the same trap 2026_08_01_000032_import_logs records in its own
              docblock.
            */
            $t->string('created_by')->nullable();
            $t->boolean('pinned')->default(false);
            $t->timestamp('deleted_date')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        Schema::create('hpbrain_conversation_messages', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('session_id', 36);
            $t->text('role');
            $t->text('content');
            $t->text('citations')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_prompt_templates', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('name');
            $t->text('template');
            $t->text('variables')->nullable();
            $t->integer('version')->default(1);
            $t->string('previous_version_id', 36)->nullable();
            $t->text('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            // Added later by 2026_01_01_001900_ai_governance.
            $t->text('category')->nullable();
            $t->text('default_model')->nullable();
            $t->decimal('default_temperature', 4, 2)->default(0.7);
        });

        // ---- Notifications & settings (2026_01_01_001800) -------------------
        Schema::create('hpbrain_notifications', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('user_id', 36);
            $t->text('type');
            $t->text('title');
            $t->text('body')->nullable();
            $t->text('entity_type')->nullable();
            $t->string('entity_id', 36)->nullable();
            $t->timestamp('read_date')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_settings', function ($t) {
            $t->string('tenant_id', 36);
            $t->string('user_id', 36)->default('_org_');
            $t->string('key');
            $t->text('value');
            $t->timestamp('updated_date')->nullable();
            $t->primary(['tenant_id', 'user_id', 'key']);
        });

        // ---- AI governance (2026_01_01_001900_ai_governance) ----------------
        // The execution ledger AiGateway writes on EVERY call, success or
        // failure. Without it here, any test that reaches the gateway errors
        // rather than asserting on the trace it is supposed to leave.
        Schema::create('hpbrain_ai_executions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('user_id', 36);
            $t->text('service_name');
            $t->string('prompt_template_id', 36)->nullable();
            $t->string('provider', 36);
            $t->text('model')->nullable();
            $t->text('status');
            $t->integer('input_tokens')->nullable();
            $t->integer('output_tokens')->nullable();
            $t->integer('latency_ms')->nullable();
            $t->decimal('estimated_cost_usd', 12, 4)->nullable();
            $t->text('error')->nullable();
            $t->text('entity_type')->nullable();
            $t->string('entity_id', 36)->nullable();
            $t->timestamp('created_date')->nullable();
        });

        // ---- Knowledge assets (2026_01_01_002000_knowledge_assets) ----------
        Schema::create('hpbrain_knowledge_assets', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('title');
            $t->string('category');
            $t->text('content');
            $t->text('tags')->nullable();
            $t->decimal('confidence', 6, 4)->default(0.7);
            $t->string('department_id', 36)->nullable();
            $t->text('related_person_ids')->nullable();
            $t->text('related_capability_ids')->nullable();
            $t->integer('reuse_count')->default(0);
            $t->text('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
        });

        // ---- Placement taxonomy (2026_01_01_002500_placement_taxonomy) ------
        Schema::create('hpbrain_job_role_capability_requirements', function ($t) {
            $t->string('tenant_id', 36);
            $t->string('job_role_id', 36);
            $t->string('capability_id', 36);
            $t->decimal('required_level', 5, 2)->default(3);
            $t->primary(['job_role_id', 'capability_id']);
        });

        // ---- Guardians (2026_01_01_002700_guardians) ------------------------
        Schema::create('hpbrain_guardians', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('student_person_id', 36);
            $t->text('first_name');
            $t->text('last_name');
            $t->text('relationship');
            $t->text('email')->nullable();
            $t->text('phone')->nullable();
            $t->boolean('is_primary_contact')->default(false);
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        // ---- Refresh tokens (2026_07_31_000100_refresh_tokens) --------------
        Schema::create('hpbrain_refresh_tokens', function ($t) {
            $t->string('jti')->primary();
            $t->string('tenant_id');
            $t->string('user_id');
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        // ---- Operational records (2026_08_04_000300_operational_records) -----
        // Mirrors the migration column for column. The UNIQUE below is not
        // decoration: idempotency of re-import is a behaviour the suite asserts,
        // and without the constraint here the test would pass on SQLite while
        // production relied on a constraint the test never exercised.
        Schema::create('hpbrain_operational_records', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('org_id', 36)->nullable();
            $t->string('dataset', 64);
            $t->string('natural_key', 191);
            $t->string('source_file')->nullable();
            $t->integer('source_row')->nullable();
            $t->dateTime('occurred_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->string('status', 64)->nullable();
            $t->string('category', 191)->nullable();
            $t->string('sub_category', 191)->nullable();
            $t->string('owner_name', 191)->nullable();
            $t->string('supervisor_name', 191)->nullable();
            $t->string('zone', 128)->nullable();
            $t->string('area', 128)->nullable();
            $t->string('subject_ref', 191)->nullable();
            $t->decimal('metric_value', 14, 4)->nullable();
            $t->string('metric_unit', 20)->nullable();
            $t->integer('quantity')->nullable();
            $t->text('payload')->nullable();
            $t->string('row_hash', 64);
            $t->string('import_job_id', 36)->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
            $t->unique(['tenant_id', 'dataset', 'natural_key'], 'operational_records_natural_key_unique');
        });

        /*
          hpbrain_students — the one-row-per-student projection.

          Mirrors 2026_08_18_000200_students plus 2026_08_18_000300's added
          columns. The UNIQUE on (tenant_id, student_ref) is load-bearing: it is
          what makes StudentProjectionBuilder's upsert idempotent, and a test
          schema without it would let a rebuild duplicate every student while the
          suite reported success.
        */
        Schema::create('hpbrain_students', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('student_ref', 191);
            $t->string('student_name');
            $t->string('standard', 191)->nullable();
            $t->string('division', 191)->nullable();
            $t->string('batch', 191)->nullable();
            $t->string('student_quota', 191)->nullable();
            $t->string('unique_id', 191)->nullable();
            $t->string('source_dataset', 64)->nullable();
            $t->string('academic_year', 64)->nullable();
            $t->boolean('in_academic')->default(false);
            $t->boolean('in_fees')->default(false);
            $t->string('academic_standard', 191)->nullable();
            $t->integer('academic_records')->default(0);
            $t->integer('fee_records')->default(0);
            $t->decimal('avg_percentage', 6, 2)->nullable();
            $t->decimal('total_obtained', 14, 2)->nullable();
            $t->decimal('total_marks', 14, 2)->nullable();
            $t->integer('subjects_count')->default(0);
            $t->string('first_academic_year', 8)->nullable();
            $t->string('last_academic_year', 8)->nullable();
            $t->decimal('total_paid', 14, 2)->nullable();
            $t->date('first_receipt_date')->nullable();
            $t->date('last_receipt_date')->nullable();
            $t->timestamp('projected_at')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
            $t->unique(['tenant_id', 'student_ref'], 'uq_student_tenant_ref');
        });

        Schema::create('hpbrain_operational_rule_metadata', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('rule_key', 191);
            $t->string('root_cause_family', 100)->nullable();
            $t->decimal('hypothesis_confidence', 6, 4)->nullable();
            $t->text('recommended_action')->nullable();
            $t->text('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->timestamp('updated_date')->nullable();
            $t->unique(['tenant_id', 'rule_key'], 'operational_rule_metadata_tenant_rule_unique');
        });

        // ---- Recommendation evidence (2026_08_07_000100_recommendation_evidence) --
        // The join Architecture Invariant 1 is enforced through: no recommendation
        // without a traceable path to its evidence. The intelligence engine reports
        // recommendations that have no row here as an invariant violation, so the
        // table has to exist for that check to mean anything in the suite.
        Schema::create('hpbrain_recommendation_evidence', function ($t) {
            $t->string('tenant_id', 36);
            $t->string('recommendation_id', 36);
            $t->string('evidence_id', 36);
            $t->timestamp('linked_date')->nullable();
            $t->primary(['tenant_id', 'recommendation_id', 'evidence_id'], 'recommendation_evidence_pk');
        });

        // ---- ESO efficacy (2026_07_2x eso tables) ----------------------------
        // An ESO's track record. The catalogue endpoint attaches these per
        // definition, because a definition with no efficacy record has no track
        // record — which is a different claim from having a poor one.
        Schema::create('hpbrain_eso_efficacy_records', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('eso_definition_id', 36);
            $t->string('gap_type', 100)->nullable();
            $t->string('population', 191)->nullable();
            $t->decimal('efficacy_score', 6, 4)->nullable();
            $t->integer('sample_size')->nullable();
            $t->timestamp('computed_date')->nullable();
            $t->string('created_by')->nullable();
            $t->timestamp('created_date')->nullable();
        });
    }
}
