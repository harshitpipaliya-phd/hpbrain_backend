<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\TerminologyRepository;
use App\Services\ConfigurationEngine;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class TerminologyEngineTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-term';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-term', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function resolves_terminology_for_healthcare(): void
    {
        $repo = app(TerminologyRepository::class);
        $repo->create(self::TENANT, [
            'industry_code' => 'healthcare',
            'entity_type'   => 'Person',
            'display_name'  => 'Patient',
            'plural_name'   => 'Patients',
            'created_by'    => 'test',
        ]);

        $engine = app(ConfigurationEngine::class);
        $result = $engine->resolveTerminology(self::TENANT, 'healthcare', 'Person');

        $this->assertEquals('Patient', $result);
    }

    /** @test */
    public function falls_back_when_terminology_not_found(): void
    {
        $engine = app(ConfigurationEngine::class);
        $result = $engine->resolveTerminology(self::TENANT, 'unknown_industry', 'Person');

        $this->assertNull($result);
    }
}
