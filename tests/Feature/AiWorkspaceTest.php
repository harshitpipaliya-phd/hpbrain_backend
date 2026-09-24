<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AiWorkspaceService;
use App\Services\EvaluationService;
use App\Services\AiFeedbackService;
use App\Services\AiAuditService;
use App\Services\SafetyService;
use App\Services\TokenCostAccountingService;
use App\Services\AiQuotaEnforcer;
use App\Support\Jwt;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

final class AiWorkspaceTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-workspace';

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-workspace', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @test */
    public function workspace_service_creates_session(): void
    {
        $service = app(AiWorkspaceService::class);
        $session = $service->createSession(self::TENANT, 'user-1', 'Test Session');

        $this->assertArrayHasKey('id', $session);
        $this->assertEquals('Test Session', $session['title']);
    }

    /** @test */
    public function workspace_service_sends_message(): void
    {
        $service = app(AiWorkspaceService::class);
        $session = $service->createSession(self::TENANT, 'user-1', 'Test Session');

        $message = $service->sendMessage(self::TENANT, $session['id'], 'Hello AI');

        $this->assertNotNull($message);
        $this->assertArrayHasKey('id', $message);
        $this->assertEquals('Hello AI', $message['content']);
    }

    /** @test */
    public function workspace_service_returns_history(): void
    {
        $service = app(AiWorkspaceService::class);
        $session = $service->createSession(self::TENANT, 'user-1', 'Test Session');
        $service->sendMessage(self::TENANT, $session['id'], 'Hello');

        $history = $service->getConversationHistory(self::TENANT, $session['id']);

        $this->assertNotEmpty($history);
    }

    /**
     * A session id is not a capability.
     *
     * The id is a UUID that may legitimately appear in another tenant's logs,
     * URLs or bug reports. Holding one must not be enough to append to the
     * conversation or read it back.
     *
     * @test
     */
    public function workspace_sessions_are_tenant_scoped(): void
    {
        $service = app(AiWorkspaceService::class);
        $session = $service->createSession(self::TENANT, 'user-1', 'Private Session');

        $service->sendMessage(self::TENANT, $session['id'], 'Confidential');

        self::assertNull(
            $service->sendMessage('tenant-other', $session['id'], 'Injected'),
            'A foreign tenant was able to append to this session.'
        );

        self::assertSame(
            [],
            $service->getConversationHistory('tenant-other', $session['id']),
            'A foreign tenant was able to read this transcript.'
        );

        // The rightful owner still sees exactly its own message, so the guard
        // above is rejecting the foreign tenant rather than breaking the table.
        self::assertCount(1, $service->getConversationHistory(self::TENANT, $session['id']));
    }

    /** @test */
    public function workspace_session_ids_are_real_uuids(): void
    {
        $service = app(AiWorkspaceService::class);
        $session = $service->createSession(self::TENANT, 'user-1', 'Test Session');

        // insertGetId() on a VARCHAR key returned 0 for every session, which
        // made each new conversation collide with the last.
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $session['id'],
        );
    }
}
