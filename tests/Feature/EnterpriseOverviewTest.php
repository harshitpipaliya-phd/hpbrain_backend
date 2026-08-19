<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * The Intelligence Workspace composite — GET /analytics/{tenant}/enterprise-overview.
 *
 * WHY THIS FILE EXISTS. The screen died with
 *
 *     Undefined property: stdClass::$owner_id
 *
 * on any tenant that had raised a single risk. hpbrain_risks has no owner_id
 * column — it never has — and the controller read `$row->owner_id` while
 * building the risk matrix. The suite could not have caught it: it had no test
 * for this endpoint at all, and the fixture schema omits the column too, so an
 * assertion written against the fixture would have failed for the same reason
 * production did.
 *
 * A risk's owner is the person who owns the DECISION it was raised against —
 * hpbrain_decisions.decided_by, the only accountability link this schema
 * records. A risk with no decision behind it has no owner and publishes null.
 */
final class EnterpriseOverviewTest extends TestCase
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
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    private function seedRisk(?string $decisionId, string $id, float $score, string $status = 'open'): void
    {
        DB::table('hpbrain_risks')->insert([
            'id' => $id, 'tenant_id' => self::TENANT, 'decision_id' => $decisionId,
            'recommendation_id' => null, 'category' => 'academic', 'probability' => 0.8,
            'impact' => 'high', 'score' => $score, 'mitigation' => null, 'status' => $status,
            'created_by' => 'test', 'created_date' => '2026-01-01 00:00:00',
            'updated_date' => '2026-01-01 00:00:00',
        ]);
    }

    /** @test */
    public function the_overview_renders_for_a_tenant_that_has_open_risks(): void
    {
        DB::table('hpbrain_decisions')->insert([
            'id' => 'dec-1', 'tenant_id' => self::TENANT, 'recommendation_id' => null,
            'decided_by' => 'person-77', 'executor_type' => 'human', 'rationale' => 'because',
            'status' => 'approved', 'confidence' => 0.9, 'created_date' => '2026-01-01 00:00:00',
        ]);

        $this->seedRisk('dec-1', 'risk-1', 0.9);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/analytics/'.self::TENANT.'/enterprise-overview')
            ->assertStatus(200)
            ->json();

        $this->assertSame(1, $body['riskMatrix']['critical']);
        $this->assertSame('person-77', $body['riskMatrix']['topItems'][0]['owner']);
    }

    /** @test */
    public function a_risk_with_no_owning_decision_publishes_a_null_owner_not_a_guess(): void
    {
        $this->seedRisk(null, 'risk-2', 0.6);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/analytics/'.self::TENANT.'/enterprise-overview')
            ->assertStatus(200)
            ->json();

        $this->assertNull($body['riskMatrix']['topItems'][0]['owner']);
    }

    /**
     * A decision belonging to ANOTHER tenant must not become this tenant's risk
     * owner, even on a matching id.
     *
     * @test
     */
    public function the_owner_join_is_tenant_scoped(): void
    {
        DB::table('hpbrain_decisions')->insert([
            'id' => 'dec-shared', 'tenant_id' => '9', 'recommendation_id' => null,
            'decided_by' => 'someone-elses-person', 'executor_type' => 'human',
            'rationale' => 'other tenant', 'status' => 'approved', 'confidence' => 0.9,
            'created_date' => '2026-01-01 00:00:00',
        ]);

        $this->seedRisk('dec-shared', 'risk-3', 0.8);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/analytics/'.self::TENANT.'/enterprise-overview')
            ->assertStatus(200)
            ->json();

        $this->assertNull($body['riskMatrix']['topItems'][0]['owner']);
    }

    /**
     * The workspace's own department and people figures are the shared ones.
     *
     * @test
     */
    public function the_workspace_publishes_the_same_counts_as_the_departments_screen(): void
    {
        $overview = $this->withHeaders($this->auth())
            ->getJson('/api/v1/analytics/'.self::TENANT.'/enterprise-overview')
            ->assertStatus(200)->json('workforceDepartment');

        $summary = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/summary')
            ->assertStatus(200)->json();

        $this->assertSame($summary['departments']['total'], $overview['totalDepartments']);
        $this->assertSame($summary['departments']['active'], $overview['activeDepartments']);
        $this->assertSame($summary['people']['total'], $overview['totalPeople']);
    }
}
