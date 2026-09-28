<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * The hpbrain_ai_* tables of the AI & Intelligence console, on the suite's
 * in-memory SQLite connection.
 *
 * A hand-maintained model of database/migrations/2026_09_25_100001..100003 —
 * those migrations are raw MySQL DDL and cannot run on SQLite (see
 * BuildsBrainSchema for the full argument). Every column below is copied from
 * them; keep the two in step.
 */
trait BuildsAiIntelligenceSchema
{
    protected function buildAiIntelligenceSchema(): void
    {
        $stamps = function ($t): void {
            $t->dateTime('created_date')->nullable();
            $t->dateTime('updated_date')->nullable();
        };

        Schema::create('hpbrain_ai_api_keys', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('account_email', 191)->nullable();
            $t->string('api_type', 191)->nullable();
            $t->string('ai_module', 64)->nullable();
            $t->string('model', 120)->nullable();
            $t->text('api_key');
            $t->string('api_limit', 191)->nullable();
            $t->integer('status')->default(1);
            $t->string('created_by', 64)->nullable();
            $stamps($t);
        });

        Schema::create('hpbrain_ai_models', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('provider', 40);
            $t->string('model_id', 120);
            $t->string('label', 120);
            $t->unsignedInteger('max_output_tokens')->nullable();
            $t->decimal('input_cost_per_1k', 12, 6)->nullable();
            $t->decimal('output_cost_per_1k', 12, 6)->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->integer('status')->default(1);
            $stamps($t);
            $t->unique(['provider', 'model_id', 'tenant_id']);
        });

        Schema::create('hpbrain_ai_modules', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('module_key', 80);
            $t->string('label', 150);
            $t->string('domain', 40)->default('hpbrain');
            $t->text('description')->nullable();
            $t->string('icon', 60)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('status')->default(true);
            $stamps($t);
            $t->unique(['module_key', 'tenant_id']);
        });

        Schema::create('hpbrain_ai_templates', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('template_key', 120);
            $t->string('name', 200);
            $t->string('domain', 40)->default('hpbrain');
            $t->string('module_key', 80)->nullable();
            $t->string('kind', 20)->default('prompt');
            $t->string('category', 60)->nullable();
            $t->text('description')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 24)->default('draft');
            $t->longText('system_prompt')->nullable();
            $t->longText('user_prompt');
            $t->text('variables')->nullable();
            $t->text('output_schema')->nullable();
            $t->string('output_format', 24)->default('text');
            $t->longText('html_layout')->nullable();
            $t->string('data_source', 120)->nullable();
            $t->text('data_arguments')->nullable();
            $t->string('provider', 40)->nullable();
            $t->string('model', 120)->nullable();
            $t->decimal('temperature', 4, 2)->nullable();
            $t->unsignedInteger('max_tokens')->nullable();
            $t->text('safety_rules')->nullable();
            $t->boolean('allow_as_evidence')->default(false);
            $t->boolean('requires_review')->default(false);
            $t->string('created_by', 64)->nullable();
            $stamps($t);
            $t->unique(['template_key', 'version', 'tenant_id']);
        });

        Schema::create('hpbrain_ai_suggestions', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('module_key', 80);
            $t->string('capability', 24);
            $t->string('label', 200);
            $t->text('description')->nullable();
            $t->string('icon', 60)->nullable();
            $t->string('action_type', 40);
            $t->string('action_ref', 150)->nullable();
            $t->text('prompt')->nullable();
            $t->text('payload')->nullable();
            $t->boolean('requires_entity')->default(false);
            $t->text('allowed_roles')->nullable();
            $t->text('required_permissions')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('status')->default(true);
            $stamps($t);
        });

        Schema::create('hpbrain_ai_policies', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('name', 191);
            $t->text('description')->nullable();
            $t->string('policy_type', 80);
            $t->tinyInteger('status')->default(1);
            $t->tinyInteger('require_disclosure')->default(0);
            $t->tinyInteger('require_acknowledgement')->default(0);
            $t->tinyInteger('ai_detection_required')->default(0);
            $t->tinyInteger('plagiarism_check_required')->default(0);
            $t->string('detection_provider', 120)->nullable();
            $t->decimal('detection_threshold', 5, 2)->nullable();
            $t->string('created_by', 64)->nullable();
            $t->string('updated_by', 64)->nullable();
            $stamps($t);
        });

        Schema::create('hpbrain_ai_policy_rules', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('policy_id', 36);
            $t->string('rule_key', 120);
            $t->longText('rule_value')->nullable();
            $stamps($t);
            $t->unique(['policy_id', 'rule_key']);
        });

        Schema::create('hpbrain_ai_policy_assignments', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('policy_id', 36);
            $t->string('scope_type', 40);
            $t->string('scope_id', 64)->nullable();
            $t->tinyInteger('status')->default(1);
            $t->string('created_by', 64)->nullable();
            $stamps($t);
        });

        Schema::create('hpbrain_ai_audit_logs', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('request_id', 64)->nullable();
            $t->string('event_type', 80);
            $t->string('actor_type', 24)->default('system');
            $t->string('actor_id', 64)->nullable();
            $t->string('actor_label', 150)->nullable();
            $t->string('subject_entity_key', 100)->nullable();
            $t->string('subject_id', 64)->nullable();
            $t->string('related_type', 80)->nullable();
            $t->string('related_id', 64)->nullable();
            $t->string('outcome', 24)->nullable();
            $t->text('message')->nullable();
            $t->longText('payload')->nullable();
            $stamps($t);
        });

        Schema::create('hpbrain_ai_conversations', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('session_key', 64);
            $t->string('title', 200)->nullable();
            $t->string('module_key', 80)->nullable();
            $t->unsignedInteger('turn_count')->default(0);
            $t->string('status', 24)->default('active');
            $t->dateTime('last_turn_at')->nullable();
            $t->string('user_id', 64)->nullable();
            $stamps($t);
            $t->unique(['session_key', 'tenant_id']);
        });

        Schema::create('hpbrain_ai_conversation_turns', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('conversation_id', 36);
            $t->unsignedInteger('turn_index')->default(0);
            $t->string('role', 16);
            $t->longText('content');
            $t->string('provider', 40)->nullable();
            $t->string('model', 120)->nullable();
            $t->unsignedInteger('input_tokens')->nullable();
            $t->unsignedInteger('output_tokens')->nullable();
            $t->unsignedInteger('latency_ms')->nullable();
            $t->string('finish_reason', 40)->nullable();
            $t->text('error')->nullable();
            $stamps($t);
        });

        Schema::create('hpbrain_ai_eval_runs', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->string('template_key', 120)->nullable();
            $t->unsignedInteger('template_version')->nullable();
            $t->string('module_key', 80)->nullable();
            $t->string('provider', 40)->nullable();
            $t->string('model', 120)->nullable();
            $t->string('status', 24)->default('draft');
            $t->unsignedInteger('case_count')->default(0);
            $t->unsignedInteger('passed_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->decimal('score', 5, 4)->nullable();
            $t->unsignedInteger('total_input_tokens')->default(0);
            $t->unsignedInteger('total_output_tokens')->default(0);
            $t->unsignedInteger('duration_ms')->nullable();
            $t->text('error')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('finished_at')->nullable();
            $t->string('created_by', 64)->nullable();
            $stamps($t);
        });

        Schema::create('hpbrain_ai_eval_cases', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('evaluation_id', 36);
            $t->string('label', 200);
            $t->text('variables')->nullable();
            $t->text('expect_contains')->nullable();
            $t->text('expect_absent')->nullable();
            $t->longText('output')->nullable();
            $t->decimal('score', 5, 4)->nullable();
            $t->boolean('passed')->nullable();
            $t->text('verdict')->nullable();
            $t->unsignedInteger('input_tokens')->nullable();
            $t->unsignedInteger('output_tokens')->nullable();
            $t->unsignedInteger('latency_ms')->nullable();
            $t->text('error')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $stamps($t);
        });

        Schema::create('hpbrain_ai_usage_events', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('ai_module', 64);
            $t->string('provider', 40);
            $t->string('model', 120)->nullable();
            $t->string('source', 24)->nullable();
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->unsignedInteger('latency_ms')->nullable();
            $t->decimal('estimated_cost_usd', 12, 6)->nullable();
            $t->string('outcome', 16)->default('success');
            $t->string('finish_reason', 40)->nullable();
            $t->text('error')->nullable();
            $t->string('related_type', 80)->nullable();
            $t->string('related_id', 64)->nullable();
            $t->string('user_id', 64)->nullable();
            $stamps($t);
        });

        Schema::create('hpbrain_ai_usage_quotas', function ($t) use ($stamps) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('ai_module', 64)->nullable();
            $t->string('period', 16)->default('month');
            $t->unsignedBigInteger('token_limit');
            $t->unsignedTinyInteger('warn_at_percent')->default(80);
            $t->boolean('status')->default(true);
            $t->string('created_by', 64)->nullable();
            $stamps($t);
        });

        // The platform catalogues, from the real seed migration (portable inserts).
        (require base_path('database/migrations/2026_09_25_100004_seed_hpbrain_ai_catalogues.php'))->up();
    }
}
