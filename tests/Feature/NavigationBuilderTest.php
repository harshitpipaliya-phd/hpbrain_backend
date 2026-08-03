<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\NavigationItemRepository;
use App\Repositories\ModuleRepository;
use App\Services\NavigationBuilder;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class NavigationBuilderTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-nav';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-nav', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function navigation_builder_filters_by_module(): void
    {
        $navRepo = app(NavigationItemRepository::class);
        $navRepo->create(self::TENANT, [
            'industry_code' => 'corporate',
            'role_key'      => 'admin',
            'item_key'      => 'analytics',
            'label'         => 'Analytics',
            'route'         => '/analytics',
            'required_module' => 'analytics',
            'created_by'    => 'test',
        ]);

        $builder = app(NavigationBuilder::class);
        $result = $builder->build(self::TENANT, 'corporate', 'admin', ['analytics'], []);

        $this->assertNotEmpty($result);
        $this->assertEquals('Analytics', $result[0]['label'] ?? null);
    }

    /** @test */
    public function navigation_builder_excludes_disabled_module(): void
    {
        $navRepo = app(NavigationItemRepository::class);
        $navRepo->create(self::TENANT, [
            'industry_code' => 'corporate',
            'role_key'      => 'admin',
            'item_key'      => 'analytics',
            'label'         => 'Analytics',
            'route'         => '/analytics',
            'required_module' => 'analytics',
            'created_by'    => 'test',
        ]);

        $builder = app(NavigationBuilder::class);
        $result = $builder->build(self::TENANT, 'corporate', 'admin', ['intelligence'], []);

        $this->assertEmpty($result);
    }
}
