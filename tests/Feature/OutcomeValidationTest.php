<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Golden-path step 9. An outcome is a measurement or it is nothing.
 *
 * The load-bearing assertion is test_a_complete_write_stores_every_submitted_field:
 * the original defect returned 201 and wrote a row, so a status-code test would
 * have passed against it. Only reading the row back catches it.
 *
 * SCHEMA IS BUILT HERE — the suite is pinned to in-memory SQLite (phpunit.xml)
 * and the hpbrain_ migrations are raw MySQL DDL. Same convention as
 * EvidenceProvenanceTest.
 */
final class OutcomeValidationTest extends TestCase
{
    private const TENANT = 'tenant-alpha';
    private const ACTOR  = 'user-analyst';

    private string $decisionId;
    private string $evidenceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decisionId = Uuid::uuid4()->toString();
        $this->evidenceId = Uuid::uuid4()->toString();

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

        Schema::create('hpbrain_decisions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('recommendation_id', 36)->nullable();
            $t->string('decided_by', 36)->nullable();
            $t->string('status')->default('proposed');
        });

        Schema::create('hpbrain_evidence', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('source')->nullable();
        });

        // The chain OutcomeController walks to derive `domain` for the event
        // payload: decision -> recommendation -> reasoning step -> mental model.
        Schema::create('hpbrain_recommendations', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('reasoning_step_id', 36)->nullable();
        });
        Schema::create('hpbrain_reasoning_steps', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('mental_model_id', 36)->nullable();
        });
        Schema::create('hpbrain_mental_models', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('domain');
        });

        Schema::create('hpbrain_event_store', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('type');
            $t->string('tenant_id', 36);
            $t->string('entity_type');
            $t->string('entity_id', 36);
            $t->string('actor_id', 36);
            $t->text('payload');
            $t->string('correlation_id', 36)->nullable();
            $t->string('causation_id', 36)->nullable();
            $t->string('idempotency_key', 36)->nullable()->unique();
            $t->string('status')->default('pending');
            $t->integer('retry_count')->default(0);
            $t->timestamp('created_at')->nullable();
        });

        $modelId = Uuid::uuid4()->toString();
        $stepId  = Uuid::uuid4()->toString();
        $recId   = Uuid::uuid4()->toString();

        DB::table('hpbrain_mental_models')->insert(['id' => $modelId, 'tenant_id' => self::TENANT, 'domain' => 'finance']);
        DB::table('hpbrain_reasoning_steps')->insert(['id' => $stepId, 'tenant_id' => self::TENANT, 'mental_model_id' => $modelId]);
        DB::table('hpbrain_recommendations')->insert(['id' => $recId, 'tenant_id' => self::TENANT, 'reasoning_step_id' => $stepId]);

        DB::table('hpbrain_decisions')->insert([
            'id' => $this->decisionId, 'tenant_id' => self::TENANT,
            'recommendation_id' => $recId, 'decided_by' => 'user-manager', 'status' => 'approved',
        ]);

        DB::table('hpbrain_evidence')->insert([
            'id' => $this->evidenceId, 'tenant_id' => self::TENANT, 'source' => 'fee_ledger',
        ]);
    }

    private function auth(string $role): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => self::ACTOR, 'tenantId' => self::TENANT, 'role' => $role,
        ])];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'tenantId'    => self::TENANT,
            'decisionId'  => $this->decisionId,
            'result'      => 'partial',
            'metrics'     => ['collectionRate' => 0.62, 'baseline' => 0.51],
            'kpis'        => ['targetCollectionRate' => 0.70],
            'evidenceIds' => [$this->evidenceId],
            'feedback'    => 'Reminder landed, but two weeks late in the cycle.',
            'confidence'  => 0.66,
        ], $overrides);
    }

    private function capture(array $overrides = [], string $role = 'analyst'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/outcomes', $this->payload($overrides), $this->auth($role));
    }

    // ---- The write actually carries the payload -----------------------------

    public function test_a_complete_write_stores_every_submitted_field(): void
    {
        $response = $this->capture();
        $response->assertStatus(201);

        $stored = DB::table('hpbrain_outcomes')->where('id', $response->json('id'))->first();

        self::assertSame(self::TENANT, $stored->tenant_id);
        self::assertSame($this->decisionId, $stored->decision_id);
        self::assertSame('partial', $stored->result);
        self::assertSame(['collectionRate' => 0.62, 'baseline' => 0.51], json_decode((string) $stored->metrics, true));
        self::assertSame(['targetCollectionRate' => 0.70], json_decode((string) $stored->kpis, true));
        self::assertSame([$this->evidenceId], json_decode((string) $stored->evidence_ids, true));
        self::assertSame('Reminder landed, but two weeks late in the cycle.', $stored->feedback);
        self::assertSame(0.66, (float) $stored->confidence);
        self::assertSame(self::ACTOR, $stored->created_by);

        // Parsed, not encoded — same rule as evidence.
        self::assertIsArray($response->json('metrics'));
        self::assertIsArray($response->json('evidence_ids'));
    }

    /** @return array<string, array{0: string}> */
    public static function requiredFields(): array
    {
        return [
            'decisionId'  => ['decisionId'],
            'result'      => ['result'],
            'metrics'     => ['metrics'],
            'evidenceIds' => ['evidenceIds'],
            'confidence'  => ['confidence'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredFields')]
    public function test_a_write_missing_a_required_field_is_rejected(string $field): void
    {
        $payload = $this->payload();
        unset($payload[$field]);

        $this->postJson('/api/v1/outcomes', $payload, $this->auth('analyst'))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        self::assertSame(0, DB::table('hpbrain_outcomes')->count());
    }

    // ---- Invariant 1: an outcome cites evidence ------------------------------

    public function test_an_outcome_with_no_evidence_is_rejected(): void
    {
        // The column default is '[]', so the database would accept this row
        // happily. An outcome nobody can check is an assertion.
        $this->capture(['evidenceIds' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('evidenceIds');
    }

    public function test_evidence_from_another_tenant_is_named_and_rejected(): void
    {
        $foreign = Uuid::uuid4()->toString();
        DB::table('hpbrain_evidence')->insert(['id' => $foreign, 'tenant_id' => 'tenant-beta', 'source' => 'x']);

        $response = $this->capture(['evidenceIds' => [$this->evidenceId, $foreign]]);

        $response->assertStatus(422)->assertJson(['error' => 'evidence_not_found']);
        // The offending id is named, so the caller need not bisect the payload.
        self::assertSame([$foreign], $response->json('ids'));
        self::assertSame(0, DB::table('hpbrain_outcomes')->count());
    }

    // ---- Governance: no outcome without an approved decision ------------------

    public function test_an_outcome_for_an_unapproved_decision_is_rejected(): void
    {
        DB::table('hpbrain_decisions')->where('id', $this->decisionId)->update(['status' => 'proposed']);

        // An outcome here would record that execution happened outside the
        // governance gate, and record it as though it were normal.
        $this->capture()
            ->assertStatus(422)
            ->assertJson(['error' => 'decision_not_approved', 'status' => 'proposed']);
    }

    public function test_a_decision_from_another_tenant_is_rejected(): void
    {
        $foreign = Uuid::uuid4()->toString();
        DB::table('hpbrain_decisions')->insert([
            'id' => $foreign, 'tenant_id' => 'tenant-beta', 'decided_by' => 'x', 'status' => 'approved',
        ]);

        $this->capture(['decisionId' => $foreign])
            ->assertStatus(422)
            ->assertJson(['error' => 'decision_not_approved']);
    }

    // ---- The event -----------------------------------------------------------

    public function test_exactly_one_outcome_recorded_event_is_appended(): void
    {
        $id = $this->capture()->assertStatus(201)->json('id');

        $events = DB::table('hpbrain_event_store')
            ->where('entity_id', $id)->where('type', 'OutcomeRecorded')->get();

        self::assertCount(1, $events);
        // The decision is the thread the whole loop hangs off.
        self::assertSame($this->decisionId, $events[0]->correlation_id);
        self::assertSame(self::ACTOR, $events[0]->actor_id);

        $payload = json_decode((string) $events[0]->payload, true);

        self::assertSame($id, $payload['outcomeId']);
        // Module 5 reads this to decide which mental model is reinforced.
        self::assertSame('finance', $payload['domain']);
    }

    public function test_the_domain_is_null_rather_than_guessed_when_the_chain_is_broken(): void
    {
        // A decision with no recommendation behind it: the domain is genuinely
        // unknown, and null says so.
        $orphan = Uuid::uuid4()->toString();
        DB::table('hpbrain_decisions')->insert([
            'id' => $orphan, 'tenant_id' => self::TENANT, 'decided_by' => 'x', 'status' => 'approved',
        ]);

        $id = $this->capture(['decisionId' => $orphan])->assertStatus(201)->json('id');

        $payload = json_decode(
            (string) DB::table('hpbrain_event_store')->where('entity_id', $id)->value('payload'), true
        );

        self::assertNull($payload['domain']);
    }

    // ---- Authorization --------------------------------------------------------

    public function test_a_viewer_cannot_capture_an_outcome(): void
    {
        $this->capture([], 'viewer')
            ->assertStatus(403)
            ->assertJson(['error' => 'forbidden', 'required' => 'create']);

        self::assertSame(0, DB::table('hpbrain_outcomes')->count());
    }
}
