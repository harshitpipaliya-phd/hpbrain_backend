<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Signals\RuleEvaluator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The five shipped signal rules, as rows.
 *
 * Every classification, severity, priority, confidence and evidence key below is
 * copied from the private method it replaces in the old SignalGenerator, so the
 * signals and evidence these produce are identical to the ones that class
 * produced. `SignalRuleParityTest` is what holds that to account.
 *
 * industry_code '*' — these are data-quality rules about people and units, which
 * every industry has. An industry-specific rule ships with its own code and a
 * tenant-specific one is written under that tenant's id, where it overrides a
 * shared rule of the same rule_key.
 */
final class SignalRuleSeeder extends Seeder
{
    /**
     * recommended_action doubles as the evidence `issue` string, which is why
     * these read as descriptions of the defect rather than as instructions. That
     * is what the previous implementation wrote into evidence, and changing the
     * wording would change stored rows.
     */
    private const RULES = [
        [
            'rule_key'           => 'people_without_department',
            'universal_entity'   => 'Person',
            'predicate'          => ['all' => [
                ['field' => 'deletedAt', 'op' => 'is_null'],
                ['field' => 'status', 'op' => 'eq', 'value' => 1],
                ['any' => [
                    ['field' => 'unit', 'op' => 'is_null'],
                    ['field' => 'unit', 'op' => 'eq', 'value' => 0],
                ]],
            ]],
            'classification'     => 'workforce',
            'severity'           => 'medium',
            'priority'           => 'medium',
            'confidence'         => 1.0,
            'evidence_fields'    => [
                'employeeNo' => 'externalRef',
                'name'       => ['concat' => ['firstName', 'lastName'], 'separator' => ' '],
                'email'      => 'email',
            ],
            'recommended_action' => 'department_id is null or zero',
            'owner_role'         => 'hr',
        ],
        [
            'rule_key'         => 'departments_without_manager',
            'universal_entity' => 'OrganizationUnit',
            'predicate'        => ['all' => [
                ['field' => 'deletedAt', 'op' => 'is_null'],
                ['field' => 'status', 'op' => 'eq', 'value' => 1],
                ['any' => [
                    ['field' => 'parent', 'op' => 'is_null'],
                    ['field' => 'parent', 'op' => 'eq', 'value' => 0],
                ]],
            ]],
            'classification'   => 'leadership',
            'severity'         => 'medium',
            'priority'         => 'medium',
            'confidence'       => 1.0,
            'evidence_fields'  => ['name' => 'name'],
            // NAME AND PREDICATE DISAGREE, and the row is the honest place to
            // say so. This ERP's unit table has no manager column, so what the
            // predicate finds is ROOT units. Carried forward verbatim because
            // Phase 3's gate is byte-identical signals. Now that the rule is a
            // row, correcting it is an UPDATE with its own gate rather than a
            // deploy — which is the entire point of this phase.
            'recommended_action' => 'parent_id is null or zero — no manager assigned',
            'owner_role'         => 'hr',
        ],
        [
            'rule_key'         => 'people_without_profile',
            'universal_entity' => 'Person',
            'predicate'        => ['all' => [
                ['field' => 'deletedAt', 'op' => 'is_null'],
                ['field' => 'status', 'op' => 'eq', 'value' => 1],
                ['any' => [
                    ['field' => 'profile', 'op' => 'is_null'],
                    ['field' => 'profile', 'op' => 'eq', 'value' => 0],
                ]],
            ]],
            'classification'   => 'access_control',
            'severity'         => 'low',
            'priority'         => 'low',
            'confidence'       => 1.0,
            'evidence_fields'  => [
                'employeeNo' => 'externalRef',
                'name'       => ['concat' => ['firstName', 'lastName'], 'separator' => ' '],
                'email'      => 'email',
            ],
            'recommended_action' => 'user_profile_id is null or zero',
            'owner_role'         => 'admin',
        ],
        [
            'rule_key'         => 'people_without_email',
            'universal_entity' => 'Person',
            'predicate'        => ['all' => [
                ['field' => 'deletedAt', 'op' => 'is_null'],
                ['field' => 'status', 'op' => 'eq', 'value' => 1],
                ['any' => [
                    ['field' => 'email', 'op' => 'is_null'],
                    ['field' => 'email', 'op' => 'eq', 'value' => ''],
                ]],
            ]],
            'classification'   => 'data_quality',
            'severity'         => 'high',
            'priority'         => 'high',
            'confidence'       => 1.0,
            // No 'email' key: the column is empty by definition here, and a
            // field that always says nothing is noise. The previous rule omitted
            // it for the same reason.
            'evidence_fields'  => [
                'employeeNo' => 'externalRef',
                'name'       => ['concat' => ['firstName', 'lastName'], 'separator' => ' '],
            ],
            'recommended_action' => 'email is null or empty',
            'owner_role'         => 'hr',
        ],
        [
            'rule_key'         => 'inactive_users_in_active_departments',
            'universal_entity' => 'Person',
            // The one rule that WANTS soft-deleted rows: it looks for people
            // who are gone but still attached to a live unit.
            'predicate'        => ['all' => [
                ['field' => 'deletedAt', 'op' => 'is_not_null'],
                ['field' => 'status', 'op' => 'neq', 'value' => 1],
            ]],
            'join_entity'      => 'OrganizationUnit',
            'join_predicate'   => ['all' => [
                ['field' => 'status', 'op' => 'eq', 'value' => 1],
            ]],
            'classification'   => 'data_quality',
            'severity'         => 'low',
            'priority'         => 'low',
            // 0.8, not 1.0 — this one infers from a join rather than reading a
            // field, and the confidence scale reserves 1.0 for direct checks.
            'confidence'       => 0.8,
            'evidence_fields'  => [
                'employeeNo' => 'externalRef',
                'name'       => ['concat' => ['firstName', 'lastName'], 'separator' => ' '],
                'email'      => 'email',
                'department' => ['join' => 'name'],
            ],
            'recommended_action' => 'user is inactive/deleted but assigned to active department',
            'owner_role'         => 'hr',
        ],
    ];

