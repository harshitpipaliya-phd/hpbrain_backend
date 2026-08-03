<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\EntityMappingRepository;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class EntityMappingTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-map';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-map', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function entity_mapping_crud_works(): void
    {
        $repo = app(EntityMappingRepository::class);

        $created = $repo->create(self::TENANT, [
            'source_system'    => 'erp',
            'source_entity'    => 'tbluser',
            'source_field'     => 'id',
            'universal_entity' => 'Person',
            'universal_field'  => 'id',
            'mapping_type'     => 'direct',
            'created_by'       => 'test',
        ]);

        $this->assertNotNull($created['id']);

        $found = $repo->find(self::TENANT, $created['id']);
        $this->assertEquals('erp', $found['source_system']);

        $updated = $repo->update(self::TENANT, $created['id'], ['mapping_type' => 'transform']);
        $this->assertEquals('transform', $updated['mapping_type']);

        $bySource = $repo->findBySource(self::TENANT, 'erp', 'tbluser');
        $this->assertNotEmpty($bySource);

        $ok = $repo->delete(self::TENANT, $created['id']);
        $this->assertTrue($ok);
    }

    /** @test */
    public function entity_mapping_is_tenant_scoped(): void
    {
        $repo = app(EntityMappingRepository::class);
        $repo->create('tenant-other', [
            'source_system'    => 'erp',
            'source_entity'    => 'tbluser',
            'source_field'     => 'id',
            'universal_entity' => 'Person',
            'universal_field'  => 'id',
            'mapping_type'     => 'direct',
            'created_by'       => 'test',
        ]);

        $results = $repo->findBySource(self::TENANT, 'erp', 'tbluser');
        $this->assertEmpty($results);
    }
}
