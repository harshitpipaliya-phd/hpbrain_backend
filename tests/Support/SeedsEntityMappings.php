<?php

declare(strict_types=1);

namespace Tests\Support;

use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\Schema;

/**
 * Installs the entity-mapping table and the institute ERP's mappings into a test
 * that builds its own schema.
 *
 * For the fixtures that predate BuildsBrainSchema and stand up only the handful
 * of tables they need. Since Phase 2 those tests exercise code paths that
 * resolve their source instead of naming it, so the mappings are now part of a
 * working fixture rather than an optional extra — a test without them is a
 * tenant without them, and the resolver fails closed for both.
 */
trait SeedsEntityMappings
{
    /** @param array<int, string> $tenantIds tenants to map */
    protected function installEntityMappings(array $tenantIds): void
    {
        if (! Schema::hasTable('hpbrain_entity_mappings')) {
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
                $t->unique(['tenant_id', 'universal_entity', 'universal_field'],
                    'entity_mappings_tenant_universal_field_unique');
            });
        }

        // Tenants are named explicitly rather than read from the organization
        // register, because these fixtures do not all build one.
        (new EntityMappingSeeder($tenantIds))->run();
    }

    /**
     * The five shipped signal rules, as rows.
     *
     * Since Phase 3 the rules ARE data, so a fixture that expects signals has
     * to seed them. A fixture without rule rows generates nothing — correctly,
     * and for the same reason a tenant without them would.
     */
    protected function installSignalRules(): void
    {
        if (! Schema::hasTable('hpbrain_signal_rules')) {
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
                $t->decimal('confidence', 6, 4);
                $t->text('evidence_fields');
                $t->text('recommended_action');
                $t->string('owner_role')->nullable();
                $t->string('threshold_op')->nullable();
                $t->decimal('threshold_value', 18, 4)->nullable();
                $t->boolean('is_active')->default(true);
                $t->text('created_by');
                $t->timestamp('created_date')->nullable();
                $t->unique(['tenant_id', 'rule_key'], 'signal_rules_tenant_rule_key_unique');
            });
        }

        (new \Database\Seeders\SignalRuleSeeder())->run();
    }
}
