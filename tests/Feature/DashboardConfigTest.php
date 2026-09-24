<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\DashboardRepository;
use App\Services\DashboardBuilder;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class DashboardConfigTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-dash';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-dash', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function dashboard_builder_returns_resolved_dashboard(): void
    {
        $dashRepo = app(DashboardRepository::class);
        $dashboard = $dashRepo->create(self::TENANT, [
            'org_id'       => 'org-1',
            'dashboard_key'=> 'main',
            'name'         => 'Main Dashboard',
            'layout'       => ['widgets' => []],
            'created_by'   => 'test',
        ]);

        $builder = app(DashboardBuilder::class);
        $result = $builder->build(self::TENANT, 'org-1', 'main', []);

        $this->assertNotNull($result);
        $this->assertEquals('Main Dashboard', $result['name']);
    }

    /** @test */
    public function dashboard_builder_returns_null_for_missing(): void
    {
        $builder = app(DashboardBuilder::class);
        $result = $builder->build(self::TENANT, 'org-1', 'nonexistent', []);

        $this->assertNull($result);
    }
}
