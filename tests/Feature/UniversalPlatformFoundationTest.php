<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class UniversalPlatformFoundationTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-upf';

    private function auth(string $role = 'admin'): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-upf', 'tenantId' => self::TENANT, 'role' => $role,
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function industries_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/industries/".self::TENANT);

        $response->assertStatus(200)->assertJsonStructure([]);
    }

    /** @test */
    public function organization_configs_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/organization-configs/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function terminology_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/terminology/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function entity_mappings_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/entity-mappings/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function feature_flags_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/feature-flags/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function modules_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/modules/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function organization_modules_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/organization-modules/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function navigation_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/navigation/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function dashboards_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/dashboards/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function branding_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/branding/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function themes_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/themes/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function forms_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/forms/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function config_versions_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/config-versions/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function industry_templates_endpoints_are_accessible(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/industry-templates/".self::TENANT);

        $response->assertStatus(200);
    }
}
