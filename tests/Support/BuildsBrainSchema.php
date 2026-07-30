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
            $t->string('org_id', 36)->nullable();
            $t->string('department_id', 36)->nullable();
            $t->text('source');
            $t->text('classification')->default('unclassified');
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
    }
}
