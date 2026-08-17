<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * The human governance gate: golden-path step 8.
 *
 * Proves the property the gate exists for — that a decision is PROPOSED by one
 * person and APPROVED by another, and that both acts are written down — rather
 * than merely that the route resolves.
 *
 * SCHEMA IS BUILT HERE, not by running migrations. The suite is pinned to
 * in-memory SQLite (phpunit.xml) while every hpbrain_ migration is raw MySQL
 * DDL (ENGINE=InnoDB, JSON DEFAULT '{}'), which SQLite cannot parse. This
 * follows the convention ApiAuthorizationTest already uses for institute_detail:
 * create exactly the tables under test, with the columns under test.
 */
final class DecisionApprovalTest extends TestCase
{
    private const TENANT = 'tenant-alpha';

    private const PROPOSER = 'user-analyst';
    private const MANAGER  = 'user-manager';
    private const OTHER_MANAGER = 'user-manager-2';

    private string $recommendationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recommendationId = Uuid::uuid4()->toString();

        Schema::create('hpbrain_recommendations', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('title')->nullable();
            $t->text('status')->default('pending');
        });

        // Mirrors hpbrain_decisions AFTER 2026_07_29_000100_decision_approval:
        // the approval columns exist and status is born 'proposed'.
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
            $t->timestamp('created_date')->nullable();
            $t->index(['tenant_id', 'status'], 'idx_decisions_tenant_status');
        });

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

        // actor_name on the audit row is resolved from here.
        Schema::create('hpbrain_auth_users', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('email');
            $t->string('name');
        });

        DB::table('hpbrain_recommendations')->insert([
            'id'        => $this->recommendationId,
            'tenant_id' => self::TENANT,
            'title'     => 'Send targeted payment reminder to Grade 9 families',
            'status'    => 'pending',
        ]);

        DB::table('hpbrain_auth_users')->insert([
            ['id' => self::PROPOSER,      'tenant_id' => self::TENANT, 'email' => 'a@x.test', 'name' => 'Ada Analyst'],
            ['id' => self::MANAGER,       'tenant_id' => self::TENANT, 'email' => 'm@x.test', 'name' => 'Mo Manager'],
            ['id' => self::OTHER_MANAGER, 'tenant_id' => self::TENANT, 'email' => 'n@x.test', 'name' => 'Nia Manager'],
        ]);
    }

    /** Token helper: id is explicit, so two managers can be told apart. */
    private function auth(string $role, string $id, string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => $id, 'tenantId' => $tenant, 'role' => $role,
        ])];
    }

    /** Records a decision as the analyst and returns its id. */
    private function propose(string $rationale = 'Reminder cadence, not ability to pay, explains the shortfall.'): string
    {
        $response = $this->postJson('/api/v1/decisions', [
            'tenantId'         => self::TENANT,
            'recommendationId' => $this->recommendationId,
            'rationale'        => $rationale,
        ], $this->auth('analyst', self::PROPOSER));

        $response->assertStatus(201);

        return $response->json('id');
    }

    // ---- Proposal ----------------------------------------------------------

    public function test_an_analyst_proposes_and_the_decision_is_born_proposed(): void
    {
        $response = $this->postJson('/api/v1/decisions', [
            'tenantId'               => self::TENANT,
            'recommendationId'       => $this->recommendationId,
            'rationale'              => 'Collection rate is recoverable with a targeted reminder.',
            'executorType'           => 'human',
            'alternativesConsidered' => ['do nothing', 'blanket reminder'],
        ], $this->auth('analyst', self::PROPOSER));

        $response->assertStatus(201);

        // The whole point of the gate: creation does not approve.
        self::assertSame('proposed', $response->json('status'));
        self::assertSame(self::PROPOSER, $response->json('decided_by'));
        self::assertNull($response->json('approved_by'));
        self::assertSame(['do nothing', 'blanket reminder'], $response->json('alternatives_considered'));
    }

    public function test_a_decision_cannot_be_recorded_without_a_stated_rationale(): void
    {
        // Invariant 2 at the point of creation. 'because' is under min:10.
        $this->postJson('/api/v1/decisions', [
            'tenantId'         => self::TENANT,
            'recommendationId' => $this->recommendationId,
            'rationale'        => 'because',
        ], $this->auth('analyst', self::PROPOSER))
            ->assertStatus(422)
            ->assertJsonValidationErrors('rationale');
    }

    public function test_a_recommendation_from_another_tenant_cannot_be_decided_on(): void
    {
        $foreign = Uuid::uuid4()->toString();
        DB::table('hpbrain_recommendations')->insert([
            'id' => $foreign, 'tenant_id' => 'tenant-beta', 'title' => 'theirs', 'status' => 'pending',
        ]);

        // The FK would accept this row: the recommendation exists. Ownership is
        // a separate question, and the answer is no.
        $this->postJson('/api/v1/decisions', [
            'tenantId'         => self::TENANT,
            'recommendationId' => $foreign,
            'rationale'        => 'Anchoring to a recommendation we do not own.',
        ], $this->auth('analyst', self::PROPOSER))
            ->assertStatus(422)
            ->assertJson(['error' => 'recommendation_not_found']);
    }

    // ---- The gate ----------------------------------------------------------

    public function test_an_analyst_cannot_approve(): void
    {
        $id = $this->propose();

        $this->postJson("/api/v1/decisions/".self::TENANT."/{$id}/approve", [], $this->auth('analyst', self::PROPOSER))
            ->assertStatus(403)
            ->assertJson(['error' => 'forbidden', 'required' => 'decision.approve']);

        // The gate held: nothing moved.
        self::assertSame('proposed', DB::table('hpbrain_decisions')->where('id', $id)->value('status'));
    }

    public function test_a_manager_cannot_approve_a_decision_they_proposed(): void
    {
        // Manager holds `create`, so a manager can propose — and must still not
        // be able to approve their own proposal. Invariant 7.
        $response = $this->postJson('/api/v1/decisions', [
            'tenantId'         => self::TENANT,
            'recommendationId' => $this->recommendationId,
            'rationale'        => 'Proposed and approved by the same hand is not a control.',
        ], $this->auth('manager', self::MANAGER));

        $id = $response->json('id');

        $this->postJson("/api/v1/decisions/".self::TENANT."/{$id}/approve", [], $this->auth('manager', self::MANAGER))
            ->assertStatus(409)
            ->assertJson(['error' => 'self_approval_forbidden']);

        self::assertSame('proposed', DB::table('hpbrain_decisions')->where('id', $id)->value('status'));

        // The refusal is itself a governance event and is recorded.
        self::assertSame(1, DB::table('hpbrain_audit_logs')
            ->where('entity_id', $id)->where('action', 'decision.approve.denied')->count());
    }

    public function test_a_manager_can_approve_another_persons_decision(): void
    {
        $id = $this->propose();

        $response = $this->postJson(
            "/api/v1/decisions/".self::TENANT."/{$id}/approve",
            ['note' => 'Reviewed the evidence; the cadence change is proportionate.'],
            $this->auth('manager', self::MANAGER)
        );

        $response->assertStatus(200);
        self::assertSame('approved', $response->json('status'));
        self::assertSame(self::MANAGER, $response->json('approved_by'));
        self::assertNotNull($response->json('approved_date'));
        self::assertSame('Reviewed the evidence; the cadence change is proportionate.', $response->json('approval_note'));
    }

    public function test_approving_a_missing_decision_is_a_404(): void
    {
        $this->postJson(
            "/api/v1/decisions/".self::TENANT."/".Uuid::uuid4()->toString()."/approve",
            [], $this->auth('manager', self::MANAGER)
        )->assertStatus(404)->assertJson(['error' => 'decision_not_found']);
    }

    public function test_a_decision_in_a_non_proposed_state_is_not_approvable(): void
    {
        $id = $this->propose();
        DB::table('hpbrain_decisions')->where('id', $id)->update(['status' => 'rejected']);

        $this->postJson("/api/v1/decisions/".self::TENANT."/{$id}/approve", [], $this->auth('manager', self::MANAGER))
            ->assertStatus(409)
            ->assertJson(['error' => 'decision_not_approvable', 'status' => 'rejected']);
    }

    public function test_a_manager_can_reject_another_persons_decision(): void
    {
        $id = $this->propose();

        $response = $this->postJson(
            "/api/v1/decisions/".self::TENANT."/{$id}/reject",
            ['note' => 'Evidence is not sufficient to act on this recommendation.'],
            $this->auth('manager', self::MANAGER)
        );

        $response->assertStatus(200);
        self::assertSame('rejected', $response->json('status'));
        self::assertSame(self::MANAGER, $response->json('approved_by'));
        self::assertSame('Evidence is not sufficient to act on this recommendation.', $response->json('approval_note'));
    }

    public function test_rejecting_requires_a_stated_note(): void
    {
        $id = $this->propose();

        $this->postJson(
            "/api/v1/decisions/".self::TENANT."/{$id}/reject",
            ['note' => 'no'],
            $this->auth('manager', self::MANAGER)
        )->assertStatus(422)->assertJsonValidationErrors('note');
    }

    public function test_rejecting_does_not_emit_decision_reached(): void
    {
        $id = $this->propose();

        $this->postJson(
            "/api/v1/decisions/".self::TENANT."/{$id}/reject",
            ['note' => 'The recommendation is understood and deliberately declined.'],
            $this->auth('manager', self::MANAGER)
        )->assertStatus(200);

        self::assertSame(0, DB::table('hpbrain_event_store')
            ->where('entity_id', $id)->where('type', 'DecisionReached')->count());
    }

    // ---- Idempotency and the record ----------------------------------------

    public function test_approving_twice_appends_exactly_one_decision_reached_event(): void
    {
        $id = $this->propose();
        $uri = "/api/v1/decisions/".self::TENANT."/{$id}/approve";

        $first  = $this->postJson($uri, ['note' => 'first'], $this->auth('manager', self::MANAGER));
        $second = $this->postJson($uri, ['note' => 'second'], $this->auth('manager', self::OTHER_MANAGER));

        $first->assertStatus(200);
        // The retry is accepted, not refused — and it changes nothing.
        $second->assertStatus(200);
        self::assertSame(self::MANAGER, $second->json('approved_by'));
        self::assertSame('first', $second->json('approval_note'));

        $events = DB::table('hpbrain_event_store')
            ->where('entity_id', $id)->where('type', 'DecisionReached')->get();

        self::assertCount(1, $events, 'A replayed approval must not append a second DecisionReached.');
        self::assertSame($id, $events[0]->correlation_id);
        self::assertSame(self::MANAGER, $events[0]->actor_id);
    }

    public function test_the_approval_is_written_to_the_audit_log(): void
    {
        $id = $this->propose();

        $this->postJson("/api/v1/decisions/".self::TENANT."/{$id}/approve", [], $this->auth('manager', self::MANAGER))
            ->assertStatus(200);

        $audit = DB::table('hpbrain_audit_logs')
            ->where('entity_id', $id)->where('action', 'decision.approve')->first();

        self::assertNotNull($audit, "An approval that is not audited did not happen.");
        self::assertSame('Decision', $audit->entity_type);
        self::assertSame(self::MANAGER, $audit->actor_id);
        self::assertSame('Mo Manager', $audit->actor_name);
        self::assertSame(
            ['from' => 'proposed', 'to' => 'approved'],
            json_decode((string) $audit->changes, true)['status']
        );
    }

    // ---- Tenant boundary ----------------------------------------------------

    public function test_a_manager_cannot_approve_across_a_tenant_boundary(): void
    {
        $id = $this->propose();

        // Same decision id, a tenant the token does not name. Only an admin may
        // cross, and only to an organization that exists — a manager, never.
        $this->postJson("/api/v1/decisions/tenant-beta/{$id}/approve", [], $this->auth('manager', self::MANAGER))
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);

        self::assertSame('proposed', DB::table('hpbrain_decisions')->where('id', $id)->value('status'));
    }
}
