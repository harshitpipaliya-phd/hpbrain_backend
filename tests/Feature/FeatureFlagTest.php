<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\FeatureFlagRepository;
use App\Services\ConfigurationEngine;
use App\Services\TenantConfigCache;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class FeatureFlagTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-flag';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-flag', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function feature_flag_evaluation_respects_enabled(): void
    {
        $repo = app(FeatureFlagRepository::class);
        $repo->create(self::TENANT, [
            'flag_key'  => 'test_feature',
            'flag_name' => 'Test Feature',
            'enabled'   => true,
            'level'     => 'platform',
            'created_by'=> 'test',
        ]);

        $engine = app(ConfigurationEngine::class);
        $this->assertTrue($engine->isFeatureEnabled(self::TENANT, 'test_feature', []));
    }

    /** @test */
    public function disabled_feature_flag_returns_false(): void
    {
        $repo = app(FeatureFlagRepository::class);
        $repo->create(self::TENANT, [
            'flag_key'  => 'disabled_feature',
            'flag_name' => 'Disabled Feature',
            'enabled'   => false,
            'level'     => 'platform',
            'created_by'=> 'test',
        ]);

        $engine = app(ConfigurationEngine::class);
        $this->assertFalse($engine->isFeatureEnabled(self::TENANT, 'disabled_feature', []));
    }
}
