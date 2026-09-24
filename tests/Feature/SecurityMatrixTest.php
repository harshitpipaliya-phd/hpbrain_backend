<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authorization\Permission;
use App\Domain\Authorization\Role;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * The authorization model, proven over HTTP rather than in isolation.
 *
 * WHAT WAS UNPROVEN. Role and Permission are well designed and covered by 25
 * standalone assertions — as pure domain logic. Nothing proved the WIRING: that
 * the right permission is attached to each of the 150 routes, and that the
 * middleware actually refuses. A permission enum that denies correctly and a
 * route that forgot to declare it produce a system that is provably secure in
 * the unit tests and open in production.
 *
 * GENERATED FROM routes/api.php, NOT HAND-WRITTEN. 150 hand-written cases would
 * be stale the day someone adds a route — and the route they forget to cover
 * is exactly the one that will be wrong. The matrix walks the live route table,
 * so a new mutating route is covered the moment it is declared.
 *
 * Pilot §B also requires that every unauthorized attempt is denied AND AUDITED.
 * The sweep counts its own denials and asserts the audit log matches, so a
 * silent refusal fails this test.
 */
final class SecurityMatrixTest extends TestCase
{
    private const TENANT = 'tenant-alpha';

    /** Every role the system recognises. */
    private const ROLES = ['viewer', 'analyst', 'manager', 'admin', 'tenant_admin'];

