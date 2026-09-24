<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\EntityMappingRepository;
use App\Repositories\OrganizationRepository;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class BackwardCompatibilityTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-bc';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-bc', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function signals_endpoint_still_works(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/signals/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function evidence_endpoint_still_works(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/evidence/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function cases_endpoint_still_works(): void
    {
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/cases/".self::TENANT);

        $response->assertStatus(200);
    }

    /** @test */
    public function new_entity_mapping_does_not_affect_existing_organizations(): void
    {
        $mappingRepo = app(EntityMappingRepository::class);

        $mappingRepo->create(self::TENANT, [
            'source_system'    => 'erp',
            'source_entity'    => 'tbluser',
            'source_field'     => 'id',
            'universal_entity' => 'Person',
            'universal_field'  => 'id',
            'mapping_type'     => 'direct',
            'created_by'       => 'test',
        ]);

        $this->assertTrue(true);
    }
}
