<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AiCacheService;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class AiCacheTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-cache';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-cache', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function cache_service_remembers_values(): void
    {
        $service = app(AiCacheService::class);
        $result = $service->remember(self::TENANT, 'test-key', fn () => 'cached-value', 60);

        $this->assertEquals('cached-value', $result);
    }

    /** @test */
    public function cache_service_forgets_keys(): void
    {
        $service = app(AiCacheService::class);
        $service->remember(self::TENANT, 'forget-key', fn () => 'value', 60);
        $service->forget(self::TENANT, 'forget-key');

        $result = $service->remember(self::TENANT, 'forget-key', fn () => 'new-value', 60);
        $this->assertEquals('new-value', $result);
    }
}
