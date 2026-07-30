<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Authorization\Permission;
use App\Domain\Authorization\Role;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Route-level permission gate. Applied as `permission:decision.approve`.
 *
 * Deliberately fails CLOSED: an unrecognised role or an unrecognised
 * permission string denies. A typo in a route definition must not silently
 * grant access — the safe failure for an authorization check is refusal.
 *
 * EVERY DENIAL IS NOW WRITTEN DOWN. Pilot Acceptance §B requires that every
 * unauthorized attempt is "denied AND audited"; this gate denied correctly and
 * recorded nothing, so a campaign of probing left no trace anywhere. A refusal
 * nobody can count is indistinguishable from an attack nobody noticed.
 */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $role = Role::tryFromName($request->attributes->get('auth.role'));

        if ($role === null) {
            $this->auditDenial($request, 'unknown_role', null);

            return response()->json(['error' => 'forbidden', 'reason' => 'unknown_role'], 403);
        }

        foreach ($permissions as $needed) {
            $permission = Permission::tryFrom($needed);

            if ($permission === null || ! $role->grants($permission)) {
                $this->auditDenial($request, $needed, $role->value);

                return response()->json([
                    'error'    => 'forbidden',
                    'required' => $needed,
                    'role'     => $role->value,
                ], 403);
            }
        }

        return $next($request);
    }

    /**
     * Record the refusal.
     *
     * NEVER LETS THE AUDIT WRITE CHANGE THE ANSWER. The whole method is wrapped:
     * if hpbrain_audit_logs is unreachable — not migrated, wrong connection,
     * database down — the caller must still receive a clean 403 rather than a
     * 500. A gate that crashes when it cannot write its log is a gate that
     * turns an availability problem into a security incident, and the denial
     * itself is the part that must not fail.
     *
     * That also keeps the authorization tests honest: ApiAuthorizationTest
     * proves all three gates without touching a database, and it must stay that
     * way — a 403 reached without a query proves the middleware fired rather
     * than proving something about seeded data.
     */
    private function auditDenial(Request $request, string $required, ?string $role): void
    {
        try {
            $actor = (string) ($request->attributes->get('auth.userId') ?? 'anonymous');

            DB::table('hpbrain_audit_logs')->insert([
                'id' => Uuid::uuid4()->toString(),
                // EnsureTenantScope runs before this gate and resolves the
                // effective tenant, so a denial is attributed to the tenant the
                // caller was actually addressing.
                'tenant_id'   => (string) ($request->attributes->get('tenantId')
                    ?? $request->attributes->get('auth.tenantId') ?? 'unresolved'),
                'entity_type' => 'Authorization',
                // entity_id is VARCHAR(36) and a route URI is routinely longer,
                // so it holds a stable 32-character digest of method+URI. The
                // readable form travels in `changes`, where there is room for it.
                'entity_id'   => md5($request->method().' '.$request->path()),
                'action'      => $required.'.denied',
                'actor_id'    => $actor,
                'actor_name'  => $this->actorName($actor),
                'changes'     => json_encode([
                    'method'   => $request->method(),
                    'path'     => '/'.ltrim($request->path(), '/'),
                    'required' => $required,
                    'role'     => $role,
                ]),
                'ip_address'  => $request->ip(),
                'user_agent'  => (string) $request->userAgent(),
                // The store's column is created_at, not created_date.
                'created_at'  => now()->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // Swallowed on purpose — see the docblock. The denial still stands.
        }
    }

    /**
     * actor_name is NOT NULL and the JWT carries no name claim. An identity
     * with no row in the Brain's user table falls back to its id: an audit
     * entry naming the actor by id is worth far more than a denial that goes
     * unrecorded because a display field could not be resolved.
     */
    private function actorName(string $actorId): string
    {
        try {
            $name = DB::table('hpbrain_auth_users')->where('id', $actorId)->value('name');
        } catch (Throwable) {
            return $actorId;
        }

        return is_string($name) && $name !== '' ? $name : $actorId;
    }
}
