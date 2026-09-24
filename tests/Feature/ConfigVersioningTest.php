<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\ConfigVersionRepository;
use App\Services\ConfigVersionService;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class ConfigVersioningTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-cv';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-cv', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function config_versioning_creates_and_activates(): void
    {
        $service = app(ConfigVersionService::class);

        $version = $service->createVersion(
            self::TENANT, 'org-1', 'branding', 'primary_color',
            ['color' => '#ff0000'], 'Initial branding config', 'test'
        );

        $this->assertNotNull($version['id']);
        $this->assertEquals('draft', $version['status']);

        $activated = $service->activateVersion(self::TENANT, $version['id'], 'test');

        $this->assertEquals('active', $activated['status']);
    }

    /** @test */
    public function config_versioning_rollback_works(): void
    {
        $service = app(ConfigVersionService::class);

        $v1 = $service->createVersion(self::TENANT, 'org-1', 'branding', 'primary_color', ['color' => '#ff0000'], 'v1', 'test');
        $service->activateVersion(self::TENANT, $v1['id'], 'test');

        $v2 = $service->createVersion(self::TENANT, 'org-1', 'branding', 'primary_color', ['color' => '#00ff00'], 'v2', 'test');
        $service->activateVersion(self::TENANT, $v2['id'], 'test');

        $rolledBack = $service->rollbackVersion(self::TENANT, $v2['id'], 'test');

        $this->assertEquals('active', $rolledBack['status']);

        $active = $service->getActiveVersion(self::TENANT, 'branding', 'primary_color');
        $this->assertNotNull($active);
    }
}