    public function run(): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $written = 0;

        foreach (self::RULES as $rule) {
            $values = [
                'industry_code'      => $rule['industry_code'] ?? '*',
                'universal_entity'   => $rule['universal_entity'],
                'predicate'          => json_encode($rule['predicate']),
                'join_entity'        => $rule['join_entity'] ?? null,
                'join_predicate'     => isset($rule['join_predicate']) ? json_encode($rule['join_predicate']) : null,
                'classification'     => $rule['classification'],
                'severity'           => $rule['severity'],
                'priority'           => $rule['priority'],
                'confidence'         => $rule['confidence'],
                'evidence_fields'    => json_encode($rule['evidence_fields']),
                'recommended_action' => $rule['recommended_action'],
                'owner_role'         => $rule['owner_role'] ?? null,
                'threshold_op'       => $rule['threshold_op'] ?? null,
                'threshold_value'    => $rule['threshold_value'] ?? null,
                'is_active'          => 1,
            ];

            $existing = DB::table('hpbrain_signal_rules')
                ->where('tenant_id', RuleEvaluator::PLATFORM_TENANT)
                ->where('rule_key', $rule['rule_key'])
                ->value('id');

            if ($existing !== null) {
                DB::table('hpbrain_signal_rules')->where('id', $existing)->update($values);
            } else {
                DB::table('hpbrain_signal_rules')->insert($values + [
                    'id'           => Uuid::uuid4()->toString(),
                    'tenant_id'    => RuleEvaluator::PLATFORM_TENANT,
                    'rule_key'     => $rule['rule_key'],
                    'created_by'   => 'system',
                    'created_date' => $now,
                ]);
            }

            $written++;
        }

        $this->command?->info("SignalRuleSeeder: {$written} rules written or refreshed.");
    }
}
