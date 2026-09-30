<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\SeedsEntityMappings;
use Tests\TestCase;

/**
 * brain:propose-hypotheses — the properties that make it safe to run on a schedule.
 *
 * It had no test. A command that writes on a timer must be idempotent (a second run
 * adds nothing), tenant-scoped, bounded, free of any model call, and it must decline
 * rather than invent when there is nothing to stand on. Each is asserted here.
 */
final class ProposeHypothesesTest extends TestCase
{
    use BuildsBrainSchema;
    use SeedsEntityMappings;

    private const TENANT = 'tenant-hyp-a';

    private const OTHER = 'tenant-hyp-b';

    /** A code-held rule whose approved cause is declared in the command itself. */
    private const RULE = 'complaint_root_cause_unrecorded';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->installEntityMappings([self::TENANT, self::OTHER]);

        // If anything here reached a provider, this would record it.
        config(['brain.ai.provider' => 'anthropic', 'brain.ai.anthropic.api_key' => 'sk-ant-test-000000000000']);
        Http::fake();
    }

    /** @return array{0: string, 1: string} signal id, case id */
    private function caseFor(string $tenant, string $rule = self::RULE, bool $withEvidence = true, string $status = 'new'): array
    {
        $signal = Uuid::uuid4()->toString();
        $case = Uuid::uuid4()->toString();
        $now = now()->format('Y-m-d H:i:s');

        DB::table('hpbrain_signals')->insert([
            'id' => $signal, 'tenant_id' => $tenant, 'source' => 'test', 'classification' => 'quality',
            'rule_key' => $rule, 'priority' => 'medium', 'severity' => 'medium', 'confidence' => 1.0,
            'status' => $status, 'created_by' => 'system', 'created_date' => $now,
        ]);
        DB::table('hpbrain_cases')->insert([
            'id' => $case, 'tenant_id' => $tenant, 'signal_id' => $signal, 'title' => 'Case',
            'status' => 'open', 'created_by' => 'system', 'created_date' => $now,
        ]);

        if ($withEvidence) {
            $content = json_encode(['issue' => 'ticket closed with no Final Solution recorded']);
            DB::table('hpbrain_evidence')->insert([
                'id' => Uuid::uuid4()->toString(), 'tenant_id' => $tenant, 'signal_id' => $signal,
                'source' => 'test', 'evidence_type' => 'observation', 'content' => $content,
                'provenance' => '{}', 'confidence' => 1.0, 'hash' => hash('sha256', $content),
                'status' => 'active', 'created_by' => 'system', 'created_date' => $now,
            ]);
        }

        return [$signal, $case];
    }

    private function run_(array $options = []): int
    {
        return Artisan::call('brain:propose-hypotheses', $options);
    }

    private function hypotheses(string $tenant): int
    {
        return DB::table('hpbrain_hypotheses')->where('tenant_id', $tenant)->count();
    }

    /** @test */
    public function it_proposes_one_hypothesis_per_case_and_never_a_second_on_rerun(): void
    {
        [, $case] = $this->caseFor(self::TENANT);

        $this->assertSame(0, $this->run_(['--tenant' => self::TENANT]));
        $this->assertSame(1, $this->hypotheses(self::TENANT));

        $row = DB::table('hpbrain_hypotheses')->where('case_id', $case)->first();
        $this->assertSame('proposed', $row->status, 'a proposal is never born confirmed');
        $this->assertSame('Information', $row->root_cause_family);
        $this->assertNotSame([], json_decode($row->supporting_evidence_ids, true), 'a hypothesis must stand on evidence');

        // A scheduler firing again must add nothing.
        $this->run_();
        $this->run_();
        $this->assertSame(1, $this->hypotheses(self::TENANT));
    }

    /** @test */
    public function a_dry_run_writes_nothing(): void
    {
        $this->caseFor(self::TENANT);

        $this->assertSame(0, $this->run_(['--dry-run' => true]));

        $this->assertSame(0, $this->hypotheses(self::TENANT));
    }

    /** @test */
    public function it_declines_rather_than_inventing_when_there_is_no_evidence_or_no_approved_cause(): void
    {
        $this->caseFor(self::TENANT, self::RULE, withEvidence: false);
        $this->caseFor(self::TENANT, 'a_rule_nobody_approved_a_cause_for');

        $this->run_();

        $this->assertSame(0, $this->hypotheses(self::TENANT));
    }

    /** @test */
    public function it_does_not_touch_resolved_signals(): void
    {
        $this->caseFor(self::TENANT, self::RULE, status: 'resolved');

        $this->run_();

        $this->assertSame(0, $this->hypotheses(self::TENANT));
    }

    /** @test */
    public function it_is_tenant_scoped(): void
    {
        [, $caseA] = $this->caseFor(self::TENANT);
        [, $caseB] = $this->caseFor(self::OTHER);

        $this->run_(['--tenant' => self::TENANT]);

        $this->assertSame(1, $this->hypotheses(self::TENANT));
        $this->assertSame(0, $this->hypotheses(self::OTHER), 'naming one tenant must leave the other alone');

        // Run for everyone: each hypothesis hangs off its own tenant's case only.
        $this->run_();
        $this->assertSame(
            self::TENANT,
            DB::table('hpbrain_hypotheses')->where('case_id', $caseA)->value('tenant_id')
        );
        $this->assertSame(
            self::OTHER,
            DB::table('hpbrain_hypotheses')->where('case_id', $caseB)->value('tenant_id')
        );
    }

    /** @test */
    public function it_is_bounded_by_limit(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->caseFor(self::TENANT);
        }

        $this->run_(['--tenant' => self::TENANT, '--limit' => 2]);
        $this->assertSame(2, $this->hypotheses(self::TENANT));

        $this->run_(['--tenant' => self::TENANT, '--limit' => 2]);
        $this->assertSame(4, $this->hypotheses(self::TENANT));
    }

    /** @test */
    public function it_never_calls_a_model_provider(): void
    {
        $this->caseFor(self::TENANT);

        $this->run_();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('hpbrain_ai_executions')->count());
    }
}
