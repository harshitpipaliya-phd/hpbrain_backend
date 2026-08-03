<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\IndustryTemplateRepository;
use App\Repositories\IndustryRepository;
use App\Services\ConfigurationEngine;
use App\Services\TenantConfigCache;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class IndustryTemplateTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-template';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-tmpl', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function industry_template_inherits_terminology(): void
    {
        $repo = app(IndustryTemplateRepository::class);
        $repo->create(self::TENANT, [
            'industry_code' => 'healthcare',
            'template_name' => 'Healthcare Template',
            'terminology'   => ['Person' => 'Patient', 'OrganizationUnit' => 'Ward'],
            'modules'       => ['intelligence', 'capabilities'],
            'created_by'    => 'test',
        ]);

        $template = $repo->findByIndustryCode(self::TENANT, 'healthcare');

        $this->assertNotNull($template);
        $this->assertEquals('Patient', $template['terminology']['Person'] ?? null);
    }

    /** @test */
    public function industry_template_has_default_modules(): void
    {
        $repo = app(IndustryTemplateRepository::class);
        $repo->create(self::TENANT, [
            'industry_code' => 'corporate',
            'template_name' => 'Corporate Template',
            'terminology'   => [],
            'modules'       => ['intelligence', 'decisions', 'analytics'],
            'created_by'    => 'test',
        ]);

        $template = $repo->findByIndustryCode(self::TENANT, 'corporate');

        $this->assertNotNull($template);
        $this->assertContains('decisions', $template['modules']);
    }
}