    protected function setUp(): void
    {
        parent::setUp();

        // The denial log. Only this table is needed: the gate refuses before
        // any controller runs, so no domain table is ever touched.
        Schema::create('hpbrain_audit_logs', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('entity_type');
            $t->string('entity_id', 36);
            $t->text('action');
            $t->string('actor_id', 36);
            $t->text('actor_name');
            $t->text('changes')->nullable();
            $t->text('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    private function auth(string $role): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-'.$role, 'tenantId' => self::TENANT, 'role' => $role,
        ])];
    }

    /**
     * Every route that states a permission beyond the `read` floor.
     *
     * The enclosing group applies `permission:read`, which every role holds, so
     * a route carrying only the group default is readable AND writable by a
     * Viewer. The explicit verb is the thing that actually excludes anyone,
     * which makes these the routes worth sweeping.
     *
     * @return array<int, array{method: string, uri: string, permissions: array<int, string>, action: string}>
     */
    private function mutatingRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $permissions = [];

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    foreach (explode(',', substr($middleware, 11)) as $permission) {
                        if ($permission !== Permission::READ->value) {
                            $permissions[] = $permission;
                        }
                    }
                }
            }

            if ($permissions === []) {
                continue;
            }

            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');

            $routes[] = [
                'method'      => (string) $method,
                'uri'         => $route->uri(),
                'permissions' => array_values(array_unique($permissions)),
                'action'      => $route->getActionName(),
            ];
        }

        return $routes;
    }

    /** Fills path parameters: the tenant segment must match the token's. */
    private function url(string $uri): string
    {
        return '/'.preg_replace_callback(
            '/\{(\w+)\??\}/',
            fn (array $m) => $m[1] === 'tenantId' ? self::TENANT : Uuid::uuid4()->toString(),
            $uri
        );
    }

    private function shouldReach(string $role, array $permissions): bool
    {
        $resolved = Role::tryFromName($role);

        if ($resolved === null) {
            return false;
        }

        // Middleware composes additively, so a role must hold EVERY declared
        // permission, not merely one of them.
        foreach ($permissions as $permission) {
            $enum = Permission::tryFrom($permission);

            if ($enum === null || ! $resolved->grants($enum)) {
                return false;
            }
        }

        return true;
    }

    public function test_no_role_reaches_a_mutating_route_it_does_not_hold_the_permission_for(): void
    {
        $routes = $this->mutatingRoutes();

        self::assertGreaterThan(
            40,
            count($routes),
            'The matrix found suspiciously few gated routes; it would be passing vacuously.'
        );

        $failures = [];
        $expectedDenials = 0;

        foreach ($routes as $route) {
            $url = $this->url($route['uri']);

            foreach (self::ROLES as $role) {
                if ($this->shouldReach($role, $route['permissions'])) {
                    continue;
                }

                $expectedDenials++;

                $status = $this->json($route['method'], $url, [], $this->auth($role))->status();

                if ($status !== 403) {
                    $failures[] = sprintf(
                        '%s %s as %s: expected 403 (needs %s), got %d',
                        $route['method'], $url, $role, implode('+', $route['permissions']), $status
                    );
                }
            }
        }

        self::assertSame([], $failures, implode("\n  - ", array_merge(
            [count($failures).' role/route combinations were not refused:'], $failures
        )));

        // Pilot §B: denied AND audited. Every refusal above must have left a
        // row — a refusal nobody can count is indistinguishable from an attack
        // nobody noticed.
        self::assertSame(
            $expectedDenials,
            DB::table('hpbrain_audit_logs')->where('action', 'like', '%.denied')->count(),
            'Every 403 must write exactly one denial audit row.'
        );
    }

    /**
     * The counterpart that stops the matrix passing vacuously: a middleware
     * that denied EVERYONE would satisfy the test above completely.
     */
    public function test_a_role_that_holds_the_permission_is_not_refused(): void
    {
        $failures = [];

        foreach ($this->mutatingRoutes() as $route) {
            $url = $this->url($route['uri']);

            // Admin holds every permission, so RequirePermission must never be
            // the thing that stops it. The request may still fail later on a
            // missing table — that is the controller's business, not the gate's.
            $status = $this->json($route['method'], $url, [], $this->auth('admin'))->status();

            if ($status === 403) {
                $failures[] = sprintf('%s %s: admin was refused', $route['method'], $url);
            }
        }

        self::assertSame([], $failures, implode("\n  - ", array_merge(
            ['admin was refused by the permission gate on:'], $failures
        )));
    }

    /**
     * The denial row must be usable evidence, not just a count. Whoever reads
     * the log after an incident needs to know who, what, from where.
     */
    public function test_a_denial_row_names_the_actor_the_route_and_the_permission(): void
    {
        $this->postJson(
            '/api/v1/decisions/'.self::TENANT.'/'.Uuid::uuid4()->toString().'/approve',
            [],
            $this->auth('analyst')
        )->assertStatus(403);

        $row = DB::table('hpbrain_audit_logs')->where('action', 'decision.approve.denied')->first();

        self::assertNotNull($row, 'A governance denial that is not audited did not happen, as far as the log knows.');
        self::assertSame('user-analyst', $row->actor_id);
        self::assertSame('Authorization', $row->entity_type);
        self::assertSame(self::TENANT, $row->tenant_id);
        self::assertNotNull($row->created_at);

        $changes = json_decode((string) $row->changes, true);

        self::assertSame('POST', $changes['method']);
        self::assertStringContainsString('/approve', $changes['path']);
        self::assertSame('decision.approve', $changes['required']);
        self::assertSame('analyst', $changes['role']);
    }

    /**
     * An unrecognised role is refused and audited too. Failing closed is only
     * half the property — a probe with a forged role claim has to leave a trace.
     */
    public function test_an_unknown_role_is_refused_and_audited(): void
    {
        $this->getJson('/api/v1/signals/'.self::TENANT, $this->auth('superuser'))
            ->assertStatus(403)
            ->assertJson(['reason' => 'unknown_role']);

        self::assertSame(1, DB::table('hpbrain_audit_logs')->where('action', 'unknown_role.denied')->count());
    }

    /**
     * The gate must answer 403, not 500, when it cannot write its own log.
     * A denial that depends on the audit table being reachable turns a database
     * outage into an authorization outage.
     */
    public function test_a_denial_still_stands_when_the_audit_log_is_unwritable(): void
    {
        Schema::drop('hpbrain_audit_logs');

        $this->postJson('/api/v1/eso-executions', [], $this->auth('analyst'))
            ->assertStatus(403)
            ->assertJson(['required' => 'eso.execute']);
    }
}
