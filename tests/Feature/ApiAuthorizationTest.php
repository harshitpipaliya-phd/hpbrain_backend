<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves the three gates that stand between a request and this API actually
 * hold: authentication (AuthenticateJwt), tenant isolation (EnsureTenantScope)
 * and role authorization (RequirePermission).
 *
 * Every assertion here is reached WITHOUT touching a database. That is not an
 * accident of convenience — all three middleware run and return before the
 * controller is invoked, so a 401/403 proves the gate fired rather than
 * proving something about seeded data. The suite is pinned to in-memory
 * SQLite (phpunit.xml) so a regression that lets a request fall through to a
 * controller fails loudly instead of hitting the shared ERP database.
 */
final class ApiAuthorizationTest extends TestCase
{
    use \Tests\Support\SeedsEntityMappings;

    private const TENANT = 'tenant-alpha';

    private function token(string $role, string $tenant = self::TENANT, string $type = 'access'): string
    {
        $user = ['id' => "user-{$role}", 'tenantId' => $tenant, 'role' => $role];

        return $type === 'access'
            ? Jwt::issueAccess($user)
            : Jwt::issueRefresh($user);
    }

    private function auth(string $role, string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.$this->token($role, $tenant)];
    }

    // ---- 1. No token -> 401 ------------------------------------------------

    /** Representative sample across read, create, update and delete surfaces. */
    public static function protectedRoutes(): array
    {
        return [
            'read collection'  => ['getJson',    '/api/v1/signals/'.self::TENANT],
            'create signal'    => ['postJson',   '/api/v1/signals'],
            'update signal'    => ['patchJson',  '/api/v1/signals/'.self::TENANT.'/sig-1/status'],
            'delete session'   => ['deleteJson', '/api/v1/conversations/sessions/'.self::TENANT.'/sess-1'],
            'workspace'        => ['getJson',    '/api/v1/workspace/'.self::TENANT],
            'tasks run'        => ['postJson',   '/api/v1/tasks/run'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_a_request_with_no_token_is_rejected_with_401(string $verb, string $uri): void
    {
        $this->{$verb}($uri)
            ->assertStatus(401)
            ->assertJson(['error' => 'missing_token']);
    }

    public function test_a_malformed_token_is_rejected_with_401(): void
    {
        $this->getJson('/api/v1/signals/'.self::TENANT, ['Authorization' => 'Bearer not-a-jwt'])
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_token']);
    }

    public function test_a_refresh_token_cannot_be_used_as_an_access_token(): void
    {
        $this->getJson('/api/v1/signals/'.self::TENANT, [
            'Authorization' => 'Bearer '.$this->token('admin', self::TENANT, 'refresh'),
        ])->assertStatus(401)->assertJson(['error' => 'wrong_token_type']);
    }

    public function test_the_dev_bypass_backdoor_is_closed_outside_local(): void
    {
        // Guards the backdoor in AuthenticateJwt. APP_ENV is `testing` here.
        $this->getJson('/api/v1/signals/'.self::TENANT, ['Authorization' => 'Bearer dev-bypass'])
            ->assertStatus(401);
    }

    // ---- 2. Viewer cannot write -------------------------------------------

    /**
     * A sample of the routes that were previously reachable with nothing but
     * `permission:read` — i.e. writable by a Viewer. Covers create, update and
     * delete so a regression in any one verb is caught.
     */
    public static function viewerForbiddenRoutes(): array
    {
        return [
            'create signal'        => ['postJson',   '/api/v1/signals', ['classification' => 'x']],
            'create evidence'      => ['postJson',   '/api/v1/evidence', ['signalId' => 'x']],
            'create case'          => ['postJson',   '/api/v1/cases', ['title' => 'x']],
            'update signal status' => ['patchJson',  '/api/v1/signals/'.self::TENANT.'/sig-1/status', ['status' => 'open']],
            'transition case'      => ['patchJson',  '/api/v1/cases/'.self::TENANT.'/case-1/transition', ['status' => 'open']],
            'update person'        => ['patchJson',  '/api/v1/people/'.self::TENANT.'/p-1', ['name' => 'x']],
            'delete session'       => ['deleteJson', '/api/v1/conversations/sessions/'.self::TENANT.'/sess-1', []],
            'send message'         => ['postJson',   '/api/v1/conversations/sessions/'.self::TENANT.'/sess-1/messages', ['content' => 'hi']],
            'run tasks'            => ['postJson',   '/api/v1/tasks/run', ['tasks' => ['expire-stale-evidence']]],
        ];
    }

    #[DataProvider('viewerForbiddenRoutes')]
    public function test_a_viewer_cannot_write(string $verb, string $uri, array $body): void
    {
        $response = $this->{$verb}($uri, $body, $this->auth('viewer'));

        $response->assertStatus(403);
        self::assertSame('forbidden', $response->json('error'));
        self::assertSame('viewer', $response->json('role'));
        self::assertContains(
            $response->json('required'),
            ['create', 'update', 'delete'],
            'Viewer must be stopped by a write permission, not by something incidental.'
        );
    }

    /**
     * The counterpart that makes the test above meaningful: the same routes
     * must NOT 403 for a role that legitimately holds the verb. Without this,
     * a middleware that denied everyone would pass the suite.
     */
    #[DataProvider('viewerForbiddenRoutes')]
    public function test_an_analyst_is_not_blocked_by_the_permission_gate(string $verb, string $uri, array $body): void
    {
        $response = $this->{$verb}($uri, $body, $this->auth('analyst'));

        // Analyst holds read+create+update but not delete, so the delete route
        // is legitimately refused; everything else must clear the gate.
        if ($response->status() === 403) {
            self::assertSame('delete', $response->json('required'));

            return;
        }

        self::assertNotSame(403, $response->status());
    }

    public function test_viewer_can_still_read(): void
    {
        // Reaches the controller, so it is not a 401/403. It may fail later on
        // the database, which is fine — the gate is what is under test.
        $status = $this->getJson('/api/v1/signals/'.self::TENANT, $this->auth('viewer'))->status();

        self::assertNotSame(401, $status);
        self::assertNotSame(403, $status);
    }

    public function test_viewer_retains_self_service_actions(): void
    {
        // The Part A decision: marking your own notifications read stays on the
        // read floor, so a Viewer must not be locked out of it.
        $status = $this->postJson(
            '/api/v1/notifications/'.self::TENANT.'/read-all', [], $this->auth('viewer')
        )->status();

        self::assertNotSame(403, $status);
    }

    public function test_viewer_can_evaluate_a_policy_because_evaluation_writes_nothing(): void
    {
        $status = $this->postJson(
            '/api/v1/policies/'.self::TENANT.'/pol-1/evaluate',
            ['context' => ['a' => 1]],
            $this->auth('viewer')
        )->status();

        self::assertNotSame(403, $status);
    }

    public function test_governance_permissions_are_separable_from_ordinary_writes(): void
    {
        // Analyst may create, but approving a decision and executing an ESO are
        // governance acts reserved for Manager and above (Role.php).
        $this->postJson('/api/v1/decisions/'.self::TENANT.'/dec-1/approve', [], $this->auth('analyst'))
            ->assertStatus(403)
            ->assertJson(['required' => 'decision.approve']);

        $this->postJson('/api/v1/eso-executions', [], $this->auth('analyst'))
            ->assertStatus(403)
            ->assertJson(['required' => 'eso.execute']);

        $this->putJson('/api/v1/settings/'.self::TENANT, ['key' => 'k', 'value' => 1], $this->auth('manager'))
            ->assertStatus(403)
            ->assertJson(['required' => 'settings.manage']);
    }

    public function test_an_unknown_role_fails_closed(): void
    {
        $this->getJson('/api/v1/signals/'.self::TENANT, $this->auth('superuser'))
            ->assertStatus(403)
            ->assertJson(['reason' => 'unknown_role']);
    }

    // ---- 3. Cross-tenant access -------------------------------------------

    public function test_a_valid_token_cannot_read_another_tenants_data(): void
    {
        // Token is scoped to tenant-alpha; the URL asks for tenant-beta.
        $this->getJson('/api/v1/signals/tenant-beta', $this->auth('admin', 'tenant-alpha'))
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);
    }

    public function test_a_valid_token_cannot_write_to_another_tenant(): void
    {
        $this->patchJson(
            '/api/v1/signals/tenant-beta/sig-1/status',
            ['status' => 'closed'],
            $this->auth('admin', 'tenant-alpha')
        )->assertStatus(403)->assertJson(['error' => 'tenant_mismatch']);
    }

    /**
     * No role may address a tenant that does not exist as an organization —
     * admin included. This is the property that stops the URL segment being
     * used to probe for arbitrary tenant strings.
     */
    public function test_tenant_isolation_is_not_escapable_by_role(): void
    {
        foreach (['viewer', 'analyst', 'manager', 'admin', 'tenant_admin'] as $role) {
            $this->getJson('/api/v1/workspace/tenant-beta', $this->auth($role, 'tenant-alpha'))
                ->assertStatus(403)
                ->assertJson(['error' => 'tenant_mismatch']);
        }
    }

    // ---- 3b. The admin cross-tenant exception ------------------------------
    //
    // The Brain keys its data by the institute's sub_institute_id, so an
    // operator working across organizations needs to address a tenant their
    // single token claim does not name. EnsureTenantScope allows exactly that,
    // for admins only, and only for organizations that exist. These tests pin
    // both halves of that bargain — the permission AND its limits.

    /** Creates the ERP table the middleware consults, with one organization. */
    private function seedOrganization(string $subInstituteId): void
    {
        \Illuminate\Support\Facades\Schema::create('institute_detail', function ($t) {
            $t->string('sub_institute_id');
            $t->timestamp('deleted_at')->nullable();
        });

        \Illuminate\Support\Facades\DB::table('institute_detail')
            ->insert(['sub_institute_id' => $subInstituteId, 'deleted_at' => null]);

        // An organization the vocabulary layer cannot describe is not one this
        // request may address — the tenant check resolves its source now, and
        // fails closed when there is none. Production maps every tenant; the
        // fixture has to as well.
        $this->installEntityMappings([$subInstituteId]);
    }

    public function test_an_admin_may_address_an_organization_that_exists(): void
    {
        $this->seedOrganization('6');

        $status = $this->getJson('/api/v1/workspace/6', $this->auth('admin', 'tenant-alpha'))->status();

        self::assertNotSame(403, $status, 'admin should be able to address organization 6');
    }

    /** The widening is for admins alone; every other role stays pinned. */
    public function test_a_non_admin_may_not_cross_tenants_even_to_a_real_organization(): void
    {
        $this->seedOrganization('6');

        foreach (['viewer', 'analyst', 'manager', 'tenant_admin'] as $role) {
            $this->getJson('/api/v1/workspace/6', $this->auth($role, 'tenant-alpha'))
                ->assertStatus(403)
                ->assertJson(['error' => 'tenant_mismatch']);
        }
    }

    /** An archived organization is not an organization. */
    public function test_an_admin_may_not_address_a_soft_deleted_organization(): void
    {
        \Illuminate\Support\Facades\Schema::create('institute_detail', function ($t) {
            $t->string('sub_institute_id');
            $t->timestamp('deleted_at')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('institute_detail')
            ->insert(['sub_institute_id' => '9', 'deleted_at' => '2026-01-01 00:00:00']);

        $this->getJson('/api/v1/workspace/9', $this->auth('admin', 'tenant-alpha'))
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);
    }

    /**
     * If institute_detail cannot be read at all, the check must fail closed.
     * No table is created here, so the lookup throws internally — and the
     * request must still be denied rather than 500, because a tenant check
     * that crashes must never be mistaken for one that passed.
     */
    public function test_the_tenant_check_fails_closed_when_the_erp_is_unreachable(): void
    {
        $this->getJson('/api/v1/workspace/6', $this->auth('admin', 'tenant-alpha'))
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);
    }

    public function test_tenant_is_taken_from_the_token_not_the_url(): void
    {
        // Matching tenant clears the isolation gate.
        $status = $this->getJson('/api/v1/workspace/'.self::TENANT, $this->auth('admin'))->status();

        self::assertNotSame(403, $status);
    }

    public function test_a_token_with_no_tenant_claim_is_rejected(): void
    {
        $token = Jwt::issueAccess(['id' => 'u1', 'tenantId' => '', 'role' => 'admin']);

        $this->getJson('/api/v1/signals/'.self::TENANT, ['Authorization' => 'Bearer '.$token])
            ->assertStatus(401)
            ->assertJson(['error' => 'tenant_unresolved']);
    }
}
