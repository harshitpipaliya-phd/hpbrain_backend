<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\ModelCapabilityRegistry;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class AiProviderRegistryTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-ai-registry';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-ai-registry', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function registers_and_retrieves_model_capabilities(): void
    {
        ModelCapabilityRegistry::register('gpt-4', ['grounded_chat', 'extraction'], 8192, 128000);

        $this->assertTrue(ModelCapabilityRegistry::hasCapability('gpt-4', 'grounded_chat'));
        $this->assertFalse(ModelCapabilityRegistry::hasCapability('gpt-4', 'embedding'));
    }

    /** @test */
    public function returns_default_capability_for_unknown_model(): void
    {
        $cap = ModelCapabilityRegistry::get('unknown-model');

        $this->assertEquals('unknown-model', $cap->model);
        $this->assertFalse($cap->has('grounded_chat'));
    }

    /** @test */
    public function gets_models_by_capability(): void
    {
        ModelCapabilityRegistry::register('model-a', ['grounded_chat'], 4096, 8192);
        ModelCapabilityRegistry::register('model-b', ['extraction'], 4096, 8192);

        $models = ModelCapabilityRegistry::getModelsByCapability('grounded_chat');

        $this->assertContains('model-a', $models);
        $this->assertNotContains('model-b', $models);
    }
}
