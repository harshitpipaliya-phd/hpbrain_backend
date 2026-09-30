<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsAiIntelligenceSchema;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The AI & Intelligence console must not be a second, weaker way to approve.
 *
 * A recommendation becomes ACCEPTED only when a canonical decision for it has
 * been APPROVED through POST /decisions + /decisions/{tenant}/{id}/approve — the
 * path that enforces decision.approve, separation of duties and DecisionReached.
 * Before this gate, the console's approve flipped the recommendation to
 * 'accepted' on its own: no decision, no event, no approver distinct from the
 * proposer.
 *
 * Every test drives real HTTP routes. Schema is built in-memory (phpunit.xml).
 */
final class RecommendationApprovalGateTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsAiIntelligenceSchema;

    private const TENANT_A = 'tenant-gate-a';

    private const TENANT_B = 'tenant-gate-b';

    private const CONSOLE = '/api/v1/ai-intelligence/recommendations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildAiIntelligenceSchema();

        config(['brain.ai.provider' => '']);
    }

    private function as(string $user, string $role, string $tenant = self::TENANT_A): array
    {
        return ['Authorization' => 'Bearer ' . Jwt::issueAccess(['id' => $user, 'tenantId' => $tenant, 'role' => $role])];
    }

    private function recommendation(string $tenant = self::TENANT_A): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_recommendations')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'title' => 'Fix the collection gap', 'category' => 'act',
            'priority' => 'high', 'confidence' => 0.8, 'status' => 'pending', 'created_by' => 'test',
            'created_date' => '2026-09-01 00:00:00', 'updated_date' => '2026-09-01 00:00:00',
        ]);

        return $id;
    }

    /** Propose a decision through the canonical route; returns the decision id. */
    private function propose(string $recommendation, string $proposer, string $role = 'analyst'): string
    {
        $response = $this->withHeaders($this->as($proposer, $role))->postJson('/api/v1/decisions', [
            'tenantId' => self::TENANT_A,
            'recommendationId' => $recommendation,
            'rationale' => 'Evidence supports acting on this recommendation.',
        ]);
        $response->assertStatus(201);

        return (string) $response->json('id');
    }

    private function recStatus(string $recommendation): string
    {
        return (string) DB::table('hpbrain_recommendations')->where('id', $recommendation)->value('status');
    }

    private function consoleApprovals(): int
    {
        return DB::table('hpbrain_ai_audit_logs')->where('event_type', 'ai.recommendation.approved')->count();
    }

    /** @test */
    public function console_approve_is_refused_when_no_decision_exists(): void
    {
        $rec = $this->recommendation();
        $before = DB::table('hpbrain_recommendations')->where('id', $rec)->first();

        $this->withHeaders($this->as('admin-1', 'tenant_admin'))
            ->postJson(self::CONSOLE . "/{$rec}/approve", ['note' => 'looks fine'])
            ->assertStatus(409)
            ->assertJsonPath('errors.decision.0', 'decision_required');

        $after = DB::table('hpbrain_recommendations')->where('id', $rec)->first();
        $this->assertSame('pending', $this->recStatus($rec), 'a refused approval must not mutate the recommendation');
        $this->assertSame($before->updated_date, $after->updated_date);
        $this->assertSame(0, $this->consoleApprovals(), 'a refused approval must not be audited as an approval');
        $this->assertSame(0, DB::table('hpbrain_decisions')->count(), 'the console must not create decisions either');
    }

    /** @test */
    public function console_approve_is_refused_while_the_decision_is_only_proposed(): void
    {
        $rec = $this->recommendation();
        $this->propose($rec, 'analyst-1');

        $this->withHeaders($this->as('admin-1', 'tenant_admin'))
            ->postJson(self::CONSOLE . "/{$rec}/approve")
            ->assertStatus(409)
            ->assertJsonPath('errors.decision.0', 'decision_required');

        $this->assertSame('pending', $this->recStatus($rec));
    }

    /** @test */
    public function a_proposer_cannot_launder_self_approval_through_the_console(): void
    {
        $rec = $this->recommendation();
        // The same administrator proposes the decision...
        $decision = $this->propose($rec, 'admin-1', 'tenant_admin');

        // ...cannot approve it in the canonical workflow...
        $this->withHeaders($this->as('admin-1', 'tenant_admin'))
            ->postJson('/api/v1/decisions/' . self::TENANT_A . "/{$decision}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error', 'self_approval_forbidden');

        // ...and the console offers no way around that.
        $this->withHeaders($this->as('admin-1', 'tenant_admin'))
            ->postJson(self::CONSOLE . "/{$rec}/approve")
            ->assertStatus(409);

        $this->assertSame('pending', $this->recStatus($rec));
        $this->assertSame('proposed', DB::table('hpbrain_decisions')->where('id', $decision)->value('status'));
    }

    /** @test */
    public function console_approve_succeeds_only_after_a_different_person_approves_the_decision(): void
    {
        $rec = $this->recommendation();
        $decision = $this->propose($rec, 'analyst-1');

        $this->withHeaders($this->as('manager-1', 'manager'))
            ->postJson('/api/v1/decisions/' . self::TENANT_A . "/{$decision}/approve", ['note' => 'approved'])
            ->assertOk();

        $this->withHeaders($this->as('admin-1', 'tenant_admin'))
            ->postJson(self::CONSOLE . "/{$rec}/approve", ['note' => 'recorded'])
            ->assertOk()
            ->assertJsonPath('data.recommendation.status', 'accepted');

        $this->assertSame('accepted', $this->recStatus($rec));
        $this->assertSame(1, $this->consoleApprovals());
    }

    /** @test */
    public function console_reject_and_defer_cannot_contradict_an_approved_decision(): void
    {
        $rec = $this->recommendation();
        $decision = $this->propose($rec, 'analyst-1');
        $this->withHeaders($this->as('manager-1', 'manager'))
            ->postJson('/api/v1/decisions/' . self::TENANT_A . "/{$decision}/approve")->assertOk();

        foreach (['reject', 'defer'] as $verb) {
            $this->withHeaders($this->as('admin-1', 'tenant_admin'))
                ->postJson(self::CONSOLE . "/{$rec}/{$verb}")
                ->assertStatus(409)
                ->assertJsonPath('errors.decision.0', 'decision_already_approved');
        }

        $this->assertSame('pending', $this->recStatus($rec));
    }

    /** @test */
    public function console_reject_and_defer_still_work_when_no_decision_was_approved(): void
    {
        $a = $this->recommendation();
        $b = $this->recommendation();

        $this->withHeaders($this->as('admin-1', 'tenant_admin'))
            ->postJson(self::CONSOLE . "/{$a}/reject", ['note' => 'not now'])->assertOk();
        $this->withHeaders($this->as('admin-1', 'tenant_admin'))
            ->postJson(self::CONSOLE . "/{$b}/defer")->assertOk();

        $this->assertSame('rejected', $this->recStatus($a));
        $this->assertSame('deferred', $this->recStatus($b));
    }

    /**
     * @test
     * @dataProvider unauthorisedRoles
     */
    public function roles_without_the_console_or_approval_permission_are_refused(string $role): void
    {
        $rec = $this->recommendation();

        foreach (['approve', 'reject', 'defer'] as $verb) {
            $this->withHeaders($this->as("user-{$role}", $role))
                ->postJson(self::CONSOLE . "/{$rec}/{$verb}")
                ->assertStatus(403);
        }

        $this->assertSame('pending', $this->recStatus($rec));
        $this->assertSame(0, $this->consoleApprovals());
    }

    /** @return array<string, array{string}> */
    public static function unauthorisedRoles(): array
    {
        return ['viewer' => ['viewer'], 'analyst' => ['analyst'], 'manager (no console access)' => ['manager']];
    }

    /** @test */
    public function another_tenant_cannot_decide_or_infer_this_recommendation(): void
    {
        $rec = $this->recommendation(self::TENANT_A);
        $decision = $this->propose($rec, 'analyst-1');
        $this->withHeaders($this->as('manager-1', 'manager'))
            ->postJson('/api/v1/decisions/' . self::TENANT_A . "/{$decision}/approve")->assertOk();

        foreach (['approve', 'reject', 'defer'] as $verb) {
            $this->withHeaders($this->as('admin-b', 'tenant_admin', self::TENANT_B))
                ->postJson(self::CONSOLE . "/{$rec}/{$verb}")
                ->assertStatus(404);
        }

        // Tenant B's own approval cannot lean on tenant A's approved decision.
        $own = $this->recommendation(self::TENANT_B);
        $this->withHeaders($this->as('admin-b', 'tenant_admin', self::TENANT_B))
            ->postJson(self::CONSOLE . "/{$own}/approve")
            ->assertStatus(409);

        $this->assertSame('pending', $this->recStatus($rec));
        $this->assertSame('pending', $this->recStatus($own));
    }

    /** @test */
    public function an_already_decided_recommendation_stays_a_conflict(): void
    {
        $rec = $this->recommendation();
        $this->withHeaders($this->as('admin-1', 'tenant_admin'))
            ->postJson(self::CONSOLE . "/{$rec}/reject")->assertOk();

        $this->withHeaders($this->as('admin-1', 'tenant_admin'))
            ->postJson(self::CONSOLE . "/{$rec}/approve")->assertStatus(409);

        $this->assertSame('rejected', $this->recStatus($rec));
    }
}
