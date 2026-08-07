<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\IndustryRepository;
use App\Repositories\TerminologyRepository;
use App\Repositories\EntityMappingRepository;
use App\Repositories\FeatureFlagRepository;
use App\Repositories\ModuleRepository;
use App\Repositories\OrganizationModuleRepository;
use App\Repositories\NavigationItemRepository;
use App\Repositories\DashboardRepository;
use App\Repositories\BrandingRepository;
use App\Repositories\ThemeRepository;
use App\Repositories\FormRepository;
use App\Repositories\ConfigVersionRepository;
use App\Repositories\IndustryTemplateRepository;
use App\Services\ConfigurationEngine;
use App\Services\TenantConfigCache;
use App\Services\ConfigVersionService;
use App\Services\NavigationBuilder;
use App\Services\DashboardBuilder;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class TenantIsolationTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT_A = 'tenant-iso-a';
    private const TENANT_B = 'tenant-iso-b';

    private function auth(string $role = 'admin', string $tenant = self::TENANT_A): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-iso', 'tenantId' => $tenant, 'role' => $role,
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    private function seedIndustry(string $tenantId, string $code, string $name): void
    {
        $repo = app(IndustryRepository::class);
        $repo->create($tenantId, ['code' => $code, 'name' => $name, 'created_by' => 'test']);
    }

    /** @test */
    public function tenant_cannot_read_another_tenant_industries(): void
    {
        $this->seedIndustry(self::TENANT_A, 'healthcare', 'Healthcare A');
        $this->seedIndustry(self::TENANT_B, 'healthcare', 'Healthcare B');

        $response = $this->withHeaders($this->auth('admin', self::TENANT_A))->getJson("/api/v1/industries/".self::TENANT_A);

        $response->assertStatus(200);
        $response->assertJsonMissing(['name' => 'Healthcare B']);
        $response->assertJsonFragment(['name' => 'Healthcare A']);
    }

    /** @test */
    public function tenant_cannot_read_another_tenant_feature_flags(): void
    {
        DB::table('hpbrain_feature_flags')->insert([
            'id' => 'flag-a', 'tenant_id' => self::TENANT_A, 'flag_key' => 'test_flag', 'flag_name' => 'Test A',
            'enabled' => true, 'level' => 'platform', 'level_id' => null, 'rollout_percentage' => 100, 'rules' => null, 'created_by' => 'test',
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);
        DB::table('hpbrain_feature_flags')->insert([
            'id' => 'flag-b', 'tenant_id' => self::TENANT_B, 'flag_key' => 'test_flag', 'flag_name' => 'Test B',
            'enabled' => true, 'level' => 'platform', 'level_id' => null, 'rollout_percentage' => 100, 'rules' => null, 'created_by' => 'test',
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);

        $response = $this->withHeaders($this->auth('admin', self::TENANT_A))->getJson("/api/v1/feature-flags/".self::TENANT_A);

        $response->assertStatus(200);
        $response->assertJsonMissing(['flag_name' => 'Test B']);
    }

    /** @test */
    public function config_engine_is_tenant_safe(): void
    {
        $repo = app(IndustryRepository::class);
        $repo->create('platform', ['code' => 'healthcare', 'name' => 'Healthcare Platform', 'created_by' => 'test']);

        $engine = app(ConfigurationEngine::class);

        $result = $engine->getIndustry('healthcare');
        $this->assertEquals('Healthcare Platform', $result['name'] ?? null);
    }

    /** @test */
    public function tenant_cannot_read_another_tenant_organization_units(): void
    {
        DB::table('hpbrain_organization_units')->insert([
            [
                'id' => 'unit-a-1',
                'tenant_id' => self::TENANT_A,
                'org_id' => self::TENANT_A,
                'unit_type' => 'department',
                'name' => 'Engineering',
                'status' => 'active',
                'created_by' => 'test',
                'created_date' => '2026-01-01 00:00:00',
                'updated_date' => '2026-01-01 00:00:00',
            ],
            [
                'id' => 'unit-a-2',
                'tenant_id' => self::TENANT_A,
                'org_id' => self::TENANT_A,
                'unit_type' => 'department',
                'name' => 'HR',
                'status' => 'active',
                'created_by' => 'test',
                'created_date' => '2026-01-01 00:00:00',
                'updated_date' => '2026-01-01 00:00:00',
            ],
            [
                'id' => 'unit-b-1',
                'tenant_id' => self::TENANT_B,
                'org_id' => self::TENANT_B,
                'unit_type' => 'department',
                'name' => 'Sales',
                'status' => 'active',
                'created_by' => 'test',
                'created_date' => '2026-01-01 00:00:00',
                'updated_date' => '2026-01-01 00:00:00',
            ],
        ]);

        $response = $this->withHeaders($this->auth('admin', self::TENANT_A))
            ->getJson('/api/v1/organization-units/'.self::TENANT_A);

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Engineering']);
        $response->assertJsonFragment(['name' => 'HR']);
        $response->assertJsonMissing(['name' => 'Sales']);
    }

    /** @test */
    public function organization_unit_index_rejects_a_forged_orgId(): void
    {
        $response = $this->withHeaders($this->auth('admin', self::TENANT_A))
            ->getJson('/api/v1/organization-units/'.self::TENANT_A.'?orgId='.self::TENANT_B);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'tenant_mismatch']);
    }
}
