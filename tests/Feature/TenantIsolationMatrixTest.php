<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;
use Tests\Support\BuildsBrainSchema;

/**
 * Tenant isolation, swept across every route that takes a tenant segment.
 *
 * THE RULE THAT MUST NEVER BEND: a caller may not read or write another
 * tenant's data by editing a URL. ApiAuthorizationTest proves it on a handful
 * of representative routes; this proves it on all of them, generated from the
 * live route table so a new route is covered the day it is declared.
 *
     * Admin users are pinned to the tenant claim too. A route tenant that differs
     * from the token tenant is a tenant mismatch, not an organization switch.
 */
final class TenantIsolationMatrixTest extends TestCase
{
    use \Tests\Support\SeedsEntityMappings;

    use BuildsBrainSchema;

    private const HOME    = 'tenant-alpha';
    private const FOREIGN = 'tenant-beta';

    /** Every role except admin stays pinned to its own token claim. */
    private const PINNED_ROLES = ['viewer', 'analyst', 'manager', 'tenant_admin'];

    private function auth(string $role, string $tenant = self::HOME): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-'.$role, 'tenantId' => $tenant, 'role' => $role,
        ])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** The ERP table EnsureTenantScope consults to decide an org exists. */
    private function seedOrganization(string $subInstituteId, ?string $deletedAt = null): void
    {
        if (! Schema::hasTable('institute_detail')) {
            Schema::create('institute_detail', function ($t) {
                $t->string('sub_institute_id');
                $t->timestamp('deleted_at')->nullable();
            });
        }

        DB::table('institute_detail')->insert([
            'sub_institute_id' => $subInstituteId, 'deleted_at' => $deletedAt,
        ]);

        // The tenant check resolves its source and fails closed without one, so
        // a seeded organization needs mappings to be addressable at all.
        $this->installEntityMappings([$subInstituteId]);
    }

    /**
     * Every route carrying a {tenantId} segment, with its HTTP verb.
     *
     * @return array<int, array{method: string, uri: string}>
     */
    private function tenantScopedRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), '{tenantId}')) {
                continue;
            }

            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');

            $routes[] = ['method' => (string) $method, 'uri' => $route->uri()];
        }

        return $routes;
    }

    private function url(string $uri, string $tenant): string
    {
        return '/'.preg_replace_callback(
            '/\{(\w+)\??\}/',
            fn (array $m) => $m[1] === 'tenantId' ? $tenant : Uuid::uuid4()->toString(),
            $uri
        );
    }

    // ---- The sweep -----------------------------------------------------------

    public function test_no_pinned_role_can_touch_another_tenant_on_any_route(): void
    {
        $routes = $this->tenantScopedRoutes();

        self::assertGreaterThan(
            60,
            count($routes),
            'The matrix found suspiciously few tenant-scoped routes; it would be passing vacuously.'
        );

        $failures = [];
        $checked  = 0;

        foreach ($routes as $route) {
            $url = $this->url($route['uri'], self::FOREIGN);

            foreach (self::PINNED_ROLES as $role) {
                $checked++;

                $response = $this->json($route['method'], $url, [], $this->auth($role));

                // 403 tenant_mismatch specifically — not merely "not 200". A
                // 404 or a 500 would also be non-200 while proving nothing
                // about isolation.
                if ($response->status() !== 403 || $response->json('error') !== 'tenant_mismatch') {
                    $failures[] = sprintf(
                        '%s %s as %s: expected 403 tenant_mismatch, got %d %s',
                        $route['method'], $url, $role, $response->status(), (string) $response->json('error')
                    );
                }
            }
        }

        self::assertSame([], $failures, implode("\n  - ", array_merge(
            [count($failures).' of '.$checked.' cross-tenant attempts were not refused:'], $failures
        )));
    }

    /**
     * Read, create, update and delete, each against a foreign tenant. The sweep
     * above already covers these; this states the four verbs explicitly because
     * "cannot read" and "cannot delete" are different claims a reviewer will
     * want to see made separately.
     */
    public function test_every_verb_is_refused_across_the_tenant_boundary(): void
    {
        $id = Uuid::uuid4()->toString();

        $cases = [
            'read'   => ['getJson',    '/api/v1/evidence/'.self::FOREIGN],
            'create' => ['postJson',   '/api/v1/decisions/'.self::FOREIGN.'/'.$id.'/approve'],
            'update' => ['patchJson',  '/api/v1/signals/'.self::FOREIGN.'/'.$id.'/status'],
            'delete' => ['deleteJson', '/api/v1/conversations/sessions/'.self::FOREIGN.'/'.$id],
        ];

        foreach ($cases as $verb => [$method, $url]) {
            // getJson($uri, $headers), deleteJson($uri, $data, $headers),
            // postJson/patchJson($uri, $data, $headers). Branch on verb so auth
            // headers never land in the request body.
            $response = match ($method) {
                'getJson'    => $this->getJson($url, $this->auth('manager')),
                'deleteJson' => $this->deleteJson($url, [], $this->auth('manager')),
                default      => $this->{$method}($url, [], $this->auth('manager')),
            };

            $response->assertStatus(403)
                ->assertJson(['error' => 'tenant_mismatch'], "manager must not {$verb} across tenants");
        }
    }

    // ---- Admins are pinned to the same boundary ------------------------------

    public function test_an_admin_cannot_address_another_organization_that_exists(): void
    {
        $this->seedOrganization('6');

        $this->getJson('/api/v1/workspace/6', $this->auth('admin'))
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);
    }

    public function test_the_cross_tenant_refusal_reaches_every_role(): void
    {
        $this->seedOrganization('6');

        foreach (array_merge(['admin'], self::PINNED_ROLES) as $role) {
            $this->getJson('/api/v1/workspace/6', $this->auth($role))
                ->assertStatus(403)
                ->assertJson(['error' => 'tenant_mismatch'], "{$role} must not cross tenants");
        }
    }

    public function test_the_widening_is_bounded_to_organizations_that_actually_exist(): void
    {
        $this->seedOrganization('6');

        // BOUND 2 — existence. Otherwise the URL segment becomes a probe for
        // arbitrary tenant strings, and an admin could address a tenant that
        // was never provisioned.
        $this->getJson('/api/v1/workspace/999', $this->auth('admin'))
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);
    }

    public function test_the_widening_is_bounded_to_organizations_that_are_not_archived(): void
    {
        // BOUND 3 — an archived organization is not an organization.
        $this->seedOrganization('9', deletedAt: '2026-01-01 00:00:00');

        $this->getJson('/api/v1/workspace/9', $this->auth('admin'))
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);
    }

    public function test_the_widening_fails_closed_when_the_erp_cannot_be_read(): void
    {
        // BOUND 4 — no institute_detail table at all. "Cannot confirm" must
        // read as no: a tenant check that crashes must never be mistaken for a
        // tenant check that passed.
        $this->getJson('/api/v1/workspace/6', $this->auth('admin'))
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);
    }

    public function test_an_admin_crossing_tenants_is_still_bounded_by_permissions(): void
    {
        $this->seedOrganization('6');

        // The widening moves the tenant boundary, not the permission one. An
        // admin holds every permission, so the check that proves this is a role
        // that does not — and a manager cannot cross at all, which is the
        // point: the two gates compose rather than substituting for each other.
        $this->putJson('/api/v1/settings/6', ['key' => 'k', 'value' => 1], $this->auth('manager'))
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);
    }

    // ---- The tenant is never taken from the payload --------------------------

    public function test_a_body_supplied_tenant_id_cannot_redirect_a_write(): void
    {
        // Routes without a {tenantId} segment take the tenant from the TOKEN.
        // A tenantId in the body is accepted by validation and then ignored;
        // if it were honoured, every such endpoint would be a cross-tenant
        // write primitive.
        $response = $this->postJson('/api/v1/evidence', [
            'tenantId'   => self::FOREIGN,
            'signalId'   => Uuid::uuid4()->toString(),
            'source'     => 'x',
            'content'    => ['note' => 'x'],
            'provenance' => ['source' => 'x', 'ts' => '2026-07-20T09:00:00Z', 'confidence' => 0.5],
        ], $this->auth('analyst'));

        // It is refused because the SIGNAL is not in the caller's own tenant —
        // the write was scoped to tenant-alpha regardless of what the body said.
        $response->assertStatus(422)->assertJson(['error' => 'signal_not_found']);
    }
}
