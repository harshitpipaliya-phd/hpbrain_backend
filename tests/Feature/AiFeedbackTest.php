<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AiFeedbackService;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class AiFeedbackTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-feedback';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-feedback', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function feedback_service_records_feedback(): void
    {
        $service = app(AiFeedbackService::class);

        $service->record(self::TENANT, 'exec-1', 'user-1', 'positive', 'Great response!');

        $feedback = $service->getFeedback(self::TENANT, 'exec-1');

        $this->assertNotEmpty($feedback);
        $this->assertEquals('positive', $feedback[0]['rating']);
    }
}
