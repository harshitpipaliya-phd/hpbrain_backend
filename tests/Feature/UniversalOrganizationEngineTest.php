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
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/organization-units/".self::TENANT);
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
}
