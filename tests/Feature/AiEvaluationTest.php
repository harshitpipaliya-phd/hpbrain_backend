<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\EvaluationService;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class AiEvaluationTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-eval';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-eval', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function evaluation_service_creates_dataset(): void
    {
        $service = app(EvaluationService::class);
        $result = $service->createDataset(self::TENANT, 'Test Dataset', [
            ['id' => 'case-1', 'input' => 'test', 'expected' => 'result'],
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('Test Dataset', $result['name']);
    }

    /** @test */
    public function evaluation_service_runs_evaluation(): void
    {
        $service = app(EvaluationService::class);
        $dataset = $service->createDataset(self::TENANT, 'Test Dataset', [
            ['id' => 'case-1', 'input' => 'test', 'expected' => 'result'],
        ]);

        $results = $service->runEvaluation(self::TENANT, $dataset['id'], 'gpt-4');

        $this->assertArrayHasKey('total', $results);
        $this->assertEquals(1, $results['total']);
    }
}
