<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Signals\RuleEvaluator;
use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Database\Seeders\SignalRuleSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * Phase 3's gate: rules held as rows produce the same signals the hand-written
 * methods produced.
 *
 * The expected values are NOT read back from the seeder — that would only prove
 * the seeder agrees with itself. They are transcribed from the five private
 * methods on the old SignalGenerator, which git still holds at 214a18b:
 * classification, severity, priority and confidence per rule, the evidence keys
 * each one wrote, and the `issue` strings verbatim.
 */
final class SignalRuleParityTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;

    private const TENANT = '4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->seedErpFixture();
        (new EntityMappingSeeder())->run();
        (new SignalRuleSeeder())->run();

        // One soft-deleted, inactive person still attached to an active unit —
        // the only fixture row the fifth rule can fire on.
        DB::table('tbluser')->insert([
            'id' => 9, 'sub_institute_id' => 4, 'employee_no' => 'E9',
            'first_name' => 'Gone', 'last_name' => 'Away', 'email' => 'gone@x.test',
            'department_id' => 2, 'user_profile_id' => 1, 'status' => 0,
            'deleted_at' => '2026-02-01 00:00:00',
        ]);
    }

    private function evaluate(): array
    {
        return app(RuleEvaluator::class)->evaluate(self::TENANT);
    }

    /** @return array<string, object> rule key => signal row */
    private function signalsByRule(): array
    {
        $out = [];

        foreach (DB::table('hpbrain_signals')->where('tenant_id', self::TENANT)->get() as $row) {
            $out[json_decode($row->metadata, true)['rule']] = $row;
        }

        return $out;
    }

    /** @test */
    public function all_five_rules_fire_on_the_fixture(): void
    {
        $result = $this->evaluate();

        $this->assertSame(5, $result['created']);
        $this->assertSame(0, $result['skipped']);

        $keys = array_keys($this->signalsByRule());
        sort($keys);

        $this->assertSame([
            'departments_without_manager',
            'inactive_users_in_active_departments',
            'people_without_department',
            'people_without_email',
            'people_without_profile',
        ], $keys);
    }

    /**
     * @test
     *
     * Classification, severity, priority and confidence per rule, transcribed
     * from the methods these rows replaced.
     */
    public function each_signal_carries_the_attributes_the_old_method_set(): void
    {
        $this->evaluate();
        $signals = $this->signalsByRule();

        $expected = [
            'people_without_department'  => ['workforce', 'medium', 'medium', 1.0],
            'departments_without_manager' => ['leadership', 'medium', 'medium', 1.0],
            'people_without_profile'     => ['access_control', 'low', 'low', 1.0],
            'people_without_email'       => ['data_quality', 'high', 'high', 1.0],
            'inactive_users_in_active_departments' => ['data_quality', 'low', 'low', 0.8],
        ];

        foreach ($expected as $rule => [$classification, $severity, $priority, $confidence]) {
            $this->assertArrayHasKey($rule, $signals, "Rule {$rule} did not fire.");
            $signal = $signals[$rule];

            $this->assertSame($classification, $signal->classification, $rule);
            $this->assertSame($severity, $signal->severity, $rule);
            $this->assertSame($priority, $signal->priority, $rule);
            $this->assertEquals($confidence, (float) $signal->confidence, $rule);
            $this->assertSame('erp.data_quality', $signal->source, $rule);
            $this->assertSame('new', $signal->status, $rule);
            $this->assertSame('system', $signal->created_by, $rule);
        }
    }

    /**
     * @test
     *
     * The confidence that must NOT be rounded. DECIMAL(6,4) is why this holds;
     * an unqualified NUMERIC would store 0.8 as 1.
     */
    public function join_based_confidence_survives_the_round_trip(): void
    {
        $this->evaluate();

        $confidence = (float) $this->signalsByRule()['inactive_users_in_active_departments']->confidence;

        $this->assertEqualsWithDelta(0.8, $confidence, 0.0001);
        $this->assertNotSame(1.0, $confidence, 'A rounded confidence would read as certainty.');
    }

    /** @test */
    public function affected_counts_match_the_fixture(): void
    {
        $this->evaluate();
        $signals = $this->signalsByRule();

        $count = fn (string $rule) => json_decode($signals[$rule]->metadata, true)['affectedCount'];

        // Person 3 has department_id 0.
        $this->assertSame(1, $count('people_without_department'));
        // Person 4 has user_profile_id 0.
        $this->assertSame(1, $count('people_without_profile'));
        // Person 5 has an empty email.
        $this->assertSame(1, $count('people_without_email'));
        // Department 1 is the only root.
        $this->assertSame(1, $count('departments_without_manager'));
        // Person 9 only.
        $this->assertSame(1, $count('inactive_users_in_active_departments'));
    }

    /** @test */
    public function evidence_payloads_keep_the_keys_and_issue_strings_they_had(): void
    {
        $this->evaluate();

        $evidence = DB::table('hpbrain_evidence')->where('tenant_id', self::TENANT)->get()
            ->map(fn ($e) => json_decode($e->content, true))
            ->keyBy('issue');

        // people_without_department — employeeNo, name (concatenated), email.
        $dept = $evidence['department_id is null or zero'];
        $this->assertSame('erp.tbluser', $dept['source']);
        $this->assertSame('3', $dept['recordId']);
        $this->assertSame('E3', $dept['employeeNo']);
        $this->assertSame('Chen Wu', $dept['name']);
        $this->assertSame('chen@x.test', $dept['email']);

        // people_without_email — no `email` key, because it is empty by
        // definition and the old rule omitted it too.
        $noEmail = $evidence['email is null or empty'];
        $this->assertSame('E5', $noEmail['employeeNo']);
        $this->assertSame('Eve Silva', $noEmail['name']);
        $this->assertArrayNotHasKey('email', $noEmail);

        // departments_without_manager — name only, and the issue string carries
        // the ERP column name exactly as before.
        $noManager = $evidence['parent_id is null or zero — no manager assigned'];
        $this->assertSame('erp.hrms_departments', $noManager['source']);
        $this->assertSame('Nursing', $noManager['name']);

        // The joined rule reaches the unit's name through the join.
        $inactive = $evidence['user is inactive/deleted but assigned to active department'];
        $this->assertSame('Gone Away', $inactive['name']);
        $this->assertSame('Surgery', $inactive['department']);
    }

    /** @test */
    public function evidence_is_hashed_and_provenanced_as_before(): void
    {
        $this->evaluate();

        $row = DB::table('hpbrain_evidence')->where('tenant_id', self::TENANT)->first();

        $this->assertSame('observation', $row->evidence_type);
        $this->assertSame('active', $row->status);
        $this->assertEquals(1.0, (float) $row->confidence);
        $this->assertSame(
            hash('sha256', $row->content.'|'.$row->provenance),
            $row->hash,
            'The hash covers content and provenance, joined by a pipe.',
        );
    }

    /** @test */
    public function every_signal_publishes_an_observation_event(): void
    {
        $this->evaluate();

        $this->assertSame(
            5,
            DB::table('hpbrain_event_store')->where('type', 'ObservationMade')->count(),
        );
    }

    // ---- what being data buys ------------------------------------------

    /** @test */
    public function a_new_rule_is_addable_by_insert_alone(): void
    {
        // The claim Phase 3 exists to make good on. No class, no deploy — one
        // row, and a sixth signal appears.
        DB::table('hpbrain_signal_rules')->insert([
            'id'                 => 'rule-no-phone',
            'tenant_id'          => RuleEvaluator::PLATFORM_TENANT,
            'rule_key'           => 'people_without_phone',
            'industry_code'      => '*',
            'universal_entity'   => 'Person',
            'predicate'          => json_encode(['all' => [
                ['field' => 'deletedAt', 'op' => 'is_null'],
                ['field' => 'status', 'op' => 'eq', 'value' => 1],
                ['any' => [
                    ['field' => 'phone', 'op' => 'is_null'],
                    ['field' => 'phone', 'op' => 'eq', 'value' => ''],
                ]],
            ]]),
            'classification'     => 'data_quality',
            'severity'           => 'low',
            'priority'           => 'low',
            'confidence'         => 0.9,
            'evidence_fields'    => json_encode(['employeeNo' => 'externalRef']),
            'recommended_action' => 'no contact number on record',
            'is_active'          => 1,
            'created_by'         => 'test',
            'created_date'       => '2026-08-04 00:00:00',
        ]);

        $this->assertSame(6, $this->evaluate()['created']);

        $signal = $this->signalsByRule()['people_without_phone'];
        $this->assertSame('data_quality', $signal->classification);
        $this->assertEqualsWithDelta(0.9, (float) $signal->confidence, 0.0001);
        // All five fixture people have a null mobile.
        $this->assertSame(5, json_decode($signal->metadata, true)['affectedCount']);
    }

    /** @test */
    public function a_tenant_rule_overrides_a_platform_rule_of_the_same_key(): void
    {
        DB::table('hpbrain_signal_rules')->insert([
            'id'                 => 'rule-override',
            'tenant_id'          => self::TENANT,
            'rule_key'           => 'people_without_email',
            'industry_code'      => '*',
            'universal_entity'   => 'Person',
            'predicate'          => json_encode(['all' => [
                ['field' => 'deletedAt', 'op' => 'is_null'],
                ['field' => 'email', 'op' => 'eq', 'value' => ''],
            ]]),
            'classification'     => 'data_quality',
            'severity'           => 'critical',
            'priority'           => 'high',
            'confidence'         => 1.0,
            'evidence_fields'    => json_encode(['employeeNo' => 'externalRef']),
            'recommended_action' => 'escalated locally',
            'is_active'          => 1,
            'created_by'         => 'test',
            'created_date'       => '2026-08-04 00:00:00',
        ]);

        $this->evaluate();

        // Still five signals — the override replaces, it does not add.
        $this->assertCount(5, $this->signalsByRule());
        $this->assertSame('critical', $this->signalsByRule()['people_without_email']->severity);
    }

    /** @test */
    public function an_inactive_rule_does_not_run(): void
    {
        DB::table('hpbrain_signal_rules')
            ->where('rule_key', 'people_without_email')->update(['is_active' => 0]);

        $this->evaluate();

        $this->assertArrayNotHasKey('people_without_email', $this->signalsByRule());
    }

    /** @test */
    public function an_industry_scoped_rule_only_runs_for_that_industry(): void
    {
        // The fixture organization's industry_type is 'Healthcare'.
        foreach ([['r-health', 'Healthcare'], ['r-mfg', 'manufacturing']] as [$id, $industry]) {
            DB::table('hpbrain_signal_rules')->insert([
                'id'                 => $id,
                'tenant_id'          => RuleEvaluator::PLATFORM_TENANT,
                'rule_key'           => 'scoped_'.$id,
                'industry_code'      => $industry,
                'universal_entity'   => 'Person',
                'predicate'          => json_encode(['all' => [['field' => 'status', 'op' => 'eq', 'value' => 1]]]),
                'classification'     => 'data_quality',
                'severity'           => 'low',
                'priority'           => 'low',
                'confidence'         => 0.5,
                'evidence_fields'    => json_encode(['employeeNo' => 'externalRef']),
                'recommended_action' => 'industry scoped probe',
                'is_active'          => 1,
                'created_by'         => 'test',
                'created_date'       => '2026-08-04 00:00:00',
            ]);
        }

        $this->evaluate();
        $fired = $this->signalsByRule();

        $this->assertArrayHasKey('scoped_r-health', $fired);
        $this->assertArrayNotHasKey('scoped_r-mfg', $fired);
    }

    /** @test */
    public function a_threshold_suppresses_a_rule_below_its_count(): void
    {
        DB::table('hpbrain_signal_rules')
            ->where('rule_key', 'people_without_department')
            ->update(['threshold_op' => 'gte', 'threshold_value' => 5]);

        $this->evaluate();

        // Only one person lacks a department, so a threshold of five suppresses it.
        $this->assertArrayNotHasKey('people_without_department', $this->signalsByRule());
    }

    /** @test */
    public function generate_endpoint_runs_the_rules(): void
    {
        $token = Jwt::issueAccess(['id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin']);

        $this->postJson('/api/v1/signals/generate', [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['created' => 5]]);
    }

    /** @test */
    public function a_tenant_with_no_mappings_creates_no_signals_rather_than_reading_another(): void
    {
        DB::table('hpbrain_entity_mappings')->where('tenant_id', '6')->delete();

        $result = app(RuleEvaluator::class)->evaluate('6');

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, DB::table('hpbrain_signals')->where('tenant_id', '6')->count());
    }
}
