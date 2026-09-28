<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\OrganizationTypeRepository;
use App\Repositories\RoleRepository;
use App\Repositories\SkillRepository;
use App\Repositories\CompetencyRepository;
use App\Repositories\LocationTypeRepository;
use App\Repositories\OrganizationUnitRepository;
use App\Repositories\PositionRepository;
use App\Repositories\PersonRoleRepository;
use App\Repositories\PersonSkillRepository;
use App\Repositories\PersonCompetencyRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ReportingStructureRepository;
use App\Repositories\OnboardingSessionRepository;
use App\Repositories\ImportJobRepository;
use App\Repositories\ImportLogRepository;
use App\Repositories\ReadinessCheckRepository;
use App\Repositories\TemplateOverrideRepository;
use App\Services\OrganizationEngine;
use App\Services\OnboardingEngine;
use App\Services\TemplateInheritanceEngine;
use App\Services\ImportEngine;
use App\Services\UnitTypeRegistry;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class UniversalOrganizationEngineTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-org';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-org', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function organization_types_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/organization-types/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function organization_units_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/organization-units/".self::TENANT.'?orgId='.self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function roles_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/roles/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function positions_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/positions/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function skills_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/skills/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function competencies_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/competencies/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function person_roles_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/person-roles/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function person_skills_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/person-skills/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function person_competencies_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/person-competencies/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function location_types_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/location-types/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function locations_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/locations/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function reporting_structures_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/reporting-structures/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function onboarding_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/onboarding/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function imports_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/imports/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function readiness_checks_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/readiness-checks/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function template_overrides_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/template-overrides/".self::TENANT);
        $response->assertStatus(200);
    }

    /** @test */
    public function organization_engine_can_create_hierarchy(): void
    {
        $engine = app(OrganizationEngine::class);
        $result = $engine->createOrganization(self::TENANT, [
            'org_id'     => 'org-'.self::TENANT,
            'units'      => [
                ['unit_type' => 'department', 'name' => 'Engineering', 'code' => 'ENG'],
                ['unit_type' => 'department', 'name' => 'HR', 'code' => 'HR'],
            ],
            'roles'      => [
                ['role_key' => 'manager', 'name' => 'Manager'],
            ],
            'created_by' => 'test',
        ]);

        $this->assertEquals('org-'.self::TENANT, $result['org_id']);
    }

    /** @test */
    public function unit_type_registry_returns_all_types(): void
    {
        $types = UnitTypeRegistry::getTypes();

        $this->assertArrayHasKey('department', $types);
        $this->assertArrayHasKey('school', $types);
        $this->assertArrayHasKey('faculty', $types);
    }

    /** @test */
    public function onboarding_engine_can_start_session(): void
    {
        $engine = app(OnboardingEngine::class);
        $session = $engine->startOnboarding(self::TENANT, [
            'org_id'     => 'org-'.self::TENANT,
            'started_by' => 'test',
        ]);

        $this->assertEquals('draft', $session['status']);
    }

    /**
     * Regression for the hardcoded-'platform'-tenant bug: completeStep(),
     * getNextStep(), validateStep(), activateOrganization() and
     * abandonOnboarding() used to resolve their session's tenant via a lookup
     * scoped to the literal tenant 'platform', which no real session is ever
     * stored under — so every one of these calls silently 404'd regardless of
     * which tenant started the session. They now take the authenticated
     * tenant directly from the caller instead of trying to rediscover it.
     *
     * @test
     */
    public function onboarding_engine_can_progress_a_session_through_its_steps(): void
    {
        $engine = app(OnboardingEngine::class);
        $session = $engine->startOnboarding(self::TENANT, [
            'org_id'     => 'org-'.self::TENANT,
            'started_by' => 'test',
        ]);

        $completed = $engine->completeStep(self::TENANT, $session['id'], '1', ['foo' => 'bar']);
        $this->assertNotNull($completed, 'completeStep must find the session it just started');
        $this->assertEquals(2, $completed['current_step']);

        $next = $engine->getNextStep(self::TENANT, $session['id']);
        $this->assertSame(2, $next['step']);

        $validation = $engine->validateStep(self::TENANT, $session['id'], '1');
        $this->assertTrue($validation['valid']);

        $activated = $engine->activateOrganization(self::TENANT, $session['id']);
        $this->assertNotNull($activated);
        $this->assertEquals('activated', $activated['status']);
    }

    /**
     * Regression: runReadinessChecks() used to write every tenant's checks
     * into a shared 'platform' bucket keyed only by org_id, so two tenants
     * reusing the same org_id (a small/sequential value in practice) could
     * read each other's readiness results via getReadinessStatus(). Checks
     * are now written and read under the caller's own tenant.
     *
     * @test
     */
    public function readiness_checks_are_isolated_per_tenant_even_for_the_same_org_id(): void
    {
        $engine = app(OnboardingEngine::class);

        $engine->runReadinessChecks(self::TENANT, 'shared-org-id');
        $engine->runReadinessChecks('tenant-other', 'shared-org-id');

        $mine = $engine->getReadinessStatus(self::TENANT, 'shared-org-id');
        $theirs = $engine->getReadinessStatus('tenant-other', 'shared-org-id');

        $this->assertGreaterThan(0, $mine['total']);
        $this->assertGreaterThan(0, $theirs['total']);

        $mineIds = array_column($mine['checks'], 'id');
        $theirIds = array_column($theirs['checks'], 'id');

        $this->assertEmpty(
            array_intersect($mineIds, $theirIds),
            'a readiness check row must never be visible from both tenants'
        );
    }
}
