<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\ModelCapabilityRegistry;
use App\Services\AiWorkspaceService;
use App\Services\AiQuotaEnforcer;
use App\Services\TokenCostAccountingService;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class AiTenantIsolationTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT_A = 'tenant-ai-a';
    private const TENANT_B = 'tenant-ai-b';

    private function auth(string $role = 'admin', string $tenant = self::TENANT_A): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-ai-iso', 'tenantId' => $tenant, 'role' => $role,
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function ai_providers_are_tenant_scoped(): void
    {
        \Illuminate\Support\Facades\DB::table('hpbrain_ai_providers')->insert([
            'id' => 'prov-a', 'tenant_id' => self::TENANT_A, 'provider_name' => 'Provider A',
            'provider_type' => 'anthropic', 'config' => json_encode([]), 'is_active' => true, 'priority' => 0,
            'created_by' => 'test', 'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);
        \Illuminate\Support\Facades\DB::table('hpbrain_ai_providers')->insert([
            'id' => 'prov-b', 'tenant_id' => self::TENANT_B, 'provider_name' => 'Provider B',
            'provider_type' => 'openai', 'config' => json_encode([]), 'is_active' => true, 'priority' => 0,
            'created_by' => 'test', 'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);

        $response = $this->withHeaders($this->auth('admin', self::TENANT_A))->getJson("/api/v1/ai/providers");

        $response->assertStatus(200);
        $this->assertStringNotContainsString('Provider B', $response->getContent());
    }

    /** @test */
    public function ai_feedback_is_tenant_scoped(): void
    {
        \Illuminate\Support\Facades\DB::table('hpbrain_ai_feedback')->insert([
            'id' => 'fb-a', 'tenant_id' => self::TENANT_A, 'execution_id' => 'exec-1', 'user_id' => 'user-1',
            'rating' => 'positive', 'feedback_text' => 'Great', 'feedback_type' => null,
            'metadata' => json_encode([]), 'created_date' => '2026-01-01 00:00:00',
        ]);
        \Illuminate\Support\Facades\DB::table('hpbrain_ai_feedback')->insert([
            'id' => 'fb-b', 'tenant_id' => self::TENANT_B, 'execution_id' => 'exec-1', 'user_id' => 'user-1',
            'rating' => 'negative', 'feedback_text' => 'Bad', 'feedback_type' => null,
            'metadata' => json_encode([]), 'created_date' => '2026-01-01 00:00:00',
        ]);

        $response = $this->withHeaders($this->auth('admin', self::TENANT_A))->getJson("/api/v1/ai/feedback/".self::TENANT_A);

        $response->assertStatus(200);
        $this->assertStringNotContainsString('Bad', $response->getContent());
    }
}
